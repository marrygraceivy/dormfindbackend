<?php
class Deployment_security
{
    public function up()
    {
        $db = lava_instance()->db;
        $db->raw('CREATE TABLE IF NOT EXISTS security_rate_limits (bucket CHAR(64) PRIMARY KEY,hits INT UNSIGNED NOT NULL,reset_at BIGINT UNSIGNED NOT NULL,INDEX (reset_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $hasIndex = $db->raw("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='login_attempts' AND INDEX_NAME='login_attempted_at'")->fetchColumn();
        if (!$hasIndex) $db->raw('ALTER TABLE login_attempts ADD INDEX login_attempted_at (attempted_at)');
        $hasActivity = $db->raw("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='refresh_tokens' AND COLUMN_NAME='last_used_at'")->fetchColumn();
        if (!$hasActivity) $db->raw('ALTER TABLE refresh_tokens ADD last_used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
        // Keep imported accounts usable without accepting plaintext passwords at login.
        $last = 0;
        do {
            $rows = $db->raw('SELECT user_id,password FROM users WHERE user_id>? ORDER BY user_id LIMIT 100', [$last])->fetchAll();
            foreach ($rows as $u) {
                $last = (int)$u['user_id'];
                if (empty(password_get_info($u['password'])['algo'])) {
                    $db->table('users')->where('user_id', $last)->where('password', $u['password'])
                        ->update(['password' => password_hash($u['password'], PASSWORD_DEFAULT)]);
                }
            }
        } while (count($rows) === 100);
    }
    public function down() { /* Preserve security records and password hashes. */ }
}
