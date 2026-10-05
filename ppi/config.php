<?php
declare(strict_types=1);

const DB_HOST = 'sql103.infinityfree.com';
const DB_NAME = 'if0_43061467_sunsafe';
const DB_USER = 'if0_43061467';
const DB_PASS = 'Sunsafe2026';

const SESSION_NAME = 'sunsafe_session';

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

session_name(SESSION_NAME);

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => true,
    'path' => '/',
]);

session_start();