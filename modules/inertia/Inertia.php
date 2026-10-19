<?php
/**
 * Ultra-optimised Inertia adapter for Trongate v2.
 * PHP: 8.2+  |  Protocol: Inertia v2/v3
 *
 * Performance notes (vs. previous version):
 *  - version():  md5_file() → stat()  (1 syscall, no disk read/hash)
 *  - hot_origin(): removed fsockopen() (was blocking 200ms on dead Vite)
 *  - header_list(): fast path for single-value headers
 *  - load_manifest()/assets(): optional APCu cross-request cache
 *  - base_path(): static-cached parse_url()
 */
class Inertia
{
    // ── State ────────────────────────────────────────────────────────────────
    private static array   $shared          = [];
    private static ?string $version         = null;
    private static bool    $encrypt_history = false;
    private static ?string $root_view       = null;
    private static array   $title_meta      = [];
    private static ?string $csrf_token      = null;
    private static bool    $method_normalised = false;
    private static ?string $base_path_cache = null;

    private const ENTRY      = 'resources/js/app.jsx';
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
    private const PARTIAL_HEADERS = [
        'only'        => 'HTTP_X_INERTIA_PARTIAL_DATA',
        'except'      => 'HTTP_X_INERTIA_PARTIAL_EXCEPT',
        'reset'       => 'HTTP_X_INERTIA_RESET',
        'except_once' => 'HTTP_X_INERTIA_EXCEPT_ONCE_PROPS',
        'error_bag'   => 'HTTP_X_INERTIA_ERROR_BAG',
    ];

    // ── Interceptor ──────────────────────────────────────────────────────────

