<?php
/**
 * Inertia adapter for Trongate v2.
 *
 * Usage in any controller:  Inertia::render('Demo/Index', ['records' => $rows]);
 * Registered as an interceptor (config: INTERCEPTORS) to do version checks
 * and to normalise Inertia's JSON PUT/PATCH/DELETE bodies for post()/validation.
 */
class Inertia {

    private static array $shared = [];
    private static ?string $version = null;
    private static bool $encrypt_history = false;
    private const ENTRY = 'resources/js/app.jsx';

    /** Blocks /inertia/... URL access. Static use never triggers this. */
    public function __construct(?string $module_name = null) {
        block_url('inertia');
    }

    // ------------------------------------------------------------------
    // Interceptor (runs on every request, before routing)
    // ------------------------------------------------------------------

    public function intercept(): void {
        if (!self::is_inertia()) {
            return;
        }

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Asset version mismatch on GET => tell the client to hard reload.
        if ($method === 'GET'
            && ($_SERVER['HTTP_X_INERTIA_VERSION'] ?? '') !== self::version()) {
            http_response_code(409);
            header('X-Inertia-Location: ' . current_url());
            die();
        }

        if ($method === 'GET' || $method === 'HEAD') {
            return;
        }

        // Make post()/validation->run() work for JSON + PUT/PATCH/DELETE.
        $_SERVER['INERTIA_ORIGINAL_METHOD'] = $method;

        if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            $decoded = ($raw !== '' && $raw !== false) ? json_decode($raw, true) : [];
            $_POST = is_array($decoded) ? $decoded : [];
            $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        }

