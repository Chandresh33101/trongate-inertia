<?php
/**
 * Trade_analytics: turns a list of closed trades into performance statistics
 * and plain-language insights. Pure PHP (no database, no framework), so it can
 * be unit-tested with known numbers. Loaded by Trade_manager_model.
 *
 * Input rows (oldest exit first):
 *   id, symbol, direction, pnl, r (R-multiple or null), entry_date, exit_date
 *   ('Y-m-d H:i:s'), fees, tags (string[])
 */
class Trade_analytics {

    /** Buckets/trades below this count are too small to draw conclusions from. */
    private const MIN_BUCKET  = 5;
    private const MIN_TAGGED  = 3;
    private const SMALL_TOTAL = 20;

    public static function compute(array $trades): array {
        $trades = array_values($trades);
        $n = count($trades);

        if ($n === 0) {
            return ['has_data' => false];
        }

        $pnls   = array_map(fn($t) => (float) $t['pnl'], $trades);
        $wins   = array_filter($pnls, fn($p) => $p > 0);
        $losses = array_filter($pnls, fn($p) => $p < 0);

        $gross_win  = array_sum($wins);
        $gross_loss = abs(array_sum($losses));
        $avg_win    = $wins   ? $gross_win / count($wins) : null;
        $avg_loss   = $losses ? -$gross_loss / count($losses) : null;

        $rs = array_values(array_filter(array_map(fn($t) => $t['r'] ?? null, $trades), fn($r) => $r !== null));

        [$equity, $max_dd, $max_dd_date, $cur_dd] = self::equity_curve($trades);
        [$best_win, $best_loss, $cur_streak]       = self::streaks($pnls);

        $hold_win  = self::avg_hold_minutes($trades, fn($p) => $p > 0);
        $hold_loss = self::avg_hold_minutes($trades, fn($p) => $p < 0);

        $summary = [
            'trades'         => $n,
            'wins'           => count($wins),
            'losses'         => count($losses),
            'breakeven'      => $n - count($wins) - count($losses),
            'win_rate'       => round(count($wins) / $n * 100, 1),
            'net_pnl'        => round(array_sum($pnls), 2),
            'gross_win'      => round($gross_win, 2),
            'gross_loss'     => round($gross_loss, 2),
            'profit_factor'  => $gross_loss > 0 ? round($gross_win / $gross_loss, 2) : null,
            'expectancy'     => round(array_sum($pnls) / $n, 2),
            'avg_win'        => $avg_win  !== null ? round($avg_win, 2)  : null,
            'avg_loss'       => $avg_loss !== null ? round($avg_loss, 2) : null,
            'payoff_ratio'   => ($avg_win !== null && $avg_loss !== null) ? round($avg_win / abs($avg_loss), 2) : null,
            'best_trade'     => round(max($pnls), 2),
            'worst_trade'    => round(min($pnls), 2),
            'avg_r'          => $rs ? round(array_sum($rs) / count($rs), 2) : null,
            'r_trades'       => count($rs),
            'fees'           => round(array_sum(array_map(fn($t) => (float) ($t['fees'] ?? 0), $trades)), 2),
            'max_drawdown'   => round($max_dd, 2),
            'max_drawdown_date' => $max_dd_date,
            'current_drawdown'  => round($cur_dd, 2),
            'longest_win_streak'  => $best_win,
            'longest_loss_streak' => $best_loss,
            'current_streak'      => $cur_streak,
            'avg_hold_win_min'    => $hold_win,
            'avg_hold_loss_min'   => $hold_loss,
        ];

        $data = [
            'has_data'     => true,
            'summary'      => $summary,
            'equity'       => $equity,
            'by_symbol'    => self::group($trades, fn($t) => [$t['symbol']], 'net_pnl', 8),
            'by_direction' => self::group($trades, fn($t) => [$t['direction']]),
            'by_weekday'   => self::weekdays($trades),
            'by_hour'      => self::group($trades, fn($t) => [sprintf('%02d:00', (int) date('G', strtotime($t['entry_date'])))], 'key'),
            'by_month'     => self::group($trades, fn($t) => [date('Y-m', strtotime($t['exit_date']))], 'key'),
            'by_tag'       => self::group($trades, fn($t) => $t['tags'] ?? [], 'net_pnl', 12),
            'distribution' => self::distribution($pnls),
        ];
        $data['insights'] = self::insights($data);

        return $data;
    }