    public function intercept(): void
    {
        if (!self::is_inertia()) {
            return;                       // hot path for non-Inertia pages
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // GET/HEAD: only the version check matters (fast via stat()).
        if ($method === 'GET' || $method === 'HEAD') {
            if ($method === 'GET'
                && ($_SERVER['HTTP_X_INERTIA_VERSION'] ?? '') !== self::version()) {
                http_response_code(409);
                header('X-Inertia-Location: ' . current_url());
                die();
            }
            return;
        }

        if (self::$method_normalised) {
            return;
        }
        self::$method_normalised = true;

        $_SERVER['INERTIA_ORIGINAL_METHOD'] = $method;

        // JSON body → $_POST (so post() / validation work).
        if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
            $raw     = file_get_contents('php://input');
            $decoded = ($raw !== '' && $raw !== false) ? json_decode($raw, true) : [];
            $_POST   = is_array($decoded) ? $decoded : [];
            $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        }

        $token = $_SERVER['HTTP_X_XSRF_TOKEN'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if ($token !== '' && !isset($_POST['csrf_token'])) {
            $_POST['csrf_token'] = $token;
        }

        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    // ── Public API: core ─────────────────────────────────────────────────────

    public static function is_inertia(): bool
    {
        return isset($_SERVER['HTTP_X_INERTIA']);
    }

    public static function share(string|array $key, mixed $value = null): void
    {
        if (is_array($key)) {
            self::$shared = array_merge(self::$shared, $key);
        } else {
            self::$shared[$key] = $value;
        }
    }

    public static function set_version(string $version): void { self::$version = $version; }
    public static function encrypt_history(bool $on = true): void { self::$encrypt_history = $on; }
    public static function setRootView(string $view): void { self::$root_view = $view; }
    public static function title(string $title): void { self::$title_meta['title'] = $title; }

    public static function meta(array $tags): void
    {
        self::$title_meta['meta'] = array_merge(self::$title_meta['meta'] ?? [], $tags);
    }

    public static function lazy(callable|Closure $value): Inertia_prop
    { return new Inertia_prop('optional', $value); }

    public static function optional(callable|Closure $value): Inertia_prop
    { return new Inertia_prop('optional', $value); }

    public static function always(mixed $value): Inertia_prop
    { return new Inertia_prop('always', $value); }

    public static function defer(Closure $value, string $group = 'default'): Inertia_prop
    { return new Inertia_prop('defer', $value, $group); }

    public static function merge(mixed $value): Inertia_merge
    { return new Inertia_merge($value, Inertia_merge::APPEND); }

    public static function prepend(mixed $value): Inertia_merge
    { return new Inertia_merge($value, Inertia_merge::PREPEND); }

    public static function deepMerge(mixed $value): Inertia_merge
    { return new Inertia_merge($value, Inertia_merge::DEEP); }

    public static function matchOn(string $key, Inertia_merge $prop): Inertia_merge
    { $prop->matchOn = $key; return $prop; }

    public static function scroll(
        callable|Closure $value,
        string $pageName = 'page',
        bool $cursor = false,
        string $cursorKey = 'cursor'
    ): Inertia_scroll {
        return new Inertia_scroll($value, $pageName, $cursor, $cursorKey);
    }

    public static function once(
        callable|Closure $value,
        ?string $customKey = null,
        ?int $expiresAt = null
    ): Inertia_once {
        return new Inertia_once($value, $customKey, $expiresAt);
    }

    // ── paginate() unchanged (see original) ──────────────────────────────────
    public static function paginate(object|array $paginator): array
    {
        if (!is_array($paginator) && method_exists($paginator, 'items') && method_exists($paginator, 'total')) {
            return [
                'data'  => $paginator->items(),
                'meta'  => [
                    'current_page' => $paginator->currentPage(),
                    'last_page'    => $paginator->lastPage(),
                    'per_page'     => $paginator->perPage(),
                    'total'        => $paginator->total(),
                    'from'         => $paginator->firstItem(),
                    'to'           => $paginator->lastItem(),
                ],
                'links' => [
                    'first' => $paginator->url(1),
                    'prev'  => $paginator->previousPageUrl(),
                    'next'  => $paginator->nextPageUrl(),
                    'last'  => $paginator->url($paginator->lastPage()),
                ],
            ];
        }

        if (!is_array($paginator) && method_exists($paginator, 'items') && method_exists($paginator, 'hasMorePages')) {
            return [
                'data'  => $paginator->items(),
                'meta'  => [
                    'current_page' => $paginator->currentPage(),
                    'per_page'     => $paginator->perPage(),
                    'has_more'     => $paginator->hasMorePages(),
                ],
                'links' => [
                    'prev' => $paginator->previousPageUrl(),
                    'next' => $paginator->nextPageUrl(),
                ],
            ];
        }

        if (!is_array($paginator) && method_exists($paginator, 'items') && method_exists($paginator, 'cursor')) {
            return [
                'data'  => $paginator->items(),
                'meta'  => [
                    'per_page'    => $paginator->perPage(),
                    'next_cursor' => $paginator->nextCursor()?->encode(),
                    'prev_cursor' => $paginator->previousCursor()?->encode(),
                ],
                'links' => [
                    'next' => $paginator->nextPageUrl(),
                    'prev' => $paginator->previousPageUrl(),
                ],
            ];
        }

        return is_array($paginator) ? $paginator : (array) $paginator;
    }

    // ── Public API: render ───────────────────────────────────────────────────

    public static function render(string $component, array $props = []): void
    {
        $is_inertia = self::is_inertia();
        $partial    = $is_inertia
            && (($_SERVER['HTTP_X_INERTIA_PARTIAL_COMPONENT'] ?? '') === $component);

        $only        = $partial ? self::header_list(self::PARTIAL_HEADERS['only'])   : [];
        $except      = $partial ? self::header_list(self::PARTIAL_HEADERS['except']) : [];
        $reset       = $is_inertia ? self::header_list(self::PARTIAL_HEADERS['reset']) : [];
        $except_once = $is_inertia ? self::header_list(self::PARTIAL_HEADERS['except_once']) : [];

        $props = $props === [] ? self::$shared : array_merge(self::$shared, $props);

        $token = self::csrf_token();
        $props['csrf_token'] = $token;
        $props['errors']     = new Inertia_prop('always', self::pull_errors());
        $props['flash']      = new Inertia_prop('always', ['message' => self::pull_flash()]);

        // ── Single-pass resolution ───────────────────────────────────────────
        $resolved  = [];
        $deferred  = [];
        $merge     = [];
        $prepend   = [];
        $deepMerge = [];
        $matchOn   = [];
        $scroll    = [];
        $onceProps = [];

        foreach ($props as $key => $value) {
            $type = $value instanceof Inertia_prop ? $value->type : 'plain';

            if ($partial && $type !== 'always') {
                if ($only !== [] && !isset($only[$key])) continue;
                if (isset($except[$key])) continue;
            }

            if ($type === 'optional') {
                if (!$partial) continue;
                if ($only !== [] && !isset($only[$key])) continue;
            }

            if ($type === 'defer') {
                if (!$partial) {
                    $deferred[$value->group][] = $key;
                    continue;
                }
                if ($only !== [] && !isset($only[$key])) continue;
            }

            if ($value instanceof Inertia_once) {
                $onceKey  = $value->customKey ?? $key;
                $onceProps[$onceKey] = ['prop' => $key, 'expiresAt' => $value->expiresAt];
                if (isset($except_once[$onceKey])
                    && ($value->expiresAt === null
                        || $value->expiresAt > (int)(microtime(true) * 1000))
                ) {
                    continue;
                }
            }

            if ($value instanceof Inertia_merge) {
                $strategy = $value->strategy;
                if ($strategy === Inertia_merge::APPEND) {
                    if (!isset($reset[$key])) $merge[] = $key;
                } elseif ($strategy === Inertia_merge::PREPEND) {
                    $prepend[] = $key;
                } elseif ($strategy === Inertia_merge::DEEP) {
                    $deepMerge[] = $key;
                }
                if ($value->matchOn !== null) {
                    $matchOn["{$key}.{$value->matchOn}"] = $value->matchOn;
                }
            }

            if ($value instanceof Inertia_scroll) {
                $dataPath    = $key . '.data';
                $mergeIntent = strtolower(trim((string)(
                    $_SERVER['HTTP_X_INERTIA_INFINITE_SCROLL_MERGE_INTENT'] ?? ''
                )));

                if (!isset($reset[$key])) {
                    if ($mergeIntent === 'prepend') $prepend[] = $dataPath;
                    else                            $merge[]   = $dataPath;
                }

                $scrollData = self::resolve($value);

                if (is_array($scrollData)) {
                    $requestUri  = $_SERVER['REQUEST_URI'] ?? '/';
                    $requestPath = parse_url($requestUri, PHP_URL_PATH) ?: '/';

                    $scheme = (
                        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
                    ) ? 'https' : 'http';

                    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $baseUrl = $scheme . '://' . $host . $requestPath;

                    if (!isset($scrollData['meta']) || !is_array($scrollData['meta'])) {
                        $scrollData['meta'] = [];
                    }
                    if (empty($scrollData['meta']['path'])) {
                        $scrollData['meta']['path'] = $baseUrl;
                    }

                    if (isset($scrollData['links']) && is_array($scrollData['links'])) {
                        foreach ($scrollData['links'] as $lk => $link) {
                            if (!is_string($link) || $link === '') continue;
                            if (str_starts_with($link, '?')) {
                                $scrollData['links'][$lk] = $baseUrl . $link;
                            } elseif (str_starts_with($link, '/')) {
                                $scrollData['links'][$lk] = $scheme . '://' . $host . $link;
                            } elseif (!preg_match('~^https?://~i', $link)) {
                                $scrollData['links'][$lk] = $baseUrl . '?' . ltrim($link, '?');
                            }
                        }
                    }
                }

                $scroll[$key]   = $value->toMeta($scrollData);
                $resolved[$key] = $scrollData;
                continue;
            }

            $resolved[$key] = self::resolve($value);
        }

        if (($resolved['errors'] ?? null) === []) {
            $resolved['errors'] = new stdClass();
        }

        $page = [
            'component' => $component,
            'props'     => $resolved,
            'url'       => $_SERVER['REQUEST_URI'] ?? '/',
            'version'   => self::version(),
        ];

        if ($deferred  !== []) $page['deferredProps']  = $deferred;
        if ($merge     !== []) $page['mergeProps']     = $merge;
        if ($prepend   !== []) $page['prependProps']   = $prepend;
        if ($deepMerge !== []) $page['deepMergeProps'] = $deepMerge;
        if ($matchOn   !== []) $page['matchPropsOn']   = array_keys($matchOn);
        if ($scroll    !== []) $page['scrollProps']    = $scroll;
        if ($onceProps !== []) $page['onceProps']      = $onceProps;
        if (self::$encrypt_history) $page['encryptHistory'] = true;

        self::send_csrf_cookie($token);
        header('Vary: X-Inertia');

        $json = json_encode($page, self::JSON_FLAGS);

        if ($is_inertia) {
            header('X-Inertia: true');
            header('Content-Type: application/json');
            echo $json;
            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        $page_attr = htmlspecialchars($json, ENT_QUOTES, 'UTF-8');
        $assets    = self::assets();
        $base_path = self::base_path();

        $inertia_title = self::$title_meta['title'] ?? '';
        $inertia_meta  = self::$title_meta['meta']  ?? [];

        $root = self::$root_view ?? 'app';
        require APPPATH . "modules/inertia/views/{$root}.php";
    }

    // ── Public API: redirects ────────────────────────────────────────────────

    public static function redirect(string $to, bool $preserveFragment = false): void
    {
        $to = self::absolute_url($to);
        if ($preserveFragment) {
            $f = self::current_fragment();
            if ($f !== '') $to .= $f;
        }
        header('Location: ' . $to, true, 303);
        die();
    }

    public static function back(string $fallback = '', bool $preserveFragment = false): void
    {
        $ref      = previous_url();
        $ref_host = parse_url($ref, PHP_URL_HOST);
        $same_host = ($ref !== '')
            && ($ref_host === null
                || $ref_host === parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));

        if ($same_host) {
            self::redirect($ref, $preserveFragment);
        }
        self::redirect($fallback !== '' ? $fallback : BASE_URL, $preserveFragment);
    }

    public static function location(string $url, bool $preserveFragment = false): void
    {
        $url = self::absolute_url($url);
        if ($preserveFragment) {
            $f = self::current_fragment();
            if ($f !== '') $url .= $f;
        }
        if (self::is_inertia()) {
            http_response_code(409);
            header('X-Inertia-Location: ' . $url);
            die();
        }
        header('Location: ' . $url, true, 303);
        die();
    }

    public static function clearHistory(): void
    {
        self::$encrypt_history = true;
        header('X-Inertia-Clear-History: true');
    }

    // ── Version ──────────────────────────────────────────────────────────────

    /**
     * FAST: one stat() syscall (mtime + size). No file read, no md5, no network.
     * If you need deterministic content hashes, set your own via set_version().
     */
    public static function version(): string
    {
        if (self::$version !== null) return self::$version;

        foreach (self::manifest_paths() as $path) {
            $s = @stat($path);
            if ($s !== false && $s['size'] > 0) {
                return self::$version = $s['mtime'] . '-' . $s['size'];
            }
        }

        return self::$version = (
            (defined('ENV') && strtolower(ENV) === 'dev'
                && is_file(APPPATH . 'public/hot'))
                ? 'dev'
                : '1'
        );
    }

    // ── Vite integration ─────────────────────────────────────────────────────

    public static function assets(string $entry = self::ENTRY): string
    {
        // Dev server (HMR) — no fsockopen; the `hot` file is authoritative.
        $origin = self::hot_origin();
        if ($origin !== null) {
            $o = htmlspecialchars($origin, ENT_QUOTES, 'UTF-8');
            $e = htmlspecialchars($entry, ENT_QUOTES, 'UTF-8');

            return '<script type="module">' . "\n"
                . "import RefreshRuntime from '{$o}/@react-refresh';\n"
                . "RefreshRuntime.injectIntoGlobalHook(window);\n"
                . "window.\$RefreshReg\$ = () => {};\n"
                . "window.\$RefreshSig\$ = () => (type) => type;\n"
                . "window.__vite_plugin_react_preamble_installed__ = true;\n"
                . "</script>\n"
                . '<script type="module" src="' . $o . '/@vite/client"></script>' . "\n"
                . '<script type="module" src="' . $o . '/' . $e . '"></script>';
        }

        // Production — cross-request APCu cache (keyed by version).
        static $cache = [];
        if (isset($cache[$entry])) return $cache[$entry];

        $version = self::version();
        $acKey   = 'inertia_assets:' . $version . ':' . $entry;

        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($acKey, $ok);
            if ($ok && is_string($cached) && $cached !== '') {
                return $cache[$entry] = $cached;
            }
        }

        $manifest = self::load_manifest();
        if (!is_array($manifest) || !isset($manifest[$entry])) {
            return $cache[$entry] = '<!-- Inertia: build assets missing. Run "bun --bun run build". -->';
        }

        $css     = [];
        $preload = [];
        $seen    = [];

        $walk = function (string $key) use (&$walk, $manifest, &$css, &$preload, &$seen): void {
            if (isset($seen[$key]) || !isset($manifest[$key])) return;
            $seen[$key] = true;

            foreach ($manifest[$key]['css'] ?? [] as $file) {
                $css[$file] = true;
            }
            foreach ($manifest[$key]['imports'] ?? [] as $import) {
                if (isset($manifest[$import]['file'])) {
                    $preload[$manifest[$import]['file']] = true;
                }
                $walk($import);
            }
        };
        $walk($entry);

        $base = BASE_URL . 'build/';
        $h    = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $html = '';
        foreach (array_keys($css) as $file) {
            $html .= '<link rel="stylesheet" href="' . $h($base . $file) . '">' . "\n";
        }
        foreach (array_keys($preload) as $file) {
            $html .= '<link rel="modulepreload" href="' . $h($base . $file) . '">' . "\n";
        }
        $html .= '<script type="module" src="' . $h($base . $manifest[$entry]['file']) . '"></script>';

        if (function_exists('apcu_store')) {
            @apcu_store($acKey, $html, 3600);
        }

        return $cache[$entry] = $html;
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * No fsockopen! The previous version blocked 200ms when Vite was down.
     * In dev mode we simply trust the presence of `public/hot`.
     */
    private static function hot_origin(): ?string
    {
        static $cached = false;
        if ($cached !== false) return $cached;

        if (!defined('ENV') || strtolower(ENV) !== 'dev') {
            return $cached = null;
        }

        $file = APPPATH . 'public/hot';
        if (!is_file($file)) return $cached = null;

        $origin = rtrim(trim((string) @file_get_contents($file)), '/');
        return $cached = ($origin !== '' ? $origin : null);
    }

    private static function manifest_paths(): array
    {
        return [
            APPPATH . 'public/build/.vite/manifest.json',
            APPPATH . 'public/build/manifest.json',
        ];
    }

    private static function load_manifest(): ?array
    {
        static $manifest = null;
        if ($manifest !== null) return $manifest;

        foreach (self::manifest_paths() as $path) {
            if (!is_file($path)) continue;

            $mtime = @filemtime($path);

            if ($mtime !== false && function_exists('apcu_fetch')) {
                $key    = 'inertia_m:' . $path . ':' . $mtime;
                $cached = apcu_fetch($key, $ok);
                if ($ok && is_array($cached)) {
                    return $manifest = $cached;
                }
            }

            $json = @file_get_contents($path);
            if ($json === false || $json === '') continue;

            $decoded = json_decode($json, true);
            if (!is_array($decoded)) continue;

            if (isset($key) && function_exists('apcu_store')) {
                @apcu_store($key, $decoded, 3600);
            }

            return $manifest = $decoded;
        }

        return $manifest = null;
    }

    /** Fast path for single-value headers (avoids explode/map/filter/flip). */
    private static function header_list(string $server_key): array
    {
        $raw = $_SERVER[$server_key] ?? '';
        if ($raw === '' || $raw === null) return [];

        if (strpos($raw, ',') === false) {
            return [trim($raw) => true];
        }

        $parts = array_filter(array_map('trim', explode(',', $raw)), 'strlen');
        return array_flip($parts);
    }

    private static function resolve(mixed $value): mixed
    {
        if ($value instanceof Inertia_prop
            || $value instanceof Inertia_merge
            || $value instanceof Inertia_scroll
            || $value instanceof Inertia_once
        ) {
            $value = $value->value;
        }

        if ($value instanceof Closure) {
            $value = $value();
        }

        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if ($v instanceof Closure
                    || $v instanceof Inertia_prop
                    || $v instanceof Inertia_merge
                    || $v instanceof Inertia_scroll
                    || $v instanceof Inertia_once
                ) {
                    $value[$k] = self::resolve($v);
                }
            }
        }

        return $value;
    }

