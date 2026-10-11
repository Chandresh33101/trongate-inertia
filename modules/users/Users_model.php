<?php
/**
 * Users_model — data layer for the users JSON API.
 *
 * Table/column names are fixed literals (never user input); every
 * user-supplied value is bound as a parameter.
 */
class Users_model extends Model {

    private const USER_LEVEL_ID       = 2;        // trongate_user_levels.id for "user"
    private const MAX_FAILED_ATTEMPTS = 5;
    private const BLOCK_DURATION      = 900;      // seconds
    private const PASSWORD_COST       = 11;
    private const TOKEN_LIFESPAN      = 604800;   // 7 days (keep in sync with Next.js COOKIE_DAYS)

    // ------------------------------------------------------------------
    // Passwords / credentials
    // ------------------------------------------------------------------

    public function hash_password(string $password): string {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => self::PASSWORD_COST]);
    }

    public function verify_credentials(string $identifier, string $password): object|bool {
        $user = $this->find_by_identifier($identifier);

        if ($user === false || (int) $user->active !== 1) {
            // Spend comparable time so response timing doesn't reveal whether the account exists
            $this->hash_password($password);
            return false;
        }

        if (!password_verify($password, $user->password)) {
            return false;
        }

        if (password_needs_rehash($user->password, PASSWORD_BCRYPT, ['cost' => self::PASSWORD_COST])) {
            $this->update_user((int) $user->id, ['password' => $this->hash_password($password)]);
        }

        return $user;
    }

    public function find_by_identifier(string $identifier): object|bool {
        $sql = 'SELECT * FROM `users` WHERE `username` = :username OR `email` = :email LIMIT 1';
        $rows = $this->db->query_bind($sql, [
            'username' => $identifier,
            'email'    => strtolower($identifier),
        ], 'object');

        return !empty($rows) ? $rows[0] : false;
    }

    public function get_user(int $id): object|bool {
        $rows = $this->db->query_bind('SELECT * FROM `users` WHERE `id` = :id LIMIT 1', ['id' => $id], 'object');
        return !empty($rows) ? $rows[0] : false;
    }

    public function is_taken(string $field, string $value, int $exclude_id = 0): bool {
        if (!in_array($field, ['username', 'email'], true)) {
            throw new InvalidArgumentException('Invalid field.');
        }

        $sql = 'SELECT `id` FROM `users` WHERE `' . $field . '` = :value AND `id` != :id LIMIT 1';
        $rows = $this->db->query_bind($sql, ['value' => $value, 'id' => $exclude_id], 'object');
        return !empty($rows);
    }

    // ------------------------------------------------------------------
    // Create / update
    // ------------------------------------------------------------------

    /**
     * Creates the trongate_users row (level 2) and the users row.
     * If the second insert fails the first is rolled back manually.
     */
    public function create_user(array $data): int {
        $trongate_user_id = $this->db->insert([
            'code'          => make_rand_str(32),
            'user_level_id' => self::USER_LEVEL_ID,
        ], 'trongate_users');

        try {
            $data['trongate_user_id'] = $trongate_user_id;
            return $this->db->insert($data, 'users');
        } catch (\Throwable $e) {
            $this->db->delete($trongate_user_id, 'trongate_users');
            throw $e;
        }
    }

    public function update_user(int $id, array $data): void {
        $this->db->update($id, $data, 'users');
    }

    public function public_user(object $user): array {
        return [
            'id'         => (int) $user->id,
            'username'   => $user->username,
            'email'      => $user->email,
            'full_name'  => $user->full_name,
            'created_at' => (int) $user->created_at,
        ];
    }

    // ------------------------------------------------------------------
    // Tokens
    // ------------------------------------------------------------------

    /** Issues a fresh token and records the login. */
    public function start_session(object $user): array {
        $this->db->query_bind('DELETE FROM `trongate_tokens` WHERE `expiry_date` < :now', ['now' => time()]);

        $token = make_rand_str(32);
        $expires_at = time() + self::TOKEN_LIFESPAN;

        $this->db->insert([
            'user_id'     => (int) $user->trongate_user_id,
            'token'       => $token,
            'expiry_date' => $expires_at,
            'code'        => '0',
        ], 'trongate_tokens');

        $this->db->query_bind(
            'UPDATE `users` SET `num_logins` = `num_logins` + 1, `last_login` = :now WHERE `id` = :id',
            ['now' => time(), 'id' => (int) $user->id]
        );

        return ['token' => $token, 'expires_at' => $expires_at];
    }

    /** Only returns an ACTIVE user whose token is unexpired AND belongs to user level 2. */
    public function get_user_by_token(string $token): object|bool {
        if (preg_match('/^[A-Za-z0-9]{32}$/', $token) !== 1) {
            return false;
        }

        $sql = 'SELECT m.*
                FROM `trongate_tokens` t
                INNER JOIN `trongate_users` u ON u.`id` = t.`user_id`
                INNER JOIN `users` m ON m.`trongate_user_id` = u.`id`
                WHERE t.`token` = :token
                  AND t.`expiry_date` > :now
                  AND u.`user_level_id` = :level
                  AND m.`active` = 1
                LIMIT 1';

        $rows = $this->db->query_bind($sql, [
            'token' => $token,
            'now'   => time(),
            'level' => self::USER_LEVEL_ID,
        ], 'object');

        return !empty($rows) ? $rows[0] : false;
    }

    public function delete_token(string $token): void {
        $this->db->query_bind('DELETE FROM `trongate_tokens` WHERE `token` = :token', ['token' => $token]);
    }

    public function delete_other_tokens(int $trongate_user_id, string $keep_token): void {
        $this->db->query_bind(
            'DELETE FROM `trongate_tokens` WHERE `user_id` = :uid AND `token` != :token',
            ['uid' => $trongate_user_id, 'token' => $keep_token]
        );
    }

    // ------------------------------------------------------------------
    // Rate limiting (re-uses the login module's login_attempts table)
    // ------------------------------------------------------------------

    public function block_duration(): int {
        return self::BLOCK_DURATION;
    }

    public function is_login_allowed(string $identifier, string $ip): bool {
        $cutoff = time() - self::BLOCK_DURATION;

        $this->db->query_bind(
            'DELETE FROM `login_attempts` WHERE `target_table` = :t AND `attempted_at` < :c',
            ['t' => 'users', 'c' => $cutoff]
        );

        foreach (['identifier' => $identifier, 'ip_address' => $ip] as $column => $value) {
            $rows = $this->db->query_bind(
                'SELECT COUNT(*) AS cnt FROM `login_attempts`
                 WHERE `target_table` = :t AND `' . $column . '` = :v AND `attempted_at` > :c',
                ['t' => 'users', 'v' => $value, 'c' => $cutoff],
                'object'
            );

            if (!empty($rows) && (int) $rows[0]->cnt >= self::MAX_FAILED_ATTEMPTS) {
                return false;
            }
        }

        return true;
    }

    public function record_failed_attempt(string $identifier, string $ip): void {
        $this->db->insert([
            'target_table' => 'users',
            'identifier'   => $identifier,
            'ip_address'   => $ip,
            'attempted_at' => time(),
        ], 'login_attempts');
    }

    public function clear_failed_attempts(string $identifier): void {
        $this->db->query_bind(
            'DELETE FROM `login_attempts` WHERE `target_table` = :t AND `identifier` = :i',
            ['t' => 'users', 'i' => $identifier]
        );
    }
}