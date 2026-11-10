<?php
declare(strict_types=1);

const QUESTION_IMAGE_LIMIT = 5;
const QUESTION_IMAGE_FILE_LIMIT = 2 * 1024 * 1024;
const QUESTION_IMAGE_TOTAL_LIMIT = 10 * 1024 * 1024;

function prepare_user_avatar_upload(): array
{
    $upload = $_FILES['avatar'] ?? null;
    if ($upload === null || !is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['status' => 'none'];
    }

    if (($upload['error'] ?? null) !== UPLOAD_ERR_OK) {
        return ['status' => 'error', 'message' => 'The profile picture could not be uploaded. Please try again.'];
    }

    $temporaryPath = $upload['tmp_name'] ?? null;
    if (!is_string($temporaryPath) || !is_uploaded_file($temporaryPath)) {
        return ['status' => 'error', 'message' => 'The profile picture upload could not be verified.'];
    }

    $fileSize = filesize($temporaryPath);
    if ($fileSize === false || $fileSize < 1 || $fileSize > QUESTION_IMAGE_FILE_LIMIT) {
        return ['status' => 'error', 'message' => 'The profile picture must be no larger than 2 MB.'];
    }

    $contents = file_get_contents($temporaryPath);
    if ($contents === false) {
        return ['status' => 'error', 'message' => 'The profile picture could not be read.'];
    }

    $imageInfo = @getimagesizefromstring($contents);
    $allowedTypes = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_WEBP => 'image/webp',
    ];
    $mimeType = $imageInfo === false ? null : ($allowedTypes[$imageInfo[2] ?? 0] ?? null);
    if ($mimeType === null || (new finfo(FILEINFO_MIME_TYPE))->buffer($contents) !== $mimeType) {
        return ['status' => 'error', 'message' => 'Choose a valid JPEG, PNG, or WebP profile picture.'];
    }

    $width = (int) ($imageInfo[0] ?? 0);
    $height = (int) ($imageInfo[1] ?? 0);
    if ($width < 1 || $height < 1 || $width > 6000 || $height > 6000 || $width * $height > 12000000) {
        return ['status' => 'error', 'message' => 'The profile picture must be no larger than 6,000 pixels per side or 12 megapixels.'];
    }
    if (!function_exists('imagewebp')) {
        return ['status' => 'error', 'message' => 'Profile picture uploads require WebP support on the server.'];
    }

    $source = @imagecreatefromstring($contents);
    if ($source === false) {
        return ['status' => 'error', 'message' => 'The profile picture could not be safely decoded.'];
    }

    $scale = min(1, 512 / max($width, $height));
    $targetWidth = max(1, (int) round($width * $scale));
    $targetHeight = max(1, (int) round($height * $scale));
    $avatar = imagecreatetruecolor($targetWidth, $targetHeight);
    if ($avatar === false) {
        imagedestroy($source);
        return ['status' => 'error', 'message' => 'The profile picture could not be processed.'];
    }

    imagealphablending($avatar, false);
    imagesavealpha($avatar, true);
    imagefill($avatar, 0, 0, imagecolorallocatealpha($avatar, 0, 0, 0, 127));
    imagecopyresampled($avatar, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
    imagedestroy($source);

    ob_start();
    $encoded = imagewebp($avatar, null, 82);
    $avatarData = ob_get_clean();
    imagedestroy($avatar);
    if (!$encoded || !is_string($avatarData) || $avatarData === '' || strlen($avatarData) > QUESTION_IMAGE_FILE_LIMIT) {
        return ['status' => 'error', 'message' => 'The profile picture could not be processed. Choose a smaller image.'];
    }

    return ['status' => 'image', 'data' => $avatarData];
}

