<?php
$routes = [
    'tg-admin'        => 'login/login/tg-admin',

    'api/register'    => 'users/register',
    'api/login'       => 'users/login',
    'api/logout'      => 'users/logout',
    'api/user-info'   => 'users/me',
];
define('CUSTOM_ROUTES', $routes);