<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/images.php';

$page = $_GET['page'] ?? 'landing';
$isLandingPage = $page === 'landing';
$isTodayPage = $page === 'today';
$isAdminPage = $page === 'admin';
$isYesterdaysPage = $page === 'yesterdays';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post_value('action');

    if ($action === 'login') {
        $email = post_value('email');
        $password = post_raw('password');
        $clientBucket = client_rate_limit_bucket($rateLimitSecret, 'admin-login');
        $identityBucket = client_rate_limit_bucket(
            $rateLimitSecret,
            'admin-login-identity',
            strtolower(substr($email, 0, 254))
        );
        $clientAllowed = consume_rate_limit($database, $clientBucket, 30, 900);
        $identityAllowed = consume_rate_limit($database, $identityBucket, 5, 600);
        if (!$clientAllowed || !$identityAllowed) {
            flash('Too many sign-in attempts. Try again in a few minutes.', 'error');
            redirect('index.php?page=admin');
        }

        $admin = false;
        if (strlen($email) <= 254 && strlen($password) <= 128) {
            $statement = $database->prepare('SELECT id, email, password_hash FROM admins WHERE email = :email');
            $statement->execute(['email' => $email]);
            $admin = $statement->fetch();
        }

        if ($admin && password_verify($password, $admin['password_hash'])) {
            clear_rate_limit_bucket($database, $identityBucket);
            session_regenerate_id(true);
            unset($_SESSION['csrf_token']);
            $_SESSION['admin_id'] = (int) $admin['id'];
            $_SESSION['admin_email'] = $admin['email'];
            csrf_token();
            flash('Welcome back.');
            redirect('index.php?page=admin');
        }

        flash('Email or password is incorrect.', 'error');
        redirect('index.php?page=admin');
    }

    if ($action === 'user_register') {
        $username = post_value('username');
        $password = post_raw('password');
        $clientBucket = client_rate_limit_bucket($rateLimitSecret, 'user-registration');
        $hourBucket = client_rate_limit_bucket($rateLimitSecret, 'user-registration-hour');
        if (
            !consume_rate_limit($database, $clientBucket, 5, 3600)
            || !consume_rate_limit($database, $hourBucket, 20, 86400)
        ) {
            flash('Too many accounts were created from this connection. Try again later.', 'error');
            redirect('index.php?page=account&mode=register');
        }
        if (!valid_username($username)) {
            flash('Choose a username with 3-24 letters, numbers, or underscores.', 'error');
            redirect('index.php?page=account&mode=register');
        }
        if (strlen($password) < 14 || strlen($password) > 128) {
            flash('Choose a password between 14 and 128 characters.', 'error');
            redirect('index.php?page=account&mode=register');
        }

        $avatarUpload = prepare_user_avatar_upload();
        if ($avatarUpload['status'] === 'error') {
            flash($avatarUpload['message'], 'error');
            redirect('index.php?page=account&mode=register');
        }
        $statement = $database->prepare('SELECT id FROM users WHERE username = :username');
        $statement->execute(['username' => $username]);
        if ($statement->fetch()) {
            flash('That username is already taken. Choose another one.', 'error');
            redirect('index.php?page=account&mode=register');
        }

        $statement = $database->prepare(
            'INSERT INTO users (username, password_hash, avatar_data, avatar_mime_type, created_at, updated_at)
             VALUES (:username, :password_hash, :avatar_data, :avatar_mime_type, :created_at, :updated_at)'
        );
        $statement->bindValue(':username', $username, PDO::PARAM_STR);
        $statement->bindValue(':password_hash', password_hash($password, PASSWORD_DEFAULT), PDO::PARAM_STR);
        $statement->bindValue(':avatar_data', $avatarUpload['data'] ?? null, $avatarUpload['status'] === 'image' ? PDO::PARAM_LOB : PDO::PARAM_NULL);
        $statement->bindValue(':avatar_mime_type', $avatarUpload['mime_type'] ?? null, $avatarUpload['status'] === 'image' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $statement->bindValue(':created_at', date('c'), PDO::PARAM_STR);
        $statement->bindValue(':updated_at', date('c'), PDO::PARAM_STR);
        try {
            $statement->execute();
        } catch (PDOException $exception) {
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }
            flash('That username is already taken. Choose another one.', 'error');
            redirect('index.php?page=account&mode=register');
        }

        session_regenerate_id(true);
        unset($_SESSION['csrf_token']);
        $_SESSION['user_id'] = (int) $database->lastInsertId();
        $_SESSION['username'] = $username;
        csrf_token();
        flash('Welcome to Brickle. Your public profile is ready.');
        redirect('index.php?page=profile&id=' . (int) $_SESSION['user_id']);
    }

    if ($action === 'user_login') {
        $username = post_value('username');
        $password = post_raw('password');
        $clientBucket = client_rate_limit_bucket($rateLimitSecret, 'user-login');
        $identityBucket = client_rate_limit_bucket(
            $rateLimitSecret,
            'user-login-identity',
            strtolower(substr($username, 0, 24))
        );
        if (
            !consume_rate_limit($database, $clientBucket, 30, 900)
            || !consume_rate_limit($database, $identityBucket, 5, 600)
        ) {
            flash('Too many sign-in attempts. Try again in a few minutes.', 'error');
            redirect('index.php?page=account&mode=login');
        }

        $user = false;
        if (valid_username($username) && strlen($password) <= 128) {
            $statement = $database->prepare(
                'SELECT id, username, password_hash FROM users WHERE username = :username'
            );
            $statement->execute(['username' => $username]);
            $user = $statement->fetch();
        }
        if ($user && password_verify($password, $user['password_hash'])) {
            clear_rate_limit_bucket($database, $identityBucket);
            session_regenerate_id(true);
            unset($_SESSION['csrf_token']);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['username'] = $user['username'];
            csrf_token();
            flash('You are signed in.');
            redirect('index.php?page=profile&id=' . (int) $user['id']);
        }

        flash('Username or password is incorrect.', 'error');
        redirect('index.php?page=account&mode=login');
    }

    if (!is_admin() && !in_array($action, ['submit_answer', 'user_logout', 'update_user_profile', 'change_user_password'], true)) {
        http_response_code(403);
        exit('Please sign in to continue.');
    }

    if ($action === 'user_logout') {
        unset($_SESSION['user_id'], $_SESSION['username']);
        session_regenerate_id(true);
        unset($_SESSION['csrf_token']);
        csrf_token();
        flash('You are signed out.');
        redirect('index.php');
    }

    if ($action === 'update_user_profile') {
        if (!is_user()) {
            http_response_code(403);
            exit('Please sign in to update your profile.');
        }

        $username = post_value('username');
        $avatarUpload = prepare_user_avatar_upload();
        $removeAvatar = ($_POST['remove_avatar'] ?? '') === '1';
        if (!valid_username($username)) {
            flash('Choose a username with 3-24 letters, numbers, or underscores.', 'error');
            redirect('index.php?page=account');
        }
        if ($avatarUpload['status'] === 'error') {
            flash($avatarUpload['message'], 'error');
            redirect('index.php?page=account');
        }
        if ($removeAvatar && $avatarUpload['status'] === 'image') {
            flash('Choose a new profile picture or remove the current one, not both.', 'error');
            redirect('index.php?page=account');
        }

        $statement = $database->prepare('SELECT id FROM users WHERE username = :username AND id != :id');
        $statement->execute(['username' => $username, 'id' => (int) $_SESSION['user_id']]);
        if ($statement->fetch()) {
            flash('That username is already taken. Choose another one.', 'error');
            redirect('index.php?page=account');
        }

        if ($avatarUpload['status'] === 'image') {
            $statement = $database->prepare(
                'UPDATE users SET username = :username, avatar_data = :avatar_data, avatar_mime_type = :avatar_mime_type, updated_at = :updated_at WHERE id = :id'
            );
            $statement->bindValue(':avatar_data', $avatarUpload['data'], PDO::PARAM_LOB);
            $statement->bindValue(':avatar_mime_type', $avatarUpload['mime_type'], PDO::PARAM_STR);
        } elseif ($removeAvatar) {
            $statement = $database->prepare(
                'UPDATE users SET username = :username, avatar_data = NULL, avatar_mime_type = NULL, updated_at = :updated_at WHERE id = :id'
            );
        } else {
            $statement = $database->prepare(
                'UPDATE users SET username = :username, updated_at = :updated_at WHERE id = :id'
            );
        }
        $statement->bindValue(':username', $username, PDO::PARAM_STR);
        $statement->bindValue(':updated_at', date('c'), PDO::PARAM_STR);
        $statement->bindValue(':id', (int) $_SESSION['user_id'], PDO::PARAM_INT);
        try {
            $statement->execute();
        } catch (PDOException $exception) {
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }
            flash('That username is already taken. Choose another one.', 'error');
            redirect('index.php?page=account');
        }

        $_SESSION['username'] = $username;
        flash('Your profile has been updated.');
        redirect('index.php?page=account');
    }

    if ($action === 'change_user_password') {
        if (!is_user()) {
            http_response_code(403);
            exit('Please sign in to change your password.');
        }

        $currentPassword = post_raw('current_password');
        $newPassword = post_raw('new_password');
        $confirmPassword = post_raw('confirm_password');
        $statement = $database->prepare('SELECT password_hash FROM users WHERE id = :id');
        $statement->execute(['id' => (int) $_SESSION['user_id']]);
        $user = $statement->fetch();
        if (!$user || strlen($currentPassword) > 128 || !password_verify($currentPassword, $user['password_hash'])) {
            flash('Your current password is incorrect.', 'error');
            redirect('index.php?page=account#password');
        }
        if (strlen($newPassword) < 14 || strlen($newPassword) > 128) {
            flash('Choose a new password between 14 and 128 characters.', 'error');
            redirect('index.php?page=account#password');
        }
        if ($newPassword !== $confirmPassword) {
            flash('The new passwords do not match.', 'error');
            redirect('index.php?page=account#password');
        }

        $statement = $database->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
        $statement->execute([
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'id' => (int) $_SESSION['user_id'],
        ]);
        session_regenerate_id(true);
        unset($_SESSION['csrf_token']);
        csrf_token();
        flash('Your password has been changed.');
        redirect('index.php?page=account#password');
    }

    if ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        csrf_token();
        flash('You are signed out.');
        redirect('index.php');
    }

    if ($action === 'save_question') {
        $prompt = post_value('prompt');
        $quizDate = post_value('quiz_date');
        $questionId = post_value('question_id');
        $imageUpload = prepare_question_image_uploads();

        if ($imageUpload['status'] === 'error') {
            flash($imageUpload['message'], 'error');
            redirect('index.php?page=admin' . ($questionId !== '' && ctype_digit($questionId) ? '&edit=' . $questionId : '') . '#schedule');
        }
        $newImages = $imageUpload['images'] ?? [];

        if ($prompt === '' || strlen($prompt) > 960) {
            flash('Enter a brickle prompt of up to 240 characters.', 'error');
            redirect('index.php?page=admin#schedule');
        }

        if ($questionId !== '' && !ctype_digit($questionId)) {
            flash('That brickle could not be found.', 'error');
            redirect('index.php?page=admin#schedule');
        }

        if ($questionId !== '') {
            $removalResult = question_image_removal_ids();
            if ($removalResult['status'] === 'error') {
                flash($removalResult['message'], 'error');
                redirect('index.php?page=admin&edit=' . (int) $questionId . '#schedule');
            }
            $removeImageIds = $removalResult['ids'];

            $statement = $database->prepare('SELECT id FROM questions WHERE id = :id');
            $statement->execute(['id' => (int) $questionId]);
            if (!$statement->fetch()) {
                flash('That brickle could not be found.', 'error');
                redirect('index.php?page=admin#schedule');
            }

            $existingImages = load_question_images($database, (int) $questionId);
            $existingImageIds = array_map(static fn(array $image): int => (int) $image['id'], $existingImages);
            if (array_diff($removeImageIds, $existingImageIds)) {
                flash('An image selected for removal does not belong to this brickle.', 'error');
                redirect('index.php?page=admin&edit=' . (int) $questionId . '#schedule');
            }

            $remainingImages = count($existingImages) - count($removeImageIds);
            if ($remainingImages + count($newImages) > QUESTION_IMAGE_LIMIT) {
                flash('A brickle can have up to 5 images. Remove an image or select fewer new images.', 'error');
                redirect('index.php?page=admin&edit=' . (int) $questionId . '#schedule');
            }

            $database->beginTransaction();
            try {
                $statement = $database->prepare(
                    'UPDATE questions SET prompt = :prompt, updated_at = :updated_at WHERE id = :id'
                );
                $statement->execute([
                    'prompt' => $prompt,
                    'updated_at' => date('c'),
                    'id' => (int) $questionId,
                ]);
                store_question_images($database, (int) $questionId, $newImages, $removeImageIds);
                $database->commit();
            } catch (DomainException $exception) {
                if ($database->inTransaction()) {
                    $database->rollBack();
                }
                flash($exception->getMessage(), 'error');
                redirect('index.php?page=admin&edit=' . (int) $questionId . '#schedule');
            } catch (Throwable $exception) {
                if ($database->inTransaction()) {
                    $database->rollBack();
                }
                throw $exception;
            }
            flash('Brickle updated. Its scheduled date has not changed.');
            redirect('index.php?page=admin&edit=' . (int) $questionId . '#schedule');
        }

        if (!valid_quiz_date($quizDate) || $quizDate < date('Y-m-d')) {
            flash('Choose today or a future date for a new brickle.', 'error');
            redirect('index.php?page=admin#schedule');
        }

        $statement = $database->prepare('SELECT id FROM questions WHERE quiz_date = :quiz_date');
        $statement->execute(['quiz_date' => $quizDate]);
        if ($statement->fetch()) {
            flash('A brickle is already scheduled for that date. Edit or delete it from the lineup.', 'error');
            redirect('index.php?page=admin#schedule');
        }

        $database->beginTransaction();
        try {
            $statement = $database->prepare(
                'INSERT INTO questions (quiz_date, prompt, created_at, updated_at) VALUES (:quiz_date, :prompt, :created_at, :updated_at)'
            );
            $now = date('c');
            $statement->execute([
                'quiz_date' => $quizDate,
                'prompt' => $prompt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $newId = (int) $database->lastInsertId();
            store_question_images($database, $newId, $newImages);
            $database->commit();
        } catch (DomainException $exception) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            flash($exception->getMessage(), 'error');
            redirect('index.php?page=admin#schedule');
        } catch (Throwable $exception) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $exception;
        }
        flash('Brickle scheduled.');
        redirect('index.php?page=admin&edit=' . $newId . '#schedule');
    }

    if ($action === 'delete_question') {
        $questionId = post_value('question_id');
        if (!ctype_digit($questionId)) {
            flash('That brickle could not be found.', 'error');
            redirect('index.php?page=admin#schedule');
        }

        $statement = $database->prepare('DELETE FROM questions WHERE id = :id');
        $statement->execute(['id' => (int) $questionId]);
        if ($statement->rowCount() !== 1) {
            flash('That brickle could not be found. It may have already been deleted.', 'error');
            redirect('index.php?page=admin#schedule');
        }

        flash('The brickle and all of its answers have been deleted.');
        redirect('index.php?page=admin#schedule');
    }

    if ($action === 'change_password') {
        $currentPassword = post_raw('current_password');
        $newPassword = post_raw('new_password');
        $confirmPassword = post_raw('confirm_password');
        $statement = $database->prepare('SELECT password_hash FROM admins WHERE id = :id');
        $statement->execute(['id' => (int) $_SESSION['admin_id']]);
        $admin = $statement->fetch();

        if (!$admin || strlen($currentPassword) > 128 || !password_verify($currentPassword, $admin['password_hash'])) {
            flash('Your current password is incorrect.', 'error');
            redirect('index.php?page=admin#password');
        }
        if (strlen($newPassword) < 14 || strlen($newPassword) > 128) {
            flash('Choose a new password between 14 and 128 characters.', 'error');
            redirect('index.php?page=admin#password');
        }
        if ($newPassword !== $confirmPassword) {
            flash('The new passwords do not match.', 'error');
            redirect('index.php?page=admin#password');
        }

        $statement = $database->prepare('UPDATE admins SET password_hash = :password_hash WHERE id = :id');
        $statement->execute([
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'id' => (int) $_SESSION['admin_id'],
        ]);
        session_regenerate_id(true);
        unset($_SESSION['csrf_token']);
        csrf_token();
        flash('Your password has been changed.');
        redirect('index.php?page=admin#password');
    }

    if ($action === 'submit_answer') {
        $questionId = post_value('question_id');
        $answer = post_value('answer');
        $displayName = post_value('display_name');
        $userId = is_user() ? (int) $_SESSION['user_id'] : null;
        $lastAnswer = (int) ($_SESSION['last_answer_at'] ?? 0);

        if ($lastAnswer > time() - 15) {
            flash('Please wait a moment before sending another answer.', 'error');
            redirect('index.php?page=today#answer');
        }
        if (!ctype_digit($questionId) || $answer === '' || strlen($answer) > 4800) {
            flash('Write an answer of up to 1,200 characters.', 'error');
            redirect('index.php?page=today#answer');
        }
        if ($userId === null && strlen($displayName) > 128) {
            flash('Your display name must be 32 characters or fewer.', 'error');
            redirect('index.php?page=today#answer');
        }

        $statement = $database->prepare('SELECT id FROM questions WHERE id = :id AND quiz_date = :quiz_date');
        $statement->execute(['id' => (int) $questionId, 'quiz_date' => date('Y-m-d')]);
        if (!$statement->fetch()) {
            flash("Today's brickle is no longer available.", 'error');
            redirect('index.php?page=today');
        }

        $answerBucket = client_rate_limit_bucket($rateLimitSecret, 'public-answer');
        if (!consume_rate_limit($database, $answerBucket, 10, 60)) {
            flash('Too many answers were sent from this connection. Please try again in a minute.', 'error');
            redirect('index.php?page=today#answer');
        }

        if ($userId !== null) {
            $statement = $database->prepare(
                'SELECT id FROM answers WHERE question_id = :question_id AND user_id = :user_id'
            );
            $statement->execute([
                'question_id' => (int) $questionId,
                'user_id' => $userId,
            ]);
            if ($statement->fetch()) {
                flash("You have already answered today's brickle.", 'error');
                redirect('index.php?page=today#answer');
            }
        }

        $statement = $database->prepare(
            'INSERT INTO answers (question_id, user_id, display_name, answer, created_at)
             VALUES (:question_id, :user_id, :display_name, :answer, :created_at)'
        );
        try {
            $statement->execute([
                'question_id' => (int) $questionId,
                'user_id' => $userId,
                'display_name' => $userId === null && $displayName !== '' ? $displayName : null,
                'answer' => $answer,
                'created_at' => date('c'),
            ]);
        } catch (PDOException $exception) {
            if ($userId === null || $exception->getCode() !== '23000') {
                throw $exception;
            }
            flash("You have already answered today's brickle.", 'error');
            redirect('index.php?page=today#answer');
        }
        $_SESSION['last_answer_at'] = time();
        flash('Thanks for sharing your answer!');
        redirect('index.php?page=today#answers');
    }

    flash('That action was not recognized.', 'error');
    redirect($isAdminPage ? 'index.php?page=admin' : 'index.php');
}

