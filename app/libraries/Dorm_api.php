<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
// Schema adapter: the original LavaLust helper assumes users.id; DormFind uses users.user_id.
class Dorm_api extends Api
{
    private ?array $cachedUser = null;
    public function rate_limit($key = null, $requests = null, $seconds = null)
    {
        if (!$this->rate_limit_enabled) return;
        $requests = $requests ?? $this->rate_limit_requests;
        $seconds = $seconds ?? $this->rate_limit_seconds;
        $identity = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $payload = $this->validate_jwt($this->get_bearer_token() ?? '');
        if ($payload) $identity = 'user:' . $payload['sub'];
        $dir = ROOT_DIR . 'runtime/cache';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Request limiter unavailable.', 503);
        $file = fopen($dir . '/rate_' . hash('sha256', $key ?? $identity) . '.json', 'c+');
        if (!$file) throw new RuntimeException('Request limiter unavailable.', 503);
        try {
            if (!flock($file, LOCK_EX | LOCK_NB)) throw new RuntimeException('Too many simultaneous requests. Please retry.', 429);
            $state = json_decode(stream_get_contents($file), true);
            $now = time();
            if (!is_array($state) || ($state['reset'] ?? 0) <= $now) $state = ['hits' => 0, 'reset' => $now + $seconds];
            if ($state['hits'] >= $requests) {
                header('Retry-After: ' . max(1, $state['reset'] - $now));
                throw new RuntimeException('Too many requests. Please try again later.', 429);
            }
            ++$state['hits']; rewind($file); ftruncate($file, 0); fwrite($file, json_encode($state)); fflush($file);
            header('X-RateLimit-Limit: ' . $requests);
            header('X-RateLimit-Remaining: ' . ($requests - $state['hits']));
            header('X-RateLimit-Reset: ' . $state['reset']);
        } finally { flock($file, LOCK_UN); fclose($file); }
    }
    public function user()
    {
        if ($this->cachedUser !== null) return $this->cachedUser;
        $payload = $this->validate_jwt($this->get_bearer_token() ?? '');
        if (!$payload || empty($payload['sid'])) throw new RuntimeException('Please log in again.', 401);
        $db = lava_instance()->db;
        $u = $db->raw('SELECT u.user_id,u.role,u.first_name,u.last_name,u.email,u.phone,u.profile_image_url,u.is_verified,u.is_banned,rt.last_used_at FROM users u JOIN refresh_tokens rt ON rt.user_id=u.user_id WHERE u.user_id=? AND rt.jti=? AND rt.expires_at>NOW() AND rt.last_used_at>DATE_SUB(NOW(), INTERVAL 30 MINUTE)', [$payload['sub'], $payload['sid']])->fetch();
        if (!$u) throw new RuntimeException('Session expired. Please log in again.', 401);
        if ($u['is_banned']) throw new RuntimeException('Account disabled. Contact the administrator.', 403);
        if (strtotime($u['last_used_at']) < time() - 60) $db->raw('UPDATE refresh_tokens SET last_used_at=NOW() WHERE user_id=? AND jti=?', [$u['user_id'], $payload['sid']]);
        unset($u['last_used_at']);
        return $this->cachedUser = $u;
    }
    public function refreshForSchema($token)
    {
        $payload = $this->validate_jwt($token, 'refresh');
        if (!$payload) throw new RuntimeException('Session expired. Please log in again.', 401);
        $db = lava_instance()->db;
        $hash = hash_hmac('sha256', $token, getenv('REFRESH_TOKEN_KEY'));
        $db->transaction();
        try {
            $found = $db->raw('SELECT id FROM refresh_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW() AND last_used_at>DATE_SUB(NOW(),INTERVAL 30 MINUTE) FOR UPDATE', [$payload['sub'], $hash])->fetch();
            if (!$found) throw new RuntimeException('Session expired. Please log in again.', 401);
            $u = $db->raw('SELECT user_id, role FROM users WHERE user_id = ? AND is_banned=0', [$payload['sub']])->fetch();
            if (!$u) throw new RuntimeException('Account not found.', 401);
            $db->raw('DELETE FROM refresh_tokens WHERE id = ?', [$found['id']]);
            $tokens = $this->issue_tokens(['id' => $u['user_id'], 'role' => $u['role']]);
            $db->commit(); return $tokens;
        } catch (Throwable $e) { $db->roll_back(); throw $e; }
    }
}
