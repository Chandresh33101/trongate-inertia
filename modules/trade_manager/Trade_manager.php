<?php
/**
 * Trade Manager: a trading journal for logged-in clients (user level 2).
 *
 * Clients can log trades, tag them and attach screenshots. Every request is
 * authenticated in the constructor and every query is scoped to the logged-in
 * user, so there is no route that can touch another client's data.
 *
 * Routes (module: trade_manager)
 *   GET  trade_manager                      list + filters + stats
 *   GET  trade_manager/analytics            performance analytics (?range=30d|90d|ytd|1y|all|custom)
 *   GET  trade_manager/create               new trade form
 *   POST trade_manager/store                save new trade
 *   GET  trade_manager/show/{id}            trade detail + screenshots
 *   GET  trade_manager/edit/{id}            edit form
 *   POST trade_manager/update/{id}          save changes (+ add screenshots)
 *   POST trade_manager/delete/{id}          delete trade
 *   POST trade_manager/delete_attachment/{id}
 *   GET  trade_manager/screenshot/{id}      stream a screenshot (owner only)
 *   GET  trade_manager/thumb/{id}           stream its thumbnail (owner only)
 */
class Trade_manager extends Trongate {

    /** Must match the key in config/login.php 'user_levels'. */
    private const CLIENT_LEVEL = 2;

    private int $user_id = 0;

