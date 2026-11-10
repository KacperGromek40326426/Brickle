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
$database->exec(
    'CREATE TABLE IF NOT EXISTS question_images (
        question_id INTEGER PRIMARY KEY REFERENCES questions(id) ON DELETE CASCADE,
        image_data BLOB NOT NULL,
        mime_type TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )'
);
$database->exec(
    'CREATE TABLE IF NOT EXISTS question_image_gallery (
        id INTEGER PRIMARY KEY,
        question_id INTEGER NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
        image_data BLOB NOT NULL,
        mime_type TEXT NOT NULL,
        sort_order INTEGER NOT NULL,
        created_at TEXT NOT NULL,
        UNIQUE (question_id, sort_order)
    )'
);
$database->exec(
    'INSERT OR IGNORE INTO question_image_gallery (question_id, image_data, mime_type, sort_order, created_at)
     SELECT qi.question_id, qi.image_data, qi.mime_type, 0, qi.updated_at
     FROM question_images qi
     WHERE NOT EXISTS (
        SELECT 1 FROM question_image_gallery qig WHERE qig.question_id = qi.question_id
     )'
);
$database->exec(
    'DELETE FROM question_images
     WHERE question_id IN (SELECT DISTINCT question_id FROM question_image_gallery)'
);
$database->exec('CREATE INDEX IF NOT EXISTS answers_question_id_idx ON answers(question_id, created_at)');
$database->exec('CREATE INDEX IF NOT EXISTS question_image_gallery_question_idx ON question_image_gallery(question_id, sort_order)');
$database->exec(
    'CREATE TABLE IF NOT EXISTS request_rate_limits (
        bucket_hash TEXT NOT NULL,
        occurred_at INTEGER NOT NULL
    )'
);
$database->exec('CREATE INDEX IF NOT EXISTS request_rate_limits_bucket_idx ON request_rate_limits(bucket_hash, occurred_at)');
$database->exec('CREATE INDEX IF NOT EXISTS request_rate_limits_expiry_idx ON request_rate_limits(occurred_at)');
$database->exec(
    'CREATE TABLE IF NOT EXISTS application_secrets (
        secret_name TEXT PRIMARY KEY,
        secret_value TEXT NOT NULL
    )'
);
$secretStatement = $database->prepare(
    'INSERT OR IGNORE INTO application_secrets (secret_name, secret_value)
     VALUES (:secret_name, :secret_value)'
);
$secretStatement->execute([
    'secret_name' => 'rate_limit_hmac',
    'secret_value' => bin2hex(random_bytes(32)),
]);
$secretStatement = $database->prepare(
    'SELECT secret_value FROM application_secrets WHERE secret_name = :secret_name'
);
$secretStatement->execute(['secret_name' => 'rate_limit_hmac']);
$rateLimitSecret = hex2bin((string) $secretStatement->fetchColumn());
if (!is_string($rateLimitSecret) || strlen($rateLimitSecret) !== 32) {
    throw new RuntimeException('The request rate-limit secret is invalid.');
}