$today = date('Y-m-d');
$statement = $database->prepare(
    'SELECT q.id, q.quiz_date, q.prompt,
        (SELECT COUNT(*) FROM question_image_gallery qi WHERE qi.question_id = q.id) AS image_count
     FROM questions q WHERE q.quiz_date = :quiz_date'
);
$statement->execute(['quiz_date' => $today]);
$todayQuestion = $statement->fetch() ?: null;
$todayImages = [];
if ($todayQuestion) {
    $todayImages = load_question_images($database, (int) $todayQuestion['id']);
}
$publicAnswers = [];
$publicStreaks = [];
if ($todayQuestion) {
    $statement = $database->prepare(
        "SELECT a.user_id, COALESCE(u.username, a.display_name, 'Anonymous') AS display_name,
            (u.avatar_data IS NOT NULL) AS has_avatar, a.answer, a.created_at
         FROM answers a
         LEFT JOIN users u ON u.id = a.user_id
         WHERE a.question_id = :question_id
         ORDER BY a.created_at ASC, a.id ASC"
    );
    $statement->execute(['question_id' => (int) $todayQuestion['id']]);
    $publicAnswers = $statement->fetchAll();
    foreach ($publicAnswers as $answer) {
        $userId = (int) ($answer['user_id'] ?? 0);
        if ($userId > 0 && !isset($publicStreaks[$userId])) {
            $publicStreaks[$userId] = load_user_brickle_streaks($database, $userId, $today);
        }
    }
}

