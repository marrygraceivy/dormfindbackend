<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Dorm_services
{
    private static function setting($key)
    {
        return config_item('dorm_' . strtolower($key)) ?: '';
    }
    public static function request($url, $data = null, $headers = [], $method = 'POST')
    {
        if (!function_exists('curl_init')) throw new RuntimeException('Enable the PHP cURL extension.', 503);
        $remaining = 20 - (microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)));
        if ($remaining < 1) throw new RuntimeException('External service took too long. Please try again shortly.', 502);
        $timeout = (int)(min(12, $remaining) * 1000);
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT_MS => min(3000, $timeout), CURLOPT_TIMEOUT_MS => $timeout,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTPHEADER => $headers]);
        if ($data !== null) curl_setopt($c, CURLOPT_POSTFIELDS, $data);
        $body = curl_exec($c); $status = curl_getinfo($c, CURLINFO_HTTP_CODE);
        if ($body === false) { curl_close($c); throw new RuntimeException('External service is unreachable. Please retry.', 502); }
        curl_close($c);
        if ($status < 200 || $status >= 300) throw new RuntimeException('External service rejected the request. Check its credentials and setup.', 502);
        return [json_decode($body, true) ?? [], $status];
    }
    public static function requireEnv(...$keys)
    {
        foreach ($keys as $key) if (!self::setting($key)) throw new RuntimeException("Configure {$key} in the backend .env first.", 503);
    }
    public static function captcha($token)
    {
        $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
        if (config_item('environment') === 'development' && self::setting('RECAPTCHA_DEV_BYPASS') === 'true' && $local) return;
        self::requireEnv('RECAPTCHA_SECRET_KEY', 'RECAPTCHA_SITE_KEY');
        if (!is_string($token) || !$token) throw new InvalidArgumentException('Please complete reCAPTCHA.', 422);
        [$r] = self::request('https://www.google.com/recaptcha/api/siteverify', http_build_query([
            'secret' => self::setting('RECAPTCHA_SECRET_KEY'), 'response' => $token, 'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]));
        $hosts = array_filter(array_map('trim', explode(',', self::setting('RECAPTCHA_ALLOWED_HOSTNAMES') ?: 'localhost,127.0.0.1')));
        if (empty($r['success']) || !in_array($r['hostname'] ?? '', $hosts, true)) throw new RuntimeException('reCAPTCHA verification failed. Try again.', 422);
    }
    public static function firebaseToken($uid)
    {
        self::requireEnv('FIREBASE_SERVICE_ACCOUNT');
        $path = self::setting('FIREBASE_SERVICE_ACCOUNT');
        if (!is_file($path)) throw new RuntimeException('Firebase service-account JSON was not found.', 503);
        $account = json_decode(file_get_contents($path), true);
        if (empty($account['client_email']) || empty($account['private_key'])) throw new RuntimeException('Invalid Firebase service-account JSON.', 503);
        $b64 = fn($v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $now = time();
        $payload = ['iss' => $account['client_email'], 'sub' => $account['client_email'],
            'aud' => 'https://identitytoolkit.googleapis.com/google.identity.identitytoolkit.v1.IdentityToolkit',
            'iat' => $now, 'exp' => $now + 3600, 'uid' => (string) $uid];
        $body = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode($payload));
        if (!openssl_sign($body, $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('Could not sign Firebase token.', 503);
        return $body . '.' . $b64($signature);
    }
    public static function upload($file, $propertyId)
    {
        self::requireEnv('CLOUDINARY_CLOUD_NAME', 'CLOUDINARY_API_KEY', 'CLOUDINARY_API_SECRET');
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new InvalidArgumentException('Choose a valid image file.', 422);
        if (!is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('Invalid upload.', 422);
        if (filesize($file['tmp_name']) > 8 * 1024 * 1024) throw new InvalidArgumentException('Images must be 8 MB or smaller.', 422);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || !getimagesize($file['tmp_name'])) throw new InvalidArgumentException('Use a JPG, PNG, or WebP image.', 422);
        $dimensions = getimagesize($file['tmp_name']);
        if ($dimensions[0] > 12000 || $dimensions[1] > 12000 || $dimensions[0] * $dimensions[1] > 40000000) throw new InvalidArgumentException('Image dimensions are too large.', 422);
        $params = ['public_id' => 'dormfind/property_' . (int)$propertyId . '/' . bin2hex(random_bytes(12)), 'timestamp' => time()];
        ksort($params);
        $signature = sha1(urldecode(http_build_query($params)) . self::setting('CLOUDINARY_API_SECRET'));
        [$r] = self::request('https://api.cloudinary.com/v1_1/' . rawurlencode(self::setting('CLOUDINARY_CLOUD_NAME')) . '/image/upload',
            $params + ['api_key' => self::setting('CLOUDINARY_API_KEY'), 'signature' => $signature,
                'file' => new CURLFile($file['tmp_name'], $mime, 'property-image')]);
        if (empty($r['secure_url']) || strlen($r['secure_url']) > 255) throw new RuntimeException('Cloudinary returned an invalid image URL.', 502);
        return $r;
    }
    public static function destroy($url, $propertyId)
    {
        $cloud = self::setting('CLOUDINARY_CLOUD_NAME');
        $prefix = 'https://res.cloudinary.com/' . $cloud . '/image/upload/';
        if (!$cloud || !str_starts_with($url, $prefix)) return; // Original local images are retained on disk.
        $path = substr($url, strlen($prefix));
        $path = preg_replace('~^v\d+/~', '', $path);
        $id = preg_replace('/\.[^.\/]+$/', '', $path);
        if (!str_starts_with($id, 'dormfind/property_' . (int)$propertyId . '/')) throw new RuntimeException('Image does not belong to this property.', 403);
        self::requireEnv('CLOUDINARY_API_KEY', 'CLOUDINARY_API_SECRET');
        $params = ['invalidate' => 'true', 'public_id' => $id, 'timestamp' => time()]; ksort($params);
        [$r] = self::request('https://api.cloudinary.com/v1_1/' . rawurlencode($cloud) . '/image/destroy',
            http_build_query($params + ['api_key' => self::setting('CLOUDINARY_API_KEY'), 'signature' => sha1(urldecode(http_build_query($params)) . self::setting('CLOUDINARY_API_SECRET'))]));
        if (!in_array($r['result'] ?? '', ['ok', 'not found'], true)) throw new RuntimeException('Cloudinary could not remove the image.', 502);
    }
    public static function email($to, $subject, $text)
    {
        if (!self::setting('SENDGRID_API_KEY') || !self::setting('SENDGRID_FROM_EMAIL')) return 'not_configured';
        try {
            self::request('https://api.sendgrid.com/v3/mail/send', json_encode([
                'personalizations' => [['to' => [['email' => $to]]]],
                'from' => ['email' => self::setting('SENDGRID_FROM_EMAIL'), 'name' => self::setting('SENDGRID_FROM_NAME') ?: 'DormFind'],
                'subject' => $subject, 'content' => [['type' => 'text/plain', 'value' => $text]]
            ]), ['Authorization: Bearer ' . self::setting('SENDGRID_API_KEY'), 'Content-Type: application/json']);
            return 'accepted';
        } catch (Throwable $e) { error_log('DormFind notification failed; check SendGrid setup.'); return 'failed'; }
    }
    public static function compatibility($a, $b)
    {
        $weights = ['sleep_schedule' => 30, 'cleanliness' => 25, 'noise_tolerance' => 20, 'pet_friendly' => 15, 'study_habits' => 10];
        $score = 0; $breakdown = [];
        foreach ($weights as $key => $weight) {
            $factor = (string)$a[$key] === (string)$b[$key] ? 1 : 0;
            if ($key === 'sleep_schedule' && !$factor && in_array('flexible', [$a[$key], $b[$key]], true)) $factor = .5;
            $points = $weight * $factor; $score += $points;
            $breakdown[$key] = ['points' => $points, 'maximum' => $weight];
        }
        return ['compatibility' => round($score, 1), 'breakdown' => $breakdown];
    }
    public static function distance($lat1, $lng1, $lat2, $lng2)
    {
        $dlat = deg2rad($lat2 - $lat1); $dlng = deg2rad($lng2 - $lng1);
        $a = sin($dlat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dlng / 2) ** 2;
        return 6371 * 2 * asin(sqrt(min(1, max(0, $a))));
    }
}
