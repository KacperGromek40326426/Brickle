<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/functions.php';

$avatarId = $_GET['avatar'] ?? '';
if ($avatarId !== '') {
    if (!is_string($avatarId) || !ctype_digit($avatarId) || (int) $avatarId < 1) {
        http_response_code(404);
        exit;
    }

    $statement = $database->prepare('SELECT avatar_data FROM users WHERE id = :id');
    $statement->execute(['id' => (int) $avatarId]);
    $avatar = $statement->fetchColumn();
    if (!is_string($avatar) || $avatar === '') {
        http_response_code(404);
        exit;
    }

    header('Content-Type: image/webp');
    header('Content-Length: ' . strlen($avatar));
    header('Content-Disposition: inline; filename="avatar.webp"');
    header('X-Content-Type-Options: nosniff');
    echo $avatar;
    exit;
}

$questionId = $_GET['id'] ?? '';
if (!is_string($questionId) || !ctype_digit($questionId) || (int) $questionId < 1) {
    http_response_code(404);
    exit;
}

$statement = $database->prepare(
    'SELECT qig.image_data, qig.mime_type, q.id AS question_id,
        (q.quiz_date = :today) AS is_today,
        (q.quiz_date < :past_cutoff AND q.id IN (
            SELECT id FROM questions
            WHERE quiz_date < :archive_cutoff
            ORDER BY quiz_date DESC
            LIMIT 5
        )) AS is_recent_past,
        EXISTS (
            SELECT 1 FROM answers a
            WHERE a.question_id = q.id AND a.user_id IS NOT NULL
        ) AS has_public_timeline
     FROM question_image_gallery qig
     JOIN questions q ON q.id = qig.question_id
     WHERE qig.id = :image_id'
);
$statement->execute([
    'today' => date('Y-m-d'),
    'past_cutoff' => date('Y-m-d'),
    'archive_cutoff' => date('Y-m-d'),
    'image_id' => (int) $questionId,
]);
$image = $statement->fetch();
if (
    !$image
    || $image['mime_type'] !== 'image/webp'
    || (!(bool) $image['is_today'] && !(bool) $image['is_recent_past'] && !(bool) $image['has_public_timeline'] && !is_admin())
) {
    http_response_code(404);
    exit;
}

header('Content-Type: image/webp');
header('Content-Length: ' . strlen($image['image_data']));
header('Content-Disposition: inline; filename="question.webp"');
header('X-Content-Type-Options: nosniff');
echo $image['image_data'];
