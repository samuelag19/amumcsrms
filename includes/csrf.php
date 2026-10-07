<?php

function ensure_csrf_session(): void
{
    require_once __DIR__ . '/session.php';
    start_app_session();
}

function csrf_token(): string
{
    ensure_csrf_session();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' .
        htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function require_valid_csrf_token(): void
{
    ensure_csrf_session();

    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals(csrf_token(), $submittedToken)) {
        http_response_code(403);
        exit('Invalid or missing security token.');
    }
}
