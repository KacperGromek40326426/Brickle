<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/admin_setup.php';

fwrite(STDOUT, 'Administrator email: ');
$emailInput = fgets(STDIN);
$email = trim($emailInput === false ? '' : $emailInput);
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    fwrite(STDERR, "Please enter a valid email address (maximum 254 characters).\n");
    exit(1);
}

if (is_initial_setup_complete($database)) {
    fwrite(STDERR, "Initial administrator setup has already been completed. Use the admin page to change the password.\n");
    exit(1);
}

$password = bin2hex(random_bytes(24));
try {
    create_initial_admin($database, $email, $password);
} catch (DomainException) {
    fwrite(STDERR, "Initial administrator setup has already been completed. Use the admin page to change the password.\n");
    exit(1);
}

fwrite(STDOUT, "\nAdministrator created.\nEmail: {$email}\nOne-time password: {$password}\n");
fwrite(STDOUT, "Sign in over HTTPS and change this password from the admin page.\n");
