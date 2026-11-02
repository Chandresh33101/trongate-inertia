import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { url } from '../../lib/url';
import { duration, money, pnlColor } from '../../lib/format';

const GREEN = '#15803d';
const RED = '#b91c1c';
const GRID = '#e5e7eb';
const RANGES = [['30d', '30 days'], ['90d', '90 days'], ['ytd', 'This year'], ['1y', '12 months'], ['all', 'All time'], ['custom', 'Custom']];

const card = { border: '1px solid #e5e7eb', borderRadius: 8, padding: '1rem', marginBottom: '1rem' };
const grid2 = { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: '1rem' };

function Kpi({ label, value, hint, color }) {
  return (
    <div style={{ padding: '.7rem .9rem', border: '1px solid #e5e7eb', borderRadius: 8 }} title={hint}>
      <div style={{ fontSize: '.75em', opacity: 0.7 }}>{label}</div>
      <div style={{ fontSize: '1.4em', fontWeight: 600, color }}>{value}</div>
    </div>
  );
}

function Section({ title, note, children }) {
  return (
    <section style={card}>
      <h3 style={{ margin: 0 }}>{title}</h3>
      {note && <p style={{ margin: '.2rem 0 .8rem', fontSize: '.85em', opacity: 0.7 }}>{note}</p>}
      {children}
    </section>
  );
}

