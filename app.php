<?php

date_default_timezone_set('Asia/Dhaka');

define('APP_NAME', 'Sezua International School');
define('BASE_URL', '/sezua');

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}