<?php
// Run only against an empty/disposable DB with a name ending in _test.
define('PREVENT_DIRECT_ACCESS', true);
$testName = getenv('DB_NAME');
if (!$testName || !str_ends_with($testName, '_test')) throw new RuntimeException('A disposable *_test database is required.');
function config_item($key) { return $key === 'environment' ? 'development' : null; }
function database_config() {
    return ['main' => ['driver' => 'mysqli', 'hostname' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: 3306, 'username' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '', 'database' => getenv('DB_NAME'), 'charset' => 'utf8mb4']];
}
require dirname(__DIR__) . '/scheme/database/Database.php';
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
$db = new Database();
$db->raw('CREATE TEMPORARY TABLE dormfind_smoke (id INT AUTO_INCREMENT PRIMARY KEY,label VARCHAR(255),n INT NULL)');
$value = "x' OR 1=1 -- <script>alert(1)</script>";
$id = $db->table('dormfind_smoke')->insert(['label' => $value, 'n' => null]);
check($id === 1, 'Insert ID');
check($db->table('dormfind_smoke')->where('label', $value)->get()['label'] === $value, 'Bound input round trip');
check($db->table('dormfind_smoke')->where('label', "' OR 1=1 --")->get() === false, 'SQL injection prevention');
check($db->table('dormfind_smoke')->where('id', 1)->update(['n' => 5]) === 1, 'Update count');
$db->transaction();
$db->table('dormfind_smoke')->where('id', 1)->update(['n' => 6]);
$db->transaction();
$db->table('dormfind_smoke')->where('id', 1)->update(['n' => 7]);
$db->roll_back();
check($db->table('dormfind_smoke')->where('id', 1)->get()['n'] === 6, 'Nested rollback');
$db->roll_back();
check($db->table('dormfind_smoke')->where('id', 1)->get()['n'] === 5, 'Outer rollback');
$db->table('dormfind_smoke')->insert(['label' => 'other', 'n' => null]);
check($db->table('dormfind_smoke')->count() === 2, 'Count');
check(count($db->table('dormfind_smoke')->in('id', [1, 2])->get_all()) === 2, 'IN query');
check($db->table('dormfind_smoke')->select('id,label')->get_key_pair()[1] === $value, 'Key pairs');
check(count($db->table('dormfind_smoke')->select('n')->get_column()) === 2, 'NULL column is retained');
check($db->table('dormfind_smoke')->row()->id === 1, 'Object row');
try { $db->table('dormfind_smoke')->order_by('id', 'DESC; DROP TABLE users'); throw new RuntimeException('Sort injection accepted'); }
catch (InvalidArgumentException $e) {}
check($db->table('dormfind_smoke')->where('id', 1)->delete() === 1, 'Delete count');
echo "PASS: MySQLi CRUD, bound input, NULLs, result formats and nested transactions\n";
