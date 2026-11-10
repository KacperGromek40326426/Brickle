<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function require_csrf(): void
{
    $submitted = $_POST['csrf_token'] ?? null;
    if (!is_string($submitted) || !hash_equals(csrf_token(), $submitted)) {
        http_response_code(400);
        exit('The form expired or was invalid. Please go back and try again.');
    }
}

function flash(string $message, string $kind = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'kind' => $kind];
}

function take_flash(): ?array
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($message) ? $message : null;
}

function redirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

function is_admin(): bool
{
    return isset($_SESSION['admin_id']) && is_int($_SESSION['admin_id']);
}

function post_value(string $key): string
{
    $value = $_POST[$key] ?? '';

    return is_string($value) ? trim($value) : '';
}

function post_raw(string $key): string
{
    $value = $_POST[$key] ?? '';

    return is_string($value) ? $value : '';
}

function valid_quiz_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return $date !== false && $date->format('Y-m-d') === $value;
}

function client_rate_limit_bucket(string $secret, string $scope, string $identity = ''): string
{
    $clientAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!is_string($clientAddress) || filter_var($clientAddress, FILTER_VALIDATE_IP) === false) {
        throw new RuntimeException('The client address is unavailable for request rate limiting.');
    }

    return hash_hmac('sha256', $scope . "\0" . $clientAddress . "\0" . $identity, $secret);
}

function consume_rate_limit(PDO $database, string $bucketHash, int $limit, int $windowSeconds): bool
{
    $now = time();
    $database->exec('BEGIN IMMEDIATE');
    try {
        $statement = $database->prepare('DELETE FROM request_rate_limits WHERE occurred_at <= :expiry');
        $statement->execute(['expiry' => $now - 86400]);

        $statement = $database->prepare(
            'SELECT COUNT(*) FROM request_rate_limits
             WHERE bucket_hash = :bucket_hash AND occurred_at > :window_start'
        );
        $statement->execute([
            'bucket_hash' => $bucketHash,
            'window_start' => $now - $windowSeconds,
        ]);
        if ((int) $statement->fetchColumn() >= $limit) {
            $database->exec('COMMIT');

            return false;
        }

        $statement = $database->prepare(
            'INSERT INTO request_rate_limits (bucket_hash, occurred_at) VALUES (:bucket_hash, :occurred_at)'
        );
        $statement->execute([
            'bucket_hash' => $bucketHash,
            'occurred_at' => $now,
        ]);
        $database->exec('COMMIT');

        return true;
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->exec('ROLLBACK');
        }
        throw $exception;
    }
}

function clear_rate_limit_bucket(PDO $database, string $bucketHash): void
{
    $statement = $database->prepare('DELETE FROM request_rate_limits WHERE bucket_hash = :bucket_hash');
    $statement->execute(['bucket_hash' => $bucketHash]);
}