    // ── Equity / drawdown ────────────────────────────────────────────────────

    /** @return array{0: array, 1: float, 2: ?string, 3: float} */
    private static function equity_curve(array $trades): array {
        $equity = 0.0;
        $peak   = 0.0;
        $max_dd = 0.0;
        $max_dd_date = null;
        $points = [];

        foreach ($trades as $t) {
            $equity += (float) $t['pnl'];
            $peak    = max($peak, $equity);
            $dd      = $peak - $equity;
            if ($dd > $max_dd) {
                $max_dd      = $dd;
                $max_dd_date = substr($t['exit_date'], 0, 10);
            }
            $points[] = [
                'date'     => substr($t['exit_date'], 0, 10),
                'pnl'      => round((float) $t['pnl'], 2),
                'equity'   => round($equity, 2),
                'drawdown' => round($dd, 2),
            ];
        }
        return [$points, $max_dd, $max_dd_date, $peak - $equity];
    }

    /** @return array{0:int,1:int,2:array{type:string,count:int}} */
    private static function streaks(array $pnls): array {
        $best_win = $best_loss = $run = 0;
        $type = '';
        foreach ($pnls as $p) {
            $t = $p > 0 ? 'win' : ($p < 0 ? 'loss' : '');
            if ($t === '') {                      // breakeven ends a streak
                $type = '';
                $run  = 0;
                continue;
            }
            $run  = ($t === $type) ? $run + 1 : 1;
            $type = $t;
            if ($t === 'win')  $best_win  = max($best_win, $run);
            if ($t === 'loss') $best_loss = max($best_loss, $run);
        }
        return [$best_win, $best_loss, ['type' => $type, 'count' => $run]];
    }

    private static function avg_hold_minutes(array $trades, callable $pick): ?int {
        $mins = [];
        foreach ($trades as $t) {
            if (!$pick((float) $t['pnl'])) {
                continue;
            }
            $m = (strtotime($t['exit_date']) - strtotime($t['entry_date'])) / 60;
            if ($m >= 0) {
                $mins[] = $m;
            }
        }
        return $mins ? (int) round(array_sum($mins) / count($mins)) : null;
    }

    // ── Breakdowns ───────────────────────────────────────────────────────────

    /**
     * Group trades by one or more keys per trade (tags put a trade in several groups).
     *
     * @param string $sort 'key' (ascending) or 'net_pnl' (descending)
     * @return array[] each: key, trades, wins, net_pnl, win_rate, avg_pnl
     */
    private static function group(array $trades, callable $keys, string $sort = 'key', ?int $limit = null): array {
        $g = [];
        foreach ($trades as $t) {
            foreach (array_unique($keys($t)) as $k) {
                $g[$k] ??= ['key' => (string) $k, 'trades' => 0, 'wins' => 0, 'net_pnl' => 0.0];
                $g[$k]['trades']++;
                $g[$k]['net_pnl'] += (float) $t['pnl'];
                if ((float) $t['pnl'] > 0) {
                    $g[$k]['wins']++;
                }
            }
        }

        $rows = array_map(function ($r) {
            $r['win_rate'] = round($r['wins'] / $r['trades'] * 100, 1);
            $r['avg_pnl']  = round($r['net_pnl'] / $r['trades'], 2);
            $r['net_pnl']  = round($r['net_pnl'], 2);
            return $r;
        }, array_values($g));

        $sort === 'net_pnl'
            ? usort($rows, fn($a, $b) => $b['net_pnl'] <=> $a['net_pnl'])
            : usort($rows, fn($a, $b) => strcmp($a['key'], $b['key']));

        return $limit !== null ? array_slice($rows, 0, $limit) : $rows;
    }

