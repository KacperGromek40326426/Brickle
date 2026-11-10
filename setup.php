<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/includes/bootstrap.php';

fwrite(STDOUT, 'Administrator email: ');
$emailInput = fgets(STDIN);
$email = trim($emailInput === false ? '' : $emailInput);
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    fwrite(STDERR, "Please enter a valid email address (maximum 254 characters).\n");
    exit(1);
}

$count = (int) $database->query('SELECT COUNT(*) FROM admins')->fetchColumn();
if ($count > 0) {
    fwrite(STDERR, "An administrator already exists. Use the admin page to change the password.\n");
    exit(1);
}

$password = bin2hex(random_bytes(24));
$statement = $database->prepare(
    'INSERT INTO admins (email, password_hash, created_at) VALUES (:email, :password_hash, :created_at)'
);
$statement->execute([
    'email' => $email,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'created_at' => date('c'),
]);

fwrite(STDOUT, "\nAdministrator created.\nEmail: {$email}\nOne-time password: {$password}\n");
fwrite(STDOUT, "Sign in over HTTPS and change this password from the admin page.\n");