$pastBrickles = [];
if ($isYesterdaysPage) {
    $statement = $database->prepare(
        'SELECT id, quiz_date, prompt FROM questions
         WHERE quiz_date < :today
         ORDER BY quiz_date DESC
         LIMIT 5'
    );
    $statement->execute(['today' => $today]);
    foreach ($statement->fetchAll() as $brickle) {
        $id = (int) $brickle['id'];
        $pastBrickles[$id] = [
            'id' => $id,
            'quiz_date' => $brickle['quiz_date'],
            'prompt' => $brickle['prompt'],
            'images' => [],
            'answers' => [],
        ];
    }

    if ($pastBrickles) {
        $placeholders = implode(',', array_fill(0, count($pastBrickles), '?'));
        $statement = $database->prepare(
            "SELECT a.question_id, a.user_id,
                COALESCE(u.username, a.display_name, 'Anonymous') AS display_name,
                (u.avatar_data IS NOT NULL) AS has_avatar, a.answer, a.created_at
             FROM answers a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.question_id IN (" . $placeholders . ")
             ORDER BY a.created_at ASC, a.id ASC"
        );
        $statement->execute(array_keys($pastBrickles));
        $pastAnswers = $statement->fetchAll();
        $pastStreaks = [];
        foreach ($pastAnswers as $answer) {
            $pastBrickles[(int) $answer['question_id']]['answers'][] = $answer;
            $userId = (int) ($answer['user_id'] ?? 0);
            if ($userId > 0 && !isset($pastStreaks[$userId])) {
                $pastStreaks[$userId] = load_user_brickle_streaks($database, $userId, $today);
            }
        }

        $statement = $database->prepare(
            'SELECT question_id, id FROM question_image_gallery
             WHERE question_id IN (' . $placeholders . ')
             ORDER BY question_id, sort_order, id'
        );
        $statement->execute(array_keys($pastBrickles));
        foreach ($statement->fetchAll() as $image) {
            $pastBrickles[(int) $image['question_id']]['images'][] = ['id' => (int) $image['id']];
        }
    }
}