        $token = $_SERVER['HTTP_X_XSRF_TOKEN'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (is_string($token) && $token !== '' && !isset($_POST['csrf_token'])) {
            $_POST['csrf_token'] = $token;
        }

        $_SERVER['REQUEST_METHOD'] = 'POST'; // post() reads $_POST for POST
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    public static function is_inertia(): bool {
        return isset($_SERVER['HTTP_X_INERTIA']);
    }

    /** Share props with every page: Inertia::share('user', fn() => ...) */
    public static function share(string|array $key, mixed $value = null): void {
        if (is_array($key)) {
            self::$shared = array_merge(self::$shared, $key);
        } else {
            self::$shared[$key] = $value;
        }
    }

    public static function set_version(string $version): void {
        self::$version = $version;
    }

    public static function encrypt_history(bool $on = true): void {
        self::$encrypt_history = $on;
    }

    /** Only included when explicitly requested by a partial reload. */
    public static function optional(callable|Closure $value): Inertia_prop {
        return new Inertia_prop('optional', $value);
    }

    /** Included even in partial reloads that don't list it. */
    public static function always(mixed $value): Inertia_prop {
        return new Inertia_prop('always', $value);
    }

    /** Loaded by the client right after the first render (Inertia v2). */
    public static function defer(Closure $value, string $group = 'default'): Inertia_prop {
        return new Inertia_prop('defer', $value, $group);
    }

    /** Merged into existing client data on partial reloads (infinite scroll). */
    public static function merge(mixed $value): Inertia_prop {
        return new Inertia_prop('merge', $value);
    }

    public static function render(string $component, array $props = []): void {
        $is_inertia = self::is_inertia();
        $partial = $is_inertia
            && (($_SERVER['HTTP_X_INERTIA_PARTIAL_COMPONENT'] ?? '') === $component);
        $only = $partial ? self::header_list('HTTP_X_INERTIA_PARTIAL_DATA') : [];
        $except = $partial ? self::header_list('HTTP_X_INERTIA_PARTIAL_EXCEPT') : [];
        $reset = $is_inertia ? self::header_list('HTTP_X_INERTIA_RESET') : [];

        $props = array_merge(self::$shared, $props);
        $token = self::csrf_token();
        $props['csrf_token'] = $token;
        $props['errors'] = new Inertia_prop('always', self::pull_errors());
        $props['flash'] = new Inertia_prop('always', ['message' => self::pull_flash()]);

        $resolved = [];
        $deferred = [];
        $merge = [];

        foreach ($props as $key => $value) {
            $type = ($value instanceof Inertia_prop) ? $value->type : 'plain';

            if ($partial && $type !== 'always') {
                if ($only !== [] && !in_array($key, $only, true)) continue;
                if (in_array($key, $except, true)) continue;
            }

            $requested = in_array($key, $only, true);
            if (!$partial && $type === 'optional') continue;
            if ($partial && $type === 'optional' && !$requested) continue;

            if ($type === 'defer') {
                if (!$partial) {
                    $deferred[$value->group][] = $key;
                    continue;
                }
                if (!$requested) continue;
            }

            if ($type === 'merge' && !in_array($key, $reset, true)) {
                $merge[] = $key;
            }

            $resolved[$key] = self::resolve($value);
        }

        if ($resolved['errors'] === []) {
            $resolved['errors'] = new stdClass();
        }

        $page = [
            'component' => $component,
            'props' => $resolved,
            'url' => $_SERVER['REQUEST_URI'] ?? '/',
            'version' => self::version(),
        ];
        if ($deferred !== []) $page['deferredProps'] = $deferred;
        if ($merge !== []) $page['mergeProps'] = $merge;
        if (self::$encrypt_history) $page['encryptHistory'] = true;

        self::send_csrf_cookie($token);
        header('Vary: X-Inertia');

        $json = json_encode($page, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($is_inertia) {
            header('X-Inertia: true');
            header('Content-Type: application/json');
            echo $json;
            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        $page_attr = htmlspecialchars($json, ENT_QUOTES, 'UTF-8');
        $assets = self::assets();
        $base_path = parse_url(BASE_URL, PHP_URL_PATH) ?: '/';
        require APPPATH . 'modules/inertia/views/app.php';
    }

    /**
     * Redirect that works for every HTTP verb (303 turns PUT/PATCH/DELETE
     * into a GET). Use this instead of redirect() in Inertia controllers.
     */
    public static function redirect(string $to): void {
        if (!preg_match('#^https?://#i', $to)) {
            $to = BASE_URL . ltrim($to, '/');
        }
        header('Location: ' . $to, true, 303);
        die();
    }

    /** Redirect to the previous page (e.g. after validation failure). */
    public static function back(string $fallback = ''): void {
        $ref = previous_url();
        $ref_host = parse_url($ref, PHP_URL_HOST);
        $same_host = ($ref !== '') && ($ref_host === null || $ref_host === parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
        self::redirect($same_host ? $ref : ($fallback !== '' ? $fallback : BASE_URL));
    }

    /** Hard redirect (external site, or a non-Inertia page like the login form). */
    public static function location(string $url): void {
        if (!preg_match('#^https?://#i', $url)) {
            $url = BASE_URL . ltrim($url, '/');
        }
        if (self::is_inertia()) {
            http_response_code(409);
            header('X-Inertia-Location: ' . $url);
            die();
        }
        header('Location: ' . $url, true, 303);
        die();
    }

    public static function version(): string {
        if (self::$version !== null) {
            return self::$version;
        }
        foreach (self::manifest_paths() as $path) {
            if (is_file($path)) {
                return self::$version = md5_file($path);
            }
        }
        return self::$version = (self::hot_origin() !== null ? 'dev' : '1');
    }

    // ------------------------------------------------------------------
    // Vite integration
    // ------------------------------------------------------------------

    public static function assets(string $entry = self::ENTRY): string {
        $origin = self::hot_origin();
        if ($origin !== null) {
            $o = htmlspecialchars($origin, ENT_QUOTES, 'UTF-8');
            return '<script type="module">' . "\n"
                . "import RefreshRuntime from '{$o}/@react-refresh';\n"
                . "RefreshRuntime.injectIntoGlobalHook(window);\n"
                . "window.\$RefreshReg\$ = () => {};\n"
                . "window.\$RefreshSig\$ = () => (type) => type;\n"
                . "window.__vite_plugin_react_preamble_installed__ = true;\n"
                . "</script>\n"
                . '<script type="module" src="' . $o . '/@vite/client"></script>' . "\n"
                . '<script type="module" src="' . $o . '/' . htmlspecialchars($entry, ENT_QUOTES, 'UTF-8') . '"></script>';
        }

        $manifest = null;
        foreach (self::manifest_paths() as $path) {
            if (is_file($path)) {
                $manifest = json_decode((string) file_get_contents($path), true);
                break;
            }
        }
        if (!is_array($manifest) || !isset($manifest[$entry])) {
            return '<!-- Inertia: build assets missing. Run "npm run build". -->';
        }

        $css = [];
        $preload = [];
        $seen = [];
        $walk = function (string $key) use (&$walk, $manifest, &$css, &$preload, &$seen): void {
            if (isset($seen[$key]) || !isset($manifest[$key])) return;
            $seen[$key] = true;
            foreach ($manifest[$key]['css'] ?? [] as $file) $css[$file] = true;
            foreach ($manifest[$key]['imports'] ?? [] as $import) {
                if (isset($manifest[$import]['file'])) $preload[$manifest[$import]['file']] = true;
                $walk($import);
            }
        };
        $walk($entry);

        $base = BASE_URL . 'build/';
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $html = '';
        foreach (array_keys($css) as $file) {
            $html .= '<link rel="stylesheet" href="' . $h($base . $file) . '">' . "\n";
        }
        foreach (array_keys($preload) as $file) {
            $html .= '<link rel="modulepreload" href="' . $h($base . $file) . '">' . "\n";
        }
        $html .= '<script type="module" src="' . $h($base . $manifest[$entry]['file']) . '"></script>';
        return $html;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private static function hot_origin(): ?string {
        static $cached = false;
        if ($cached !== false) return $cached;

        if (strtolower(ENV) !== 'dev') return $cached = null;

        $file = APPPATH . 'public/hot';
        if (!is_file($file)) return $cached = null;

        $origin = rtrim(trim((string) file_get_contents($file)), '/');
        if ($origin === '') return $cached = null;

        $parts = parse_url($origin);
        $conn = @fsockopen($parts['host'] ?? 'localhost', (int) ($parts['port'] ?? 5173), $errno, $errstr, 0.2);
        if ($conn === false) return $cached = null; // dev server not running
        fclose($conn);

        return $cached = $origin;
    }

    private static function manifest_paths(): array {
        return [
            APPPATH . 'public/build/.vite/manifest.json',
            APPPATH . 'public/build/manifest.json',
        ];
    }

    private static function header_list(string $server_key): array {
        $raw = (string) ($_SERVER[$server_key] ?? '');
        return $raw === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen'));
    }

    private static function resolve(mixed $value): mixed {
        if ($value instanceof Inertia_prop) {
            $value = $value->value;
        }
        if ($value instanceof Closure) {
            $value = $value();
        }
        if (is_array($value)) {
            return array_map([self::class, 'resolve'], $value);
        }
        return $value;
    }

    /** Trongate validation errors -> { field: "first message" } (and consume them). */
    private static function pull_errors(): array {
        $raw = $_SESSION['form_submission_errors'] ?? [];
        unset($_SESSION['form_submission_errors'], $_SESSION['form_submission_name']);

        $errors = [];
        if (is_array($raw)) {
            foreach ($raw as $field => $messages) {
                $errors[(string) $field] = is_array($messages) ? (string) reset($messages) : (string) $messages;
            }
        }

        $bag = (string) ($_SERVER['HTTP_X_INERTIA_ERROR_BAG'] ?? '');
        if ($bag !== '' && $errors !== []) {
            $errors = [$bag => $errors];
        }
        return $errors;
    }

    /** set_flashdata() message -> flash.message (and consume it). */
    private static function pull_flash(): ?string {
        $msg = $_SESSION['flashdata'] ?? null;
        unset($_SESSION['flashdata']);
        return is_string($msg) ? $msg : null;
    }

    private static function csrf_token(): string {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /** Readable cookie; axios sends it back as X-XSRF-TOKEN automatically. */
    private static function send_csrf_cookie(string $token): void {
        if (($_COOKIE['XSRF-TOKEN'] ?? '') === $token || headers_sent()) return;
        setcookie('XSRF-TOKEN', $token, [
            'path' => parse_url(BASE_URL, PHP_URL_PATH) ?: '/',
            'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => false,
        ]);
    }
}

/** Marker wrapper for optional / always / defer / merge props. */
final class Inertia_prop {
    public function __construct(
        public string $type,
        public mixed $value,
        public string $group = 'default'
    ) {}
}