function prepare_question_image_uploads(): array
{
    $upload = $_FILES['question_images'] ?? null;
    if ($upload === null || !is_array($upload)) {
        return ['status' => 'none'];
    }

    foreach (['name', 'tmp_name', 'error', 'size'] as $key) {
        if (!isset($upload[$key]) || !is_array($upload[$key])) {
            return ['status' => 'error', 'message' => 'The image uploads could not be read. Please try again.'];
        }
    }

    $selected = [];
    foreach ($upload['error'] as $index => $error) {
        if (!is_int($error)) {
            return ['status' => 'error', 'message' => 'The image uploads could not be read. Please try again.'];
        }
        if ($error !== UPLOAD_ERR_NO_FILE) {
            $selected[] = $index;
        }
    }

    if (!$selected) {
        return ['status' => 'none'];
    }
    if (count($selected) > QUESTION_IMAGE_LIMIT) {
        return ['status' => 'error', 'message' => 'Choose up to 5 images per brickle.'];
    }

    $images = [];
    $totalSize = 0;
    foreach ($selected as $index) {
        $error = $upload['error'][$index];
        if ($error !== UPLOAD_ERR_OK) {
            $message = $error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE
                ? 'Each image must be no larger than 2 MB. Select fewer or smaller images.'
                : 'An image could not be uploaded. Please try again.';

            return ['status' => 'error', 'message' => $message];
        }

        $temporaryPath = $upload['tmp_name'][$index] ?? null;
        if (!is_string($temporaryPath) || !is_uploaded_file($temporaryPath)) {
            return ['status' => 'error', 'message' => 'An uploaded image could not be verified.'];
        }

        $fileSize = filesize($temporaryPath);
        if ($fileSize === false || $fileSize < 1 || $fileSize > QUESTION_IMAGE_FILE_LIMIT) {
            return ['status' => 'error', 'message' => 'Each image must be no larger than 2 MB.'];
        }
        $totalSize += $fileSize;
        if ($totalSize > QUESTION_IMAGE_TOTAL_LIMIT) {
            return ['status' => 'error', 'message' => 'The selected images must total no more than 10 MB.'];
        }

        $contents = file_get_contents($temporaryPath);
        if ($contents === false) {
            return ['status' => 'error', 'message' => 'An uploaded image could not be read.'];
        }

        $imageInfo = @getimagesizefromstring($contents);
        if ($imageInfo === false) {
            return ['status' => 'error', 'message' => 'Choose valid JPEG, PNG, or WebP images.'];
        }

        $allowedTypes = [
            IMAGETYPE_JPEG => 'image/jpeg',
            IMAGETYPE_PNG => 'image/png',
            IMAGETYPE_WEBP => 'image/webp',
        ];
        $mimeType = $allowedTypes[$imageInfo[2] ?? 0] ?? null;
        if ($mimeType === null || strlen($contents) !== $fileSize) {
            return ['status' => 'error', 'message' => 'Choose valid JPEG, PNG, or WebP images.'];
        }

        $width = (int) ($imageInfo[0] ?? 0);
        $height = (int) ($imageInfo[1] ?? 0);
        if ($width < 1 || $height < 1 || $width > 6000 || $height > 6000 || $width * $height > 12000000) {
            return ['status' => 'error', 'message' => 'Each image must be no larger than 6,000 pixels per side or 12 megapixels.'];
        }

        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        if ($fileInfo->buffer($contents) !== $mimeType) {
            return ['status' => 'error', 'message' => 'An image file type could not be verified.'];
        }

        if (!function_exists('imagewebp')) {
            return ['status' => 'error', 'message' => 'Image uploads are unavailable because WebP support is not enabled on the server.'];
        }

        $image = @imagecreatefromstring($contents);
        if ($image === false) {
            return ['status' => 'error', 'message' => 'An image could not be safely decoded.'];
        }

        ob_start();
        $encoded = imagewebp($image, null, 82);
        $normalizedImage = ob_get_clean();
        imagedestroy($image);

        if (!$encoded || !is_string($normalizedImage) || $normalizedImage === '') {
            return ['status' => 'error', 'message' => 'An image could not be processed. Please try a different image.'];
        }
        if (strlen($normalizedImage) > QUESTION_IMAGE_FILE_LIMIT) {
            return ['status' => 'error', 'message' => 'A processed image is too large. Choose a smaller image.'];
        }

        $images[] = [
            'data' => $normalizedImage,
            'mime_type' => 'image/webp',
        ];
    }

    return ['status' => 'images', 'images' => $images];
}

