<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/images.php';

$page = $_GET['page'] ?? 'home';
$isAdminPage = $page === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = post_value('action');

    if ($action === 'login') {
        $attempts = $_SESSION['login_attempts'] ?? ['count' => 0, 'until' => 0];
        if (!is_array($attempts) || !isset($attempts['count'], $attempts['until'])) {
            $attempts = ['count' => 0, 'until' => 0];
        }

        if ((int) $attempts['until'] > time()) {
            flash('Too many sign-in attempts. Try again in a few minutes.', 'error');
            redirect('index.php?page=admin');
        }

        $email = post_value('email');
        $password = post_raw('password');
        $admin = false;
        if (strlen($email) <= 254 && strlen($password) <= 128) {
            $statement = $database->prepare('SELECT id, email, password_hash FROM admins WHERE email = :email');
            $statement->execute(['email' => $email]);
            $admin = $statement->fetch();
        }

        if ($admin && password_verify($password, $admin['password_hash'])) {
            session_regenerate_id(true);
            unset($_SESSION['csrf_token']);
            $_SESSION['admin_id'] = (int) $admin['id'];
            $_SESSION['admin_email'] = $admin['email'];
            unset($_SESSION['login_attempts']);
            csrf_token();
            flash('Welcome back.');
            redirect('index.php?page=admin');
        }

        $attempts['count'] = (int) $attempts['count'] + 1;
        if ($attempts['count'] >= 5) {
            $attempts = ['count' => 0, 'until' => time() + 600];
        }
        $_SESSION['login_attempts'] = $attempts;
        flash('Email or password is incorrect.', 'error');
        redirect('index.php?page=admin');
    }

    if (!is_admin() && $action !== 'submit_answer') {
        http_response_code(403);
        exit('Please sign in to continue.');
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
        $imageUpload = prepare_question_image_upload();

        if ($imageUpload['status'] === 'error') {
            flash($imageUpload['message'], 'error');
            redirect('index.php?page=admin' . ($questionId !== '' && ctype_digit($questionId) ? '&edit=' . $questionId : '') . '#schedule');
        }

        if ($prompt === '' || strlen($prompt) > 960) {
            flash('Enter a question of up to 240 characters.', 'error');
            redirect('index.php?page=admin#schedule');
        }

        if ($questionId !== '' && !ctype_digit($questionId)) {
            flash('That question could not be found.', 'error');
            redirect('index.php?page=admin#schedule');
        }

        if ($questionId !== '') {
            $statement = $database->prepare('SELECT id FROM questions WHERE id = :id');
            $statement->execute(['id' => (int) $questionId]);
            if (!$statement->fetch()) {
                flash('That question could not be found.', 'error');
                redirect('index.php?page=admin#schedule');
            }

            $statement = $database->prepare(
                'UPDATE questions SET prompt = :prompt, updated_at = :updated_at WHERE id = :id'
            );
            $statement->execute([
                'prompt' => $prompt,
                'updated_at' => date('c'),
                'id' => (int) $questionId,
            ]);
            if ($imageUpload['status'] === 'image') {
                store_question_image($database, (int) $questionId, $imageUpload['data'], $imageUpload['mime_type']);
            } elseif (post_value('remove_image') === '1') {
                delete_question_image($database, (int) $questionId);
            }
            flash('Question updated. Its scheduled date has not changed.');
            redirect('index.php?page=admin&edit=' . (int) $questionId . '#schedule');
        }

        if (!valid_quiz_date($quizDate) || $quizDate < date('Y-m-d')) {
            flash('Choose today or a future date for a new quiz.', 'error');
            redirect('index.php?page=admin#schedule');
        }

        $statement = $database->prepare('SELECT id FROM questions WHERE quiz_date = :quiz_date');
        $statement->execute(['quiz_date' => $quizDate]);
        if ($statement->fetch()) {
            flash('A quiz is already scheduled for that date. Edit or delete it from the schedule.', 'error');
            redirect('index.php?page=admin#schedule');
        }

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
        if ($imageUpload['status'] === 'image') {
            store_question_image($database, $newId, $imageUpload['data'], $imageUpload['mime_type']);
        }
        flash('Question scheduled.');
        redirect('index.php?page=admin&edit=' . $newId . '#schedule');
    }

    if ($action === 'delete_question') {
        $questionId = post_value('question_id');
        if (!ctype_digit($questionId)) {
            flash('That quiz could not be found.', 'error');
            redirect('index.php?page=admin#schedule');
        }

        $statement = $database->prepare('DELETE FROM questions WHERE id = :id');
        $statement->execute(['id' => (int) $questionId]);
        if ($statement->rowCount() !== 1) {
            flash('That quiz could not be found. It may have already been deleted.', 'error');
            redirect('index.php?page=admin#schedule');
        }

        flash('The quiz and all of its answers have been deleted.');
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
        $lastAnswer = (int) ($_SESSION['last_answer_at'] ?? 0);

        if ($lastAnswer > time() - 15) {
            flash('Please wait a moment before sending another answer.', 'error');
            redirect('index.php#answer');
        }
        if (!ctype_digit($questionId) || $answer === '' || strlen($answer) > 4800) {
            flash('Write an answer of up to 1,200 characters.', 'error');
            redirect('index.php#answer');
        }
        if (strlen($displayName) > 128) {
            flash('Your display name must be 32 characters or fewer.', 'error');
            redirect('index.php#answer');
        }

        $statement = $database->prepare('SELECT id FROM questions WHERE id = :id AND quiz_date = :quiz_date');
        $statement->execute(['id' => (int) $questionId, 'quiz_date' => date('Y-m-d')]);
        if (!$statement->fetch()) {
            flash('That question is no longer available.', 'error');
            redirect('index.php');
        }

        $statement = $database->prepare(
            'INSERT INTO answers (question_id, display_name, answer, created_at) VALUES (:question_id, :display_name, :answer, :created_at)'
        );
        $statement->execute([
            'question_id' => (int) $questionId,
            'display_name' => $displayName === '' ? null : $displayName,
            'answer' => $answer,
            'created_at' => date('c'),
        ]);
        $_SESSION['last_answer_at'] = time();
        flash('Thanks for sharing your answer!');
        redirect('index.php#answers');
    }

    flash('That action was not recognized.', 'error');
    redirect($isAdminPage ? 'index.php?page=admin' : 'index.php');
}

