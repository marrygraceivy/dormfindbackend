<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
$database['main'] = [
    'driver' => getenv('DB_DRIVER') ?: 'mysqli',
    'hostname' => getenv('DB_HOST') ?: 'localhost',
    'port' => getenv('DB_PORT') ?: '3306',
    'username' => getenv('DB_USER') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
    'database' => getenv('DB_NAME') ?: 'dormfind_db',
    'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
    'dbprefix' => getenv('DB_PREFIX') ?: '',
    'connect_timeout' => getenv('DB_CONNECT_TIMEOUT') ?: 5,
    'read_timeout' => getenv('DB_READ_TIMEOUT') ?: 10,
    'ssl_required' => filter_var(getenv('DB_SSL_REQUIRED') ?: (getenv('RENDER') ? 'true' : 'false'), FILTER_VALIDATE_BOOLEAN),
    'ssl_ca' => getenv('DB_SSL_CA') ?: '',
];
