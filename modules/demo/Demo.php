<?php
class Demo extends Trongate {

    private const PER_PAGE = 5;

    public function __construct(?string $module_name = null) {
        parent::__construct($module_name);

        // SHARED PROPS: available on every page as usePage().props.*
        // Closures are lazy: they only run when the page is built.
        Inertia::share([
            'app'  => ['name' => WEBSITE_NAME],
            'year' => fn() => date('Y'),
            'auth' => fn() => ['logged_in' => $this->is_admin()],
        ]);
    }

    private function is_admin(): bool {
        try {
            return $this->trongate_tokens->attempt_get_valid_token(1) !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    // ------------------------------------------------------------------
    // 1. LIST: props, search (GET query), pagination
    // ------------------------------------------------------------------
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

        Inertia::render('Demo/Index', [
            'records'  => $records,
            'total'    => $total,
            'page'     => $page,
            'per_page' => self::PER_PAGE,
            'filters'  => ['q' => $q],
        ]);
    }

    // ------------------------------------------------------------------
    // 2. CREATE: validation errors + flash + redirect
    // ------------------------------------------------------------------
    public function create(): void {
        $this->validation->set_rules('name', 'name', 'required|min_length[3]|max_length[100]');

        if ($this->validation->run() !== true) {   // also checks CSRF
            Inertia::back();                        // errors arrive as props.errors
        }

        $this->db->insert(['name' => post('name', true)], 'demo_table');
        set_flashdata('Record added');              // arrives as props.flash.message
        Inertia::redirect('demo');
    }

    // ------------------------------------------------------------------
    // 3. EDIT / UPDATE: PUT request
    // ------------------------------------------------------------------
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

    // ------------------------------------------------------------------
    // 4. DELETE: DELETE request
    // ------------------------------------------------------------------
    public function delete(): void {
        $this->validation->run();                   // CSRF gate
        $id = segment(3, 'int');

        if ($id > 0) {
            $this->db->delete($id, 'demo_table');
            set_flashdata('Record Deleted');
        }
        Inertia::redirect('demo');
    }

    // ------------------------------------------------------------------
    // 5. STATIC PAGE
    // ------------------------------------------------------------------
    public function about(): void {
        Inertia::render('Demo/About');
    }

    // ------------------------------------------------------------------
    // 6. PLAYGROUND: optional / always / defer / merge props
    // ------------------------------------------------------------------
    public function demo(): void {
        $per = 3;

        // Only honour ?page= on partial reloads (load more); a full page load starts at 1.
        $is_partial = isset($_SERVER['HTTP_X_INERTIA_PARTIAL_DATA']);
        $page   = $is_partial ? max(1, (int) ($_GET['page'] ?? 1)) : 1;
        $offset = ($page - 1) * $per;
        $total  = $this->db->count('demo_table');

        $items = $this->db->query_bind(
            'SELECT id, name FROM demo_table ORDER BY id ASC LIMIT :limit OFFSET :offset',
            ['limit' => $per, 'offset' => $offset], 'array'
        );

        Inertia::render('Demo/Demo', [
            // Included in EVERY response, even partial reloads that don't list it
            'server_time' => Inertia::always(date('H:i:s')),

            // MERGE: partial reloads append to the client's array
            'items'    => Inertia::merge($items),
            'page'     => $page,
            'has_more' => ($offset + $per) < $total,

            // OPTIONAL: never sent unless a partial reload asks for it
            'lazy_total'   => Inertia::optional(fn() => $this->db->count('demo_table')),
            'visible_info' => Inertia::optional(fn() => [
                'loaded_at' => date('H:i:s'),
                'note'      => 'Fetched only when this block scrolled into view.',
            ]),

            // DEFER: page renders first, then the client fetches these (one request per group)
            'stats' => Inertia::defer(function () {
                usleep(800000);
                return ['rows' => $this->db->count('demo_table'), 'generated' => date('H:i:s')];
            }),
            'slow_stats' => Inertia::defer(function () {
                usleep(1500000);
                return ['message' => 'Loaded from the "secondary" group.', 'generated' => date('H:i:s')];
            }, 'secondary'),
        ]);
    }

    // ------------------------------------------------------------------
    // 7. ERROR BAG: second form on the same page
    // ------------------------------------------------------------------
    public function subscribe(): void {
        $this->validation->set_rules('email', 'email', 'required|valid_email');

        if ($this->validation->run() !== true) {
            Inertia::back();   // errors arrive as props.errors.newsletter.email
        }

        set_flashdata('Subscribed: ' . post('email', true) . ' (demo only, nothing stored)');
        Inertia::back('demo/demo');
    }

    // ------------------------------------------------------------------
    // 8. POST via <Link method="post"> and router.post
    // ------------------------------------------------------------------
    public function ping(): void {
        $this->validation->run();                   // CSRF gate
        set_flashdata('Pong! POST + CSRF worked at ' . date('H:i:s'));
        Inertia::back('demo');
    }

    // ------------------------------------------------------------------
    // 9. HARD REDIRECTS: Inertia::location
    // ------------------------------------------------------------------
    public function go_external(): void {
        Inertia::location('https://trongate.io');   // client does window.location = ...
    }

    public function secret(): void {
        // Don't use trongate_security->make_sure_allowed() here: its 302 to the
        // login HTML would be shown in an Inertia modal. Check the token and hard-redirect.
        if ($this->trongate_tokens->attempt_get_valid_token(1) === false) {
            Inertia::location('tg-admin');
        }
        Inertia::render('Demo/Secret', ['message' => 'Only logged-in admins can see this.']);
    }
}