$isAccountPage = $page === 'account';
$isProfilePage = $page === 'profile';
$accountUser = null;
$profileUser = null;
$timeline = [];
$timelineStreaks = [];
if ($isAccountPage && is_user()) {
    $statement = $database->prepare(
        'SELECT id, username, (avatar_data IS NOT NULL) AS has_avatar FROM users WHERE id = :id'
    );
    $statement->execute(['id' => (int) $_SESSION['user_id']]);
    $accountUser = $statement->fetch() ?: null;
}
if ($isProfilePage) {
    $profileId = $_GET['id'] ?? '';
    if (!is_string($profileId) || !ctype_digit($profileId) || (int) $profileId < 1) {
        http_response_code(404);
    } else {
        $statement = $database->prepare(
            'SELECT id, username, (avatar_data IS NOT NULL) AS has_avatar FROM users WHERE id = :id'
        );
        $statement->execute(['id' => (int) $profileId]);
        $profileUser = $statement->fetch() ?: null;
        if (!$profileUser) {
            http_response_code(404);
        } else {
            $timelineStreaks = load_user_brickle_streaks($database, (int) $profileUser['id'], $today);
            $statement = $database->prepare(
                'SELECT q.id, q.quiz_date, q.prompt, a.answer, a.created_at,
                    (u.avatar_data IS NOT NULL) AS has_avatar, qig.id AS image_id
                 FROM answers a
                 JOIN questions q ON q.id = a.question_id
                 JOIN users u ON u.id = a.user_id
                 LEFT JOIN question_image_gallery qig ON qig.question_id = q.id
                 WHERE a.user_id = :user_id AND q.quiz_date <= :today
                 ORDER BY q.quiz_date DESC, q.id DESC, qig.sort_order, qig.id'
            );
            $statement->execute(['user_id' => (int) $profileUser['id'], 'today' => $today]);
            foreach ($statement->fetchAll() as $row) {
                $brickleId = (int) $row['id'];
                if (!isset($timeline[$brickleId])) {
                    $timeline[$brickleId] = [
                        'id' => $brickleId,
                        'quiz_date' => $row['quiz_date'],
                        'prompt' => $row['prompt'],
                        'answer' => $row['answer'],
                        'created_at' => $row['created_at'],
                        'images' => [],
                        'streak' => $timelineStreaks[$brickleId] ?? 0,
                    ];
                }
                if ($row['image_id'] !== null) {
                    $timeline[$brickleId]['images'][] = ['id' => (int) $row['image_id']];
                }
            }
        }
    }
}

$currentUser = null;
if (is_user()) {
    $statement = $database->prepare('SELECT id, username FROM users WHERE id = :id');
    $statement->execute(['id' => (int) $_SESSION['user_id']]);
    $currentUser = $statement->fetch() ?: null;
    if (!$currentUser) {
        unset($_SESSION['user_id'], $_SESSION['username']);
        session_regenerate_id(true);
    }
}

$editQuestion = null;
$adminQuestions = [];
$adminStreaks = [];
$upcomingQuizCount = 0;
if ($isAdminPage && is_admin()) {
    $editId = $_GET['edit'] ?? '';
    if (is_string($editId) && ctype_digit($editId)) {
        $statement = $database->prepare(
            'SELECT q.id, q.quiz_date, q.prompt,
                (SELECT COUNT(*) FROM question_image_gallery qi WHERE qi.question_id = q.id) AS image_count
             FROM questions q WHERE q.id = :id'
        );
        $statement->execute(['id' => (int) $editId]);
        $editQuestion = $statement->fetch() ?: null;
        if ($editQuestion) {
            $editQuestion['images'] = load_question_images($database, (int) $editQuestion['id']);
        }
    }

    $rows = $database->query(
        "SELECT q.id, q.quiz_date, q.prompt,
            (SELECT COUNT(*) FROM question_image_gallery qi WHERE qi.question_id = q.id) AS image_count,
            a.user_id, COALESCE(u.username, a.display_name, 'Anonymous') AS display_name,
            (u.avatar_data IS NOT NULL) AS has_avatar,
            a.answer, a.created_at AS answer_created_at
         FROM questions q
         LEFT JOIN answers a ON a.question_id = q.id
         LEFT JOIN users u ON u.id = a.user_id
         ORDER BY q.quiz_date DESC, a.created_at ASC, a.id ASC"
    )->fetchAll();
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        if (!isset($adminQuestions[$id])) {
            $adminQuestions[$id] = [
                'id' => $id,
                'quiz_date' => $row['quiz_date'],
                'prompt' => $row['prompt'],
                'images' => [],
                'answers' => [],
            ];
        }
        if ($row['answer'] !== null) {
            $userId = (int) ($row['user_id'] ?? 0);
            $adminQuestions[$id]['answers'][] = [
                'user_id' => $row['user_id'],
                'display_name' => $row['display_name'],
                'has_avatar' => $row['has_avatar'],
                'answer' => $row['answer'],
                'created_at' => $row['answer_created_at'],
            ];
            if ($userId > 0 && !isset($adminStreaks[$userId])) {
                $adminStreaks[$userId] = load_user_brickle_streaks($database, $userId, $today);
            }
        }
    }
    $imageRows = $database->query(
        'SELECT question_id, id FROM question_image_gallery ORDER BY question_id, sort_order, id'
    )->fetchAll();
    foreach ($imageRows as $imageRow) {
        $questionId = (int) $imageRow['question_id'];
        if (isset($adminQuestions[$questionId])) {
            $adminQuestions[$questionId]['images'][] = ['id' => (int) $imageRow['id']];
        }
    }
    foreach ($adminQuestions as $question) {
        if ($question['quiz_date'] > $today) {
            $upcomingQuizCount++;
        }
    }
}

