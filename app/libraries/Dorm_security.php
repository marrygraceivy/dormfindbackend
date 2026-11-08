<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Dorm_csrf_exception extends RuntimeException {}

/** Browser session is used only for CSRF. Authentication remains short-lived bearer JWTs. */
class Dorm_security
{
    private function sessionToken(): string
    {
        $path = ROOT_DIR . 'runtime/session';
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Security storage is unavailable.', 503);
        }
        $secure = config_item('environment') === 'production';
        if (!session_start(['name' => 'dormfind_csrf', 'save_path' => $path,
            'use_strict_mode' => 1, 'use_only_cookies' => 1, 'cookie_httponly' => true,
            'cookie_secure' => $secure, 'cookie_samesite' => 'Lax', 'gc_maxlifetime' => 1800])) {
            throw new RuntimeException('Security session could not be started.', 503);
        }
        if (empty($_SESSION['csrf']) || ($_SESSION['last_activity'] ?? 0) < time() - 1800) {
            session_regenerate_id(true);
            $_SESSION = ['csrf' => bin2hex(random_bytes(32))];
        }
        $_SESSION['last_activity'] = time();
        $token = $_SESSION['csrf'];
        // Release the lock BEFORE queries, Cloudinary, reCAPTCHA or email calls.
        session_write_close();
        return $token;
    }

    public function token(): string { return $this->sessionToken(); }

    public function guard(): void
    {
        if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS'], true)) return;
        $origin = rtrim($_SERVER['HTTP_ORIGIN'] ?? '', '/');
        $allowed = config_item('security_headers')['cors']['allowed_origins'] ?? [];
        $app = parse_url(BASE_URL);
        $allowed[] = ($app['scheme'] ?? 'http') . '://' . ($app['host'] ?? 'localhost') . (isset($app['port']) ? ':' . $app['port'] : '');
        if ($origin !== '' && !in_array($origin, $allowed, true)) {
            throw new RuntimeException('Origin not allowed.', 403);
        }
        $expected = $this->sessionToken();
        $given = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($given) || !hash_equals($expected, $given)) {
            throw new Dorm_csrf_exception('Security token expired. Refresh the page and try again.', 403);
        }
    }
}
