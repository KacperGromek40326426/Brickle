<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    set_exception_handler(static function (Throwable $exception): void {
        error_log('Brickle request failed: ' . get_class($exception) . ': ' . $exception->getMessage());
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store, private');
        }
        $message = 'Brickle is temporarily unavailable. Check the hosting configuration and server error log.';
        if ($exception instanceof RuntimeException) {
            foreach ([
                'PHP PDO is required.',
                'The configured PDO database driver is unavailable.',
                'No usable database is configured.',
                'Configure PDO SQLite or MySQL for this application.',
                'PHP sessions are required.',
                'The PHP session could not be started.',
                'The configured Brickle database could not be reached.',
                'The private SQLite directory',
                'The public document root or private SQLite directory',
            ] as $safePrefix) {
                if (str_starts_with($exception->getMessage(), $safePrefix)) {
                    $message = $exception->getMessage();
                    http_response_code(503);
                    break;
                }
            }
        }
        echo $message;
    });
}

$configPath = getenv('BRICKLE_CONFIG_PATH');
if ($configPath === false || $configPath === '') {
    $externalConfigPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'brickle-config.php';
    $localConfigPath = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
    $configPath = is_file($externalConfigPath) ? $externalConfigPath : $localConfigPath;
} elseif (!is_file($configPath)) {
    throw new RuntimeException('The configured Brickle settings file could not be found.');
}
$appConfig = [];
if (is_file($configPath)) {
    $loadedConfig = require $configPath;
    if (!is_array($loadedConfig)) {
        throw new RuntimeException('The Brickle configuration file must return an array.');
    }
    $appConfig = $loadedConfig;
}

$timezone = $appConfig['timezone'] ?? getenv('APP_TIMEZONE');
if (!is_string($timezone) || $timezone === '') {
    $timezone = 'UTC';
}
try {
    date_default_timezone_set($timezone);
} catch (ValueError $exception) {
    throw new RuntimeException('The configured application timezone is invalid.', 0, $exception);
}

$secureCookies = ($appConfig['secure_cookies'] ?? null) !== false && getenv('APP_SECURE_COOKIES') !== '0';
if (!extension_loaded('session')) {
    throw new RuntimeException('PHP sessions are required. Enable the session extension in the hosting control panel.');
}
if (PHP_SAPI !== 'cli' && $secureCookies) {
    $httpsValue = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    $isHttps = in_array($httpsValue, ['on', '1'], true)
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    if (!$isHttps) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Brickle is available only over HTTPS.');
    }
}
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
if (!session_start()) {
    throw new RuntimeException('The PHP session could not be started. Check the hosting session configuration.');
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, private');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
if ($secureCookies) {
    header('Strict-Transport-Security: max-age=31536000');
}

$databaseConfig = is_array($appConfig['database'] ?? null) ? $appConfig['database'] : [];
$dsn = getenv('BRICKLE_DB_DSN');
if (!is_string($dsn) || $dsn === '') {
    $dsn = $databaseConfig['dsn'] ?? '';
}
if (!is_string($dsn)) {
    throw new RuntimeException('The Brickle database DSN must be a string.');
}
$databaseUser = getenv('BRICKLE_DB_USER');
if (!is_string($databaseUser) || $databaseUser === '') {
    $databaseUser = $databaseConfig['username'] ?? null;
}
$databasePassword = getenv('BRICKLE_DB_PASSWORD');
if (!is_string($databasePassword)) {
    $databasePassword = $databaseConfig['password'] ?? null;
}

$storagePath = $appConfig['storage_path'] ?? getenv('PRIVATE_STORAGE_PATH');
if (!is_string($storagePath) || $storagePath === '') {
    $storagePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'brickle-private-storage';
}

function ensure_private_storage_directory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException('The private SQLite directory could not be created.');
    }
    if (!is_writable($path)) {
        throw new RuntimeException('The private SQLite directory is not writable by PHP.');
    }

    if (PHP_SAPI === 'cli') {
        return;
    }

    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $resolvedPath = realpath($path);
    if ($documentRoot === false || $resolvedPath === false) {
        throw new RuntimeException('The public document root or private SQLite directory could not be resolved.');
    }
    $documentRoot = rtrim($documentRoot, DIRECTORY_SEPARATOR);
    $documentRoot = $documentRoot === '' ? DIRECTORY_SEPARATOR : $documentRoot;
    $storageIsPublic = $documentRoot === DIRECTORY_SEPARATOR
        ? str_starts_with($resolvedPath, DIRECTORY_SEPARATOR)
        : ($resolvedPath === $documentRoot
            || str_starts_with($resolvedPath, $documentRoot . DIRECTORY_SEPARATOR));
    if ($storageIsPublic) {
        throw new RuntimeException('The private SQLite directory must be outside the public document root.');
    }
}

if (!class_exists(PDO::class)) {
    throw new RuntimeException('PHP PDO is required. Enable the PDO extension in the hosting control panel.');
}

if (is_string($dsn) && $dsn !== '') {
    $driver = strtolower(strtok($dsn, ':') ?: '');
    if (!in_array($driver, PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException(
            'The configured PDO database driver is unavailable. Enable pdo_' . $driver . ' or configure another supported database.'
        );
    }
    if ($driver === 'sqlite') {
        ensure_private_storage_directory($storagePath);
        $databasePath = rtrim($storagePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'quiz.sqlite';
        if (!str_contains($dsn, ':memory:') && !str_contains($dsn, $databasePath)) {
            throw new RuntimeException('SQLite must use the configured private storage directory.');
        }
        $dsn = 'sqlite:' . $databasePath;
    } elseif ($driver !== 'mysql') {
        throw new RuntimeException('Configure PDO SQLite or MySQL for this application.');
    }
} else {
    $availableDrivers = PDO::getAvailableDrivers();
    if (in_array('sqlite', $availableDrivers, true)) {
        ensure_private_storage_directory($storagePath);
        $dsn = 'sqlite:' . rtrim($storagePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'quiz.sqlite';
    } else {
        throw new RuntimeException(
            'No usable database is configured. Enable pdo_sqlite or provide MySQL PDO connection settings in the private Brickle configuration.'
        );
    }
}

try {
    $database = new PDO($dsn, is_string($databaseUser) ? $databaseUser : null, is_string($databasePassword) ? $databasePassword : null);
} catch (PDOException $exception) {
    throw new RuntimeException('The configured Brickle database could not be reached. Check the private database settings.', 0, $exception);
}
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $databasePath = rtrim($storagePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'quiz.sqlite';
    if (!is_file($databasePath) && DIRECTORY_SEPARATOR === '/' && !chmod($databasePath, 0600)) {
        throw new RuntimeException('The SQLite database file permissions could not be secured.');
    }
}

require_once __DIR__ . '/schema.php';
initialize_brickle_schema($database);

$secretStatement = $database->prepare(
    'SELECT secret_value FROM application_secrets WHERE secret_name = :secret_name'
);
$secretStatement->execute(['secret_name' => 'rate_limit_hmac']);
$rateLimitSecret = hex2bin((string) $secretStatement->fetchColumn());
if (!is_string($rateLimitSecret) || strlen($rateLimitSecret) !== 32) {
    throw new RuntimeException('The request rate-limit secret is invalid.');
}