    /** Always returns Mon..Sun in order (empty days have 0 trades). */
    private static function weekdays(array $trades): array {
        $names = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $rows  = self::group($trades, fn($t) => [$names[(int) date('N', strtotime($t['entry_date'])) - 1]]);
        $by    = array_column($rows, null, 'key');

        return array_map(
            fn($d) => $by[$d] ?? ['key' => $d, 'trades' => 0, 'wins' => 0, 'net_pnl' => 0.0, 'win_rate' => 0.0, 'avg_pnl' => 0.0],
            $names
        );
    }

    /** ~10 equal-width P&L buckets for a histogram. */
    private static function distribution(array $pnls): array {
        $min = min($pnls);
        $max = max($pnls);
        if ($min === $max) {
            return [['from' => round($min, 2), 'to' => round($max, 2), 'count' => count($pnls)]];
        }
        $bins  = min(10, max(4, (int) ceil(sqrt(count($pnls)))));
        $width = ($max - $min) / $bins;
        $out   = [];
        for ($i = 0; $i < $bins; $i++) {
            $out[] = ['from' => round($min + $i * $width, 2), 'to' => round($min + ($i + 1) * $width, 2), 'count' => 0];
        }
        foreach ($pnls as $p) {
            $i = min($bins - 1, (int) floor(($p - $min) / $width));
            $out[$i]['count']++;
        }
        return $out;
    }

    // ── Insights ─────────────────────────────────────────────────────────────

