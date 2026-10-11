<?php
/**
 * Users Module — JSON API for the Next.js user area.
 *
 * Auth model: token-based. Login/register return a token that the client
 * sends as "Authorization: Bearer <token>" (or the "TrongateToken" header).
 * Tokens live in trongate_tokens and belong to trongate_users rows with
 * user_level_id = 2, so they can never unlock the admin panel (level 1).
 *
 * CSRF is intentionally not used here: no cookies/sessions authenticate
 * these requests, only the explicit token header.
 */
class Users extends Trongate {

    private const ALLOW_REGISTRATION = true;

    public function __construct(?string $module_name = null) {
        parent::__construct($module_name);
        

        if (REQUEST_TYPE === 'OPTIONS') {
            http_response_code(204);
            die();
        }
    }

    // ------------------------------------------------------------------
    // Public endpoints
    // ------------------------------------------------------------------

    /** POST api/user/register */
    public function register(): void {
        $this->guard(fn() => $this->handle_register());
    }

    /** POST api/user/login */
    public function login(): void {
        $this->guard(fn() => $this->handle_login());
    }

    /** GET api/user/me */
    public function me(): void {
        // sleep(20);
        $this->guard(fn() => $this->handle_me());
    }

    /** GET|POST api/user/logout */
    public function logout(): void {
        $this->guard(fn() => $this->handle_logout());
    }

    /** POST|PUT|PATCH api/user/profile */
    public function update_profile(): void {
        $this->guard(fn() => $this->handle_update_profile());
    }

    /** POST|PUT|PATCH api/user/password */
    public function change_password(): void {
        $this->guard(fn() => $this->handle_change_password());
    }

    // ------------------------------------------------------------------
    // Handlers (private: unreachable by URL)
    // ------------------------------------------------------------------

    private function handle_register(): void {
        $this->require_method(['POST']);

        if (!self::ALLOW_REGISTRATION) {
            $this->respond(403, 'error', 'registration_closed', 'Registration is currently closed.');
        }

        $body = $this->read_body();
        $username = $this->field($body, 'username');
        $email = strtolower($this->field($body, 'email'));
        $password = $this->field($body, 'password', false);
        $full_name = trim(strip_tags($this->field($body, 'full_name')));

        $errors = [];

        if (preg_match('/^[A-Za-z0-9_]{3,50}$/', $username) !== 1) {
            $errors['username'][] = 'Username must be 3-50 characters: letters, numbers and underscores only.';
        } elseif ($this->model->is_taken('username', $username)) {
            $errors['username'][] = 'That username is already taken.';
        }

        if ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'][] = 'Please enter a valid email address.';
        } elseif ($this->model->is_taken('email', $email)) {
            $errors['email'][] = 'That email address is already registered.';
        }

        if (strlen($password) < 6) {
            $errors['password'][] = 'Password must be at least 6 characters long.';
        } elseif (strlen($password) > 72) {
            $errors['password'][] = 'Password cannot be longer than 72 characters.';
        }

        if (array_key_exists('password_confirmation', $body)
            && !hash_equals($password, $this->field($body, 'password_confirmation', false))) {
            $errors['password_confirmation'][] = 'Passwords do not match.';
        }

        if (mb_strlen($full_name) > 100) {
            $errors['full_name'][] = 'Full name cannot exceed 100 characters.';
        }

        if (!empty($errors)) {
            $this->respond(422, 'error', 'validation_error', $errors);
        }

        try {
            $user_id = $this->model->create_user([
                'username'   => $username,
                'email'      => $email,
                'password'   => $this->model->hash_password($password),
                'full_name'  => $full_name,
                'active'     => 1,
                'created_at' => time(),
            ]);
        } catch (\PDOException $e) {
            // Race condition on the UNIQUE keys
            if ((string) $e->getCode() === '23000') {
                $this->respond(422, 'error', 'validation_error', ['username' => ['That username or email is already registered.']]);
            }
            throw $e;
        }

        $user = $this->model->get_user($user_id);
        $auth = $this->model->start_session($user);

