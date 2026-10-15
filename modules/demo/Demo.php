<?php
/**
 * Full-featured Inertia adapter showcase.
 * Demonstrates: CRUD, merge props, infinite scroll, once props,
 * clear history, fragment redirects, pagination, title/meta.
 */
class Demo extends Trongate {

    private const PER_PAGE = 5;

    public function __construct(?string $module_name = null) {
        parent::__construct($module_name);

        // ── Shared props (available on every page) ──────────────────────────────────────
        Inertia::share([
            'app'  => ['name' => WEBSITE_NAME],
            'year' => fn() => date('Y'),
            'auth' => fn() => ['logged_in' => $this->is_admin()],
        ]);

        // ── ONCE PROPS：Cross-page caching; parsed only once. ─────────────────────────
        Inertia::share('site_config', Inertia::once(
            fn() => [
                'timezone'    => date_default_timezone_get(),
                'generated'   => date('Y-m-d H:i:s'),
                // 'expensive'   => $this->computeExpensiveConfig(),
            ],
            'site-config-v1',              // custom key：Cross-page sharing
            time() + 3600                   //  Expires in hour
        ));
    }

    private function computeExpensiveConfig(): array {
        // Simulating computationally expensive operations (real-world scenarios might involve complex permission or configuration queries)
        usleep(300000);
        return ['feature_flags' => ['beta' => true, 'dark_mode' => false]];
    }

