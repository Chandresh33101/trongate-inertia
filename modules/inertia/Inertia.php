<?php
/**
 * Ultra-optimised Inertia adapter for Trongate v2.
 *
 * Usage in any controller:  Inertia::render('Demo/Index', ['records' => $rows]);
 * Registered as an interceptor (config: INTERCEPTORS) to do version checks
 * and to normalise Inertia's JSON PUT/PATCH/DELETE bodies for post()/validation.
 *
 * Protocol version: Inertia v2.x / v3.x compatible
 * PHP: 8.2+
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

    private const ENTRY       = 'resources/js/app.jsx';
    private const JSON_FLAGS  = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
    private const PARTIAL_HEADERS = [
        'only'   => 'HTTP_X_INERTIA_PARTIAL_DATA',
        'except' => 'HTTP_X_INERTIA_PARTIAL_EXCEPT',
        'reset'  => 'HTTP_X_INERTIA_RESET',
        'except_once' => 'HTTP_X_INERTIA_EXCEPT_ONCE_PROPS',
        'error_bag'   => 'HTTP_X_INERTIA_ERROR_BAG',
    ];

    // ── Interceptor ──────────────────────────────────────────────────────────

    /**
     * Runs on every request (registered via INTERCEPTORS config).
     * Handles version checks and JSON body normalisation.
     */
    public function intercept(): void
    {
        if (!self::is_inertia()) {
            return;
        }

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Asset version mismatch on GET => hard reload.
        if ($method === 'GET'
            && ($_SERVER['HTTP_X_INERTIA_VERSION'] ?? '') !== self::version()) {
            http_response_code(409);
            header('X-Inertia-Location: ' . current_url());
            die();
        }

        if ($method === 'GET' || $method === 'HEAD') {
            return;
        }

        // Normalise method once.
        if (self::$method_normalised) {
            return;
        }
        self::$method_normalised = true;

        $_SERVER['INERTIA_ORIGINAL_METHOD'] = $method;

        // Decode JSON body into $_POST so post()/validation work.
        if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            $decoded = ($raw !== '' && $raw !== false) ? json_decode($raw, true) : [];
            $_POST = is_array($decoded) ? $decoded : [];
            $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        }

        // CSRF token from XSRF/CSRF header.
        $token = $_SERVER['HTTP_X_XSRF_TOKEN'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if ($token !== '' && !isset($_POST['csrf_token'])) {
            $_POST['csrf_token'] = $token;
        }

        // post() reads $_POST when REQUEST_METHOD is POST.
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    // ── Public API: core ─────────────────────────────────────────────────────

    public static function is_inertia(): bool
    {
        return isset($_SERVER['HTTP_X_INERTIA']);
    }

    /** Share props with every page: Inertia::share('user', fn() => ...) */
    public static function share(string|array $key, mixed $value = null): void
    {
        if (is_array($key)) {
            self::$shared = array_merge(self::$shared, $key);
        } else {
            self::$shared[$key] = $value;
        }
    }

    public static function set_version(string $version): void
    {
        self::$version = $version;
    }

    public static function encrypt_history(bool $on = true): void
    {
        self::$encrypt_history = $on;
    }

    /**
     * Override the root view for the current request.
     * Inertia::setRootView('custom_app') renders views/custom_app.php.
     */
    public static function setRootView(string $view): void
    {
        self::$root_view = $view;
    }

    /**
     * Set page title and/or meta tags for the root template.
     * Inertia::title('Dashboard'); Inertia::meta(['description' => '...']);
     */
    public static function title(string $title): void
    {
        self::$title_meta['title'] = $title;
    }

    public static function meta(array $tags): void
    {
        self::$title_meta['meta'] = array_merge(self::$title_meta['meta'] ?? [], $tags);
    }

    /** Alias for optional() — kept for parity with older adapters. */
    public static function lazy(callable|Closure $value): Inertia_prop
    {
        return new Inertia_prop('optional', $value);
    }

    /** Alias for optional() — legacy name. */
    public static function optional(callable|Closure $value): Inertia_prop
    {
        return new Inertia_prop('optional', $value);
    }

    /** Included even in partial reloads that don't list it. */
    public static function always(mixed $value): Inertia_prop
    {
        return new Inertia_prop('always', $value);
    }

    /** Loaded by the client right after the first render (Inertia v2). */
    public static function defer(Closure $value, string $group = 'default'): Inertia_prop
    {
        return new Inertia_prop('defer', $value, $group);
    }

    // ── Public API: merge variants ───────────────────────────────────────────

    /** Append to existing client data on partial reloads (default merge). */
    public static function merge(mixed $value): Inertia_merge
    {
        return new Inertia_merge($value, Inertia_merge::APPEND);
    }

    /** Prepend to existing client data on partial reloads. */
    public static function prepend(mixed $value): Inertia_merge
    {
        return new Inertia_merge($value, Inertia_merge::PREPEND);
    }

    /** Deep-merge nested objects/arrays into existing client data. */
    public static function deepMerge(mixed $value): Inertia_merge
    {
        return new Inertia_merge($value, Inertia_merge::DEEP);
    }

    /**
     * Attach a match key to a merge/prepend/deepMerge prop so the client
     * can deduplicate items by a field (e.g. 'id').
     *
     * Usage: Inertia::matchOn('id', Inertia::merge($items))
     */
    public static function matchOn(string $key, Inertia_merge $prop): Inertia_merge
    {
        $prop->matchOn = $key;
        return $prop;
    }

    // ── Public API: infinite scroll ──────────────────────────────────────────

    /**
     * Wrap a paginator (length-aware, simple, or cursor) for infinite scroll.
     *
     * $pageName  — query-string key that holds the page/cursor (default 'page').
     * $cursorKey — set true for cursor paginators; the value is the cursor field name.
     *
     * Usage: Inertia::scroll(fn () => User::paginate(), 'page');
     */
    public static function scroll(
        callable|Closure $value,
        string $pageName = 'page',
        bool $cursor = false,
        string $cursorKey = 'cursor'
    ): Inertia_scroll {
        return new Inertia_scroll($value, $pageName, $cursor, $cursorKey);
    }

    // ── Public API: once (cached-across-visits) ──────────────────────────────

    /**
     * Prop whose value is computed once per client session and then cached.
     *
     * $customKey  — optional key the client uses to identify the cached value.
     * $expiresAt  — optional Unix epoch millis after which the cache is stale.
     *
     * Usage: Inertia::once(fn () => expensiveQuery(), 'lookups');
     */
    public static function once(
        callable|Closure $value,
        ?string $customKey = null,
        ?int $expiresAt = null
    ): Inertia_once {
        return new Inertia_once($value, $customKey, $expiresAt);
    }

    // ── Public API: pagination helper ────────────────────────────────────────

    /**
     * Normalise a paginator result into an Inertia-friendly prop.
     * Works with length-aware, simple, and cursor paginators.
     *
     * Returns an array with keys: data, meta, links.
     */
    public static function paginate(object|array $paginator): array
    {
        /*if (is_array($paginator)) {
            $paginator = (object)$paginator;
        }*/
        // Length-aware paginator (Laravel / Trongate).
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

        // Simple paginator.
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

        // Cursor paginator.
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

        // Fallback: treat as plain array.
        return is_array($paginator) ? $paginator : (array) $paginator;
    }

    // ── Public API: render ───────────────────────────────────────────────────

    public static function render(string $component, array $props = []): void
    {
        $is_inertia = self::is_inertia();
        $partial    = $is_inertia
            && (($_SERVER['HTTP_X_INERTIA_PARTIAL_COMPONENT'] ?? '') === $component);

        // Parse partial headers into hash maps for O(1) lookups.
        $only   = $partial ? self::header_list(self::PARTIAL_HEADERS['only'])   : [];
        $except = $partial ? self::header_list(self::PARTIAL_HEADERS['except']) : [];
        $reset  = $is_inertia ? self::header_list(self::PARTIAL_HEADERS['reset']) : [];
        $except_once = self::header_list(self::PARTIAL_HEADERS['except_once']);

        // Merge shared props.
        $props = $props === [] ? self::$shared : array_merge(self::$shared, $props);

        // Always-injected props.
        $token = self::csrf_token();
        $props['csrf_token'] = $token;
        $props['errors']     = new Inertia_prop('always', self::pull_errors());
        $props['flash']      = new Inertia_prop('always', ['message' => self::pull_flash()]);

        // ── Single-pass resolution ───────────────────────────────────────────
        $resolved   = [];
        $deferred   = [];
        $merge      = [];
        $prepend    = [];
        $deepMerge  = [];
        $matchOn    = [];
        $scroll     = [];
        $onceProps  = [];

        foreach ($props as $key => $value) {
            $type = $value instanceof Inertia_prop ? $value->type : 'plain';

            // Partial-reload filtering.
            if ($partial && $type !== 'always') {
                if ($only !== [] && !isset($only[$key])) continue;
                if (isset($except[$key])) continue;
            }

            // Optional / lazy props: only in partial reloads when requested.
            if ($type === 'optional') {
                if (!$partial) continue;
                if ($only !== [] && !isset($only[$key])) continue;
            }

            // Deferred props: collect for first load, resolve on request.
            if ($type === 'defer') {
                if (!$partial) {
                    $deferred[$value->group][] = $key;
                    continue;
                }
                if ($only !== [] && !isset($only[$key])) continue;
            }

            // Once props: check client cache.
            $onceKey = null;
            $onceMeta = null;
            if ($value instanceof Inertia_once) {
                $onceKey = $value->customKey ?? $key;
                $onceMeta = ['prop' => $key, 'expiresAt' => $value->expiresAt];
                $onceProps[$onceKey] = $onceMeta;

                // Skip resolution if client already has it and not expired.
                if (isset($except_once[$onceKey])
                    && ($value->expiresAt === null || $value->expiresAt > (int) (microtime(true) * 1000))
                ) {
                    continue;
                }
            }

            // Merge family: collect metadata, resolve value.
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

            // Scroll props: collect metadata.
            if ($value instanceof Inertia_scroll) {
                // Merge the nested 'data' path instead of the whole object
                $dataPath = $key . '.data';
                if (!isset($reset[$key])) {
                    $merge[] = $dataPath;
                }
                // Also track the parent for metadata
                $scroll[$key] = $value->toMeta();
            }

            // Resolve the value.
            $resolved[$key] = self::resolve($value);
        }

        // Post-process: errors empty object, not empty array.
        if (($resolved['errors'] ?? null) === []) {
            $resolved['errors'] = new stdClass();
        }

        // ── Build page object ────────────────────────────────────────────────
        $page = [
            'component' => $component,
            'props'     => $resolved,
            'url'       => $_SERVER['REQUEST_URI'] ?? '/',
            'version'   => self::version(),
        ];

        if ($deferred !== [])  $page['deferredProps']  = $deferred;
        if ($merge !== [])     $page['mergeProps']     = $merge;
        if ($prepend !== [])   $page['prependProps']   = $prepend;
        if ($deepMerge !== []) $page['deepMergeProps'] = $deepMerge;
        if ($matchOn !== [])   $page['matchPropsOn']   = array_keys($matchOn);
        if ($scroll !== [])    $page['scrollProps']    = $scroll;
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

        // ── HTML render ──────────────────────────────────────────────────────
        header('Content-Type: text/html; charset=utf-8');
        $page_attr = htmlspecialchars($json, ENT_QUOTES, 'UTF-8');
        $assets    = self::assets();
        $base_path = parse_url(BASE_URL, PHP_URL_PATH) ?: '/';

        // Root-view customisation data.
        $inertia_title = self::$title_meta['title'] ?? '';
        $inertia_meta  = self::$title_meta['meta']  ?? [];

        $root = self::$root_view ?? 'app';
        require APPPATH . "modules/inertia/views/{$root}.php";
    }

    // ── Public API: redirects ────────────────────────────────────────────────

    /**
     * Redirect that works for every HTTP verb (303 turns PUT/PATCH/DELETE
     * into a GET). Use this instead of redirect() in Inertia controllers.
     *
     * $preserveFragment — when true, the #hash from the current request is
     * carried over to the destination.
     */
    public static function redirect(string $to, bool $preserveFragment = false): void
    {
        $to = self::absolute_url($to);

        if ($preserveFragment) {
            $fragment = self::current_fragment();
            if ($fragment !== '') {
                $to .= $fragment;
            }
        }

        header('Location: ' . $to, true, 303);
        die();
    }

    /** Redirect to the previous page (e.g. after validation failure). */
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

        $dest = $fallback !== '' ? $fallback : BASE_URL;
        self::redirect($dest, $preserveFragment);
    }

    /**
     * Hard redirect (external site, or a non-Inertia page like the login form).
     *
     * $preserveFragment — when true, the #hash from the current request is
     * carried over to the destination.
     */
    public static function location(string $url, bool $preserveFragment = false): void
    {
        $url = self::absolute_url($url);

        if ($preserveFragment) {
            $fragment = self::current_fragment();
            if ($fragment !== '') {
                $url .= $fragment;
            }
        }

        if (self::is_inertia()) {
            http_response_code(409);
            header('X-Inertia-Location: ' . $url);
            die();
        }

        header('Location: ' . $url, true, 303);
        die();
    }

    // ── Public API: history ──────────────────────────────────────────────────

    /**
     * Clear the browser history state. When the client renders this page,
     * it rotates the encryption key, rendering previous history unreadable.
     */
    public static function clearHistory(): void
    {
        self::$encrypt_history = true; // ensure encryption is on
        header('X-Inertia-Clear-History: true');
    }

    // ── Version ──────────────────────────────────────────────────────────────

    public static function version(): string
    {
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

    // ── Vite integration ─────────────────────────────────────────────────────

    public static function assets(string $entry = self::ENTRY): string
    {
        // Dev server (HMR).
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

        // Production manifest.
        $manifest = self::load_manifest();
        if (!is_array($manifest) || !isset($manifest[$entry])) {
            return '<!-- Inertia: build assets missing. Run "npm run build". -->';
        }

        // Cache the import-walk result per entry.
        static $cache = [];
        if (isset($cache[$entry])) {
            return $cache[$entry];
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

        return $cache[$entry] = $html;
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private static function hot_origin(): ?string
    {
        static $cached = false;
        if ($cached !== false) return $cached;

        if (strtolower(ENV) !== 'dev') return $cached = null;

        $file = APPPATH . 'public/hot';
        if (!is_file($file)) return $cached = null;

        $origin = rtrim(trim((string) file_get_contents($file)), '/');
        if ($origin === '') return $cached = null;

        $parts = parse_url($origin);
        $conn  = @fsockopen($parts['host'] ?? 'localhost', (int) ($parts['port'] ?? 5173), $errno, $errstr, 0.2);
        if ($conn === false) return $cached = null;
        fclose($conn);

        return $cached = $origin;
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
            if (is_file($path)) {
                $manifest = json_decode((string) file_get_contents($path), true);
                return $manifest;
            }
        }

        return $manifest = null;
    }

    /** Parse a comma-separated header into a flipped hash map (O(1) lookup). */
    private static function header_list(string $server_key): array
    {
        $raw = (string) ($_SERVER[$server_key] ?? '');
        if ($raw === '') return [];

        $parts = array_filter(array_map('trim', explode(',', $raw)), 'strlen');
        return array_flip($parts);
    }

    /** Recursively resolve closures and Inertia_prop markers. */
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

    /** Trongate validation errors -> { field: "first message" } (and consume them). */
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

    /** set_flashdata() message -> flash.message (and consume it). */
    private static function pull_flash(): ?string
    {
        $msg = $_SESSION['flashdata'] ?? null;
        unset($_SESSION['flashdata']);
        return is_string($msg) ? $msg : null;
    }

    private static function csrf_token(): string
    {
        if (self::$csrf_token !== null) {
            return self::$csrf_token;
        }

        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return self::$csrf_token = $_SESSION['csrf_token'];
    }

    /** Readable cookie; axios sends it back as X-XSRF-TOKEN automatically. */
    private static function send_csrf_cookie(string $token): void
    {
        if (($_COOKIE['XSRF-TOKEN'] ?? '') === $token || headers_sent()) {
            return;
        }

        setcookie('XSRF-TOKEN', $token, [
            'path'     => parse_url(BASE_URL, PHP_URL_PATH) ?: '/',
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => false,
        ]);
    }

    /** Ensure a URL is absolute (prepends BASE_URL if relative). */
    private static function absolute_url(string $url): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        return BASE_URL . ltrim($url, '/');
    }

    /** Extract the current request's fragment (#hash), if any. */
    private static function current_fragment(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $pos = strpos($uri, '#');

        return $pos !== false ? substr($uri, $pos) : '';
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// Prop marker classes
// ═══════════════════════════════════════════════════════════════════════════════

/** Marker wrapper for optional / always / defer props. */
final class Inertia_prop
{
    public function __construct(
        public string $type,
        public mixed $value,
        public string $group = 'default'
    ) {}
}

/**
 * Marker for merge / prepend / deep-merge props.
 * Carries an optional match key for client-side item matching.
 */
final class Inertia_merge
{
    public const APPEND  = 'append';
    public const PREPEND = 'prepend';
    public const DEEP    = 'deep';

    public ?string $matchOn = null;

    public function __construct(
        public mixed $value,
        public string $strategy
    ) {}
}

/**
 * Marker for infinite-scroll props.
 * Wraps a paginator and emits scroll metadata for the client.
 */
final class Inertia_scroll
{
    public function __construct(
        public mixed $value,
        public string $pageName = 'page',
        public bool $cursor = false,
        public string $cursorKey = 'cursor'
    ) {}

    public function toMeta(): array
    {
        $meta = [
            'pageName' => $this->pageName,
            'type'     => $this->cursor ? 'cursor' : 'paged',
        ];

        $data = $this->value instanceof Closure ? ($this->value)() : $this->value;

        // Plain array paginator
        if (is_array($data)) {
            $inner = $data['meta'] ?? [];
            $links = $data['links'] ?? [];
            $base  = strtok($_SERVER['REQUEST_URI'] ?? '', '?');

            if (isset($inner['current_page'])) {
                $meta['currentPage'] = (int) $inner['current_page'];
            }
            if (isset($inner['last_page'])) {
                $meta['lastPage'] = (int) $inner['last_page'];
            }
            if (isset($inner['total'], $inner['per_page'])) {
                $meta['hasMore'] = ($inner['current_page'] * $inner['per_page']) < $inner['total'];
            }
            if (!empty($links['next'])) {
                $meta['nextPage'] = $base . '?' . ltrim($links['next'], '?');
            }
            if (!empty($links['prev'])) {
                $meta['previousPage'] = $base . '?' . ltrim($links['prev'], '?');
            }

            return $meta;
        }

        // Paginator object
        if (is_object($data)) {
            if (method_exists($data, 'currentPage'))      $meta['currentPage'] = $data->currentPage();
            if (method_exists($data, 'lastPage'))         $meta['lastPage'] = $data->lastPage();
            if (method_exists($data, 'hasMorePages'))     $meta['hasMore'] = $data->hasMorePages();
            if (method_exists($data, 'nextPageUrl'))      $meta['nextPage'] = $data->nextPageUrl();
            if (method_exists($data, 'previousPageUrl'))  $meta['previousPage'] = $data->previousPageUrl();
            if ($this->cursor && method_exists($data, 'nextCursor')) {
                $meta['cursor'] = $data->nextCursor()?->encode();
            }
        }

        return $meta;
    }
}

/**
 * Marker for cached-across-visits props.
 * The client caches the value and sends X-Inertia-Except-Once-Props
 * to skip re-resolution.
 */
final class Inertia_once
{
    public function __construct(
        public mixed $value,
        public ?string $customKey = null,
        public ?int $expiresAt = null
    ) {}
}