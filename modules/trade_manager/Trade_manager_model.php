<?php
/**
 * Trade Manager Model
 *
 * Data layer for the client trading journal: trades, tags and screenshot
 * attachments. EVERY public method takes the owning Trongate user id and
 * scopes its queries by it, so a client can never read or change another
 * client's data even if an id is guessed.
 */
require_once __DIR__ . '/Trade_analytics.php';

class Trade_manager_model extends Model {

    public const PER_PAGE            = 15;
    public const MAX_TAGS_PER_TRADE  = 10;
    public const MAX_FILES_PER_TRADE = 10;
    public const MAX_FILE_KB         = 5120;   // 5 MB per screenshot
    public const MAX_IMAGE_EDGE      = 2560;   // px; larger images are scaled down
    public const THUMB_EDGE          = 480;    // px

    public const ANALYTICS_MAX_TRADES = 5000;

    public const DIRECTIONS = ['long', 'short'];
    public const STATUSES   = ['open', 'closed'];

    // ═══════════════════════════════════════════════════════════════════
    // Trades: reading
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Paginated, filterable list of a client's trades (with tags + screenshot counts).
     *
     * @param array $filters q, status, direction, tag, from, to (all optional)
     * @return array{rows: array, total: int, page: int, pages: int}
     */
    public function list_trades(int $user_id, array $filters, int $page): array {
        [$where, $params] = $this->build_filters($user_id, $filters);

        $row   = $this->db->query_bind("SELECT COUNT(*) AS total FROM trades t WHERE $where", $params, 'array');
        $total = (int) ($row[0]['total'] ?? 0);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min($pages, max(1, $page));

        $params['limit']  = self::PER_PAGE;
        $params['offset'] = ($page - 1) * self::PER_PAGE;

        $rows = $this->db->query_bind(
            "SELECT t.* FROM trades t WHERE $where
             ORDER BY t.entry_date DESC, t.id DESC
             LIMIT :limit OFFSET :offset",
            $params, 'array'
        );

        $ids   = array_map(fn($r) => (int) $r['id'], $rows);
        $tags  = $this->tags_for_trades($ids);
        $files = $this->attachment_counts($ids);

        foreach ($rows as &$r) {
            $r = $this->present_trade($r);
            $r['tags']             = $tags[$r['id']]  ?? [];
            $r['attachment_count'] = $files[$r['id']] ?? 0;
        }
        unset($r);

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * One trade with its tags and attachments, or null if it isn't this client's.
     */
    public function get_trade(int $user_id, int $trade_id): ?array {
        $rows = $this->db->query_bind(
            'SELECT * FROM trades WHERE id = :id AND trongate_user_id = :uid',
            ['id' => $trade_id, 'uid' => $user_id], 'array'
        );
        if (empty($rows)) {
            return null;
        }

        $trade = $this->present_trade($rows[0]);
        $trade['tags']        = $this->tags_for_trades([$trade_id])[$trade_id] ?? [];
        $trade['attachments'] = $this->list_attachments($user_id, $trade_id);
        return $trade;
    }

    /** All of a client's tags with usage counts (for filters and autocomplete). */
    public function list_tags(int $user_id): array {
        $rows = $this->db->query_bind(
            'SELECT g.id, g.name, COUNT(m.trade_id) AS uses
               FROM trade_tags g
          LEFT JOIN trade_tag_map m ON m.tag_id = g.id
              WHERE g.trongate_user_id = :uid
           GROUP BY g.id, g.name
           ORDER BY g.name ASC',
            ['uid' => $user_id], 'array'
        );
        foreach ($rows as &$r) {
            $r['id']   = (int) $r['id'];
            $r['uses'] = (int) $r['uses'];
        }
        return $rows;
    }

    /** Headline numbers across ALL of a client's closed trades. */
    public function stats(int $user_id): array {
        $rows = $this->db->query_bind(
            "SELECT COUNT(*)                                        AS total,
                    SUM(status = 'open')                            AS open_count,
                    SUM(status = 'closed')                          AS closed_count,
                    SUM(status = 'closed' AND pnl > 0)              AS wins,
                    SUM(status = 'closed' AND pnl < 0)              AS losses,
                    COALESCE(SUM(CASE WHEN status = 'closed' THEN pnl END), 0)            AS net_pnl,
                    COALESCE(SUM(CASE WHEN status = 'closed' AND pnl > 0 THEN pnl END), 0) AS gross_win,
                    COALESCE(SUM(CASE WHEN status = 'closed' AND pnl < 0 THEN pnl END), 0) AS gross_loss
               FROM trades WHERE trongate_user_id = :uid",
            ['uid' => $user_id], 'array'
        );
        $s = $rows[0] ?? [];

        $closed = (int) ($s['closed_count'] ?? 0);
        $wins   = (int) ($s['wins'] ?? 0);
        $win    = (float) ($s['gross_win'] ?? 0);
        $loss   = abs((float) ($s['gross_loss'] ?? 0));

        return [
            'total'         => (int) ($s['total'] ?? 0),
            'open'          => (int) ($s['open_count'] ?? 0),
            'closed'        => $closed,
            'wins'          => $wins,
            'losses'        => (int) ($s['losses'] ?? 0),
            'win_rate'      => $closed > 0 ? round($wins / $closed * 100, 1) : null,
            'net_pnl'       => round((float) ($s['net_pnl'] ?? 0), 2),
            'avg_pnl'       => $closed > 0 ? round((float) $s['net_pnl'] / $closed, 2) : null,
            'profit_factor' => $loss > 0 ? round($win / $loss, 2) : null,
        ];
    }

    /**
     * Performance analytics over a client's CLOSED trades, by exit date.
     *
     * @param string $range 30d | 90d | ytd | 1y | all | custom (uses $from/$to)
     */
    public function analytics(int $user_id, string $range, string $from = '', string $to = ''): array {
        [$start, $end] = $this->resolve_range($range, $from, $to);

        $where  = "trongate_user_id = :uid AND status = 'closed' AND pnl IS NOT NULL AND exit_date IS NOT NULL";
        $params = ['uid' => $user_id];
        if ($start !== null) {
            $where .= ' AND exit_date >= :start_d';
            $params['start_d'] = $start . ' 00:00:00';
        }
        if ($end !== null) {
            $where .= ' AND exit_date <= :end_d';
            $params['end_d'] = $end . ' 23:59:59';
        }

        $rows = $this->db->query_bind(
            "SELECT id, symbol, direction, entry_price, stop_loss, quantity, fees, pnl, entry_date, exit_date
               FROM trades WHERE $where
           ORDER BY exit_date ASC, id ASC LIMIT " . self::ANALYTICS_MAX_TRADES,
            $params, 'array'
        );

        $tags = [];
        foreach (array_chunk(array_map(fn($r) => (int) $r['id'], $rows), 500) as $chunk) {
            $tags += $this->tags_for_trades($chunk);
        }

        foreach ($rows as &$r) {
            $risk   = $r['stop_loss'] !== null ? abs((float) $r['entry_price'] - (float) $r['stop_loss']) * (float) $r['quantity'] : 0.0;
            $r['r'] = $risk > 0 ? (float) $r['pnl'] / $risk : null;
            $r['tags'] = $tags[(int) $r['id']] ?? [];
        }
        unset($r);

        return Trade_analytics::compute($rows);
    }

    /** @return array{0: ?string, 1: ?string} inclusive Y-m-d bounds (null = open-ended) */
    private function resolve_range(string $range, string $from, string $to): array {
        $today = date('Y-m-d');
        return match ($range) {
            '30d'    => [date('Y-m-d', strtotime('-29 days')), $today],
            '90d'    => [date('Y-m-d', strtotime('-89 days')), $today],
            '1y'     => [date('Y-m-d', strtotime('-1 year +1 day')), $today],
            'ytd'    => [date('Y') . '-01-01', $today],
            'custom' => [$this->is_ymd($from) ? $from : null, $this->is_ymd($to) ? $to : null],
            default  => [null, null],
        };
    }

    // ═══════════════════════════════════════════════════════════════════
    // Trades: writing
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Insert or update a trade, its tags and any new screenshots.
     *
     * The trade + tags are saved in one transaction. Screenshots are processed
     * afterwards: a bad image must not discard an otherwise valid journal entry,
     * so its error is returned instead of thrown.
     *
     * @param int|null $trade_id Null to create. Must belong to $user_id to update.
     * @param array    $input    Already-validated scalar fields.
     * @param string[] $tags     Raw tag names from the form.
     * @param array    $files    Normalised uploads (see normalise_uploads()).
     * @return array{id: int, upload_errors: string[]}|null Null if updating a trade that isn't theirs.
     */
    public function save_trade(int $user_id, ?int $trade_id, array $input, array $tags, array $files = []): ?array {
        if ($trade_id !== null && $this->get_trade_row($user_id, $trade_id) === null) {
            return null;
        }

        $data = $this->prepare_trade_data($input);
        $now  = date('Y-m-d H:i:s');

        $this->db->query('START TRANSACTION');
        try {
            if ($trade_id === null) {
                $data['trongate_user_id'] = $user_id;
                $data['created_at']       = $now;
                $data['updated_at']       = $now;
                $trade_id = $this->db->insert($data, 'trades');
            } else {
                $data['updated_at'] = $now;
                $this->db->update($trade_id, $data, 'trades');
            }

            $this->sync_tags($user_id, $trade_id, $tags);
            $this->db->query('COMMIT');
        } catch (Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }

        $upload_errors = $files !== [] ? $this->add_attachments($user_id, $trade_id, $files) : [];

        return ['id' => $trade_id, 'upload_errors' => $upload_errors];
    }

    /**
     * Delete a trade (tag links + attachment rows cascade) and its files on disk.
     */
    public function delete_trade(int $user_id, int $trade_id): bool {
        if ($this->get_trade_row($user_id, $trade_id) === null) {
            return false;
        }

        $attachments = $this->list_attachments($user_id, $trade_id);

        $this->db->query_bind(
            'DELETE FROM trades WHERE id = :id AND trongate_user_id = :uid',
            ['id' => $trade_id, 'uid' => $user_id]
        );

        foreach ($attachments as $a) {
            $this->unlink_attachment_files($user_id, $a['file_name'], $a['thumb_name']);
        }
        $this->prune_unused_tags($user_id);
        return true;
    }

    /** Net P&L for a closed trade; null while the trade is open or has no exit. */
    public static function calculate_pnl(string $direction, float $entry, ?float $exit, float $qty, float $fees): ?float {
        if ($exit === null) {
            return null;
        }
        $gross = $direction === 'short' ? ($entry - $exit) * $qty : ($exit - $entry) * $qty;
        return round($gross - $fees, 6);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Tags
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Clean raw tag input: trim, strip odd characters, cap length/count, de-dupe
     * case-insensitively. Returns the surviving names.
     *
     * @param mixed $raw Array from the form (anything else yields no tags).
     * @return string[]
     */
    public function normalise_tags(mixed $raw): array {
        if (!is_array($raw)) {
            return [];
        }

        $clean = [];
        foreach ($raw as $name) {
            if (!is_string($name)) {
                continue;
            }
            $name = preg_replace('/[^\p{L}\p{N} _\-#.\/+]/u', '', $name) ?? '';
            $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
            $name = mb_substr($name, 0, 30);
            if ($name === '') {
                continue;
            }
            $clean[mb_strtolower($name)] ??= $name;
            if (count($clean) >= self::MAX_TAGS_PER_TRADE) {
                break;
            }
        }
        return array_values($clean);
    }

    private function sync_tags(int $user_id, int $trade_id, array $names): void {
        $names = $this->normalise_tags($names);

        $this->db->query_bind('DELETE FROM trade_tag_map WHERE trade_id = :tid', ['tid' => $trade_id]);

        foreach ($names as $name) {
            // The unique key is case-insensitive, so this reuses "Breakout" for "breakout".
            $this->db->query_bind(
                'INSERT INTO trade_tags (trongate_user_id, name) VALUES (:uid, :name)
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
                ['uid' => $user_id, 'name' => $name]
            );
            $tag = $this->db->query('SELECT LAST_INSERT_ID() AS id', 'array');
            $this->db->query_bind(
                'INSERT IGNORE INTO trade_tag_map (trade_id, tag_id) VALUES (:tid, :gid)',
                ['tid' => $trade_id, 'gid' => (int) $tag[0]['id']]
            );
        }

        $this->prune_unused_tags($user_id);
    }

    private function prune_unused_tags(int $user_id): void {
        $this->db->query_bind(
            'DELETE FROM trade_tags
              WHERE trongate_user_id = :uid
                AND NOT EXISTS (SELECT 1 FROM trade_tag_map m WHERE m.tag_id = trade_tags.id)',
            ['uid' => $user_id]
        );
    }

    /** @return array<int, string[]> trade_id => tag names */
    private function tags_for_trades(array $trade_ids): array {
        if ($trade_ids === []) {
            return [];
        }
        [$in, $params] = $this->in_clause($trade_ids, 't');
        $rows = $this->db->query_bind(
            "SELECT m.trade_id, g.name
               FROM trade_tag_map m
               JOIN trade_tags g ON g.id = m.tag_id
              WHERE m.trade_id IN ($in)
           ORDER BY g.name ASC",
            $params, 'array'
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['trade_id']][] = $r['name'];
        }
        return $out;
    }

    // ═══════════════════════════════════════════════════════════════════
    // Screenshot attachments
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Turn PHP's awkward $_FILES['screenshots'] structure into a list of
     * single-file arrays. Empty slots (UPLOAD_ERR_NO_FILE) are dropped.
     *
     * @return array<int, array{name:string,type:string,tmp_name:string,error:int,size:int}>
     */
    public function normalise_uploads(string $field): array {
        $f = $_FILES[$field] ?? null;
        if (!is_array($f) || !isset($f['name'])) {
            return [];
        }

        $names = is_array($f['name']) ? $f['name'] : [$f['name']];
        $out   = [];
        foreach ($names as $i => $name) {
            $err = is_array($f['error']) ? $f['error'][$i] : $f['error'];
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = [
                'name'     => (string) $name,
                'type'     => (string) (is_array($f['type'])     ? $f['type'][$i]     : $f['type']),
                'tmp_name' => (string) (is_array($f['tmp_name']) ? $f['tmp_name'][$i] : $f['tmp_name']),
                'error'    => (int) $err,
                'size'     => (int) (is_array($f['size'])        ? $f['size'][$i]     : $f['size']),
            ];
        }
        return $out;
    }

    /**
     * Pre-flight check of uploads so the controller can reject the whole form
     * (with inline errors) before anything is written.
     *
     * @return string[] error messages; empty when OK.
     */
    public function check_uploads(array $files, int $already_attached = 0): array {
        $errors = [];

        if ($already_attached + count($files) > self::MAX_FILES_PER_TRADE) {
            $errors[] = 'A trade can have at most ' . self::MAX_FILES_PER_TRADE . ' screenshots.';
        }

        foreach ($files as $file) {
            $label = $file['name'] !== '' ? $file['name'] : 'screenshot';
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = "$label: " . match ($file['error']) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'file is too large.',
                    UPLOAD_ERR_PARTIAL                        => 'upload was interrupted.',
                    default                                   => 'upload failed.',
                };
            } elseif ($file['size'] > self::MAX_FILE_KB * 1024) {
                $errors[] = "$label: must be " . (self::MAX_FILE_KB / 1024) . ' MB or smaller.';
            }
        }
        return $errors;
    }

    /**
     * Process and store screenshots for a trade. Content (not the client-supplied
     * name or type) decides what is accepted: the Image module sniffs the real
     * MIME type, re-encodes oversize images and writes a thumbnail.
     *
     * @return string[] per-file error messages (empty when all saved)
     */
    public function add_attachments(int $user_id, int $trade_id, array $files): array {
        $errors   = [];
        $existing = count($this->list_attachments($user_id, $trade_id));
        $saved_files = $_FILES;   // Image::upload() reads the first entry of $_FILES

        $user_dir = 'uploads/' . $user_id;

        foreach ($files as $file) {
            $label = $file['name'] !== '' ? $file['name'] : 'screenshot';

            if ($existing >= self::MAX_FILES_PER_TRADE) {
                $errors[] = "$label: screenshot limit (" . self::MAX_FILES_PER_TRADE . ') reached.';
                continue;
            }

            $_FILES = ['screenshot' => $file];

            try {
                $image = new Image();
                $info  = $image->upload([
                    'destination'         => $user_dir,
                    'target_module'       => 'trade_manager',
                    'upload_to_module'    => true,
                    'make_rand_name'      => true,
                    'max_width'           => self::MAX_IMAGE_EDGE,
                    'max_height'          => self::MAX_IMAGE_EDGE,
                    'thumbnail_dir'       => $user_dir . '/thumbs',
                    'thumbnail_max_width' => self::THUMB_EDGE,
                    'thumbnail_max_height'=> self::THUMB_EDGE,
                ]);
                $image->destroy();
            } catch (Throwable $e) {
                $errors[] = "$label: " . $this->friendly_upload_error($e);
                continue;
            }

            $this->db->insert([
                'trade_id'         => $trade_id,
                'trongate_user_id' => $user_id,
                'file_name'        => $info['file_name'],
                'thumb_name'       => isset($info['thumbnail_path']) ? basename($info['thumbnail_path']) : null,
                'original_name'    => mb_substr(basename($file['name']), 0, 255),
                'mime_type'        => $info['file_type'],
                'file_size'        => (int) (@filesize($info['file_path']) ?: $info['file_size']),
                'created_at'       => date('Y-m-d H:i:s'),
            ], 'trade_attachments');
            $existing++;
        }

        $_FILES = $saved_files;
        return $errors;
    }

    /** @return array[] attachments for a trade (ownership enforced). */
    public function list_attachments(int $user_id, int $trade_id): array {
        $rows = $this->db->query_bind(
            'SELECT id, trade_id, file_name, thumb_name, original_name, mime_type, file_size, caption, created_at
               FROM trade_attachments
              WHERE trade_id = :tid AND trongate_user_id = :uid
           ORDER BY id ASC',
            ['tid' => $trade_id, 'uid' => $user_id], 'array'
        );
        foreach ($rows as &$r) {
            $r['id']        = (int) $r['id'];
            $r['trade_id']  = (int) $r['trade_id'];
            $r['file_size'] = (int) $r['file_size'];
        }
        return $rows;
    }

    public function get_attachment(int $user_id, int $attachment_id): ?array {
        $rows = $this->db->query_bind(
            'SELECT * FROM trade_attachments WHERE id = :id AND trongate_user_id = :uid',
            ['id' => $attachment_id, 'uid' => $user_id], 'array'
        );
        return $rows[0] ?? null;
    }

    public function delete_attachment(int $user_id, int $attachment_id): ?int {
        $a = $this->get_attachment($user_id, $attachment_id);
        if ($a === null) {
            return null;
        }
        $this->db->query_bind(
            'DELETE FROM trade_attachments WHERE id = :id AND trongate_user_id = :uid',
            ['id' => $attachment_id, 'uid' => $user_id]
        );
        $this->unlink_attachment_files($user_id, $a['file_name'], $a['thumb_name']);
        return (int) $a['trade_id'];
    }

    /**
     * Absolute path to a stored file, or null if it is missing. Only the
     * basename from the database is used, so no path can escape the client's folder.
     */
    public function attachment_path(int $user_id, string $file_name, bool $thumb = false): ?string {
        $dir  = APPPATH . 'modules/trade_manager/uploads/' . $user_id . ($thumb ? '/thumbs/' : '/');
        $path = $dir . basename($file_name);
        return is_file($path) ? $path : null;
    }

    private function unlink_attachment_files(int $user_id, string $file_name, ?string $thumb_name): void {
        foreach ([[$file_name, false], [$thumb_name, true]] as [$name, $is_thumb]) {
            if ($name === null || $name === '') {
                continue;
            }
            $path = $this->attachment_path($user_id, $name, $is_thumb);
            if ($path !== null) {
                @unlink($path);
            }
        }
    }

    private function friendly_upload_error(Throwable $e): string {
        $msg = $e->getMessage();
        if (stripos($msg, 'Invalid file type') !== false || stripos($msg, 'not an image') !== false) {
            return 'only JPG, PNG, GIF or WebP images are allowed.';
        }
        // Keep internals out of the UI in production.
        return (defined('ENV') && strtolower(ENV) === 'dev') ? $msg : 'could not be processed.';
    }

    /** @return array<int, int> trade_id => number of attachments */
    private function attachment_counts(array $trade_ids): array {
        if ($trade_ids === []) {
            return [];
        }
        [$in, $params] = $this->in_clause($trade_ids, 'a');
        $rows = $this->db->query_bind(
            "SELECT trade_id, COUNT(*) AS n FROM trade_attachments WHERE trade_id IN ($in) GROUP BY trade_id",
            $params, 'array'
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['trade_id']] = (int) $r['n'];
        }
        return $out;
    }

    // ═══════════════════════════════════════════════════════════════════
    // Internals
    // ═══════════════════════════════════════════════════════════════════

    private function get_trade_row(int $user_id, int $trade_id): ?array {
        $rows = $this->db->query_bind(
            'SELECT id FROM trades WHERE id = :id AND trongate_user_id = :uid',
            ['id' => $trade_id, 'uid' => $user_id], 'array'
        );
        return $rows[0] ?? null;
    }

    /** Build the WHERE clause for list_trades(); always scoped to the owner. */
    private function build_filters(int $user_id, array $f): array {
        $where  = ['t.trongate_user_id = :uid'];
        $params = ['uid' => $user_id];

        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(t.symbol LIKE :q1 OR t.notes LIKE :q2)';
            $params['q1'] = $like;
            $params['q2'] = $like;
        }
        if (in_array($f['status'] ?? '', self::STATUSES, true)) {
            $where[] = 't.status = :status';
            $params['status'] = $f['status'];
        }
        if (in_array($f['direction'] ?? '', self::DIRECTIONS, true)) {
            $where[] = 't.direction = :direction';
            $params['direction'] = $f['direction'];
        }
        $tag = trim((string) ($f['tag'] ?? ''));
        if ($tag !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM trade_tag_map m JOIN trade_tags g ON g.id = m.tag_id
                                 WHERE m.trade_id = t.id AND g.name = :tag AND g.trongate_user_id = :tag_uid)';
            $params['tag']     = $tag;
            $params['tag_uid'] = $user_id;
        }
        if ($this->is_ymd($f['from'] ?? '')) {
            $where[] = 't.entry_date >= :from_d';
            $params['from_d'] = $f['from'] . ' 00:00:00';
        }
        if ($this->is_ymd($f['to'] ?? '')) {
            $where[] = 't.entry_date <= :to_d';
            $params['to_d'] = $f['to'] . ' 23:59:59';
        }

        return [implode(' AND ', $where), $params];
    }

    private function is_ymd(mixed $v): bool {
        if (!is_string($v) || $v === '') {
            return false;
        }
        $d = DateTime::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }

    /** Map validated form input onto trades columns, computing status-dependent fields. */
    private function prepare_trade_data(array $in): array {
        $direction = in_array($in['direction'], self::DIRECTIONS, true) ? $in['direction'] : 'long';
        $closed    = ($in['status'] ?? '') === 'closed';

        $entry = (float) $in['entry_price'];
        $qty   = (float) $in['quantity'];
        $fees  = $this->num_or_null($in['fees'] ?? null) ?? 0.0;
        $exit  = $closed ? $this->num_or_null($in['exit_price'] ?? null) : null;

        return [
            'symbol'      => strtoupper(mb_substr(trim($in['symbol']), 0, 20)),
            'direction'   => $direction,
            'status'      => $closed ? 'closed' : 'open',
            'entry_price' => $entry,
            'exit_price'  => $exit,
            'quantity'    => $qty,
            'stop_loss'   => $this->num_or_null($in['stop_loss'] ?? null),
            'take_profit' => $this->num_or_null($in['take_profit'] ?? null),
            'fees'        => $fees,
            'pnl'         => $closed ? self::calculate_pnl($direction, $entry, $exit, $qty, $fees) : null,
            'entry_date'  => $this->to_sql_datetime($in['entry_date']),
            'exit_date'   => $closed ? $this->to_sql_datetime($in['exit_date'] ?? null) : null,
            'notes'       => ($in['notes'] ?? '') !== '' ? $in['notes'] : null,
        ];
    }

    private function num_or_null(mixed $v): ?float {
        return ($v === null || $v === '' || !is_numeric($v)) ? null : (float) $v;
    }

    /** "2026-11-02T11:17" (datetime-local) → "2026-11-02 11:17:00". */
    private function to_sql_datetime(mixed $v): ?string {
        if (!is_string($v) || $v === '') {
            return null;
        }
        $d = DateTime::createFromFormat('Y-m-d\TH:i', $v) ?: DateTime::createFromFormat('Y-m-d H:i:s', $v);
        return $d ? $d->format('Y-m-d H:i:s') : null;
    }

    /** Shape a DB row for the front end: real numbers, datetime-local dates, R-multiple. */
    private function present_trade(array $r): array {
        $num = fn($v) => $v === null ? null : (float) $v;

        $entry = (float) $r['entry_price'];
        $qty   = (float) $r['quantity'];
        $stop  = $num($r['stop_loss']);
        $pnl   = $num($r['pnl']);
        $risk  = $stop !== null ? abs($entry - $stop) * $qty : 0.0;

        return [
            'id'          => (int) $r['id'],
            'symbol'      => $r['symbol'],
            'direction'   => $r['direction'],
            'status'      => $r['status'],
            'entry_price' => $entry,
            'exit_price'  => $num($r['exit_price']),
            'quantity'    => $qty,
            'stop_loss'   => $stop,
            'take_profit' => $num($r['take_profit']),
            'fees'        => (float) $r['fees'],
            'pnl'         => $pnl,
            'r_multiple'  => ($pnl !== null && $risk > 0) ? round($pnl / $risk, 2) : null,
            'entry_date'  => str_replace(' ', 'T', substr((string) $r['entry_date'], 0, 16)),
            'exit_date'   => $r['exit_date'] ? str_replace(' ', 'T', substr((string) $r['exit_date'], 0, 16)) : null,
            'notes'       => $r['notes'] ?? '',
            'created_at'  => $r['created_at'],
            'updated_at'  => $r['updated_at'],
        ];
    }

    /** Build "(:p0,:p1,…)" placeholders for an IN() list of ints. */
    private function in_clause(array $ids, string $prefix): array {
        $marks  = [];
        $params = [];
        foreach (array_values($ids) as $i => $id) {
            $marks[]                 = ":{$prefix}{$i}";
            $params["{$prefix}{$i}"] = (int) $id;
        }
        return [implode(',', $marks), $params];
    }
}