    private function is_admin(): bool {
        try {
            return $this->trongate_tokens->attempt_get_valid_token(1) !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // 1. LIST：基础 CRUD 列表 + 搜索 + 分页
    // ═══════════════════════════════════════════════════════════════════
    public function index(): void {
        $q    = trim((string) ($_GET['q'] ?? ''));
        $like = '%' . addcslashes($q, '%_\\') . '%';

        $row   = $this->db->query_bind(
            'SELECT COUNT(*) AS total FROM demo_table WHERE name LIKE :q',
            ['q' => $like], 'array'
        );
        $total = (int) ($row[0]['total'] ?? 0);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min($pages, max(1, (int) ($_GET['page'] ?? 1)));

        $records = $this->db->query_bind(
            'SELECT id, name FROM demo_table WHERE name LIKE :q
             ORDER BY id ASC LIMIT :limit OFFSET :offset',
            ['q' => $like, 'limit' => self::PER_PAGE, 'offset' => ($page - 1) * self::PER_PAGE],
            'array'
        );

        Inertia::title('Demo Records');
        Inertia::render('Demo/Index', [
            'records'  => $records,
            'total'    => $total,
            'page'     => $page,
            'per_page' => self::PER_PAGE,
            'filters'  => ['q' => $q],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2. CREATE
    // ═══════════════════════════════════════════════════════════════════
    public function create(): void {
        $this->validation->set_rules('name', 'name', 'required|min_length[3]|max_length[100]');

        if ($this->validation->run() !== true) {
            Inertia::back();
        }

        $this->db->insert(['name' => post('name', true)], 'demo_table');
        set_flashdata('Record added');
        Inertia::redirect('demo');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3. EDIT / UPDATE
    // ═══════════════════════════════════════════════════════════════════
    public function edit(): void {
        $record = $this->db->get_where(segment(3, 'int'), 'demo_table');

        if ($record === false) {
            set_flashdata('Record not found');
            Inertia::redirect('demo');
        }

        Inertia::render('Demo/Edit', [
            'record' => ['id' => (int) $record->id, 'name' => $record->name],
        ]);
    }

    public function update(): void {
        $id = segment(3, 'int');

        $this->validation->set_rules('name', 'name', 'required|min_length[3]|max_length[100]');
        if ($this->validation->run() !== true) {
            Inertia::back();
        }

        if ($this->db->get_where($id, 'demo_table') === false) {
            set_flashdata('Record not found');
            Inertia::redirect('demo/edit/' . $id);
        }

        $this->db->update($id, ['name' => post('name', true)], 'demo_table');
        set_flashdata('Record updated');
        Inertia::redirect('demo');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4. DELETE
    // ═══════════════════════════════════════════════════════════════════
    public function delete(): void {
        $this->validation->run();
        $id = segment(3, 'int');

        if ($id > 0) {
            $this->db->delete($id, 'demo_table');
            set_flashdata('Record Deleted');
        }
        Inertia::redirect('demo');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 5. STATIC PAGE
    // ═══════════════════════════════════════════════════════════════════
    public function about(): void {
        Inertia::title('About');
        Inertia::render('Demo/About');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 6. PLAYGROUND：展示所有 Prop 类型
    // ═══════════════════════════════════════════════════════════════════
    public function demo(): void {
        $per = 3;
        $is_partial = isset($_SERVER['HTTP_X_INERTIA_PARTIAL_DATA']);
        $page   = $is_partial ? max(1, (int) ($_GET['page'] ?? 1)) : 1;
        $offset = ($page - 1) * $per;
        $page_helper = max(1, (int) ($_GET['page'] ?? 1));
        $offset_helper = ($page_helper - 1) * $per;
        $total  = $this->db->count('demo_table');

        $items = $this->db->query_bind(
            'SELECT id, name FROM demo_table ORDER BY id ASC LIMIT :limit OFFSET :offset',
            ['limit' => $per, 'offset' => $offset], 'array'
        );

        Inertia::render('Demo/Demo', [
            // ── ALWAYS：每次响应都包含 ───────────────────────────────────
            'server_time' => Inertia::always(date('H:i:s')),

            // ── MERGE：部分重载时追加到客户端数组 ──────────────────────
            'items'    => Inertia::merge($items),
            'page'     => $page,
            'has_more' => ($offset + $per) < $total,

            // ── PREPEND：部分重载时前插 ─────────────────────────────────
            'recent_activity' => Inertia::prepend([
                ['id' => uniqid(), 'action' => 'Demo page visited', 'time' => date('H:i:s')],
            ]),

            // ── DEEP MERGE：嵌套对象深度合并 ────────────────────────────
            'user_prefs' => Inertia::deepMerge([
                'theme'    => 'dark',
                'notifications' => ['email' => true, 'sms' => false],
            ]),

            // ── MATCH ON：按 id 去重 ────────────────────────────────────
            'notifications' => Inertia::matchOn('id', Inertia::merge([
                ['id' => 1, 'text' => 'Welcome back!'],
                ['id' => 2, 'text' => 'New message'],
            ])),

            // ── OPTIONAL / LAZY：仅在部分重载请求时发送 ─────────────────
            'lazy_total'   => Inertia::lazy(fn() => $this->db->count('demo_table')),
            'visible_info' => Inertia::optional(fn() => [
                'loaded_at' => date('H:i:s'),
                'note'      => 'Fetched only when this block scrolled into view.',
            ]),

            // ── DEFER：页面先渲染，客户端随后请求 ──────────────────────
            'stats' => Inertia::defer(function () {
                usleep(800000);
                return ['rows' => $this->db->count('demo_table'), 'generated' => date('H:i:s')];
            }),
            'slow_stats' => Inertia::defer(function () {
                usleep(1500000);
                return ['message' => 'Loaded from the "secondary" group.', 'generated' => date('H:i:s')];
            }, 'secondary'),

            // ── PAGINATION HELPER ───────────────────────────────────────
            'paginated' => Inertia::paginate([
                'data' => $items,
                'meta' => [
                    'current_page' => $page_helper,
                    'last_page'    => (int) ceil($total / $per),
                    'per_page'     => $per,
                    'total'        => $total,
                ],
                'links' => [
                    'prev' => $page_helper > 1 ? '?page=' . ($page_helper - 1) : null,
                    'next' => ($offset_helper + $per) < $total ? '?page=' . ($page_helper + 1) : null,
                ],
            ]),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 7. INFINITE SCROLL 演示
    // ═══════════════════════════════════════════════════════════════════
    public function scroll_demo(): void {
        $per = 5;
        $is_partial = isset($_SERVER['HTTP_X_INERTIA_PARTIAL_DATA']);
        $page   = $is_partial ? max(1, (int) ($_GET['page'] ?? 1)) : 1;
        $offset = ($page - 1) * $per;
        $total = $this->db->count('demo_table');
        $lastPage = max(1, (int) ceil($total / $per));

        $items = $this->db->query_bind(
            'SELECT id, name FROM demo_table ORDER BY id ASC LIMIT :limit OFFSET :offset',
            ['limit' => $per, 'offset' => $offset], 'array'
        );

        Inertia::title('Infinite Scroll Demo');
        Inertia::render('Demo/ScrollDemo', [
            'users' => Inertia::scroll(fn() => [
                'data' => $items,
                'meta' => [
                    'current_page' => $page,
                    'last_page'    => $lastPage,
                    'per_page'     => $per,
                    'total'        => $total,
                ],
                'links' => [
                    'next' => $page < $lastPage ? '?page=' . ($page + 1) : null,
                    'prev' => $page > 1 ? '?page=' . ($page - 1) : null,
                ],
            ], 'page'),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 8. CLEAR HISTORY 演示
    // ═══════════════════════════════════════════════════════════════════
    public function logout_demo(): void {
        // 清除浏览器历史加密密钥 → 之前的敏感页面历史无法回退查看
        Inertia::clearHistory();
        Inertia::title('Logged Out');
        Inertia::render('Demo/LoggedOut', [
            'message' => 'History cleared. Back button will not reveal previous pages.',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 9. FRAGMENT-PRESERVING REDIRECT 演示
    // ═══════════════════════════════════════════════════════════════════
    public function redirect_with_fragment(): void {
        // 保留当前 URL 的 #hash，重定向到新页面
        Inertia::redirect('demo/about', preserveFragment: true);
    }

    public function go_external(): void {
        Inertia::location('https://trongate.io', preserveFragment: true);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 10. ERROR BAG 演示
    // ═══════════════════════════════════════════════════════════════════
    public function subscribe(): void {
        $this->validation->set_rules('email', 'email', 'required|valid_email');

        if ($this->validation->run() !== true) {
            Inertia::back();
        }

        set_flashdata('Subscribed: ' . post('email', true) . ' (demo only)');
        Inertia::back('demo/demo');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 11. POST + CSRF 演示
    // ═══════════════════════════════════════════════════════════════════
    public function ping(): void {
        $this->validation->run();
        set_flashdata('Pong! POST + CSRF worked at ' . date('H:i:s'));
        Inertia::back('demo');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 12. TITLE + META 演示
    // ═══════════════════════════════════════════════════════════════════
    public function secret(): void {
        if ($this->trongate_tokens->attempt_get_valid_token(1) === false) {
            Inertia::location('tg-admin');
        }
        Inertia::title('Secret Area');
        Inertia::meta([
            'description' => 'Admins Secret Area',
            'og:title'    => 'Admin Logged In Area',
            'og:type'     => 'website',
        ]);
        Inertia::render('Demo/Secret', ['message' => 'Only logged-in admins can see this.']);
    }
}