    /** @return array<int, array{tone: string, text: string}> most important first */
    private static function insights(array $d): array {
        $s   = $d['summary'];
        $out = [];
        $add = function (string $tone, string $text) use (&$out) {
            $out[] = ['tone' => $tone, 'text' => $text];
        };
        $m = fn(float $v) => ($v > 0 ? '+' : ($v < 0 ? '-' : '')) . number_format(abs($v), 2);

        // 1. The headline
        if ($s['expectancy'] > 0) {
            $add('good', "Your edge is positive: on average each trade makes {$m($s['expectancy'])}"
                . ($s['profit_factor'] !== null ? " (profit factor {$s['profit_factor']})." : '.'));
        } elseif ($s['expectancy'] < 0) {
            $add('warn', "On average each trade loses " . number_format(abs($s['expectancy']), 2) . " (net {$m($s['net_pnl'])} over {$s['trades']} trades). Review the breakdowns below to see where it leaks.");
        } else {
            $add('info', 'You are breaking even on average.');
        }

        // 2. Win rate vs payoff: the classic trap
        if ($s['payoff_ratio'] !== null && $s['trades'] >= self::MIN_BUCKET) {
            if ($s['win_rate'] >= 50 && $s['payoff_ratio'] < 1) {
                $add('warn', "You win {$s['win_rate']}% of trades, but your average loss ({$m($s['avg_loss'])}) is bigger than your average win ({$m($s['avg_win'])}). Cutting losers sooner, or letting winners run, would lift results more than picking more winners.");
            } elseif ($s['win_rate'] < 50 && $s['payoff_ratio'] >= 1.5) {
                $add('good', "You win only {$s['win_rate']}% of trades but your average win is {$s['payoff_ratio']}x your average loss. That is a sound trend-style profile; keep losses small.");
            }
        }

        // 3. Tags: the most actionable breakdown
        $tags = array_values(array_filter($d['by_tag'], fn($t) => $t['trades'] >= self::MIN_TAGGED));
        if ($tags) {
            $best  = $tags[0];
            $worst = end($tags);
            if ($worst['net_pnl'] < 0) {
                $add('warn', "Trades tagged “{$worst['key']}” lost " . number_format(abs($worst['net_pnl']), 2) . " over {$worst['trades']} trades (win rate {$worst['win_rate']}%). Consider avoiding or tightening rules for this setup.");
            }
            if ($best['net_pnl'] > 0 && $best['key'] !== $worst['key']) {
                $add('good', "Your best tag is “{$best['key']}”: {$m($best['net_pnl'])} over {$best['trades']} trades (win rate {$best['win_rate']}%). Look for more of these.");
            }
        }

        // 4. Weekday
        $days = array_values(array_filter($d['by_weekday'], fn($x) => $x['trades'] >= self::MIN_BUCKET));
        if (count($days) >= 2) {
            usort($days, fn($a, $b) => $b['avg_pnl'] <=> $a['avg_pnl']);
            $best  = $days[0];
            $worst = end($days);
            if ($worst['avg_pnl'] < 0) {
                $add('warn', "{$worst['key']} is your weakest day: {$m($worst['avg_pnl'])} per trade across {$worst['trades']} trades."
                    . ($best['avg_pnl'] > 0 ? " {$best['key']} is your best ({$m($best['avg_pnl'])} per trade)." : ''));
            }
        }

        // 5. Long vs short
        $dir = array_column($d['by_direction'], null, 'key');
        if (isset($dir['long'], $dir['short']) && $dir['long']['trades'] >= self::MIN_BUCKET && $dir['short']['trades'] >= self::MIN_BUCKET) {
            [$l, $sh] = [$dir['long'], $dir['short']];
            if (($l['net_pnl'] > 0) !== ($sh['net_pnl'] > 0)) {
                [$good, $bad] = $l['net_pnl'] > 0 ? [$l, $sh] : [$sh, $l];
                $add('warn', "{$good['key']} trades make money ({$m($good['net_pnl'])}) while {$bad['key']} trades lose (" . number_format(abs($bad['net_pnl']), 2) . "). You may be better off skipping the {$bad['key']} side for now.");
            }
        }

        // 6. Holding losers longer than winners
        if ($s['avg_hold_win_min'] !== null && $s['avg_hold_loss_min'] !== null
            && $s['wins'] >= self::MIN_TAGGED && $s['losses'] >= self::MIN_TAGGED
            && $s['avg_hold_win_min'] > 0 && $s['avg_hold_loss_min'] > $s['avg_hold_win_min'] * 1.5) {
            $add('warn', 'You hold losing trades about ' . round($s['avg_hold_loss_min'] / $s['avg_hold_win_min'], 1)
                . 'x longer than winning ones (' . self::duration($s['avg_hold_loss_min']) . ' vs ' . self::duration($s['avg_hold_win_min'])
                . '). That pattern often means hoping instead of following the stop.');
        }

        // 7. Fee drag
        if ($s['gross_win'] > 0 && $s['fees'] > $s['gross_win'] * 0.2) {
            $pct = round($s['fees'] / $s['gross_win'] * 100);
            $add('warn', "Fees total {$s['fees']}, about {$pct}% of your gross profit. Fewer, higher-quality trades or lower costs would help.");
        }

        // 8. Drawdown context
        if ($s['max_drawdown'] > 0 && $s['current_drawdown'] > 0) {
            $add('info', "You are currently " . number_format($s['current_drawdown'], 2) . " below your equity peak; your deepest drawdown was " . number_format($s['max_drawdown'], 2) . " (around {$s['max_drawdown_date']}).");
        } elseif ($s['max_drawdown'] > 0) {
            $add('good', "You are at a new equity high. Your deepest drawdown was " . number_format($s['max_drawdown'], 2) . ".");
        }

        // 9. Live streak
        $cs = $s['current_streak'];
        if ($cs['type'] === 'loss' && $cs['count'] >= 3) {
            $add('warn', "You are on a {$cs['count']}-trade losing streak. Consider reducing size until you are back in rhythm.");
        } elseif ($cs['type'] === 'win' && $cs['count'] >= 5) {
            $add('info', "You are on a {$cs['count']}-trade winning streak. Stay disciplined; do not raise risk just because it is going well.");
        }

        // 10. Honesty about sample size
        if ($s['trades'] < self::SMALL_TOTAL) {
            $add('info', "This is based on only {$s['trades']} closed trades. Patterns usually need 30+ trades before they can be trusted, so treat the points above as hints.");
        }

        return $out;
    }

    private static function duration(int $minutes): string {
        if ($minutes < 90)   return $minutes . ' min';
        if ($minutes < 2880) return round($minutes / 60, 1) . ' h';
        return round($minutes / 1440, 1) . ' days';
    }
}
