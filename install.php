<?php
declare(strict_types=1);

$httpsValue = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
$https = in_array($httpsValue, ['on', '1'], true)
    || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
if (!$https) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Initial setup is available only over HTTPS.');
}

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/admin_setup.php';

function render_install_page(string $title, string $message, string $form = ''): never
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . ' - Brickle setup</title></head><body><main>';
    echo '<h1>' . e($title) . '</h1><p>' . e($message) . '</p>' . $form;
    echo '</main></body></html>';
    exit;
}

if (is_initial_setup_complete($database)) {
    http_response_code(404);
    render_install_page('Setup unavailable', 'Initial setup has already been completed.');
}

$tokenConfigPath = __DIR__ . '/includes/setup-token.php';
if (!is_file($tokenConfigPath)) {
    http_response_code(503);
    render_install_page(
        'Setup not configured',
        'The one-time setup token has not been configured. Follow the shared-hosting setup instructions.'
    );
}

$expectedTokenHash = require $tokenConfigPath;
if (!is_string($expectedTokenHash) || preg_match('/\A[a-f0-9]{64}\z/', $expectedTokenHash) !== 1) {
    error_log('Brickle setup token configuration is invalid.');
    http_response_code(500);
    render_install_page('Setup unavailable', 'The setup token configuration is invalid.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $step = $_POST['step'] ?? '';

    if ($step === 'authorize') {
        $tokenBucket = client_rate_limit_bucket($rateLimitSecret, 'initial-setup-token');
        if (!consume_rate_limit($database, $tokenBucket, 10, 3600)) {
            http_response_code(429);
            render_install_page('Try again later', 'Too many setup token attempts were made from this connection.');
        }

        $token = $_POST['token'] ?? '';
        if (!is_string($token) || strlen($token) !== 64 || preg_match('/\A[a-fA-F0-9]{64}\z/', $token) !== 1) {
            http_response_code(403);
            render_install_page('Invalid token', 'The setup token is invalid. Check it and try again.', token_form());
        }

        if (!hash_equals($expectedTokenHash, hash('sha256', $token))) {
            http_response_code(403);
            render_install_page('Invalid token', 'The setup token is invalid. Check it and try again.', token_form());
        }

        session_regenerate_id(true);
        unset($_SESSION['csrf_token']);
        $_SESSION['brickle_setup_authorized_at'] = time();
        redirect('install.php');
    }

    if ($step === 'create_admin') {
        $authorizedAt = $_SESSION['brickle_setup_authorized_at'] ?? null;
        if (!is_int($authorizedAt) || $authorizedAt < time() - 600) {
            unset($_SESSION['brickle_setup_authorized_at']);
            http_response_code(403);
            render_install_page('Authorization expired', 'Enter the setup token again to continue.', token_form());
        }

        $email = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $passwordConfirmation = is_string($_POST['password_confirmation'] ?? null)
            ? $_POST['password_confirmation']
            : '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            http_response_code(400);
            render_install_page('Check your email', 'Enter a valid email address of no more than 254 characters.', admin_form($email));
        }
        if (strlen($password) < 14 || strlen($password) > 128) {
            http_response_code(400);
            render_install_page('Check your password', 'Choose a password between 14 and 128 characters.', admin_form($email));
        }
        if (!hash_equals($password, $passwordConfirmation)) {
            http_response_code(400);
            render_install_page('Passwords do not match', 'Enter the same password in both password fields.', admin_form($email));
        }

        try {
            create_initial_admin($database, $email, $password);
        } catch (DomainException) {
            unset($_SESSION['brickle_setup_authorized_at']);
            http_response_code(404);
            render_install_page('Setup unavailable', 'Initial setup has already been completed.');
        } catch (PDOException $exception) {
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }
            http_response_code(400);
            render_install_page('Email already in use', 'An administrator already uses that email address.', admin_form($email));
        }

        unset($_SESSION['brickle_setup_authorized_at'], $_SESSION['csrf_token']);
        session_regenerate_id(true);
        $tokenRemoved = unlink($tokenConfigPath);
        render_install_page(
            'Administrator created',
            $tokenRemoved
                ? 'Your administrator account is ready. The one-time setup token was removed. Sign in to Brickle now.'
                : 'Your administrator account is ready and setup is permanently disabled. Remove includes/setup-token.php from the hosting account as an additional precaution.',
            '<p><a href="index.php?page=admin">Go to the admin sign-in</a></p>'
        );
    }

    http_response_code(400);
    render_install_page('Invalid request', 'The setup request was not recognized.');
}

$authorizedAt = $_SESSION['brickle_setup_authorized_at'] ?? null;
if (is_int($authorizedAt) && $authorizedAt >= time() - 600) {
    render_install_page('Create administrator', 'Choose the email address and password for the Brickle administrator.', admin_form());
}

unset($_SESSION['brickle_setup_authorized_at']);
render_install_page('One-time Brickle setup', 'Enter the one-time setup token to create the first administrator.', token_form());

function token_form(): string
{
    return '<form method="post" action="install.php">'
        . csrf_field()
        . '<input type="hidden" name="step" value="authorize">'
        . '<label for="setup_token">One-time setup token</label>'
        . '<input id="setup_token" name="token" type="password" autocomplete="off" minlength="64" maxlength="64" required>'
        . '<button type="submit">Continue</button></form>';
}

function admin_form(string $email = ''): string
{
    return '<form method="post" action="install.php">'
        . csrf_field()
        . '<input type="hidden" name="step" value="create_admin">'
        . '<label for="admin_email">Administrator email</label>'
        . '<input id="admin_email" name="email" type="email" maxlength="254" autocomplete="email" value="' . e($email) . '" required>'
        . '<label for="admin_password">Password (14-128 characters)</label>'
        . '<input id="admin_password" name="password" type="password" minlength="14" maxlength="128" autocomplete="new-password" required>'
        . '<label for="password_confirmation">Confirm password</label>'
        . '<input id="password_confirmation" name="password_confirmation" type="password" minlength="14" maxlength="128" autocomplete="new-password" required>'
        . '<button type="submit">Create administrator</button></form>';
}