        $this->respond(200, 'success', 'registration_success', 'Account created successfully.', $this->auth_payload($user, $auth));
    }

    private function handle_login(): void {
        $this->require_method(['POST']);

        $body = $this->read_body();
        $identifier = strtolower($this->field($body, 'username'));   // username OR email
        $password = $this->field($body, 'password', false);
        $ip = ip_address();

        if ($identifier === '' || $password === '' || strlen($identifier) > 255 || strlen($password) > 1024) {
            $this->respond(422, 'error', 'validation_error', 'Please enter your username/email and password.');
        }

        if (!$this->model->is_login_allowed($identifier, $ip)) {
            header('Retry-After: ' . $this->model->block_duration());
            $this->respond(429, 'error', 'too_many_attempts', 'Too many failed attempts. Please try again later.');
        }

        $user = $this->model->verify_credentials($identifier, $password);

        if ($user === false) {
            $this->model->record_failed_attempt($identifier, $ip);
            $this->respond(401, 'error', 'invalid_credentials', 'The username/email and/or password is incorrect.');
        }

        $this->model->clear_failed_attempts($identifier);
        $auth = $this->model->start_session($user);

        $this->respond(200, 'success', 'login_success', 'Login successful.', $this->auth_payload($user, $auth));
    }

    private function handle_me(): void {
        $this->require_method(['GET']);
        [, $user] = $this->authenticate();

        $this->respond(200, 'success', 'user_info', 'user-info', [
            'user' => $this->model->public_user($user),
        ]);
    }

    private function handle_logout(): void {
        $this->require_method(['GET', 'POST']);

        $token = $this->bearer_token();
        if ($token !== '') {
            $this->model->delete_token($token);
        }

        $this->respond(200, 'success', 'logout_success', 'Logged out.');
    }

    private function handle_update_profile(): void {
        $this->require_method(['POST', 'PUT', 'PATCH']);
        [, $user] = $this->authenticate();

        $body = $this->read_body();
        $update = [];
        $errors = [];

        if (array_key_exists('email', $body)) {
            $email = strtolower($this->field($body, 'email'));
            if ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors['email'][] = 'Please enter a valid email address.';
            } elseif ($this->model->is_taken('email', $email, (int) $user->id)) {
                $errors['email'][] = 'That email address is already registered.';
            } else {
                $update['email'] = $email;
            }
        }

        if (array_key_exists('full_name', $body)) {
            $full_name = trim(strip_tags($this->field($body, 'full_name')));
            if (mb_strlen($full_name) > 100) {
                $errors['full_name'][] = 'Full name cannot exceed 100 characters.';
            } else {
                $update['full_name'] = $full_name;
            }
        }

        if (!empty($errors)) {
            $this->respond(422, 'error', 'validation_error', $errors);
        }

        if (!empty($update)) {
            $this->model->update_user((int) $user->id, $update);
        }

        $fresh = $this->model->get_user((int) $user->id);
        $this->respond(200, 'success', 'profile_updated', 'Profile updated.', [
            'user' => $this->model->public_user($fresh),
        ]);
    }

    private function handle_change_password(): void {
        $this->require_method(['POST', 'PUT', 'PATCH']);
        [$token, $user] = $this->authenticate();

        $body = $this->read_body();
        $current = $this->field($body, 'current_password', false);
        $new = $this->field($body, 'new_password', false);

        if (!password_verify($current, $user->password)) {
            $this->respond(422, 'error', 'validation_error', ['current_password' => ['Your current password is incorrect.']]);
        }

        if (strlen($new) < 8 || strlen($new) > 72) {
            $this->respond(422, 'error', 'validation_error', ['new_password' => ['New password must be 8-72 characters long.']]);
        }

        $this->model->update_user((int) $user->id, ['password' => $this->model->hash_password($new)]);
        // Sign out every other device
        $this->model->delete_other_tokens((int) $user->trongate_user_id, $token);

        $this->respond(200, 'success', 'password_updated', 'Password updated.');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function authenticate(): array {
        $token = $this->bearer_token();
        $user = ($token === '') ? false : $this->model->get_user_by_token($token);

        if ($user === false) {
            $this->respond(401, 'error', 'unauthenticated', 'Unauthenticated.');
        }

        return [$token, $user];
    }

    /**
     * Reads the token from "Authorization: Bearer x" or the "TrongateToken" header.
     * Tokens are 32 alphanumeric chars (make_rand_str).
     */
    private function bearer_token(): string {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

        if ($header === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strtolower($name) === 'authorization') {
                    $header = $value;
                    break;
                }
            }
        }

        if (is_string($header) && preg_match('/^Bearer\s+([A-Za-z0-9]{32})$/i', trim($header), $m) === 1) {
            return $m[1];
        }

        $alt = $_SERVER['HTTP_TRONGATETOKEN'] ?? '';
        if (is_string($alt) && preg_match('/^[A-Za-z0-9]{32}$/', $alt) === 1) {
            return $alt;
        }

        return '';
    }

    private function auth_payload(object $user, array $auth): array {
        return [
            'access_token' => $auth['token'],
            'token_type'   => 'Bearer',
            'expires_at'   => $auth['expires_at'],
            'user'         => $this->model->public_user($user),
        ];
    }

    private function read_body(): array {
        try {
            $data = post(); // whole payload, no whitespace cleanup (passwords!)
        } catch (\Exception $e) {
            $this->respond(400, 'error', 'invalid_json', 'The request body is not valid JSON.');
            return [];
        }
        return is_array($data) ? $data : [];
    }

    private function field(array $body, string $key, bool $trim = true): string {
        $value = $body[$key] ?? '';
        if (!is_string($value)) {
            return '';
        }
        return $trim ? trim($value) : $value;
    }

    private function require_method(array $allowed): void {
        if (!in_array(REQUEST_TYPE, $allowed, true)) {
            header('Allow: ' . implode(', ', $allowed));
            $this->respond(405, 'error', 'method_not_allowed', 'Method not allowed.');
        }
    }

    private function guard(callable $fn): void {
        try {
            $fn();
        } catch (\Throwable $e) {
            error_log('[users] ' . $e->getMessage());
            $detail = (strtolower(ENV) === 'dev') ? $e->getMessage() : 'Something went wrong. Please try again.';
            $this->respond(500, 'error', 'server_error', $detail);
        }
    }
}