$today = date('Y-m-d');
$statement = $database->prepare(
    'SELECT q.id, q.quiz_date, q.prompt,
        EXISTS(SELECT 1 FROM question_images qi WHERE qi.question_id = q.id) AS has_image
     FROM questions q WHERE q.quiz_date = :quiz_date'
);
$statement->execute(['quiz_date' => $today]);
$todayQuestion = $statement->fetch() ?: null;
$publicAnswers = [];
if ($todayQuestion) {
    $statement = $database->prepare(
        'SELECT display_name, answer, created_at FROM answers WHERE question_id = :question_id ORDER BY created_at ASC, id ASC'
    );
    $statement->execute(['question_id' => (int) $todayQuestion['id']]);
    $publicAnswers = $statement->fetchAll();
}

$editQuestion = null;
$adminQuestions = [];
$upcomingQuizCount = 0;
if ($isAdminPage && is_admin()) {
    $editId = $_GET['edit'] ?? '';
    if (is_string($editId) && ctype_digit($editId)) {
        $statement = $database->prepare(
            'SELECT q.id, q.quiz_date, q.prompt,
                EXISTS(SELECT 1 FROM question_images qi WHERE qi.question_id = q.id) AS has_image
             FROM questions q WHERE q.id = :id'
        );
        $statement->execute(['id' => (int) $editId]);
        $editQuestion = $statement->fetch() ?: null;
    }

    $rows = $database->query(
        'SELECT q.id, q.quiz_date, q.prompt,
            EXISTS(SELECT 1 FROM question_images qi WHERE qi.question_id = q.id) AS has_image,
            a.display_name, a.answer, a.created_at AS answer_created_at
         FROM questions q
         LEFT JOIN answers a ON a.question_id = q.id
         ORDER BY q.quiz_date DESC, a.created_at ASC, a.id ASC'
    )->fetchAll();
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        if (!isset($adminQuestions[$id])) {
            $adminQuestions[$id] = [
                'id' => $id,
                'quiz_date' => $row['quiz_date'],
                'prompt' => $row['prompt'],
                'has_image' => (bool) $row['has_image'],
                'answers' => [],
            ];
        }
        if ($row['answer'] !== null) {
            $adminQuestions[$id]['answers'][] = [
                'display_name' => $row['display_name'],
                'answer' => $row['answer'],
                'created_at' => $row['answer_created_at'],
            ];
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
    <meta name="theme-color" content="#11131b">
    <title><?= $isAdminPage ? 'Admin · ' : '' ?>Daily Question</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<header class="site-header">
    <a class="brand" href="index.php"><span class="brand-mark">?</span> daily<span>question</span></a>
    <nav aria-label="Main navigation">
        <a href="index.php"<?= !$isAdminPage ? ' aria-current="page"' : '' ?>>Today</a>
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

    <?php if ($isAdminPage && !is_admin()): ?>
        <section class="login-panel panel">
            <p class="eyebrow">PRIVATE AREA</p>
            <h1>Welcome back.</h1>
            <p class="muted">Sign in to write a question and see every answer.</p>
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
                <p class="eyebrow">YOUR PRIVATE SPACE</p>
                <h1>Good to see you.</h1>
                <p class="muted">Signed in as <?= e(is_string($adminEmail) ? $adminEmail : '') ?>.</p>
            </div>
            <span class="date-pill"><?= e(date('l, F j')) ?></span>
        </section>

        <section class="admin-grid">
            <div class="panel editor-panel">
                <p class="eyebrow"><?= $editQuestion ? 'EDIT SCHEDULED QUESTION' : 'PLAN A DAILY QUIZ' ?></p>
                <h2><?= $editQuestion ? 'Polish the question.' : 'What are we asking?' ?></h2>
                <p class="muted"><?= $editQuestion
                    ? 'This date is fixed once scheduled. Delete this quiz if you need to remove it.'
                    : 'Schedule a question for today or any upcoming day. Its date is fixed after scheduling.' ?></p>
                <form class="stack-form" method="post" action="index.php?page=admin" enctype="multipart/form-data">
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
                        <input id="quiz_date" name="quiz_date" type="date" min="<?= e($today) ?>" value="<?= e($today) ?>" required>
                    <?php endif; ?>
                    <label for="prompt">Your question</label>
                    <textarea id="prompt" name="prompt" rows="4" maxlength="240" placeholder="What's something small that made you happy today?" required><?= e($editQuestion['prompt'] ?? '') ?></textarea>
                    <label for="question_image">Question image <span class="label-optional">optional · JPEG, PNG, or WebP · up to 2 MB</span></label>
                    <input id="question_image" name="question_image" type="file" accept="image/jpeg,image/png,image/webp">
                    <?php if ($editQuestion && $editQuestion['has_image']): ?>
                        <img class="question-image editor-image" src="image.php?id=<?= (int) $editQuestion['id'] ?>" alt="Current image for this quiz">
                        <span class="muted image-help">Choose a new image above to replace this one.</span>
                        <label class="remove-image-label">
                            <input name="remove_image" type="checkbox" value="1">
                            Remove the current image
                        </label>
                    <?php endif; ?>
                    <div class="form-actions">
                        <button class="button button-primary" type="submit"><?= $editQuestion ? 'Save question' : 'Schedule question' ?> <span aria-hidden="true">→</span></button>
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
                    <p class="eyebrow">YOUR DAILY LINEUP</p>
                    <h2>Schedule &amp; answers</h2>
                </div>
                <div class="schedule-counts">
                    <span class="count-pill"><?= $upcomingQuizCount ?> upcoming</span>
                    <span class="count-pill"><?= count($adminQuestions) ?> total</span>
                </div>
            </div>
            <?php if (!$adminQuestions): ?>
                <div class="empty-state panel"><p>Your schedule is clear. Add a question for today or a future day.</p></div>
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
                                    <?php if ($question['has_image']): ?>
                                        <img class="question-image schedule-image" src="image.php?id=<?= (int) $question['id'] ?>" alt="Image for this quiz" loading="lazy">
                                    <?php endif; ?>
                                </div>
                                <div class="archive-card-actions">
                                    <a class="text-link" href="index.php?page=admin&amp;edit=<?= (int) $question['id'] ?>#schedule">Edit question</a>
                                    <details class="delete-control">
                                        <summary class="button button-danger">Delete</summary>
                                        <div class="delete-confirm">
                                            <p>This permanently deletes this quiz and all <?= count($question['answers']) ?> of its answers.</p>
                                            <form method="post" action="index.php?page=admin#schedule">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_question">
                                                <input type="hidden" name="question_id" value="<?= (int) $question['id'] ?>">
                                                <button class="button button-danger" type="submit">Delete quiz and answers</button>
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
                                                <strong><?= e($answer['display_name'] ?: 'Anonymous') ?></strong>
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
            <p class="eyebrow"><span class="live-dot"></span> A LITTLE QUESTION FOR TODAY</p>
            <p class="hero-date"><?= e(date('l, F j')) ?></p>
            <?php if ($todayQuestion): ?>
                <h1><?= nl2br(e($todayQuestion['prompt'])) ?></h1>
                <?php if ($todayQuestion['has_image']): ?>
                    <img class="question-image homepage-image" src="image.php?id=<?= (int) $todayQuestion['id'] ?>" alt="Image for today's question">
                <?php endif; ?>
                <p class="hero-subtitle">No accounts, no pressure. Just your answer.</p>
                <a class="button button-primary hero-button" href="#answer">Add your answer <span aria-hidden="true">↓</span></a>
            <?php else: ?>
                <h1>Something to think about is on its way.</h1>
                <p class="hero-subtitle">Today's question hasn't been posted yet. Pop back soon.</p>
            <?php endif; ?>
            <div class="hero-decoration" aria-hidden="true"><span>?</span><i></i><b></b></div>
        </section>

        <?php if ($todayQuestion): ?>
            <section class="community-section" id="answers">
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">THE COMMUNITY</p>
                        <h2>What everyone said</h2>
                    </div>
                    <span class="count-pill"><?= count($publicAnswers) ?> <?= count($publicAnswers) === 1 ? 'answer' : 'answers' ?></span>
                </div>
                <?php if (!$publicAnswers): ?>
                    <div class="empty-state panel"><span class="empty-icon" aria-hidden="true">✳</span><p>Be the first to share a thought.</p></div>
                <?php else: ?>
                    <ul class="answer-list">
                        <?php foreach ($publicAnswers as $answer): ?>
                            <li class="answer-card panel">
                                <div class="answer-meta">
                                    <strong><?= e($answer['display_name'] ?: 'Anonymous') ?></strong>
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
                    <p class="muted">Your name is optional. Your words will be visible to everyone.</p>
                </div>
                <form class="stack-form" method="post" action="index.php#answer">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="submit_answer">
                    <input type="hidden" name="question_id" value="<?= (int) $todayQuestion['id'] ?>">
                    <label for="answer">Your answer</label>
                    <textarea id="answer" name="answer" rows="4" maxlength="1200" placeholder="Share what's on your mind..." required></textarea>
                    <label for="display_name">Display name <span class="label-optional">optional</span></label>
                    <input id="display_name" name="display_name" type="text" maxlength="32" autocomplete="nickname" placeholder="Leave blank to post anonymously">
                    <button class="button button-primary" type="submit">Share your answer <span aria-hidden="true">→</span></button>
                </form>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</main>

<footer class="site-footer">
    <span>Made for good conversations.</span>
    <span>One question. Every day.</span>
</footer>
</body>
</html>