$notice = take_flash();
$adminEmail = $_SESSION['admin_email'] ?? '';
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#101119">
    <?php if ($isLandingPage): ?>
        <meta name="description" content="Brickle gives your friends one question to answer each day. Share your take, see everyone's answers, and keep the conversation going.">
    <?php endif; ?>
    <title><?= $isLandingPage
        ? 'About Brickle'
        : ($isTodayPage
            ? 'Brickle of the Day'
            : ($isAdminPage
        ? 'Admin · Brickle of the Day'
        : ($isYesterdaysPage
            ? 'Brickles of the Yesterdays · Brickle of the Day'
            : ($isAccountPage
                ? 'Account · Brickle of the Day'
                : ($isProfilePage && $profileUser ? e($profileUser['username']) . ' · Brickle' : 'Brickle of the Day'))))) ?></title>
    <link rel="stylesheet" href="assets/styles.css">
    <script src="assets/app.js" defer></script>
</head>
<body>
<header class="site-header">
    <a class="brand" href="index.php"><span class="brand-mark">?</span> daily<span>brickle</span></a>
    <nav aria-label="Main navigation">
        <a href="index.php"<?= $isLandingPage ? ' aria-current="page"' : '' ?>><span class="nav-label-long">What is Brickle?</span><span class="nav-label-short">About</span></a>
        <a href="index.php?page=today" aria-label="Today's Brickle"<?= $isTodayPage ? ' aria-current="page"' : '' ?>><span class="nav-label-long">Today's Brickle</span><span class="nav-label-short">Today</span></a>
        <a href="index.php?page=yesterdays"<?= $isYesterdaysPage ? ' aria-current="page"' : '' ?>><span class="nav-label-long">Brickles of the Yesterdays</span><span class="nav-label-short">Yesterdays</span></a>
        <?php if ($currentUser): ?>
            <a href="index.php?page=profile&amp;id=<?= (int) $currentUser['id'] ?>"<?= $isProfilePage && $profileUser && (int) $profileUser['id'] === (int) $currentUser['id'] ? ' aria-current="page"' : '' ?>><?= e($currentUser['username']) ?></a>
            <a href="index.php?page=account"<?= $isAccountPage ? ' aria-current="page"' : '' ?>>Settings</a>
            <form class="nav-form" method="post" action="index.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="user_logout">
                <button class="nav-link" type="submit">Sign out</button>
            </form>
        <?php else: ?>
            <a href="index.php?page=account&amp;mode=login"<?= $isAccountPage ? ' aria-current="page"' : '' ?>>Sign in</a>
            <a href="index.php?page=account&amp;mode=register">Join</a>
        <?php endif; ?>
        <?php if (is_admin()): ?>
            <a href="index.php?page=admin"<?= $isAdminPage ? ' aria-current="page"' : '' ?>>Admin</a>
            <form class="nav-form" method="post" action="index.php?page=admin">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="logout">
                <button class="nav-link" type="submit">Sign out</button>
            </form>
        <?php else: ?>
            <a href="index.php?page=admin"<?= $isAdminPage ? ' aria-current="page"' : '' ?>>Admin</a>
        <?php endif; ?>
    </nav>
</header>

