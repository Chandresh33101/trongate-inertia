<?php
define('BASE_URL', 'http://localhost/');
define('ENV', 'dev');
define('DEFAULT_MODULE', 'welcome');
define('DEFAULT_METHOD', 'index');
define('MODULE_ASSETS_TRIGGER', '_module');
define('ERROR_404', 'templates/error_404');

define('INTERCEPTORS', ['inertia' => 'intercept']);

define('USERS_ALLOWED_ORIGINS', [
    'http://localhost:3000',
    'https://your-nextjs-site.com',
]);

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = defined('USERS_ALLOWED_ORIGINS') ? USERS_ALLOWED_ORIGINS : [];

header('Vary: Origin');

if ($origin !== '' && in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-Requested-With, TrongateToken');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, OPTIONS');
    header('Access-Control-Max-Age: 86400');
}