function question_image_removal_ids(): array
{
    $submitted = $_POST['remove_image_ids'] ?? [];
    if (!is_array($submitted)) {
        return ['status' => 'error', 'message' => 'The image removals could not be read.'];
    }

    $ids = [];
    foreach ($submitted as $id) {
        if (!is_string($id) || !ctype_digit($id) || (int) $id < 1) {
            return ['status' => 'error', 'message' => 'An image selected for removal could not be found.'];
        }
        $ids[] = (int) $id;
    }

    return ['status' => 'ids', 'ids' => array_values(array_unique($ids))];
}

function store_question_images(PDO $database, int $questionId, array $images, array $removeIds = []): void
{
    if ($removeIds) {
        $placeholders = implode(',', array_fill(0, count($removeIds), '?'));
        $statement = $database->prepare(
            'DELETE FROM question_image_gallery WHERE question_id = ? AND id IN (' . $placeholders . ')'
        );
        $statement->execute(array_merge([$questionId], $removeIds));
    }

    $statement = $database->prepare(
        'SELECT COUNT(*) FROM question_image_gallery WHERE question_id = :question_id'
    );
    $statement->execute(['question_id' => $questionId]);
    $existingCount = (int) $statement->fetchColumn();
    if ($existingCount + count($images) > QUESTION_IMAGE_LIMIT) {
        throw new DomainException('A brickle can have up to 5 images. Remove an image or select fewer new images.');
    }

    if (!$images) {
        return;
    }

    $statement = $database->prepare(
        'SELECT COALESCE(MAX(sort_order), -1) FROM question_image_gallery WHERE question_id = :question_id'
    );
    $statement->execute(['question_id' => $questionId]);
    $sortOrder = (int) $statement->fetchColumn();

    $statement = $database->prepare(
        'INSERT INTO question_image_gallery (question_id, image_data, mime_type, sort_order, created_at)
         VALUES (:question_id, :image_data, :mime_type, :sort_order, :created_at)'
    );
    foreach ($images as $image) {
        $statement->bindValue(':question_id', $questionId, PDO::PARAM_INT);
        $statement->bindValue(':image_data', $image['data'], PDO::PARAM_LOB);
        $statement->bindValue(':mime_type', $image['mime_type'], PDO::PARAM_STR);
        $statement->bindValue(':sort_order', ++$sortOrder, PDO::PARAM_INT);
        $statement->bindValue(':created_at', date('c'), PDO::PARAM_STR);
        $statement->execute();
    }
}

function load_question_images(PDO $database, int $questionId): array
{
    $statement = $database->prepare(
        'SELECT id, sort_order FROM question_image_gallery WHERE question_id = :question_id ORDER BY sort_order, id'
    );
    $statement->execute(['question_id' => $questionId]);

    return $statement->fetchAll();
}

function render_question_gallery(array $images, string $className, string $altText): void
{
    if (!$images) {
        return;
    }

    echo '<div class="question-gallery ' . e($className) . '" data-carousel role="region" aria-roledescription="carousel" aria-label="Brickle images">';
    echo '<div class="gallery-slides">';
    foreach ($images as $index => $image) {
        $isCurrent = $index === 0;
        echo '<div class="gallery-slide" data-gallery-slide role="group" aria-roledescription="slide" aria-label="'
            . ($index + 1) . ' of ' . count($images) . '"' . ($isCurrent ? '' : ' hidden') . '>';
        echo '<img class="question-image" src="image.php?id=' . (int) $image['id'] . '" alt="'
            . e($altText) . ($index + 1) . ' of ' . count($images) . '" loading="lazy">';
        echo '</div>';
    }
    echo '</div>';

    if (count($images) > 1) {
        echo '<div class="gallery-controls">';
        echo '<button class="gallery-arrow" type="button" data-gallery-previous aria-label="Previous image">‹</button>';
        echo '<div class="gallery-indicators" aria-label="Choose an image">';
        foreach ($images as $index => $image) {
            echo '<button class="gallery-indicator" type="button" data-gallery-to="' . $index
                . '" aria-label="Show image ' . ($index + 1) . ' of ' . count($images) . '"'
                . ($index === 0 ? ' aria-current="true"' : '') . '></button>';
        }
        echo '</div>';
        echo '<span class="gallery-position" data-gallery-position aria-live="polite">1 / ' . count($images) . '</span>';
        echo '<button class="gallery-arrow" type="button" data-gallery-next aria-label="Next image">›</button>';
        echo '</div>';
    }
    echo '</div>';
}
