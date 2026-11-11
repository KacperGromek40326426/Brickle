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

function is_user(): bool
{
    return isset($_SESSION['user_id']) && is_int($_SESSION['user_id']);
}

function valid_username(string $username): bool
{
    return preg_match('/\A[A-Za-z0-9_]{3,24}\z/', $username) === 1;
}

function load_user_brickle_streaks(PDO $database, int $userId, string $today): array
{
    $statement = $database->prepare(
        'SELECT q.id, EXISTS (
            SELECT 1 FROM answers a WHERE a.question_id = q.id AND a.user_id = :user_id
         ) AS answered
         FROM questions q
         WHERE q.quiz_date <= :today
         ORDER BY q.quiz_date ASC, q.id ASC'
    );
    $statement->execute(['user_id' => $userId, 'today' => $today]);
    $streak = 0;
    $streaks = [];
    foreach ($statement->fetchAll() as $brickle) {
        $streak = (bool) $brickle['answered'] ? $streak + 1 : 0;
        $streaks[(int) $brickle['id']] = $streak;
    }

    return $streaks;
}

function render_answer_identity(array $answer, int $streak = 0): void
{
    $userId = isset($answer['user_id']) ? (int) $answer['user_id'] : 0;
    $displayName = (string) ($answer['display_name'] ?? 'Anonymous');

    echo '<div class="answer-author">';
    if ($userId > 0) {
        if (!empty($answer['has_avatar'])) {
            echo '<img class="avatar avatar-small" src="image.php?avatar=' . $userId . '" alt="" loading="lazy">';
        } else {
            echo '<span class="avatar avatar-small avatar-placeholder" aria-hidden="true">'
                . e(substr($displayName, 0, 1)) . '</span>';
        }
        echo '<a class="profile-link" href="index.php?page=profile&amp;id=' . $userId . '">'
            . e($displayName) . '</a>';
        if ($streak > 0) {
            echo '<span class="streak-badge" title="' . $streak . ' consecutive brickles answered">'
                . $streak . ' brickle' . ($streak === 1 ? '' : 's') . '</span>';
        }
    } else {
        echo '<strong>' . e($displayName) . '</strong>';
    }
    echo '</div>';
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
    $database->beginTransaction();
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
            $database->commit();

            return false;
        }

        $statement = $database->prepare(
            'INSERT INTO request_rate_limits (bucket_hash, occurred_at) VALUES (:bucket_hash, :occurred_at)'
        );
        $statement->execute([
            'bucket_hash' => $bucketHash,
            'occurred_at' => $now,
        ]);
        $database->commit();

        return true;
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
}

function clear_rate_limit_bucket(PDO $database, string $bucketHash): void
{
    $statement = $database->prepare('DELETE FROM request_rate_limits WHERE bucket_hash = :bucket_hash');
    $statement->execute(['bucket_hash' => $bucketHash]);
}
