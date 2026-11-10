<?php
declare(strict_types=1);

$timezone = getenv('APP_TIMEZONE');
if ($timezone === false || $timezone === '') {
    $timezone = 'UTC';
}
date_default_timezone_set($timezone);

$secureCookies = getenv('APP_SECURE_COOKIES') !== '0';
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secureCookies,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, private');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
if ($secureCookies) {
    header('Strict-Transport-Security: max-age=31536000');
}

$storagePath = getenv('PRIVATE_STORAGE_PATH');
if ($storagePath === false || $storagePath === '') {
    $storagePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'brickle-private-storage';
}
$storageExists = is_dir($storagePath);
if (!$storageExists) {
    if (!mkdir($storagePath, 0700, true) && !is_dir($storagePath)) {
        throw new RuntimeException('The private database directory could not be created.');
    }
}
if (!is_writable($storagePath)) {
    throw new RuntimeException('The private database directory is not writable by PHP.');
}

$databasePath = rtrim($storagePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'quiz.sqlite';
$databaseExists = is_file($databasePath);
$database = new PDO('sqlite:' . $databasePath);
if (!$databaseExists && DIRECTORY_SEPARATOR === '/' && !chmod($databasePath, 0600)) {
    throw new RuntimeException('The database file permissions could not be secured.');
}
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->exec('PRAGMA foreign_keys = ON');
$database->exec('PRAGMA busy_timeout = 5000');
$database->exec(
    'CREATE TABLE IF NOT EXISTS admins (
        id INTEGER PRIMARY KEY,
        email TEXT NOT NULL UNIQUE COLLATE NOCASE,
        password_hash TEXT NOT NULL,
        created_at TEXT NOT NULL
    )'
);
$database->exec(
    'CREATE TABLE IF NOT EXISTS questions (
        id INTEGER PRIMARY KEY,
        quiz_date TEXT NOT NULL UNIQUE,
        prompt TEXT NOT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )'
);
$database->exec(
    'CREATE TABLE IF NOT EXISTS answers (
        id INTEGER PRIMARY KEY,
        question_id INTEGER NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
        display_name TEXT,
        answer TEXT NOT NULL,
        created_at TEXT NOT NULL
    )'
);
$database->exec('CREATE INDEX IF NOT EXISTS answers_question_id_idx ON answers(question_id, created_at)');
