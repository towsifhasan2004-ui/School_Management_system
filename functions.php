<?php

require_once __DIR__ . '/../config/app.php';

function e($value)
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf()
{
    $token = $_POST['csrf_token'] ?? '';

    if (
        empty($_SESSION['csrf_token']) ||
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        http_response_code(403);
        exit('Invalid security token. Please refresh the page.');
    }
}

function redirect($url)
{
    header("Location: " . $url);
    exit;
}