<main class="page-shell">
    <?php if ($notice): ?>
        <div class="notice notice-<?= e($notice['kind'] === 'error' ? 'error' : 'success') ?>" role="status">
            <?= e($notice['message'] ?? '') ?>
        </div>
    <?php endif; ?>

    <?php if ($isLandingPage): ?>
        <section class="landing-hero">
            <p class="eyebrow"><span class="live-dot"></span> A DAILY QUESTION FOR YOUR PEOPLE</p>
            <h1>One small question.<br><span>A lot to talk about.</span></h1>
            <p class="landing-copy">Brickle gives your friends one question to answer each day. Share your take, see what everyone else thinks, and keep the conversation going.</p>
            <div class="landing-actions">
                <a class="button button-primary" href="index.php?page=today">See today's question <span aria-hidden="true">→</span></a>
                <a class="landing-secondary-link" href="index.php?page=today#answers">Read the answers <span aria-hidden="true">↓</span></a>
            </div>
            <div class="landing-details">
                <article class="landing-detail panel">
                    <span class="landing-detail-mark" aria-hidden="true">01</span>
                    <h2>A fresh prompt</h2>
                    <p>A new daily question gives everyone an easy reason to join in.</p>
                </article>
                <article class="landing-detail panel">
                    <span class="landing-detail-mark" aria-hidden="true">02</span>
                    <h2>Everyone's answers</h2>
                    <p>See the group's replies together and discover what your friends think.</p>
                </article>
                <article class="landing-detail panel">
                    <span class="landing-detail-mark" aria-hidden="true">03</span>
                    <h2>Your way to answer</h2>
                    <p>Join in anonymously, choose an alias, or sign in with your profile.</p>
                </article>
            </div>
            <div class="landing-decoration" aria-hidden="true"><span>?</span><i></i><b></b></div>
        </section>
    <?php elseif ($isAccountPage && !$currentUser): ?>
        <?php $accountMode = ($_GET['mode'] ?? 'login') === 'register' ? 'register' : 'login'; ?>
        <section class="login-panel panel account-panel">
            <p class="eyebrow"><?= $accountMode === 'register' ? 'JOIN THE COMMUNITY' : 'WELCOME BACK' ?></p>
            <h1><?= $accountMode === 'register' ? 'Create your profile.' : 'Sign in.' ?></h1>
            <p class="muted"><?= $accountMode === 'register'
                ? 'Your username, profile picture, and answers will be public. You can still answer anonymously when signed out.'
                : 'Sign in to answer as yourself and keep your brickle streak.' ?></p>
            <?php if ($accountMode === 'register'): ?>
                <form class="stack-form account-form" method="post" action="index.php?page=account&amp;mode=register" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="user_register">
                    <label for="register_username">Username</label>
                    <input id="register_username" name="username" type="text" minlength="3" maxlength="24" pattern="[A-Za-z0-9_]{3,24}" autocomplete="username" required>
                    <span class="muted form-hint">3-24 letters, numbers, or underscores. Your username and answers are visible to everyone.</span>
                    <label for="register_password">Password</label>
                    <input id="register_password" name="password" type="password" minlength="14" maxlength="128" autocomplete="new-password" required>
                    <label for="register_avatar">Profile picture <span class="label-optional">optional · JPEG, PNG, or WebP · 1 MB max</span></label>
                    <input id="register_avatar" name="avatar" type="file" accept="image/jpeg,image/png,image/webp">
                    <button class="button button-primary" type="submit">Create profile <span aria-hidden="true">→</span></button>
                </form>
                <p class="account-switch muted">Already registered? <a class="text-link" href="index.php?page=account&amp;mode=login">Sign in</a></p>
            <?php else: ?>
                <form class="stack-form account-form" method="post" action="index.php?page=account&amp;mode=login">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="user_login">
                    <label for="login_username">Username</label>
                    <input id="login_username" name="username" type="text" maxlength="24" autocomplete="username" required>
                    <label for="login_password">Password</label>
                    <input id="login_password" name="password" type="password" maxlength="128" autocomplete="current-password" required>
                    <button class="button button-primary" type="submit">Sign in <span aria-hidden="true">→</span></button>
                </form>
                <p class="account-switch muted">New here? <a class="text-link" href="index.php?page=account&amp;mode=register">Create a profile</a></p>
            <?php endif; ?>
        </section>
    <?php elseif ($isAccountPage && $currentUser && $accountUser): ?>
        <section class="admin-heading">
            <div>
                <p class="eyebrow">YOUR ACCOUNT</p>
                <h1>Profile settings</h1>
                <p class="muted">Your profile and registered answers are public.</p>
            </div>
            <a class="text-link" href="index.php?page=profile&amp;id=<?= (int) $accountUser['id'] ?>">View your timeline</a>
        </section>
        <section class="account-settings-grid">
            <div class="panel editor-panel">
                <p class="eyebrow">PUBLIC PROFILE</p>
                <h2>Make it yours.</h2>
                <?php if (!empty($accountUser['has_avatar'])): ?>
                    <img class="avatar avatar-large account-avatar" src="image.php?avatar=<?= (int) $accountUser['id'] ?>" alt="Your profile picture">
                <?php endif; ?>
                <form class="stack-form account-form" method="post" action="index.php?page=account" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_user_profile">
                    <label for="profile_username">Username</label>
                    <input id="profile_username" name="username" type="text" minlength="3" maxlength="24" pattern="[A-Za-z0-9_]{3,24}" value="<?= e($accountUser['username']) ?>" autocomplete="username" required>
                    <label for="profile_avatar">Change profile picture <span class="label-optional">optional · JPEG, PNG, or WebP · 1 MB max</span></label>
                    <input id="profile_avatar" name="avatar" type="file" accept="image/jpeg,image/png,image/webp">
                    <?php if (!empty($accountUser['has_avatar'])): ?>
                        <label class="remove-image-label"><input name="remove_avatar" type="checkbox" value="1"> Remove current profile picture</label>
                    <?php endif; ?>
                    <button class="button button-primary" type="submit">Save profile</button>
                </form>
            </div>
            <section class="panel password-panel" id="password">
                <p class="eyebrow">ACCOUNT SECURITY</p>
                <h2>Change your password</h2>
                <form class="stack-form account-form" method="post" action="index.php?page=account#password">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="change_user_password">
                    <label for="user_current_password">Current password</label>
                    <input id="user_current_password" name="current_password" type="password" maxlength="128" autocomplete="current-password" required>
                    <label for="user_new_password">New password</label>
                    <input id="user_new_password" name="new_password" type="password" minlength="14" maxlength="128" autocomplete="new-password" required>
                    <label for="user_confirm_password">Confirm new password</label>
                    <input id="user_confirm_password" name="confirm_password" type="password" minlength="14" maxlength="128" autocomplete="new-password" required>
                    <button class="button button-secondary" type="submit">Update password</button>
                </form>
            </section>
        </section>
    <?php elseif ($isProfilePage && !$profileUser): ?>
        <section class="empty-state panel profile-not-found"><p>This profile could not be found.</p></section>
    <?php elseif ($isProfilePage && $profileUser): ?>
        <?php $currentProfileStreak = $timelineStreaks ? end($timelineStreaks) : 0; ?>
        <section class="profile-heading">
            <?php if (!empty($profileUser['has_avatar'])): ?>
                <img class="avatar avatar-profile" src="image.php?avatar=<?= (int) $profileUser['id'] ?>" alt="">
            <?php else: ?>
                <span class="avatar avatar-profile avatar-placeholder" aria-hidden="true"><?= e(substr($profileUser['username'], 0, 1)) ?></span>
            <?php endif; ?>
            <div class="profile-heading-copy">
                <p class="eyebrow">BRICKLE PROFILE</p>
                <h1><?= e($profileUser['username']) ?></h1>
                <span class="streak-badge streak-summary"><?= (int) $currentProfileStreak ?> brickle<?= (int) $currentProfileStreak === 1 ? '' : 's' ?> in a row</span>
                <p class="muted profile-streak-help">A streak counts consecutive posted brickles answered.</p>
            </div>
            <?php if ($currentUser && (int) $currentUser['id'] === (int) $profileUser['id']): ?>
                <a class="button button-secondary" href="index.php?page=account">Edit profile</a>
            <?php endif; ?>
        </section>
        <section class="archive-section profile-timeline">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">A PERSONAL HISTORY</p>
                    <h2><?= e($profileUser['username']) ?>'s brickles</h2>
                </div>
                <span class="count-pill"><?= count($timeline) ?> answered</span>
            </div>
            <?php if (!$timeline): ?>
                <div class="empty-state panel"><p><?= e($profileUser['username']) ?> hasn't answered a brickle yet.</p></div>
            <?php else: ?>
                <div class="archive-list">
                    <?php foreach ($timeline as $brickle): ?>
                        <article class="panel timeline-card">
                            <div class="timeline-date">
                                <p class="eyebrow"><?= e(date('l, F j, Y', strtotime($brickle['quiz_date']))) ?></p>
                                <?php if ($brickle['streak'] > 0): ?>
                                    <span class="streak-badge"><?= (int) $brickle['streak'] ?> in a row</span>
                                <?php endif; ?>
                            </div>
                            <h2><?= nl2br(e($brickle['prompt'])) ?></h2>
                            <?php render_question_gallery($brickle['images'], 'schedule-image-gallery', 'Brickle image '); ?>
                            <div class="timeline-answer">
                                <span class="eyebrow">ANSWER</span>
                                <p><?= nl2br(e($brickle['answer'])) ?></p>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php elseif ($isAdminPage && !is_admin()): ?>
        <section class="login-panel panel">
            <p class="eyebrow">PRIVATE AREA</p>
            <h1>Welcome back.</h1>
            <p class="muted">Sign in to create brickles and see every answer.</p>
            <form class="stack-form" method="post" action="index.php?page=admin">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="login">
                <label for="email">Email address</label>
                <input id="email" name="email" type="email" maxlength="254" autocomplete="username" required>
                <label for="password">Password</label>
                <input id="password" name="password" type="password" maxlength="128" autocomplete="current-password" required>
                <button class="button button-primary" type="submit">Sign in <span aria-hidden="true">→</span></button>
            </form>
        </section>
    <?php elseif ($isAdminPage): ?>
        <section class="admin-heading">
            <div>
                <p class="eyebrow">YOUR PRIVATE LITTLE CONTROL ROOM</p>
                <h1>Good to see you.</h1>
                <p class="muted">Signed in as <?= e(is_string($adminEmail) ? $adminEmail : '') ?>. The people await today's important question.</p>
            </div>
            <span class="date-pill"><?= e(date('l, F j')) ?></span>
        </section>

        <section class="admin-grid">
            <div class="panel editor-panel">
                <p class="eyebrow"><?= $editQuestion ? 'EDIT SCHEDULED BRICKLE' : 'PLAN A DAILY BRICKLE' ?></p>
                <h2><?= $editQuestion ? 'Polish this brickle.' : "What's today's brickle?" ?></h2>
                <p class="muted"><?= $editQuestion
                    ? 'This date is fixed once scheduled. Delete this brickle if you need to remove it.'
                    : 'Schedule a brickle for today or any upcoming day. Its date is fixed after scheduling.' ?></p>
                <form class="stack-form" method="post" action="index.php?page=admin" enctype="multipart/form-data" data-question-editor>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_question">
                    <input type="hidden" name="question_id" value="<?= e($editQuestion ? (string) $editQuestion['id'] : '') ?>">
                    <?php if ($editQuestion): ?>
                        <div class="fixed-date">
                            <span class="fixed-date-label">SCHEDULED FOR</span>
                            <strong><?= e(date('l, F j, Y', strtotime($editQuestion['quiz_date']))) ?></strong>
                            <span class="muted">Dates cannot be changed after scheduling.</span>
                        </div>
                    <?php else: ?>
                        <label for="quiz_date">Publish on</label>
                        <input id="quiz_date" name="quiz_date" type="date" min="<?= e($today) ?>" value="<?= e($today) ?>" data-question-date required>
                    <?php endif; ?>
                    <label for="prompt">Your brickle prompt</label>
                    <textarea id="prompt" name="prompt" rows="4" maxlength="240" data-question-prompt placeholder="What's something small that made you happy today?" required><?= e($editQuestion['prompt'] ?? '') ?></textarea>
                    <label for="question_images">Brickle images <span class="label-optional">optional · up to 5 total · JPEG, PNG, or WebP · 1 MB each</span></label>
                    <input id="question_images" name="question_images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple data-question-images>
                    <?php if ($editQuestion && $editQuestion['images']): ?>
                        <div class="existing-images">
                            <span class="image-section-label">Current images</span>
                            <?php foreach ($editQuestion['images'] as $imageIndex => $image): ?>
                                <div class="existing-image-item" data-existing-image data-image-url="image.php?id=<?= (int) $image['id'] ?>">
                                    <img class="question-image editor-image" src="image.php?id=<?= (int) $image['id'] ?>" alt="Current brickle image <?= $imageIndex + 1 ?>">
                                    <label class="remove-image-label">
                                        <input name="remove_image_ids[]" type="checkbox" value="<?= (int) $image['id'] ?>" data-remove-image>
                                        Remove image <?= $imageIndex + 1 ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <p class="muted image-help">Choose up to 5 images total (1 MB each). New images are added after current images; select any current images you want to remove.</p>
                    <details class="visitor-preview" data-question-preview>
                        <summary class="button button-secondary">Preview as a visitor</summary>
                        <div class="preview-content">
                            <p class="eyebrow"><span class="live-dot"></span> TODAY'S TINY QUESTION OF GREAT IMPORTANCE</p>
                            <p class="hero-date" data-preview-date><?= e(date('l, F j', strtotime($editQuestion['quiz_date'] ?? $today))) ?></p>
                            <h3 data-preview-prompt><?= nl2br(e($editQuestion['prompt'] ?? 'Your brickle prompt will appear here.')) ?></h3>
                            <div class="preview-gallery question-gallery homepage-image" data-preview-gallery></div>
                            <p class="hero-subtitle">No login. No essay citations. Just your answer.</p>
                            <span class="button button-primary preview-answer-button">Add your answer ↓</span>
                        </div>
                    </details>
                    <div class="form-actions">
                        <button class="button button-primary" type="submit"><?= $editQuestion ? 'Save brickle' : 'Schedule brickle' ?> <span aria-hidden="true">→</span></button>
                        <?php if ($editQuestion): ?><a class="text-link" href="index.php?page=admin#schedule">Schedule another</a><?php endif; ?>
                    </div>
                </form>
            </div>

            <section class="panel password-panel" id="password">
                <p class="eyebrow">ACCOUNT SECURITY</p>
                <h2>Change your password</h2>
                <p class="muted">Use at least 14 characters and never share your sign-in.</p>
                <form class="stack-form" method="post" action="index.php?page=admin#password">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="change_password">
                    <label for="current_password">Current password</label>
                    <input id="current_password" name="current_password" type="password" maxlength="128" autocomplete="current-password" required>
                    <label for="new_password">New password</label>
                    <input id="new_password" name="new_password" type="password" minlength="14" maxlength="128" autocomplete="new-password" required>
                    <label for="confirm_password">Confirm new password</label>
                    <input id="confirm_password" name="confirm_password" type="password" minlength="14" maxlength="128" autocomplete="new-password" required>
                    <button class="button button-secondary" type="submit">Update password</button>
                </form>
            </section>
        </section>

        <section class="archive-section" id="schedule">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">YOUR DAILY BRICKLE LINEUP</p>
                    <h2>Brickles &amp; answers</h2>
                </div>
                <div class="schedule-counts">
                    <span class="count-pill"><?= $upcomingQuizCount ?> upcoming</span>
                    <span class="count-pill"><?= count($adminQuestions) ?> total</span>
                </div>
            </div>
            <?php if (!$adminQuestions): ?>
                <div class="empty-state panel"><p>Your schedule is suspiciously peaceful. Add a brickle before anyone notices.</p></div>
            <?php else: ?>
                <div class="archive-list">
                    <?php foreach ($adminQuestions as $question): ?>
                        <?php
                        $questionStatus = $question['quiz_date'] > $today
                            ? 'upcoming'
                            : ($question['quiz_date'] === $today ? 'today' : 'past');
                        $questionStatusLabel = ucfirst($questionStatus);
                        ?>
                        <article class="panel archive-card">
                            <div class="archive-card-heading">
                                <div>
                                    <div class="schedule-date">
                                        <p class="eyebrow"><?= e(date('l, F j, Y', strtotime($question['quiz_date']))) ?></p>
                                        <span class="schedule-status schedule-status-<?= e($questionStatus) ?>"><?= e($questionStatusLabel) ?></span>
                                    </div>
                                    <h3><?= e($question['prompt']) ?></h3>
                                    <?php render_question_gallery($question['images'], 'schedule-image-gallery', 'Brickle image '); ?>
                                </div>
                                <div class="archive-card-actions">
                                    <a class="text-link" href="index.php?page=admin&amp;edit=<?= (int) $question['id'] ?>#schedule">Edit brickle</a>
                                    <details class="delete-control">
                                        <summary class="button button-danger">Delete</summary>
                                        <div class="delete-confirm">
                                            <p>This permanently deletes this brickle and all <?= count($question['answers']) ?> of its answers.</p>
                                            <form method="post" action="index.php?page=admin#schedule">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_question">
                                                <input type="hidden" name="question_id" value="<?= (int) $question['id'] ?>">
                                                <button class="button button-danger" type="submit">Delete brickle and answers</button>
                                            </form>
                                        </div>
                                    </details>
                                </div>
                            </div>
                            <?php if (!$question['answers']): ?>
                                <p class="muted answer-empty">No answers yet.</p>
                            <?php else: ?>
                                <p class="answer-count"><?= count($question['answers']) ?> <?= count($question['answers']) === 1 ? 'answer' : 'answers' ?></p>
                                <ul class="answer-list admin-answer-list">
                                    <?php foreach ($question['answers'] as $answer): ?>
                                        <li class="answer-card">
                                            <div class="answer-meta">
                                                <?php render_answer_identity(
                                                    $answer,
                                                    $adminStreaks[(int) ($answer['user_id'] ?? 0)][$question['id']] ?? 0
                                                ); ?>
                                                <time datetime="<?= e($answer['created_at']) ?>"><?= e(date('M j · g:i a', strtotime($answer['created_at']))) ?></time>
                                            </div>
                                            <p><?= nl2br(e($answer['answer'])) ?></p>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php elseif ($isYesterdaysPage): ?>
        <section class="archive-section public-archive">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">A LOOK BACK</p>
                    <h1>Brickles of the Yesterdays</h1>
                    <p class="muted">The five most recent past brickles and the answers friends shared.</p>
                </div>
                <span class="count-pill"><?= count($pastBrickles) ?> of 5</span>
            </div>
            <?php if (!$pastBrickles): ?>
                <div class="empty-state panel"><p>No yesterdays yet. Time is working on it.</p></div>
            <?php else: ?>
                <div class="archive-list">
                    <?php foreach ($pastBrickles as $brickle): ?>
                        <article class="panel archive-card public-brickle">
                            <div class="schedule-date">
                                <p class="eyebrow"><?= e(date('l, F j, Y', strtotime($brickle['quiz_date']))) ?></p>
                            </div>
                            <h2><?= nl2br(e($brickle['prompt'])) ?></h2>
                            <?php render_question_gallery($brickle['images'], 'schedule-image-gallery', 'Brickle image '); ?>
                            <?php if (!$brickle['answers']): ?>
                                <p class="muted answer-empty">No answers were shared for this brickle.</p>
                            <?php else: ?>
                                <p class="answer-count"><?= count($brickle['answers']) ?> <?= count($brickle['answers']) === 1 ? 'answer' : 'answers' ?></p>
                                <ul class="answer-list">
                                    <?php foreach ($brickle['answers'] as $answer): ?>
                                        <li class="answer-card">
                                            <div class="answer-meta">
                                                <?php render_answer_identity(
                                                    $answer,
                                                    $pastStreaks[(int) ($answer['user_id'] ?? 0)][$brickle['id']] ?? 0
                                                ); ?>
                                                <time datetime="<?= e($answer['created_at']) ?>"><?= e(date('M j · g:i a', strtotime($answer['created_at']))) ?></time>
                                            </div>
                                            <p><?= nl2br(e($answer['answer'])) ?></p>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <section class="hero">
            <p class="eyebrow"><span class="live-dot"></span> TODAY'S TINY QUESTION OF GREAT IMPORTANCE</p>
            <p class="hero-date"><?= e(date('l, F j')) ?></p>
            <?php if ($todayQuestion): ?>
                <h1><?= nl2br(e($todayQuestion['prompt'])) ?></h1>
                <?php render_question_gallery($todayImages, 'homepage-image', 'Brickle image '); ?>
                <p class="hero-subtitle">No login. No essay citations. Just your answer.</p>
                <a class="button button-primary hero-button" href="#answer">Add your answer <span aria-hidden="true">↓</span></a>
            <?php else: ?>
                <h1>Today's brickle is on its way.</h1>
                <p class="hero-subtitle">Today's question hasn't arrived. We assume it's stuck in traffic.</p>
            <?php endif; ?>
            <div class="hero-decoration" aria-hidden="true"><span>?</span><i></i><b></b></div>
        </section>

        <?php if ($todayQuestion): ?>
            <section class="community-section" id="answers">
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">THE COMMUNITY</p>
                        <h2>The group chat, but organized</h2>
                    </div>
                    <span class="count-pill"><?= count($publicAnswers) ?> <?= count($publicAnswers) === 1 ? 'answer' : 'answers' ?></span>
                </div>
                <?php if (!$publicAnswers): ?>
                    <div class="empty-state panel"><span class="empty-icon" aria-hidden="true">✳</span><p>It's quiet in here. Someone say something mildly interesting.</p></div>
                <?php else: ?>
                    <ul class="answer-list">
                        <?php foreach ($publicAnswers as $answer): ?>
                            <li class="answer-card panel">
                                <div class="answer-meta">
                                    <?php render_answer_identity(
                                        $answer,
                                        $publicStreaks[(int) ($answer['user_id'] ?? 0)][$todayQuestion['id']] ?? 0
                                    ); ?>
                                    <time datetime="<?= e($answer['created_at']) ?>"><?= e(date('g:i a', strtotime($answer['created_at']))) ?></time>
                                </div>
                                <p><?= nl2br(e($answer['answer'])) ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <section class="reply-section panel" id="answer">
                <div>
                    <p class="eyebrow">YOUR TURN</p>
                    <h2>Add your answer</h2>
                    <p class="muted">Your answer will be visible to everyone<?= $currentUser ? ' and linked to your profile' : '' ?>. Please keep the witness protection details out of it.</p>
                </div>
                <form class="stack-form" method="post" action="index.php?page=today#answer">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="submit_answer">
                    <input type="hidden" name="question_id" value="<?= (int) $todayQuestion['id'] ?>">
                    <label for="answer_text">Your answer</label>
                    <textarea id="answer_text" name="answer" rows="4" maxlength="1200" placeholder="Share what's on your mind..." required></textarea>
                    <?php if ($currentUser): ?>
                        <div class="answering-as">
                            <span class="muted">Answering as</span>
                            <?php render_answer_identity(['user_id' => $currentUser['id'], 'display_name' => $currentUser['username']]); ?>
                        </div>
                    <?php else: ?>
                        <label for="display_name">Display name <span class="label-optional">optional</span></label>
                        <input id="display_name" name="display_name" type="text" maxlength="32" autocomplete="nickname" placeholder="Leave blank to post anonymously">
                        <p class="muted form-hint">Want a profile and brickle streak? <a class="text-link" href="index.php?page=account&amp;mode=register">Create an account</a>.</p>
                    <?php endif; ?>
                    <button class="button button-primary" type="submit">Share your answer <span aria-hidden="true">→</span></button>
                </form>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</main>

<footer class="site-footer">
    <span>Made for good conversations and suspiciously specific opinions.</span>
    <span>
        <?php if ($isTodayPage): ?>
            One <details class="footer-easter-egg">
                <summary aria-label="Reveal a tiny Brickle surprise">brickle</summary>
                <span class="footer-easter-egg-popover">
                    <img src="assets/brickle-easter-egg.png" alt="A tiny face hiding behind its hands." width="40" height="40">
                </span>
            </details>. Every day.
        <?php else: ?>
            One brickle a day. Absolutely no homework.
        <?php endif; ?>
    </span>
</footer>
</body>
</html>
