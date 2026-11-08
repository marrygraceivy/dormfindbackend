<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class DormController extends Controller
{
    private function run($fn, $database = true)
    {
        try {
            $this->call->library('dorm_security');
            $this->dorm_security->guard();
            $this->call->library('api'); // Parent of Dorm_api, loaded by LavaLust.
            $this->call->library('dorm_api');
            $this->call->library('dorm_services');
            $this->dorm_api->rate_limit();
            if ($database) {
                $this->call->library('database');
                $this->call->model('Dorm_model');
                $this->throttleSensitive();
            }
            $this->dorm_api->respond(['data' => $fn()]);
        } catch (Throwable $e) {
            $status = (int)$e->getCode();
            if (!in_array($status, [400,401,403,404,409,413,422,429,502,503], true)) $status = 500;
            http_response_code($status); header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            if ($e instanceof mysqli_sql_exception) {
                error_log('DormFind database query failed (code ' . $e->getCode() . ').');
                if (in_array($e->getCode(), [1205, 1213, 2006, 2013], true)) $status = 503;
            } elseif ($status === 500) error_log('DormFind API failure: ' . get_class($e));
            http_response_code($status);
            if ($status === 503) header('Retry-After: 5');
            echo json_encode(['error' => $status === 500 ? 'Request could not be completed. Please try again later.' : ($e instanceof mysqli_sql_exception ? 'Database is busy. Please try again shortly.' : $e->getMessage()), 'code' => $e instanceof Dorm_csrf_exception ? 'csrf_failed' : null]);
        }
    }
    private function body() { return $this->dorm_api->body(); }
    private function user($landlord = false)
    {
        $u = $this->dorm_api->user();
        if ($landlord && !$this->isOwner($u)) throw new RuntimeException('A landlord account is required.', 403);
        return $u;
    }
    private function isOwner($u) { return in_array(strtolower($u['role']), ['property owner','building owner','property manager','landlord'], true); }
    private function admin() { $u=$this->user(); if (strtolower($u['role'])!=='admin') throw new RuntimeException('Administrator only.',403); return $u; }
    private function verifiedOwner() { $u=$this->user(true); if (!(int)$u['is_verified']) throw new RuntimeException('Wait for admin approval before posting properties.',403); return $u; }
    private function one($sql, $args = []) { return $this->Dorm_model->one($sql, $args); }
    private function all($sql, $args = []) { return $this->Dorm_model->all($sql, $args); }
    private function required($b, $key, $max = 255)
    {
        $v = $b[$key] ?? null;
        if (!is_string($v) || !trim($v) || mb_strlen(trim($v)) > $max) throw new InvalidArgumentException("Enter a valid {$key} (maximum {$max} characters).", 422);
        return trim($v);
    }
    private function owned($id)
    {
        $u = $this->user(true);
        $p = $this->Dorm_model->findRow('properties', ['property_id' => $id, 'owner_id' => $u['user_id']]);
        if (!$p) throw new RuntimeException('Property not found or not owned by you.', 404);
        return $p;
    }
    private function imageUrl($url)
    {
        if (preg_match('~^https://~', $url)) return $url;
        $url = basename($url);
        return BASE_URL . (str_starts_with($url, 'dorm') ? 'dormpictures/' : 'propertypic/') . rawurlencode($url);
    }
    private function decorate($p)
    {
        $p['images'] = $this->Dorm_model->rows('property_images', ['property_id' => $p['property_id']]);
        usort($p['images'], fn($a, $b) => ((int)$b['is_primary'] <=> (int)$a['is_primary']) ?: ((int)$a['image_id'] <=> (int)$b['image_id']));
        foreach ($p['images'] as &$i) $i['image_url'] = $this->imageUrl($i['image_url']);
        $p['amenities'] = $this->Dorm_model->rows('property_amenities', ['property_id' => $p['property_id']], ['amenity', 'ASC']);
        $p['amenities'] = array_column($p['amenities'], 'amenity');
        return $p;
    }
    private function decorateMany(array $properties)
    {
        if (!$properties) return [];
        $children = $this->Dorm_model->propertyChildren(array_column($properties, 'property_id'));
        foreach ($properties as &$p) {
            $p['images'] = $children['images'][$p['property_id']] ?? [];
            usort($p['images'], fn($a, $b) => ((int)$b['is_primary'] <=> (int)$a['is_primary']) ?: ((int)$a['image_id'] <=> (int)$b['image_id']));
            foreach ($p['images'] as &$image) $image['image_url'] = $this->imageUrl($image['image_url']);
            unset($image);
            $p['amenities'] = $children['amenities'][$p['property_id']] ?? [];
            sort($p['amenities']);
        }
        unset($p);
        return $properties;
    }
    private function contractTerms($i, array $amenities)
    {
        $rules=[
            'Wi-Fi'=>'Wi-Fi access is available as listed. The tenant must not use it for unlawful activity and must follow fair-use limits.',
            'Water'=>'Water service is available as listed. The tenant must avoid waste and follow any disclosed usage or billing arrangement.',
            'Electricity'=>'Electricity is available as listed. The tenant must use appliances safely and follow any disclosed metering or billing arrangement.',
            'Kitchen'=>'Kitchen access is available. The tenant must clean after use and follow fire and food-safety rules.',
            'Laundry'=>'Laundry access is available. The tenant must follow the posted schedule and equipment instructions.',
            'Parking'=>'Parking is available only in the assigned area and does not transfer to another person without the landlord\'s approval.',
            'Air conditioning'=>'Air conditioning is available as listed. The tenant must use it responsibly and follow any disclosed electricity arrangement.',
            'Security'=>'Security facilities are provided as listed. The tenant must not share keys, access codes, or entry devices with unauthorized persons.',
            'Study area'=>'The study area must remain quiet, clean, and available for its intended shared use.',
            'Pet friendly'=>'Pets are allowed for this property. The tenant remains responsible for cleanliness, noise, injury, and any damage caused by the pet.',
        ];
        $amenityLines=[];
        foreach ($amenities as $amenity) if (isset($rules[$amenity])) $amenityLines[]='- '.$amenity.': '.$rules[$amenity];
        if (!$amenityLines) $amenityLines[]='- No additional amenity-specific rule applies beyond the property listing and written house rules.';
        return "DORMFIND RENTAL AGREEMENT\n\n"
            ."Property: {$i['title']}\nLocation: {$i['address']}\nLandlord: {$i['landlord_first']} {$i['landlord_last']}\n"
            ."Tenant: {$i['student_first']} {$i['student_last']}\nMonthly rent: PHP ".number_format((float)$i['monthly_rent'],2)."\n"
            ."Rental period: {$i['start_date']} to {$i['end_date']}\n\n"
            ."GENERAL TERMS\n"
            ."1. The tenant will pay rent on the agreed schedule, care for the rented space, follow lawful house rules, and return the space in proper condition, subject to ordinary wear.\n"
            ."2. The landlord will provide the listed space and the amenities stated below for the agreed rental period.\n"
            ."3. NO REFUND RULE: Payments already made are non-refundable after the rental period begins, except when the landlord cannot provide the agreed space or when applicable law requires a refund.\n"
            ."4. Damage, prohibited activity, serious disturbance, or violation of the written property rules may be handled according to the agreement and applicable law.\n"
            ."5. Any change to the rent, rental dates, property, or listed amenities must be recorded in writing and accepted by both parties.\n\n"
            ."AMENITY-SPECIFIC TERMS\n".implode("\n",$amenityLines);
    }
    public function index()
    {
        $file = ROOT_DIR . 'public/app.html';
        if (is_file($file)) { header('Content-Type: text/html; charset=utf-8'); readfile($file); return; }
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1>DormFind API is ready</h1><p>For the React interface, open <a href="http://localhost:5173">localhost:5173</a> after running npm install and npm run dev in frontend/.</p>';
    }
    public function health()
    {
        // Liveness only: no database, API keys, sessions or third-party dependencies.
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['data' => ['application' => 'DormFind', 'status' => 'running']]);
    }
    public function csrf() { $this->run(fn() => ['token' => $this->dorm_security->token()], false); }
    public function readiness()
    {
        $this->run(function () {
            $this->admin();
            $required = ['users','properties','property_images','roommate_preferences','inquiries_applications',
                'refresh_tokens','migrations','property_amenities','rental_contracts','rental_notifications',
                'registration_email_otps','password_reset_tokens','account_email_otps','login_attempts','security_rate_limits'];
            $tables = array_column($this->all('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()'), 'name');
            $missing = array_values(array_diff($required, $tables));
            if ($missing) throw new RuntimeException('Pending database migrations. Run php lava migration run.', 503);
            return ['database' => 'connected', 'ready' => true];
        });
    }
    private function throttleSensitive()
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $paths = ['send-email-otp' => 6, 'forgot-password' => 6, 'reset-password' => 15, 'login' => 30, 'email-otp' => 6];
        $action = basename($path ?? '');
        if (!isset($paths[$action])) return;
        $this->Dorm_model->throttle($action . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), $paths[$action], 600);
        $b = $this->body();
        $email = $b['email'] ?? $b['new_email'] ?? '';
        if (is_string($email) && $email !== '') {
            $this->Dorm_model->throttle($action . '|account|' . strtolower(trim($email)), $action === 'login' ? 20 : 3, 600);
        }
    }
    public function config()
    {
        $this->run(function () {
            $firebase = [];
            foreach (['apiKey'=>'FIREBASE_API_KEY','authDomain'=>'FIREBASE_AUTH_DOMAIN','databaseURL'=>'FIREBASE_DATABASE_URL','projectId'=>'FIREBASE_PROJECT_ID','appId'=>'FIREBASE_APP_ID'] as $k=>$v) $firebase[$k] = config_item('dorm_' . strtolower($v)) ?: '';
            return ['mapbox_token'=>config_item('dorm_mapbox_public_token') ?: '', 'recaptcha_site_key'=>config_item('dorm_recaptcha_site_key') ?: '',
                'local_captcha_bypass'=>config_item('environment') === 'development' && config_item('dorm_recaptcha_dev_bypass') === 'true' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true),
                'firebase'=>$firebase, 'cloudinary_ready'=>(bool)(config_item('dorm_cloudinary_cloud_name') && config_item('dorm_cloudinary_api_key') && config_item('dorm_cloudinary_api_secret'))];
        }, false);
    }
    public function register()
    {
        $this->run(function () {
            $b=$this->body(); Dorm_services::captcha($b['recaptcha_token'] ?? '');
            $first=$this->required($b,'first_name',50); $last=$this->required($b,'last_name',50);
            $email=strtolower($this->required($b,'email',100));
            if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email.',422);
            $otp=$this->required($b,'email_otp',6);
            if (!preg_match('/^\d{6}$/',$otp)) throw new InvalidArgumentException('Enter the 6-digit email OTP.',422);
            $verified=$this->one('SELECT otp_id,code_hash FROM registration_email_otps WHERE email=? AND used_at IS NULL AND expires_at>NOW() AND attempts<5',[$email]);
            if (!$verified || !hash_equals($verified['code_hash'],hash('sha256',$email.'|'.$otp))) {
                if ($verified) $this->db->raw('UPDATE registration_email_otps SET attempts=attempts+1 WHERE otp_id=?',[$verified['otp_id']]);
                throw new RuntimeException('Invalid or expired email OTP. Request a new code.',422);
            }
            $password=$b['password'] ?? '';
            if (!is_string($password) || strlen($password)<8 || strlen($password)>72) throw new InvalidArgumentException('Password must be 8–72 characters.',422);
            $role=strtolower($b['role'] ?? 'student');
            if (!in_array($role,['student','landlord'],true)) throw new InvalidArgumentException('Choose Student or Landlord.',422);
            $phone=$b['phone'] ?? ''; if (!is_string($phone) || strlen($phone)>20) throw new InvalidArgumentException('Invalid phone number.',422);
            if ($this->Dorm_model->findRow('users', ['email' => $email])) throw new RuntimeException('Email is already registered.',409);
            $this->db->transaction();
            try {
                $locked = $this->one('SELECT otp_id,code_hash FROM registration_email_otps WHERE otp_id=? AND used_at IS NULL AND expires_at>NOW() AND attempts<5 FOR UPDATE', [$verified['otp_id']]);
                if (!$locked || !hash_equals($locked['code_hash'], hash('sha256', $email.'|'.$otp))) throw new RuntimeException('Invalid or expired email OTP.', 422);
                $this->Dorm_model->createRow('users', ['first_name'=>$first,'last_name'=>$last,'email'=>$email,'password'=>password_hash($password,PASSWORD_DEFAULT),'phone'=>$phone,'role'=>$role,'is_verified'=>$role==='student'?1:0]);
                $this->db->raw('UPDATE registration_email_otps SET used_at=NOW() WHERE otp_id=? AND used_at IS NULL',[$verified['otp_id']]);
                $this->db->commit();
            } catch (mysqli_sql_exception $e) { $this->db->roll_back(); if ($e->getCode()===1062) throw new RuntimeException('Email is already registered.',409); throw $e; }
            catch (Throwable $e) { $this->db->roll_back(); throw $e; }
            return ['message'=>'Account created. Please log in.', 'notification'=>Dorm_services::email($email,'Welcome to DormFind',"Hello {$first}! Your DormFind account is ready. You can now find housing and roommates.")];
        });
    }
    public function sendEmailOtp()
    {
        $this->run(function () {
            $email=strtolower($this->required($this->body(),'email',100));
            if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email.',422);
            if ($this->Dorm_model->findRow('users',['email'=>$email])) throw new RuntimeException('Email is already registered.',409);
            $recent=$this->one('SELECT otp_id FROM registration_email_otps WHERE email=? AND created_at>DATE_SUB(NOW(),INTERVAL 60 SECOND)',[$email]);
            if ($recent) throw new RuntimeException('Please wait one minute before requesting another code.',429);
            $code=(string)random_int(100000,999999);
            $sent=Dorm_services::email($email,'Your DormFind registration code',"Your DormFind verification code is {$code}. It expires in 10 minutes. Do not share this code.");
            if ($sent==='not_configured') throw new RuntimeException('Email OTP is not configured. Add valid SendGrid settings to .env.',503);
            if ($sent!=='accepted') throw new RuntimeException('The email code could not be sent. Check SendGrid and try again.',502);
            $this->db->raw('INSERT INTO registration_email_otps (email,code_hash,expires_at,attempts,used_at,created_at) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 10 MINUTE),0,NULL,NOW()) ON DUPLICATE KEY UPDATE code_hash=VALUES(code_hash),expires_at=VALUES(expires_at),attempts=0,used_at=NULL,created_at=NOW()',[$email,hash('sha256',$email.'|'.$code)]);
            return ['message'=>'A 6-digit code was sent to your email.'];
        });
    }
    public function login()
    {
        $this->run(function () {
            $b=$this->body(); Dorm_services::captcha($b['recaptcha_token'] ?? '');
            $email=strtolower($this->required($b,'email',100)); $password=$b['password'] ?? '';
            if (!is_string($password) || strlen($password)>1024) throw new RuntimeException('Invalid email or password.',401);
            $attemptKey=hash('sha256','account|'.$email);
            if (random_int(1, 100) === 1) $this->db->raw('DELETE FROM login_attempts WHERE attempted_at<DATE_SUB(NOW(),INTERVAL 1 DAY) LIMIT 1000');
            $attempts=(int)($this->one('SELECT COUNT(*) total FROM login_attempts WHERE attempt_key=? AND attempted_at>DATE_SUB(NOW(),INTERVAL 15 MINUTE)',[$attemptKey])['total'] ?? 0);
            if ($attempts>=5) throw new RuntimeException('Too many login attempts. Try again after 15 minutes.',429);
            $u=$this->Dorm_model->findRow('users', ['email' => $email]);
            $info=$u ? password_get_info($u['password']) : [];
            $hashed=!empty($info['algo']);
            $valid=$u && $hashed && password_verify($password,$u['password']);
            if (!$valid) { $this->db->raw('INSERT INTO login_attempts (attempt_key,attempted_at) VALUES (?,NOW())',[$attemptKey]); throw new RuntimeException('Invalid email or password.',401); }
            if ($u['is_banned']) throw new RuntimeException('Account disabled. Contact the administrator.',403);
            $this->db->raw('DELETE FROM login_attempts WHERE attempt_key=?',[$attemptKey]);
            // Migration 008 hashes imported plaintext passwords before production use.
            if (password_needs_rehash($u['password'],PASSWORD_DEFAULT)) $this->Dorm_model->changeRows('users', ['user_id'=>$u['user_id']], ['password'=>password_hash($password,PASSWORD_DEFAULT)]);
            $tokens=$this->dorm_api->issue_tokens(['id'=>$u['user_id'],'role'=>$u['role']]);
            unset($u['password']); return ['user'=>$u,'tokens'=>$tokens];
        });
    }
    public function refresh() { $this->run(fn()=>['tokens'=>$this->dorm_api->refreshForSchema($this->required($this->body(),'refresh_token',4096))]); }
    public function logout()
    {
        $this->run(function () {
            $token=$this->body()['refresh_token'] ?? '';
            if (is_string($token) && $token) $this->dorm_api->revoke_refresh_token($token);
            return ['message'=>'Logged out.'];
        });
    }
    public function me() { $this->run(fn()=>$this->user()); }
    public function updateProfile()
    {
        $this->run(function () {
            $u=$this->user(); $b=$this->body();
            $first=$this->required($b,'first_name',50); $last=$this->required($b,'last_name',50);
            foreach ([$first,$last] as $name) if (!preg_match("/^[\\p{L}\\p{M} .'-]+$/u",$name)) throw new InvalidArgumentException('Names may contain letters, spaces, apostrophes, periods, and hyphens only.',422);
            $phone=trim((string)($b['phone'] ?? ''));
            if ($phone!=='' && (!preg_match('/^[0-9+() .-]{7,20}$/',$phone))) throw new InvalidArgumentException('Enter a valid phone number.',422);
            $this->Dorm_model->changeRows('users',['user_id'=>$u['user_id']],['first_name'=>$first,'last_name'=>$last,'phone'=>$phone]);
            return $this->one('SELECT user_id,role,first_name,last_name,email,phone,profile_image_url,is_verified,is_banned FROM users WHERE user_id=?',[$u['user_id']]);
        });
    }
    public function sendProfileEmailOtp()
    {
        $this->run(function () {
            $u=$this->user(); $email=strtolower($this->required($this->body(),'new_email',100));
            if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid Gmail or email address.',422);
            if ($email===strtolower($u['email'])) throw new InvalidArgumentException('Enter a different email address.',422);
            if ($this->Dorm_model->findRow('users',['email'=>$email])) throw new RuntimeException('That email is already registered.',409);
            $recent=$this->one('SELECT otp_id FROM account_email_otps WHERE user_id=? AND created_at>DATE_SUB(NOW(),INTERVAL 60 SECOND)',[$u['user_id']]);
            if ($recent) throw new RuntimeException('Please wait one minute before requesting another code.',429);
            $code=(string)random_int(100000,999999);
            $sent=Dorm_services::email($email,'Confirm your new DormFind email',"Hello {$u['first_name']}, your code for changing your DormFind email is {$code}. It expires in 10 minutes. Do not share this code.");
            if ($sent==='not_configured') throw new RuntimeException('Email OTP is not configured. Add valid SendGrid settings to .env.',503);
            if ($sent!=='accepted') throw new RuntimeException('The verification email could not be sent. Try again.',502);
            $hash=hash('sha256',$u['user_id'].'|'.$email.'|'.$code);
            $this->db->raw('INSERT INTO account_email_otps (user_id,new_email,code_hash,expires_at,attempts,used_at,created_at) VALUES (?,?,?,DATE_ADD(NOW(),INTERVAL 10 MINUTE),0,NULL,NOW()) ON DUPLICATE KEY UPDATE new_email=VALUES(new_email),code_hash=VALUES(code_hash),expires_at=VALUES(expires_at),attempts=0,used_at=NULL,created_at=NOW()',[$u['user_id'],$email,$hash]);
            return ['message'=>'A 6-digit verification code was sent to the new email.'];
        });
    }
    public function changeProfileEmail()
    {
        $this->run(function () {
            $u=$this->user(); $b=$this->body(); $email=strtolower($this->required($b,'new_email',100)); $otp=$this->required($b,'otp',6);
            if (!filter_var($email,FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/',$otp)) throw new InvalidArgumentException('Enter the new email and its 6-digit code.',422);
            $row=$this->one('SELECT otp_id,code_hash,attempts FROM account_email_otps WHERE user_id=? AND new_email=? AND used_at IS NULL AND expires_at>NOW() AND attempts<5',[$u['user_id'],$email]);
            $valid=$row && hash_equals($row['code_hash'],hash('sha256',$u['user_id'].'|'.$email.'|'.$otp));
            if (!$valid) {
                if ($row) $this->db->raw('UPDATE account_email_otps SET attempts=attempts+1 WHERE otp_id=?',[$row['otp_id']]);
                throw new RuntimeException('Invalid or expired verification code.',422);
            }
            if ($this->Dorm_model->findRow('users',['email'=>$email])) throw new RuntimeException('That email is already registered.',409);
            $this->db->transaction();
            try {
                $locked = $this->one('SELECT otp_id,code_hash FROM account_email_otps WHERE otp_id=? AND used_at IS NULL AND expires_at>NOW() AND attempts<5 FOR UPDATE', [$row['otp_id']]);
                if (!$locked || !hash_equals($locked['code_hash'], hash('sha256', $u['user_id'].'|'.$email.'|'.$otp))) throw new RuntimeException('Invalid or expired verification code.', 422);
                $this->Dorm_model->changeRows('users',['user_id'=>$u['user_id']],['email'=>$email]);
                $this->db->raw('UPDATE account_email_otps SET used_at=NOW() WHERE otp_id=?',[$row['otp_id']]);
                $this->db->commit();
            } catch (Throwable $e) { $this->db->roll_back(); throw $e; }
            Dorm_services::email($u['email'],'DormFind email changed',"Your DormFind account email was changed to {$email}. If you did not request this, contact the administrator immediately.");
            return $this->one('SELECT user_id,role,first_name,last_name,email,phone,profile_image_url,is_verified,is_banned FROM users WHERE user_id=?',[$u['user_id']]);
        });
    }
    public function forgotPassword()
    {
        $this->run(function () {
            $b=$this->body(); Dorm_services::captcha($b['recaptcha_token'] ?? '');
            $email=strtolower($this->required($b,'email',100));
            if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email.',422);
            $user=$this->Dorm_model->findRow('users',['email'=>$email]);
            if ($user) {
                $token=bin2hex(random_bytes(32));
                $origin=trim(explode(',',getenv('FRONTEND_ORIGINS') ?: 'http://127.0.0.1:5173')[0]);
                $url=rtrim($origin,'/').'/?reset='.rawurlencode($token);
                $sent=Dorm_services::email($email,'Reset your DormFind password',"Hello {$user['first_name']}, open this link within one hour to reset your password: {$url}");
                if ($sent==='accepted') $this->db->raw('INSERT INTO password_reset_tokens (user_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 1 HOUR))',[$user['user_id'],hash('sha256',$token)]);
                elseif ($sent==='not_configured') throw new RuntimeException('Password reset email is not configured. Add valid SendGrid settings to .env.',503);
                else throw new RuntimeException('The reset email could not be sent. Check SendGrid and try again.',502);
            }
            return ['message'=>'If the email is registered, a reset link has been sent.'];
        });
    }
    public function resetPassword()
    {
        $this->run(function () {
            $b=$this->body(); $token=$this->required($b,'token',64); $password=$b['password'] ?? '';
            if (!preg_match('/^[a-f0-9]{64}$/',$token)) throw new RuntimeException('Invalid or expired reset link.',422);
            if (!is_string($password) || strlen($password)<8 || strlen($password)>72) throw new InvalidArgumentException('Password must be 8–72 characters.',422);
            $row=$this->one('SELECT token_id,user_id FROM password_reset_tokens WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW()', [hash('sha256',$token)]);
            if (!$row) throw new RuntimeException('Invalid or expired reset link.',422);
            $this->db->transaction();
            try {
                $locked = $this->one('SELECT token_id FROM password_reset_tokens WHERE token_id=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE', [$row['token_id']]);
                if (!$locked) throw new RuntimeException('Invalid or expired reset link.', 422);
                $this->Dorm_model->changeRows('users',['user_id'=>$row['user_id']],['password'=>password_hash($password,PASSWORD_DEFAULT)]);
                $this->db->raw('UPDATE password_reset_tokens SET used_at=NOW() WHERE token_id=?',[$row['token_id']]);
                $this->db->raw('DELETE FROM refresh_tokens WHERE user_id=?',[$row['user_id']]);
                $this->db->commit();
            } catch (Throwable $e) { $this->db->roll_back(); throw $e; }
            return ['message'=>'Password changed. You can now log in.'];
        });
    }
    public function properties()
    {
        $this->run(function () {
            $rows=$this->Dorm_model->listings($_GET);
            $distance=isset($_GET['radius_km']) && $_GET['radius_km']!=='';
            if ($distance && (!isset($_GET['lat'],$_GET['lng']) || !is_numeric($_GET['lat']) || !is_numeric($_GET['lng']) || abs((float)$_GET['lat'])>90 || abs((float)$_GET['lng'])>180 || !is_numeric($_GET['radius_km']) || $_GET['radius_km']<=0)) throw new InvalidArgumentException('Select a map search center and a positive radius.',422);
            $out=[];
            foreach ($rows as $p) {
                $p['distance_km']=null;
                if ($distance) {
                    if ($p['latitude']===null || $p['longitude']===null) continue;
                    $p['distance_km']=round(Dorm_services::distance((float)$_GET['lat'],(float)$_GET['lng'],(float)$p['latitude'],(float)$p['longitude']),2);
                    if ($p['distance_km']>(float)$_GET['radius_km']) continue;
                }
                $out[]=$p;
            }
            return $this->decorateMany($out);
        });
    }
    public function property($id)
    {
        $this->run(function () use ($id) {
            $p=$this->one('SELECT p.*,u.first_name AS owner_first_name,u.last_name AS owner_last_name FROM properties p JOIN users u ON u.user_id=p.owner_id WHERE p.property_id = ?',[$id]);
            if (!$p) throw new RuntimeException('Property not found.',404);
            $public=$this->one('SELECT 1 FROM users u JOIN property_images pi ON pi.property_id=? WHERE u.user_id=? AND u.is_verified=1 AND u.is_banned=0 LIMIT 1',[$id,$p['owner_id']]);
            if ((!in_array($p['status'],['approved','fully_leased'],true) || !$public) && $this->user()['user_id']!=$p['owner_id']) throw new RuntimeException('Property not found.',404);
            return $this->decorate($p);
        });
    }
    public function ownedProperties() { $this->run(function () { $u=$this->user(true); return $this->decorateMany($this->Dorm_model->rows('properties', ['owner_id'=>$u['user_id']], ['property_id','DESC'])); }); }
    public function saveProperty($id = null)
    {
        $this->run(function () use ($id) {
            $u=$this->verifiedOwner(); $existing=$id ? $this->owned($id) : null;
            $b=$id ? $this->body() : json_decode($_POST['property'] ?? '',true);
            if (!is_array($b)) throw new InvalidArgumentException('Submit property details and an image together.',422);
            if (!$id && empty($_FILES['image'])) throw new InvalidArgumentException('A property photo is required before saving.',422);
            $title=$this->required($b,'title',100);
            $desc=$b['description'] ?? ''; if (!is_string($desc) || mb_strlen($desc)>10000) throw new InvalidArgumentException('Description is too long.',422);
            $rent=$b['monthly_rent'] ?? null; $capacity=$b['total_capacity'] ?? null; $beds=$b['available_beds'] ?? null;
            if (!is_numeric($rent) || $rent<0 || $rent>99999999.99 || filter_var($capacity,FILTER_VALIDATE_INT)===false || $capacity<1 || $capacity>100000 || filter_var($beds,FILTER_VALIDATE_INT)===false || $beds<0 || $beds>$capacity) throw new InvalidArgumentException('Check rent, capacity, and available beds.',422);
            $lat=$b['latitude'] ?? null; $lng=$b['longitude'] ?? null;
            if ($lat==='' || $lat===null) $lat=null; if ($lng==='' || $lng===null) $lng=null;
            if ($lat===null || $lng===null || !is_numeric($lat) || !is_numeric($lng) || abs((float)$lat)>90 || abs((float)$lng)>180) throw new InvalidArgumentException('Pin the property location on the map.',422);
            $address=sprintf('Pinned location: %.6f, %.6f',(float)$lat,(float)$lng);
            $amenities=$b['amenities'] ?? [];
            if (!is_array($amenities) || count($amenities)>15) throw new InvalidArgumentException('Choose up to 15 amenities.',422);
            $allowed=['Wi-Fi','Water','Electricity','Kitchen','Laundry','Parking','Air conditioning','Security','Study area','Pet friendly'];
            foreach ($amenities as $a) if (!in_array($a,$allowed,true)) throw new InvalidArgumentException('Invalid amenity.',422);
            $status=$beds==0 ? 'fully_leased' : (!$id || $existing['status']!=='approved' ? 'pending' : 'approved');
            $uploaded=null;
            $this->db->transaction();
            try {
                $data=['title'=>$title,'address'=>$address,'description'=>$desc,'monthly_rent'=>$rent,'total_capacity'=>$capacity,'available_beds'=>$beds,'latitude'=>$lat,'longitude'=>$lng,'status'=>$status];
                if ($id) $this->Dorm_model->changeRows('properties', ['property_id'=>$id,'owner_id'=>$u['user_id']], $data);
                else { $id=$this->Dorm_model->createRow('properties', $data+['owner_id'=>$u['user_id']]); }
                if (!$id) throw new RuntimeException('Property could not be saved.',500);
                if (isset($_FILES['image'])) {
                    $uploaded=Dorm_services::upload($_FILES['image'],$id);
                    $this->Dorm_model->createRow('property_images',['property_id'=>$id,'image_url'=>$uploaded['secure_url'],'is_primary'=>1]);
                }
                $this->Dorm_model->removeRows('property_amenities', ['property_id'=>$id]);
                foreach (array_unique($amenities) as $a) $this->Dorm_model->createRow('property_amenities', ['property_id'=>$id,'amenity'=>$a]);
                $this->db->commit();
            } catch (Throwable $e) { $this->db->roll_back(); if ($uploaded) { try { Dorm_services::destroy($uploaded['secure_url'],$id); } catch (Throwable $cleanup) { error_log('Cloudinary cleanup failed: '.$cleanup->getMessage()); } } throw $e; }
            return $this->decorate($this->Dorm_model->findRow('properties', ['property_id'=>$id]));
        });
    }
    public function deleteProperty($id)
    {
        $this->run(function () use ($id) {
            $this->owned($id);
            foreach ($this->Dorm_model->rows('property_images', ['property_id'=>$id]) as $i) Dorm_services::destroy($i['image_url'],$id);
            $this->Dorm_model->removeRows('properties', ['property_id'=>$id]);
            return ['message'=>'Property deleted.'];
        });
    }
    public function uploadImage($id)
    {
        $this->run(function () use ($id) {
            $this->owned($id); $r=Dorm_services::upload($_FILES['image'] ?? null,$id);
            $this->db->transaction();
            try {
                $this->db->raw('SELECT property_id FROM properties WHERE property_id = ? FOR UPDATE',[$id]);
                $has=$this->Dorm_model->findRow('property_images', ['property_id'=>$id,'is_primary'=>1]);
                $this->Dorm_model->createRow('property_images', ['property_id'=>$id,'image_url'=>$r['secure_url'],'is_primary'=>$has ? 0 : 1]);
                $this->db->commit();
            } catch (Throwable $e) { $this->db->roll_back(); Dorm_services::destroy($r['secure_url'],$id); throw $e; }
            return ['message'=>'Image uploaded.'];
        });
    }
    public function deleteImage($id,$image)
    {
        $this->run(function () use ($id,$image) {
            $this->owned($id); $i=$this->Dorm_model->findRow('property_images', ['image_id'=>$image,'property_id'=>$id]);
            if (!$i) throw new RuntimeException('Image not found.',404);
            $this->db->transaction();
            try {
                $this->db->raw('SELECT property_id FROM properties WHERE property_id = ? FOR UPDATE',[$id]);
                $remaining = $this->all('SELECT image_id FROM property_images WHERE property_id=? FOR UPDATE', [$id]);
                if (count($remaining) <= 1) throw new RuntimeException('Keep at least one photo for each property.', 422);
                $this->Dorm_model->removeRows('property_images', ['image_id'=>$image,'property_id'=>$id]);
                if ($i['is_primary']) $this->db->raw('UPDATE property_images SET is_primary = 1 WHERE property_id = ? ORDER BY image_id LIMIT 1',[$id]);
                $this->db->commit();
            } catch (Throwable $e) { $this->db->roll_back(); throw $e; }
            try { Dorm_services::destroy($i['image_url'], $id); }
            catch (Throwable $cleanup) { error_log('DormFind image removed from database; Cloudinary cleanup needs retry.'); }
            return ['message'=>'Image removed.'];
        });
    }
    public function primaryImage($id,$image)
    {
        $this->run(function () use ($id,$image) {
            $this->owned($id);
            if (!$this->Dorm_model->findRow('property_images', ['property_id'=>$id,'image_id'=>$image])) throw new RuntimeException('Image not found.',404);
            $this->db->transaction();
            try { $this->db->raw('SELECT property_id FROM properties WHERE property_id = ? FOR UPDATE',[$id]); $this->db->raw('UPDATE property_images SET is_primary = (image_id = ?) WHERE property_id = ?',[$image,$id]); $this->db->commit(); }
            catch (Throwable $e) { $this->db->roll_back(); throw $e; }
            return ['message'=>'Cover photo updated.'];
        });
    }
    public function preferences() { $this->run(function () { $u=$this->user(); return $this->Dorm_model->findRow('roommate_preferences', ['user_id'=>$u['user_id']]); }); }
    public function savePreferences()
    {
        $this->run(function () {
            $u=$this->user(); if (strtolower($u['role'])!=='student') throw new RuntimeException('Roommate matching is for student accounts.',403);
            $b=$this->body();
            $fields=['sleep_schedule'=>['early_bird','night_owl','flexible'],'cleanliness'=>['very_tidy','average','relaxed'],'noise_tolerance'=>['quiet','moderate','loud'],'study_habits'=>['at_home','library','cafes']];
            foreach ($fields as $key=>$allowed) if (!in_array($b[$key] ?? null,$allowed,true)) throw new InvalidArgumentException("Choose a valid {$key}.",422);
            if (!in_array($b['pet_friendly'] ?? null,[0,1,false,true,'0','1'],true)) throw new InvalidArgumentException('Choose a pet preference.',422);
            $data=['sleep_schedule'=>$b['sleep_schedule'],'cleanliness'=>$b['cleanliness'],'noise_tolerance'=>$b['noise_tolerance'],'study_habits'=>$b['study_habits'],'pet_friendly'=>(int)$b['pet_friendly']];
            $this->Dorm_model->savePreference($u['user_id'], $data);
            return ['message'=>'Preferences saved.'];
        });
    }
    public function matches()
    {
        $this->run(function () {
            $u=$this->user(); if (strtolower($u['role'])!=='student') throw new RuntimeException('Roommate matching is for student accounts.',403);
            $mine=$this->Dorm_model->findRow('roommate_preferences', ['user_id'=>$u['user_id']]);
            if (!$mine) throw new RuntimeException('Complete your lifestyle questionnaire first.',422);
            $rows=$this->all("SELECT u.user_id,u.first_name,u.last_name,u.profile_image_url,r.sleep_schedule,r.cleanliness,r.noise_tolerance,r.pet_friendly,r.study_habits FROM users u JOIN roommate_preferences r ON r.user_id=u.user_id WHERE LOWER(u.role)='student' AND u.is_banned=0 AND u.user_id<>?",[$u['user_id']]);
            foreach ($rows as &$r) $r+=Dorm_services::compatibility($mine,$r); unset($r);
            usort($rows,fn($a,$b)=>$b['compatibility']<=>$a['compatibility']); return $rows;
        });
    }
    public function inquiries()
    {
        $this->run(function () {
            $u=$this->user();
            if ($this->isOwner($u)) return $this->all('SELECT i.*,p.title,p.owner_id,applicant.first_name,applicant.last_name,applicant.email,applicant.phone,rc.contract_id,rc.landlord_signed_at,rc.student_signed_at FROM inquiries_applications i JOIN properties p ON p.property_id=i.property_id JOIN users applicant ON applicant.user_id=i.applicant_id LEFT JOIN rental_contracts rc ON rc.inquiry_id=i.inquiry_id WHERE p.owner_id=? ORDER BY i.created_at DESC',[$u['user_id']]);
            return $this->all('SELECT i.*,p.title,p.owner_id,applicant.first_name,applicant.last_name,rc.contract_id,rc.landlord_signed_at,rc.student_signed_at FROM inquiries_applications i JOIN properties p ON p.property_id=i.property_id JOIN users applicant ON applicant.user_id=i.applicant_id LEFT JOIN rental_contracts rc ON rc.inquiry_id=i.inquiry_id WHERE i.applicant_id=? ORDER BY i.created_at DESC',[$u['user_id']]);
        });
    }
    public function createInquiry()
    {
        $this->run(function () {
            $u=$this->user(); $b=$this->body(); $message=$this->required($b,'message',2000);
            if ($u['role'] !== 'student') throw new RuntimeException('Only students can send rental inquiries.',403);
            $p=$this->one("SELECT p.*,u.email FROM properties p JOIN users u ON u.user_id=p.owner_id WHERE p.property_id = ? AND p.status='approved' AND p.available_beds>0 AND u.is_verified=1 AND u.is_banned=0 AND EXISTS (SELECT 1 FROM property_images pi WHERE pi.property_id=p.property_id)",[$b['property_id'] ?? 0]);
            if (!$p) throw new RuntimeException('Property is unavailable.',404);
            if ($p['owner_id']==$u['user_id']) throw new RuntimeException('You cannot inquire about your own property.',422);
            $this->Dorm_model->createRow('inquiries_applications', ['property_id'=>$p['property_id'],'applicant_id'=>$u['user_id'],'message'=>$message]);
            return ['message'=>'Inquiry sent.','notification'=>Dorm_services::email($p['email'],'New DormFind inquiry',"{$u['first_name']} asked about {$p['title']}:\n{$message}\nOpen DormFind to respond.")];
        });
    }
    public function updateInquiry($id)
    {
        $this->run(function () use ($id) {
            $u=$this->user(true); $b=$this->body(); $status=$b['status'] ?? '';
            if (!in_array($status,['pending','accepted','declined'],true)) throw new InvalidArgumentException('Invalid inquiry status.',422);
            $i=$this->one('SELECT i.*,u.email,p.title FROM inquiries_applications i JOIN properties p ON p.property_id=i.property_id JOIN users u ON u.user_id=i.applicant_id WHERE i.inquiry_id=? AND p.owner_id=?',[$id,$u['user_id']]);
            if (!$i) throw new RuntimeException('Inquiry not found.',404);
            if ($i['status']!=='pending') throw new RuntimeException('This inquiry already has a final response and can no longer be changed.',409);
            if ($status==='pending') throw new RuntimeException('Choose Accepted or Rejected.',422);
            $this->db->transaction();
            try {
                $locked=$this->one('SELECT i.status,i.property_id,p.available_beds FROM inquiries_applications i JOIN properties p ON p.property_id=i.property_id WHERE i.inquiry_id=? FOR UPDATE',[$id]);
                if (!$locked || $locked['status']!=='pending') throw new RuntimeException('This inquiry already has a final response and can no longer be changed.',409);
                if ($status==='accepted') {
                    if ((int)$locked['available_beds']<1) throw new RuntimeException('This property is already occupied.',409);
                    $this->Dorm_model->changeRows('properties',['property_id'=>$locked['property_id']],['available_beds'=>0,'status'=>'fully_leased']);
                    $this->db->raw("UPDATE inquiries_applications SET status='declined' WHERE property_id=? AND inquiry_id<>? AND status='pending'",[$locked['property_id'],$id]);
                }
                $this->Dorm_model->changeRows('inquiries_applications', ['inquiry_id'=>$id], ['status'=>$status]);
                $this->db->commit();
            } catch (Throwable $e) { $this->db->roll_back(); throw $e; }
            $friendly=$status==='declined'?'rejected':'accepted';
            return ['message'=>'Inquiry updated.','notification'=>Dorm_services::email($i['email'],'DormFind inquiry update',"Your inquiry for {$i['title']} was {$friendly}.")];
        });
    }
    public function adminOverview()
    {
        $this->run(function () {
            $this->admin();
            $properties=$this->all('SELECT p.*,u.first_name AS owner_first_name,u.last_name AS owner_last_name FROM properties p JOIN users u ON u.user_id=p.owner_id ORDER BY p.property_id DESC');
            return ['stats'=>$this->one("SELECT SUM(role='student' AND is_banned=0) AS students,SUM(role='landlord' AND is_banned=0) AS landlords,SUM(role='landlord' AND is_verified=0 AND is_banned=0) AS pending_landlords FROM users") + ['active_listings'=>(int)($this->one("SELECT COUNT(*) AS n FROM properties p JOIN users u ON u.user_id=p.owner_id WHERE p.status='approved' AND u.is_verified=1 AND u.is_banned=0 AND EXISTS (SELECT 1 FROM property_images pi WHERE pi.property_id=p.property_id)")['n'] ?? 0)],
                'users'=>$this->all('SELECT user_id,first_name,last_name,email,phone,role,is_verified,is_banned,created_at FROM users ORDER BY user_id DESC'),
                'properties'=>$this->decorateMany($properties)];
        });
    }
    public function adminUser($id)
    {
        $this->run(function () use ($id) {
            $admin=$this->admin(); $b=$this->body();
            $target=$this->Dorm_model->findRow('users',['user_id'=>$id]);
            if (!$target) throw new RuntimeException('User not found.',404);
            if ($target['role']==='admin' || $target['user_id']==$admin['user_id']) throw new RuntimeException('Admin accounts cannot be changed here.',403);
            $data=[];
            if (array_key_exists('is_banned',$b)) { if (!in_array($b['is_banned'],[0,1,true,false],true)) throw new InvalidArgumentException('Invalid ban flag.',422); $data['is_banned']=(int)$b['is_banned']; }
            if (array_key_exists('is_verified',$b)) { if ($target['role']!=='landlord' || !in_array($b['is_verified'],[0,1,true,false],true)) throw new InvalidArgumentException('Only landlords can be approved.',422); $data['is_verified']=(int)$b['is_verified']; }
            if (!$data) throw new InvalidArgumentException('No account change provided.',422);
            $this->Dorm_model->changeRows('users',['user_id'=>$id],$data);
            return ['message'=>'User updated.'];
        });
    }
    public function adminProperty($id)
    {
        $this->run(function () use ($id) {
            $this->admin(); $status=$this->body()['status'] ?? '';
            if ($status!=='approved') throw new InvalidArgumentException('Only property approval is allowed here.',422);
            $property=$this->Dorm_model->findRow('properties',['property_id'=>$id]);
            if (!$property) throw new RuntimeException('Property not found.',404);
            if ($property['status']==='approved') return ['message'=>'Property is already approved.'];
            if (!$this->Dorm_model->findRow('property_images',['property_id'=>$id])) throw new RuntimeException('A property image is required before approval.',422);
            $this->Dorm_model->changeRows('properties',['property_id'=>$id],['status'=>(int)$property['available_beds']>0?'approved':'fully_leased']);
            return ['message'=>'Property approved.'];
        });
    }
    public function adminDeleteProperty($id)
    {
        $this->run(function () use ($id) {
            $this->admin(); $property=$this->Dorm_model->findRow('properties',['property_id'=>$id]);
            if (!$property) throw new RuntimeException('Property not found.',404);
            foreach ($this->Dorm_model->rows('property_images',['property_id'=>$id]) as $image) Dorm_services::destroy($image['image_url'],$id);
            $this->Dorm_model->removeRows('properties',['property_id'=>$id]);
            return ['message'=>'Property deleted.'];
        });
    }
    public function createContract()
    {
        $this->run(function () {
            $u=$this->verifiedOwner(); $b=$this->body(); $id=filter_var($b['inquiry_id'] ?? null,FILTER_VALIDATE_INT);
            $i=$this->one('SELECT i.*,p.owner_id,p.title,p.address,p.monthly_rent,landlord.first_name AS landlord_first,landlord.last_name AS landlord_last,landlord.email AS landlord_email,student.first_name AS student_first,student.last_name AS student_last,student.email AS student_email FROM inquiries_applications i JOIN properties p ON p.property_id=i.property_id JOIN users landlord ON landlord.user_id=p.owner_id JOIN users student ON student.user_id=i.applicant_id WHERE i.inquiry_id=? AND p.owner_id=?',[$id ?: 0,$u['user_id']]);
            if (!$i || $i['status']!=='accepted') throw new RuntimeException('Accept an inquiry you own before creating a contract.',422);
            $start=$this->required($b,'start_date',10); $end=$this->required($b,'end_date',10);
            foreach ([$start,$end] as $date) { $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date); if (!$parsed || $parsed->format('Y-m-d')!==$date) throw new InvalidArgumentException('Use valid YYYY-MM-DD dates.',422); }
            if ($end<=$start || $end>date('Y-m-d',strtotime($start.' +24 months'))) throw new InvalidArgumentException('End date must be after start, within 24 months.',422);
            $i['start_date']=$start; $i['end_date']=$end;
            $amenities=array_column($this->Dorm_model->rows('property_amenities',['property_id'=>$i['property_id']],['amenity','ASC']),'amenity');
            $terms=$this->contractTerms($i,$amenities);
            $this->db->transaction();
            try {
                $this->db->raw('SELECT inquiry_id FROM inquiries_applications WHERE inquiry_id=? FOR UPDATE',[$id]);
                $contract=$this->Dorm_model->createRow('rental_contracts',['inquiry_id'=>$id,'monthly_rent'=>$i['monthly_rent'],'start_date'=>$start,'end_date'=>$end,'terms'=>$terms]);
                $this->Dorm_model->changeRows('inquiries_applications',['inquiry_id'=>$id],['rent_start'=>$start,'rent_end'=>$end]);
                $this->db->commit();
            } catch (Throwable $e) {
                $this->db->roll_back();
                if ($e instanceof mysqli_sql_exception && $e->getCode()===1062) throw new RuntimeException('A contract already exists for this inquiry.',409);
                throw $e;
            }
            $subject='DormFind rental agreement for '.$i['title'];
            $intro="A rental agreement was created for {$i['title']}. Sign in to DormFind to review and sign it.\n\n";
            $studentMail=Dorm_services::email($i['student_email'],$subject,$intro.$terms);
            $landlordMail=Dorm_services::email($i['landlord_email'],$subject,$intro.$terms);
            return ['contract_id'=>$contract,'message'=>'Contract created. Both parties must sign.','notification'=>($studentMail==='accepted'&&$landlordMail==='accepted')?'accepted':'failed'];
        });
    }
    public function contract($id)
    {
        $this->run(function () use ($id) {
            $u=$this->user(); $contract=$this->one('SELECT c.*,p.title,p.owner_id,i.applicant_id,i.status FROM rental_contracts c JOIN inquiries_applications i ON i.inquiry_id=c.inquiry_id JOIN properties p ON p.property_id=i.property_id WHERE c.contract_id=?',[$id]);
            if (!$contract || !in_array($u['user_id'],[$contract['owner_id'],$contract['applicant_id']])) throw new RuntimeException('Contract not found.',404);
            return $contract;
        });
    }
    public function signContract($id)
    {
        $this->run(function () use ($id) {
            $u=$this->user(); $name=trim($u['first_name'].' '.$u['last_name']);
            $c=$this->one('SELECT c.*,i.status,p.owner_id,p.title,i.applicant_id,landlord.email AS landlord_email,student.email AS student_email FROM rental_contracts c JOIN inquiries_applications i ON i.inquiry_id=c.inquiry_id JOIN properties p ON p.property_id=i.property_id JOIN users landlord ON landlord.user_id=p.owner_id JOIN users student ON student.user_id=i.applicant_id WHERE c.contract_id=?',[$id]);
            if (!$c || !in_array($u['user_id'],[$c['owner_id'],$c['applicant_id']])) throw new RuntimeException('Contract not found.',404);
            if ($c['status']!=='accepted') throw new RuntimeException('Inquiry must remain accepted.',422);
            $side=$u['user_id']==$c['owner_id'] ? 'landlord' : 'student';
            if ($c[$side.'_signed_at']) throw new RuntimeException('You have already signed this contract.',409);
            $this->db->raw('UPDATE rental_contracts SET '.$side.'_signed_at=NOW(),'.$side.'_signature=?,'.$side.'_ip=? WHERE contract_id=? AND '.$side.'_signed_at IS NULL',[$name,$_SERVER['REMOTE_ADDR'] ?? '',$id]);
            $fresh=$this->one('SELECT landlord_signed_at,student_signed_at FROM rental_contracts WHERE contract_id=?',[$id]);
            $otherEmail=$side==='landlord'?$c['student_email']:$c['landlord_email'];
            Dorm_services::email($otherEmail,'DormFind rental agreement signed',"{$name} signed the rental agreement for {$c['title']}. Sign in to DormFind to review its status.");
            if ($fresh['landlord_signed_at'] && $fresh['student_signed_at']) {
                $complete="Both parties have signed the rental agreement for {$c['title']}.\nRental period: {$c['start_date']} to {$c['end_date']}\n\n{$c['terms']}";
                Dorm_services::email($c['student_email'],'DormFind agreement completed',$complete);
                Dorm_services::email($c['landlord_email'],'DormFind agreement completed',$complete);
            }
            return ['message'=>'Signature recorded. Email notifications were sent to the registered addresses.'];
        });
    }
    public function notifications()
    {
        $this->run(function () {
            $u=$this->user(); return $this->Dorm_model->rows('rental_notifications',['recipient_id'=>$u['user_id']],['notification_id','DESC']);
        });
    }
    public function reports()
    {
        $this->run(function () {
            $u=$this->user(); $isAdmin=strtolower($u['role'])==='admin'; $isLandlord=$this->isOwner($u);
            if (!$isAdmin && !$isLandlord) throw new RuntimeException('Reports are available to landlords and administrators only.',403);
            $where=$isAdmin?'1=1':'p.owner_id=?'; $args=$isAdmin?[]:[$u['user_id']];
            $tenants=$this->all("SELECT i.inquiry_id,CONCAT(tenant.first_name,' ',tenant.last_name) tenant_name,tenant.email tenant_email,tenant.phone tenant_phone,p.title dorm_name,c.start_date,c.end_date,CONCAT(landlord.first_name,' ',landlord.last_name) landlord_name,landlord.email landlord_email,landlord.phone landlord_phone,CASE WHEN c.landlord_signed_at IS NOT NULL AND c.student_signed_at IS NOT NULL THEN 'signed' ELSE 'awaiting signatures' END agreement_status FROM inquiries_applications i JOIN properties p ON p.property_id=i.property_id JOIN users tenant ON tenant.user_id=i.applicant_id JOIN users landlord ON landlord.user_id=p.owner_id JOIN rental_contracts c ON c.inquiry_id=i.inquiry_id WHERE i.status='accepted' AND {$where} ORDER BY landlord.last_name,landlord.first_name,p.title,c.start_date",$args);
            $properties=$this->all("SELECT p.property_id,p.title dorm_name,p.status,p.total_capacity,p.available_beds,p.monthly_rent,CONCAT(landlord.first_name,' ',landlord.last_name) landlord_name,landlord.email landlord_email,landlord.phone landlord_phone,COUNT(CASE WHEN i.status='accepted' THEN 1 END) tenant_count FROM properties p JOIN users landlord ON landlord.user_id=p.owner_id LEFT JOIN inquiries_applications i ON i.property_id=p.property_id WHERE {$where} GROUP BY p.property_id,p.title,p.status,p.total_capacity,p.available_beds,p.monthly_rent,landlord.first_name,landlord.last_name,landlord.email,landlord.phone ORDER BY landlord.last_name,landlord.first_name,p.title",$args);
            $accounts=[];
            if ($isAdmin) $accounts=$this->all("SELECT user_id,CONCAT(first_name,' ',last_name) account_name,email,phone,role,CASE WHEN is_banned=1 THEN 'banned' WHEN role='landlord' AND is_verified=0 THEN 'approval revoked or pending' ELSE 'active' END account_status,created_at FROM users WHERE role<>'admin' ORDER BY role,last_name,first_name");
            return ['generated_at'=>date('c'),'role'=>$isAdmin?'admin':'landlord','summary'=>['tenants'=>count($tenants),'properties'=>count($properties),'accounts'=>count($accounts)],'tenants'=>$tenants,'properties'=>$properties,'accounts'=>$accounts];
        });
    }
    public function chatToken() { $this->run(function () { $u=$this->user(); return ['token'=>Dorm_services::firebaseToken($u['user_id'])]; }); }
    public function contacts()
    {
        $this->run(function () { $u=$this->user(); return $this->all('SELECT user_id,first_name,last_name,role FROM users WHERE user_id<>? AND is_banned=0 AND role<>\'admin\' ORDER BY first_name,last_name',[$u['user_id']]); });
    }
    public function conversation()
    {
        $this->run(function () {
            $u=$this->user(); $b=$this->body(); $peer=$this->one("SELECT user_id,first_name,last_name,role FROM users WHERE user_id=? AND is_banned=0 AND role<>'admin'",[$b['peer_id'] ?? 0]);
            if ($u['role']==='admin') throw new RuntimeException('Admin accounts do not use the rental chat.',403);
            if (!$peer || $peer['user_id']==$u['user_id']) throw new InvalidArgumentException('Choose another user.',422);
            $ids=[(int)$u['user_id'],(int)$peer['user_id']]; sort($ids);
            return ['id'=>'u'.$ids[0].'_u'.$ids[1], 'peer'=>$peer, 'members'=>[(string)$u['user_id']=>true,(string)$peer['user_id']=>true]];
        });
    }
}
