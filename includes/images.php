<?php
declare(strict_types=1);

function prepare_question_image_upload(): array
{
    $upload = $_FILES['question_image'] ?? null;
    if ($upload === null || !is_array($upload)) {
        return ['status' => 'none'];
    }

    $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
    if (!is_int($error)) {
        return ['status' => 'error', 'message' => 'The image upload could not be read.'];
    }
    if ($error === UPLOAD_ERR_NO_FILE) {
        return ['status' => 'none'];
    }
    if ($error !== UPLOAD_ERR_OK) {
        $message = $error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE
            ? 'Choose an image no larger than 2 MB.'
            : 'The image could not be uploaded. Please try again.';

        return ['status' => 'error', 'message' => $message];
    }

    $temporaryPath = $upload['tmp_name'] ?? null;
    if (!is_string($temporaryPath) || !is_uploaded_file($temporaryPath)) {
        return ['status' => 'error', 'message' => 'The uploaded image could not be verified.'];
    }

    $fileSize = filesize($temporaryPath);
    if ($fileSize === false || $fileSize < 1 || $fileSize > 2 * 1024 * 1024) {
        return ['status' => 'error', 'message' => 'Choose an image no larger than 2 MB.'];
    }

    $contents = file_get_contents($temporaryPath);
    if ($contents === false) {
        return ['status' => 'error', 'message' => 'The uploaded image could not be read.'];
    }

    $imageInfo = @getimagesizefromstring($contents);
    if ($imageInfo === false) {
        return ['status' => 'error', 'message' => 'Choose a valid JPEG, PNG, or WebP image.'];
    }

    $allowedTypes = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_WEBP => 'image/webp',
    ];
    $mimeType = $allowedTypes[$imageInfo[2] ?? 0] ?? null;
    if ($mimeType === null || strlen($contents) !== $fileSize) {
        return ['status' => 'error', 'message' => 'Choose a valid JPEG, PNG, or WebP image.'];
    }

    $width = (int) ($imageInfo[0] ?? 0);
    $height = (int) ($imageInfo[1] ?? 0);
    if ($width < 1 || $height < 1 || $width > 6000 || $height > 6000 || $width * $height > 12000000) {
        return ['status' => 'error', 'message' => 'Choose an image no larger than 6,000 pixels per side or 12 megapixels.'];
    }

    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    if ($fileInfo->buffer($contents) !== $mimeType) {
        return ['status' => 'error', 'message' => 'The image file type could not be verified.'];
    }

    if (!function_exists('imagewebp')) {
        return ['status' => 'error', 'message' => 'Image uploads are unavailable because WebP support is not enabled on the server.'];
    }

    $image = @imagecreatefromstring($contents);
    if ($image === false) {
        return ['status' => 'error', 'message' => 'The image could not be safely decoded.'];
    }

    ob_start();
    $encoded = imagewebp($image, null, 82);
    $normalizedImage = ob_get_clean();
    imagedestroy($image);

    if (!$encoded || !is_string($normalizedImage) || $normalizedImage === '') {
        return ['status' => 'error', 'message' => 'The image could not be processed. Please try a different image.'];
    }
    if (strlen($normalizedImage) > 2 * 1024 * 1024) {
        return ['status' => 'error', 'message' => 'The processed image is too large. Choose a smaller image.'];
    }

    return [
        'status' => 'image',
        'data' => $normalizedImage,
        'mime_type' => 'image/webp',
    ];
}

function store_question_image(PDO $database, int $questionId, string $imageData, string $mimeType): void
{
    $statement = $database->prepare(
        'INSERT INTO question_images (question_id, image_data, mime_type, updated_at)
         VALUES (:question_id, :image_data, :mime_type, :updated_at)
         ON CONFLICT(question_id) DO UPDATE SET
            image_data = excluded.image_data,
            mime_type = excluded.mime_type,
            updated_at = excluded.updated_at'
    );
    $statement->bindValue(':question_id', $questionId, PDO::PARAM_INT);
    $statement->bindValue(':image_data', $imageData, PDO::PARAM_LOB);
    $statement->bindValue(':mime_type', $mimeType, PDO::PARAM_STR);
    $statement->bindValue(':updated_at', date('c'), PDO::PARAM_STR);
    $statement->execute();
}

function delete_question_image(PDO $database, int $questionId): void
{
    $statement = $database->prepare('DELETE FROM question_images WHERE question_id = :question_id');
    $statement->execute(['question_id' => $questionId]);
}