    public function __construct(?string $module_name = null) {
        parent::__construct($module_name);

        $token = $this->trongate_tokens->attempt_get_valid_token(self::CLIENT_LEVEL);
        $uid   = $token !== false ? $this->trongate_tokens->get_user_id($token) : false;

        if ($uid === false) {
            // Inertia-aware: sends X-Inertia-Location for XHR visits, a 303 otherwise.
            Inertia::location('login/login/client-login');
        }
        $this->user_id = (int) $uid;

        Inertia::share([
            'app'  => ['name' => WEBSITE_NAME],
            'year' => fn() => date('Y'),
            'auth' => ['logged_in' => true, 'level' => 'client'],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Pages
    // ═══════════════════════════════════════════════════════════════════

    public function index(): void {
        $filters = [
            'q'         => trim((string) ($_GET['q'] ?? '')),
            'status'    => (string) ($_GET['status'] ?? ''),
            'direction' => (string) ($_GET['direction'] ?? ''),
            'tag'       => trim((string) ($_GET['tag'] ?? '')),
            'from'      => (string) ($_GET['from'] ?? ''),
            'to'        => (string) ($_GET['to'] ?? ''),
        ];
        $page = (int) ($_GET['page'] ?? 1);

        $list = $this->model->list_trades($this->user_id, $filters, $page);

        Inertia::title('Trade Journal');
        Inertia::render('TradeManager/Index', [
            'trades'   => $list['rows'],
            'total'    => $list['total'],
            'page'     => $list['page'],
            'per_page' => Trade_manager_model::PER_PAGE,
            'filters'  => $filters,
            'tags'     => $this->model->list_tags($this->user_id),
            'stats'    => Inertia::defer(fn() => $this->model->stats($this->user_id)),
        ]);
    }

    public function analytics(): void {
        $range = (string) ($_GET['range'] ?? '90d');
        if (!in_array($range, ['30d', '90d', 'ytd', '1y', 'all', 'custom'], true)) {
            $range = '90d';
        }
        $from = (string) ($_GET['from'] ?? '');
        $to   = (string) ($_GET['to'] ?? '');

        Inertia::title('Performance Analytics');
        Inertia::render('TradeManager/Analytics', [
            'range'     => $range,
            'from'      => $from,
            'to'        => $to,
            'analytics' => $this->model->analytics($this->user_id, $range, $from, $to),
        ]);
    }

    public function create(): void {
        Inertia::title('New Trade');
        Inertia::render('TradeManager/Form', [
            'trade'    => null,
            'tags'     => $this->model->list_tags($this->user_id),
            'limits'   => $this->limits(),
            'defaults' => ['entry_date' => date('Y-m-d\TH:i')],
        ]);
    }

    public function show(): void {
        $trade = $this->find_trade_or_leave(segment(3, 'int'));

        Inertia::title($trade['symbol'] . ' #' . $trade['id']);
        Inertia::render('TradeManager/Show', ['trade' => $trade]);
    }

    public function edit(): void {
        $trade = $this->find_trade_or_leave(segment(3, 'int'));

        Inertia::title('Edit ' . $trade['symbol']);
        Inertia::render('TradeManager/Form', [
            'trade'    => $trade,
            'tags'     => $this->model->list_tags($this->user_id),
            'limits'   => $this->limits(),
            'defaults' => null,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Actions
    // ═══════════════════════════════════════════════════════════════════

    public function store(): void {
        $this->save(null);
    }

    public function update(): void {
        $id = segment(3, 'int');
        if ($id < 1) {
            $this->leave_with('Trade not found');
        }
        $this->save($id);
    }

    public function delete(): void {
        $this->validation->run();   // CSRF check (stops the request if it fails)

        $id = segment(3, 'int');
        if ($id > 0 && $this->model->delete_trade($this->user_id, $id)) {
            set_flashdata('Trade deleted');
        } else {
            set_flashdata('Trade not found');
        }
        Inertia::redirect('trade_manager');
    }

    public function delete_attachment(): void {
        $this->validation->run();   // CSRF

        $trade_id = $this->model->delete_attachment($this->user_id, segment(3, 'int'));
        if ($trade_id === null) {
            $this->leave_with('Screenshot not found');
        }
        set_flashdata('Screenshot removed');
        Inertia::back('trade_manager/edit/' . $trade_id);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Private screenshot delivery
    // ═══════════════════════════════════════════════════════════════════

    public function screenshot(): void {
        $this->stream_attachment(false);
    }

    public function thumb(): void {
        $this->stream_attachment(true);
    }

    private function stream_attachment(bool $thumb): void {
        $a = $this->model->get_attachment($this->user_id, segment(3, 'int'));

        $name = $a === null ? null : ($thumb && $a['thumb_name'] ? $a['thumb_name'] : $a['file_name']);
        $path = $name === null ? null : $this->model->attachment_path($this->user_id, $name, $thumb && $a['thumb_name']);

        if ($path === null) {
            http_response_code(404);
            die('Not found');
        }

        // Thumbnails keep the original format, so the stored MIME type is right for both.
        $mime = in_array($a['mime_type'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)
            ? $a['mime_type'] : 'application/octet-stream';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Cache-Control: private, max-age=86400');
        header('Content-Disposition: inline; filename="screenshot-' . (int) $a['id'] . '"');
        readfile($path);
        exit;
    }

    // ═══════════════════════════════════════════════════════════════════
    // Internals
    // ═══════════════════════════════════════════════════════════════════

    /** Shared create/update flow. */
    private function save(?int $trade_id): void {
        $this->validation->set_rules('symbol',      'symbol',       'required|max_length[20]');
        $this->validation->set_rules('entry_price', 'entry price',  'required|numeric|greater_than[0]');
        $this->validation->set_rules('quantity',    'quantity',     'required|numeric|greater_than[0]');
        $this->validation->set_rules('entry_date',  'entry date',   'required|valid_datetime_local');
        $this->validation->set_rules('exit_price',  'exit price',   'numeric|greater_than[0]');
        $this->validation->set_rules('exit_date',   'exit date',    'valid_datetime_local');
        $this->validation->set_rules('stop_loss',   'stop loss',    'numeric|greater_than[0]');
        $this->validation->set_rules('take_profit', 'take profit',  'numeric|greater_than[0]');
        $this->validation->set_rules('fees',        'fees',         'numeric');
        $this->validation->set_rules('notes',       'notes',        'max_length[10000]');

        $ok = $this->validation->run();   // also verifies the CSRF token

        $input = [
            'symbol'      => $this->field('symbol'),
            'direction'   => $this->field('direction'),
            'status'      => $this->field('status'),
            'entry_price' => $this->field('entry_price'),
            'exit_price'  => $this->field('exit_price'),
            'quantity'    => $this->field('quantity'),
            'stop_loss'   => $this->field('stop_loss'),
            'take_profit' => $this->field('take_profit'),
            'fees'        => $this->field('fees'),
            'entry_date'  => $this->field('entry_date'),
            'exit_date'   => $this->field('exit_date'),
            'notes'       => $this->field('notes', false),
        ];

        // Rules the generic validator can't express.
        $errors = $_SESSION['form_submission_errors'] ?? [];

        if (!in_array($input['direction'], Trade_manager_model::DIRECTIONS, true)) {
            $errors['direction'][] = 'Choose long or short.';
        }
        if (!in_array($input['status'], Trade_manager_model::STATUSES, true)) {
            $errors['status'][] = 'Choose open or closed.';
        }
        if ($input['status'] === 'closed') {
            if ($input['exit_price'] === '') {
                $errors['exit_price'][] = 'Exit price is required for a closed trade.';
            }
            if ($input['exit_date'] === '') {
                $errors['exit_date'][] = 'Exit date is required for a closed trade.';
            } elseif ($input['entry_date'] !== '' && $input['exit_date'] < $input['entry_date']) {
                $errors['exit_date'][] = 'Exit date cannot be before the entry date.';
            }
        }

        $files    = $this->model->normalise_uploads('screenshots');
        $existing = $trade_id !== null ? count($this->model->list_attachments($this->user_id, $trade_id)) : 0;
        $file_errors = $this->model->check_uploads($files, $existing);
        if ($file_errors !== []) {
            $errors['screenshots'] = [implode(' ', $file_errors)];
        }

        if (!$ok || $errors !== []) {
            $_SESSION['form_submission_errors'] = $errors;
            Inertia::back('trade_manager');
        }

        $tags   = $this->model->normalise_tags($_POST['tags'] ?? []);
        $result = $this->model->save_trade($this->user_id, $trade_id, $input, $tags, $files);

        if ($result === null) {
            $this->leave_with('Trade not found');
        }

        if ($result['upload_errors'] !== []) {
            // The trade itself is saved; send the client to the edit page to retry the images.
            $_SESSION['form_submission_errors'] = ['screenshots' => [implode(' ', $result['upload_errors'])]];
            set_flashdata('Trade saved, but some screenshots could not be added.');
            Inertia::redirect('trade_manager/edit/' . $result['id']);
        }

        set_flashdata($trade_id === null ? 'Trade added' : 'Trade updated');
        Inertia::redirect('trade_manager/show/' . $result['id']);
    }

    /** @return array The client's trade, or redirects to the list if it isn't theirs. */
    private function find_trade_or_leave(int $id): array {
        $trade = $id > 0 ? $this->model->get_trade($this->user_id, $id) : null;
        if ($trade === null) {
            $this->leave_with('Trade not found');
        }
        return $trade;
    }

    private function leave_with(string $message): never {
        set_flashdata($message);
        Inertia::redirect('trade_manager');
        exit;
    }

    /**
     * Read one scalar form field. Arrays (a tampered request) become ''.
     * $clean = false keeps line breaks, which journal notes need.
     */
    private function field(string $name, bool $clean = true): string {
        $v = post($name, $clean);
        if (!is_string($v)) {
            return '';
        }
        return $clean ? $v : trim(str_replace(["\r\n", "\r"], "\n", $v));
    }

    private function limits(): array {
        return [
            'max_files'   => Trade_manager_model::MAX_FILES_PER_TRADE,
            'max_file_mb' => Trade_manager_model::MAX_FILE_KB / 1024,
            'max_tags'    => Trade_manager_model::MAX_TAGS_PER_TRADE,
        ];
    }
}
