<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/functions.php';

$questionId = $_GET['id'] ?? '';
if (!is_string($questionId) || !ctype_digit($questionId) || (int) $questionId < 1) {
    http_response_code(404);
    exit;
}

$statement = $database->prepare(
    'SELECT qi.image_data, qi.mime_type, q.quiz_date
     FROM question_images qi
     JOIN questions q ON q.id = qi.question_id
     WHERE qi.question_id = :question_id'
);
$statement->execute(['question_id' => (int) $questionId]);
$image = $statement->fetch();
if (
    !$image
    || $image['mime_type'] !== 'image/webp'
    || ($image['quiz_date'] !== date('Y-m-d') && !is_admin())
) {
    http_response_code(404);
    exit;
}

header('Content-Type: image/webp');
header('Content-Length: ' . strlen($image['image_data']));
header('Content-Disposition: inline; filename="question.webp"');
header('X-Content-Type-Options: nosniff');
echo $image['image_data'];
