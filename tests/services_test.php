<?php
// Run from project root: php tests/services_test.php. No database writes or external calls.
define('PREVENT_DIRECT_ACCESS', true);
// CLI unit checks exercise config-backed provider setup without booting LavaLust.
function config_item($name) {
 if ($name === 'environment') return getenv('APP_ENV') ?: 'development';
 return getenv(strtoupper(substr($name, 5))) ?: '';
}
require __DIR__ . '/../app/libraries/Dorm_services.php';
function check($value, $message) { if (!$value) throw new RuntimeException($message); echo "PASS {$message}\n"; }
$a=['sleep_schedule'=>'night_owl','cleanliness'=>'very_tidy','noise_tolerance'=>'quiet','pet_friendly'=>1,'study_habits'=>'library'];
check(Dorm_services::compatibility($a,$a)['compatibility']==100,'identical lifestyles score 100%');
$b=['sleep_schedule'=>'early_bird','cleanliness'=>'relaxed','noise_tolerance'=>'loud','pet_friendly'=>0,'study_habits'=>'cafes'];
check(Dorm_services::compatibility($a,$b)['compatibility']==0,'different lifestyles score 0%');
$b=$a;$b['sleep_schedule']='flexible';check(Dorm_services::compatibility($a,$b)['compatibility']==85,'flexible sleep gives half the 30-point weight');
check(abs(Dorm_services::distance(13.41,121.18,13.41,121.18))<0.00001,'same coordinates are zero kilometers apart');
check(abs(Dorm_services::distance(0,0,0,1)-111.1949)<0.01,'Haversine distance uses kilometers');
// Generate disposable test credentials; verify the actual JWT signature with the public key.
$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);openssl_pkey_export($key,$private);
$path=tempnam(sys_get_temp_dir(),'dormfind-test-');file_put_contents($path,json_encode(['client_email'=>'test@example.invalid','private_key'=>$private]));
putenv('FIREBASE_SERVICE_ACCOUNT='.$path);
try {
 $token=Dorm_services::firebaseToken(42);[$head,$payload,$sig]=explode('.',$token);
 $decode=fn($v)=>base64_decode(strtr($v,'-_','+/'));
 $claims=json_decode($decode($payload),true);
 check($claims['uid']==='42' && $claims['exp']-$claims['iat']===3600,'Firebase UID maps exactly to MySQL user_id');
 check(openssl_verify($head.'.'.$payload,$decode($sig),openssl_pkey_get_details($key)['key'],OPENSSL_ALGO_SHA256)===1,'Firebase custom token uses a valid RS256 signature');
} finally { unlink($path); putenv('FIREBASE_SERVICE_ACCOUNT'); }
// Bypass is forbidden in production and on non-loopback requests.
putenv('APP_ENV=production');putenv('RECAPTCHA_DEV_BYPASS=true');$_SERVER['REMOTE_ADDR']='127.0.0.1';
try { Dorm_services::captcha('anything'); check(false,'production must reject missing reCAPTCHA settings'); }
catch (RuntimeException $e) { check($e->getCode()===503,'production never accepts local reCAPTCHA bypass'); }
putenv('APP_ENV=development');$_SERVER['REMOTE_ADDR']='203.0.113.1';
try { Dorm_services::captcha('anything'); check(false,'remote must reject bypass'); }
catch (RuntimeException $e) { check($e->getCode()===503,'remote requests never accept local reCAPTCHA bypass'); }
echo "All service checks passed.\n";