    private static function pull_errors(): array
    {
        $raw = $_SESSION['form_submission_errors'] ?? [];
        unset($_SESSION['form_submission_errors'], $_SESSION['form_submission_name']);

        $errors = [];
        if (is_array($raw)) {
            foreach ($raw as $field => $messages) {
                $errors[(string) $field] = is_array($messages)
                    ? (string) reset($messages)
                    : (string) $messages;
            }
        }

        $bag = (string) ($_SERVER[self::PARTIAL_HEADERS['error_bag']] ?? '');
        if ($bag !== '' && $errors !== []) {
            $errors = [$bag => $errors];
        }

        return $errors;
    }

    private static function pull_flash(): ?string
    {
        $msg = $_SESSION['flashdata'] ?? null;
        unset($_SESSION['flashdata']);
        return is_string($msg) ? $msg : null;
    }

    private static function csrf_token(): string
    {
        if (self::$csrf_token !== null) return self::$csrf_token;

        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return self::$csrf_token = $_SESSION['csrf_token'];
    }

    private static function send_csrf_cookie(string $token): void
    {
        if (($_COOKIE['XSRF-TOKEN'] ?? '') === $token || headers_sent()) return;

        setcookie('XSRF-TOKEN', $token, [
            'path'     => self::base_path(),
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => false,
        ]);
    }