// ── Equity curve + drawdown ─────────────────────────────────────────────────
function EquityChart({ points }) {
  const [hover, setHover] = useState(null);
  const W = 800, H = 240, DH = 90, P = { l: 56, r: 12, t: 10, b: 22 };
  const n = points.length;
  const xs = (i) => P.l + (n === 1 ? (W - P.l - P.r) / 2 : (i / (n - 1)) * (W - P.l - P.r));

  const eq = points.map((p) => p.equity);
  const lo = Math.min(0, ...eq), hi = Math.max(0, ...eq);
  const span = hi - lo || 1;
  const y = (v) => P.t + (1 - (v - lo) / span) * (H - P.t - P.b);
  const line = points.map((p, i) => `${i ? 'L' : 'M'}${xs(i).toFixed(1)},${y(p.equity).toFixed(1)}`).join(' ');
  const area = `${line} L${xs(n - 1).toFixed(1)},${y(0).toFixed(1)} L${xs(0).toFixed(1)},${y(0).toFixed(1)} Z`;

  const maxDd = Math.max(1, ...points.map((p) => p.drawdown));
  const dy = (v) => 6 + (v / maxDd) * (DH - 14);
  const ddLine = points.map((p, i) => `${i ? 'L' : 'M'}${xs(i).toFixed(1)},${dy(p.drawdown).toFixed(1)}`).join(' ');
  const ddArea = `M${xs(0).toFixed(1)},6 ${ddLine.replace(/^M/, 'L')} L${xs(n - 1).toFixed(1)},6 Z`;

  const ticks = [lo, lo + span / 2, hi];
  const onMove = (e) => {
    const r = e.currentTarget.getBoundingClientRect();
    const px = ((e.clientX - r.left) / r.width) * W;
    setHover(Math.max(0, Math.min(n - 1, Math.round(((px - P.l) / (W - P.l - P.r)) * (n - 1)))));
  };
  const h = hover !== null ? points[hover] : null;

  return (
    <div>
      <div style={{ minHeight: 22, fontSize: '.85em' }}>
        {h ? <>#{hover + 1} · {h.date} · equity <strong style={{ color: pnlColor(h.equity) }}>{money(h.equity)}</strong> · trade <span style={{ color: pnlColor(h.pnl) }}>{money(h.pnl)}</span> · drawdown {h.drawdown ? `-${h.drawdown}` : '0'}</> : 'Hover the chart for details'}
      </div>
      <svg viewBox={`0 0 ${W} ${H}`} width="100%" role="img" aria-label="Cumulative P&L over closed trades" onMouseMove={onMove} onMouseLeave={() => setHover(null)}>
        {ticks.map((t, i) => (
          <g key={i}>
            <line x1={P.l} x2={W - P.r} y1={y(t)} y2={y(t)} stroke={GRID} />
            <text x={P.l - 6} y={y(t) + 4} textAnchor="end" fontSize="11" fill="#6b7280">{Math.round(t).toLocaleString()}</text>
          </g>
        ))}
        <line x1={P.l} x2={W - P.r} y1={y(0)} y2={y(0)} stroke="#9ca3af" strokeDasharray="4 3" />
        <path d={area} fill={eq[n - 1] >= 0 ? GREEN : RED} opacity="0.12" />
        <path d={line} fill="none" stroke={eq[n - 1] >= 0 ? GREEN : RED} strokeWidth="2" />
        <text x={P.l} y={H - 4} fontSize="11" fill="#6b7280">{points[0].date}</text>
        <text x={W - P.r} y={H - 4} fontSize="11" fill="#6b7280" textAnchor="end">{points[n - 1].date}</text>
        {h && <><line x1={xs(hover)} x2={xs(hover)} y1={P.t} y2={H - P.b} stroke="#6b7280" /><circle cx={xs(hover)} cy={y(h.equity)} r="4" fill="#111827" /></>}
      </svg>
      <div style={{ fontSize: '.75em', opacity: 0.7, marginTop: 6 }}>Drawdown from peak (max {Math.round(maxDd).toLocaleString()})</div>
      <svg viewBox={`0 0 ${W} ${DH}`} width="100%" role="img" aria-label="Drawdown">
        <path d={ddArea} fill={RED} opacity="0.2" />
        <path d={ddLine} fill="none" stroke={RED} strokeWidth="1.5" />
        {h && <line x1={xs(hover)} x2={xs(hover)} y1={0} y2={DH} stroke="#6b7280" />}
      </svg>
    </div>
  );
}

// ── Vertical columns (weekday / hour / month / distribution) ────────────────
function Columns({ items, label = (d) => d.key, value = (d) => d.net_pnl, title, minHeight = 150 }) {
  const vals = items.map(value);
  const max = Math.max(1, ...vals.map(Math.abs));
  const W = Math.max(320, items.length * 44), H = minHeight, mid = H / 2;
  const bw = Math.min(30, (W / items.length) * 0.65);
  return (
    <svg viewBox={`0 0 ${W} ${H + 26}`} width="100%" role="img" aria-label={title}>
      <line x1="0" x2={W} y1={mid} y2={mid} stroke="#9ca3af" />
      {items.map((d, i) => {
        const v = vals[i];
        const h = (Math.abs(v) / max) * (mid - 14);
        const cx = (i + 0.5) * (W / items.length);
        return (
          <g key={i}>
            <title>{`${label(d)}: ${money(v)}${d.trades !== undefined ? ` · ${d.trades} trades · ${d.win_rate}% wins` : ''}`}</title>
            <rect x={cx - bw / 2} y={v >= 0 ? mid - h : mid} width={bw} height={Math.max(h, v === 0 ? 0 : 1)} fill={v >= 0 ? GREEN : RED} opacity={d.trades === 0 ? 0.2 : 0.9} rx="2" />
            <text x={cx} y={H + 14} textAnchor="middle" fontSize="11" fill="#6b7280">{label(d)}</text>
          </g>
        );
      })}
    </svg>
  );
}

// ── Horizontal ranked bars (symbols / tags) ─────────────────────────────────
function Bars({ rows, empty }) {
  if (!rows.length) return <p style={{ opacity: 0.6 }}>{empty}</p>;
  const max = Math.max(1, ...rows.map((r) => Math.abs(r.net_pnl)));
  return (
    <div>
      {rows.map((r) => (
        <div key={r.key} style={{ display: 'grid', gridTemplateColumns: '110px 1fr 92px', gap: 8, alignItems: 'center', marginBottom: 6, fontSize: '.9em' }}>
          <span title={r.key} style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{r.key}</span>
          <div style={{ background: '#f3f4f6', borderRadius: 4, height: 14 }} title={`${r.trades} trades · ${r.win_rate}% wins · avg ${money(r.avg_pnl)}`}>
            <div style={{ width: `${(Math.abs(r.net_pnl) / max) * 100}%`, height: '100%', background: r.net_pnl >= 0 ? GREEN : RED, borderRadius: 4 }} />
          </div>
          <span style={{ textAlign: 'right', color: pnlColor(r.net_pnl), fontWeight: 600 }}>{money(r.net_pnl)}</span>
          <span />
          <span style={{ gridColumn: '2 / 4', fontSize: '.75em', opacity: 0.65, marginTop: -4 }}>{r.trades} trades · {r.win_rate}% wins · avg {money(r.avg_pnl)}</span>
        </div>
      ))}
    </div>
  );
}

// ── Histogram of per-trade results ──────────────────────────────────────────
function Histogram({ bins }) {
  const max = Math.max(1, ...bins.map((b) => b.count));
  const W = 800, H = 130;
  const bw = (W / bins.length) * 0.8;
  return (
    <svg viewBox={`0 0 ${W} ${H + 30}`} width="100%" role="img" aria-label="Distribution of trade results">
      <line x1="0" x2={W} y1={H} y2={H} stroke="#9ca3af" />
      {bins.map((b, i) => {
        const h = (b.count / max) * (H - 18);
        const cx = (i + 0.5) * (W / bins.length);
        const fill = b.to <= 0 ? RED : b.from >= 0 ? GREEN : '#9ca3af';
        return (
          <g key={i}>
            <title>{`${money(b.from)} to ${money(b.to)}: ${b.count} trade${b.count === 1 ? '' : 's'}`}</title>
            <rect x={cx - bw / 2} y={H - h} width={bw} height={h} fill={fill} opacity="0.85" rx="2" />
            {b.count > 0 && <text x={cx} y={H - h - 4} textAnchor="middle" fontSize="11" fill="#374151">{b.count}</text>}
            <text x={cx} y={H + 14} textAnchor="middle" fontSize="10" fill="#6b7280">{Math.round(b.from)}</text>
            <text x={cx} y={H + 26} textAnchor="middle" fontSize="10" fill="#9ca3af">to {Math.round(b.to)}</text>
          </g>
        );
      })}
    </svg>
  );
}

const TONE = { good: GREEN, warn: '#b45309', info: '#2563eb' };
const TONE_LABEL = { good: 'Strength', warn: 'Watch out', info: 'Note' };

export default function Analytics({ range, from, to, analytics: a }) {
  const [custom, setCustom] = useState({ from, to });

  const go = (next) => router.get(url('trade_manager/analytics'), next, { preserveScroll: true, preserveState: true, replace: true });
  const pick = (r) => (r === 'custom' ? go({ range: 'custom', ...custom }) : go({ range: r }));

  const s = a.summary;

  return (
    <>
      <Head title="Performance Analytics" />
      <h1>Performance Analytics</h1>

      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', alignItems: 'center', marginBottom: '1rem' }}>
        {RANGES.map(([k, label]) => (
          <button key={k} type="button" className={range === k ? '' : 'alt'} onClick={() => pick(k)}>{label}</button>
        ))}
        {range === 'custom' && (
          <>
            <input type="date" value={custom.from} onChange={(e) => setCustom({ ...custom, from: e.target.value })} aria-label="From" />
            <input type="date" value={custom.to} onChange={(e) => setCustom({ ...custom, to: e.target.value })} aria-label="To" />
            <button type="button" onClick={() => go({ range: 'custom', ...custom })}>Apply</button>
          </>
        )}
      </div>

      {!a.has_data ? (
        <div style={card}>
          <p><strong>No closed trades in this period.</strong></p>
          <p>Analytics are built from closed trades (with an exit price). Close a trade, or widen the date range.</p>
          <Link href={url('trade_manager/create')} className="button">Log a trade</Link>
        </div>
      ) : (
        <>
          <Section title="What your trading is telling you" note="Plain-language findings from your own numbers. Based on closed trades in this period.">
            <ul style={{ listStyle: 'none', padding: 0, margin: 0 }}>
              {a.insights.map((i, k) => (
                <li key={k} style={{ borderLeft: `4px solid ${TONE[i.tone]}`, padding: '.4rem .8rem', margin: '.5rem 0', background: '#f9fafb' }}>
                  <strong style={{ color: TONE[i.tone], fontSize: '.8em', textTransform: 'uppercase' }}>{TONE_LABEL[i.tone]}</strong>
                  <div>{i.text}</div>
                </li>
              ))}
            </ul>
          </Section>

          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: '.6rem', marginBottom: '1rem' }}>
            <Kpi label="Net P&L" value={money(s.net_pnl)} color={pnlColor(s.net_pnl)} hint="Sum of all closed trades, after fees" />
            <Kpi label="Win rate" value={`${s.win_rate}%`} hint={`${s.wins} wins · ${s.losses} losses · ${s.breakeven} breakeven`} />
            <Kpi label="Profit factor" value={s.profit_factor ?? '—'} hint="Gross profit ÷ gross loss. Above 1 means profitable; 1.5+ is solid" />
            <Kpi label="Expectancy" value={money(s.expectancy)} color={pnlColor(s.expectancy)} hint="Average result per trade" />
            <Kpi label="Avg win / avg loss" value={`${money(s.avg_win)} / ${money(s.avg_loss)}`} hint={s.payoff_ratio ? `Payoff ratio ${s.payoff_ratio}` : undefined} />
            <Kpi label="Avg R" value={s.avg_r ?? '—'} hint={`Average result in multiples of initial risk (needs a stop loss). ${s.r_trades} trades have one`} />
            <Kpi label="Max drawdown" value={s.max_drawdown ? `-${s.max_drawdown}` : '0'} color={s.max_drawdown ? RED : undefined} hint="Largest fall from an equity peak" />
            <Kpi label="Best / worst trade" value={`${money(s.best_trade)} / ${money(s.worst_trade)}`} />
            <Kpi label="Streaks (W / L)" value={`${s.longest_win_streak} / ${s.longest_loss_streak}`} hint="Longest winning and losing runs" />
            <Kpi label="Avg hold (win / loss)" value={`${duration(s.avg_hold_win_min)} / ${duration(s.avg_hold_loss_min)}`} hint="Do you hold losers longer than winners?" />
            <Kpi label="Trades · fees" value={`${s.trades} · ${s.fees}`} />
          </div>

          <Section title="Equity curve" note="Cumulative net P&L after every closed trade, in the order they were closed.">
            <EquityChart points={a.equity} />
          </Section>

          <div style={grid2}>
            <Section title="By weekday" note="Net P&L by the day you entered.">
              <Columns items={a.by_weekday} title="Net P&L by weekday" />
            </Section>
            <Section title="By entry hour" note="Net P&L by the hour you entered (server time).">
              <Columns items={a.by_hour} title="Net P&L by entry hour" />
            </Section>
            <Section title="By month">
              <Columns items={a.by_month} title="Net P&L by month" />
            </Section>
            <Section title="Long vs short">
              <Bars rows={a.by_direction} empty="No data." />
            </Section>
            <Section title="Tags" note="Which setups and habits make or cost you money. Tag every trade to get the most out of this.">
              <Bars rows={a.by_tag} empty="No tagged trades yet. Add tags like “breakout” or “FOMO” to your trades." />
            </Section>
            <Section title="Symbols" note="Top 8 by net P&L.">
              <Bars rows={a.by_symbol} empty="No data." />
            </Section>
          </div>

          <Section title="Result distribution" note="How many trades landed in each P&L range. Look for a long left tail (a few big losses).">
            <Histogram bins={a.distribution} />
          </Section>
        </>
      )}
    </>
  );
}
