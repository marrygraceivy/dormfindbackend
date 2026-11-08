<?php
define('PREVENT_DIRECT_ACCESS', true);
function config_item($key) { return null; }
require dirname(__DIR__) . '/app/libraries/Dorm_services.php';
$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 30;
try {
    Dorm_services::request('https://example.invalid/');
    throw new RuntimeException('Expired provider budget was accepted.');
} catch (RuntimeException $e) {
    if ($e->getCode() !== 502) throw $e;
}
echo "PASS: exhausted provider budget fails before any network call\n";