    /** Static-cached path component of BASE_URL. */
    private static function base_path(): string
    {
        return self::$base_path_cache ??= (parse_url(BASE_URL, PHP_URL_PATH) ?: '/');
    }

    private static function absolute_url(string $url): string
    {
        if (preg_match('#^https?://#i', $url)) return $url;
        return BASE_URL . ltrim($url, '/');
    }

    private static function current_fragment(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $pos = strpos($uri, '#');
        return $pos !== false ? substr($uri, $pos) : '';
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// Prop marker classes — unchanged
// ═══════════════════════════════════════════════════════════════════════════════

final class Inertia_prop
{
    public function __construct(
        public string $type,
        public mixed $value,
        public string $group = 'default'
    ) {}
}

final class Inertia_merge
{
    public const APPEND  = 'append';
    public const PREPEND = 'prepend';
    public const DEEP    = 'deep';

    public ?string $matchOn = null;

    public function __construct(public mixed $value, public string $strategy) {}
}

final class Inertia_scroll
{
    public function __construct(
        public mixed $value,
        public string $pageName = 'page',
        public bool $cursor = false,
        public string $cursorKey = 'cursor'
    ) {}

    public function toMeta(mixed $data = null): array
    {
        $meta = [
            'pageName' => $this->pageName,
            'type'     => $this->cursor ? 'cursor' : 'paged',
            'reset'    => false,
        ];

        if (func_num_args() === 0) {
            $data = $this->value instanceof Closure ? ($this->value)() : $this->value;
        }

        if (is_array($data)) {
            $inner = $data['meta'] ?? [];
            $links = $data['links'] ?? [];

            if ($this->cursor) {
                $nextCursor = $inner['next_cursor'] ?? $inner['nextCursor'] ?? null;
                $prevCursor = $inner['prev_cursor'] ?? $inner['prevCursor'] ?? null;

                if ($nextCursor !== null && $nextCursor !== '') {
                    $meta['nextPage'] = $nextCursor;
                } elseif (!empty($links['next'])) {
                    $meta['nextPage'] = self::cursor_from_link((string) $links['next'], $this->cursorKey);
                }
                if ($prevCursor !== null && $prevCursor !== '') {
                    $meta['previousPage'] = $prevCursor;
                } elseif (!empty($links['prev'])) {
                    $meta['previousPage'] = self::cursor_from_link((string) $links['prev'], $this->cursorKey);
                }
                $meta['hasMore'] = !empty($meta['nextPage']);
                return $meta;
            }

            $currentPage = isset($inner['current_page']) ? (int) $inner['current_page'] : 1;
            $meta['currentPage'] = $currentPage;

            if (isset($inner['last_page'])) {
                $meta['lastPage'] = (int) $inner['last_page'];
            }
            if (array_key_exists('has_more', $inner)) {
                $meta['hasMore'] = (bool) $inner['has_more'];
            } elseif (isset($inner['total'], $inner['per_page'])) {
                $meta['hasMore'] = ($currentPage * (int) $inner['per_page']) < (int) $inner['total'];
            } else {
                $meta['hasMore'] = !empty($links['next']);
            }
            if (!empty($links['next']) && $meta['hasMore']) $meta['nextPage'] = $currentPage + 1;
            if (!empty($links['prev']) && $currentPage > 1)    $meta['previousPage'] = $currentPage - 1;

            return $meta;
        }

        if (is_object($data)) {
            if ($this->cursor) {
                if (method_exists($data, 'nextCursor') && $data->nextCursor() !== null) {
                    $meta['nextPage'] = $data->nextCursor()->encode();
                }
                if (method_exists($data, 'previousCursor') && $data->previousCursor() !== null) {
                    $meta['previousPage'] = $data->previousCursor()->encode();
                }
                $meta['hasMore'] = !empty($meta['nextPage']);
                return $meta;
            }
            if (method_exists($data, 'currentPage'))   $meta['currentPage'] = (int) $data->currentPage();
            if (method_exists($data, 'lastPage'))      $meta['lastPage']    = (int) $data->lastPage();
            if (method_exists($data, 'hasMorePages'))  $meta['hasMore']     = (bool) $data->hasMorePages();
            if (!empty($meta['hasMore']) && isset($meta['currentPage'])) {
                $meta['nextPage'] = $meta['currentPage'] + 1;
            }
            if (($meta['currentPage'] ?? 1) > 1) {
                $meta['previousPage'] = $meta['currentPage'] - 1;
            }
        }

        return $meta;
    }

    private static function cursor_from_link(string $url, string $cursorKey): ?string
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') return null;

        parse_str($query, $params);
        $cursor = $params[$cursorKey] ?? null;
        return is_scalar($cursor) && (string) $cursor !== '' ? (string) $cursor : null;
    }
}

final class Inertia_once
{
    public function __construct(
        public mixed $value,
        public ?string $customKey = null,
        public ?int $expiresAt = null
    ) {}
}
