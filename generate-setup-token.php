<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$configPath = __DIR__ . '/includes/setup-token.php';
if (is_file($configPath)) {
    fwrite(STDERR, "A setup token configuration already exists. Remove it before generating another.\n");
    exit(1);
}

$token = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);
$config = "<?php\ndeclare(strict_types=1);\n\nreturn '{$tokenHash}';\n";
$configFile = fopen($configPath, 'x');
if ($configFile === false) {
    fwrite(STDERR, "The setup token configuration could not be created.\n");
    exit(1);
}
$written = fwrite($configFile, $config);
$closed = fclose($configFile);
if ($written !== strlen($config) || !$closed) {
    if (!unlink($configPath)) {
        fwrite(STDERR, "The incomplete setup token configuration could not be removed.\n");
    }
    fwrite(STDERR, "The setup token configuration could not be written.\n");
    exit(1);
}

if (DIRECTORY_SEPARATOR === '/' && !chmod($configPath, 0600)) {
    if (!unlink($configPath)) {
        fwrite(STDERR, "The setup token configuration could not be removed after a permissions error.\n");
    }
    fwrite(STDERR, "The setup token configuration permissions could not be secured.\n");
    exit(1);
}

fwrite(STDOUT, "One-time setup token (save it now; it cannot be shown again):\n{$token}\n");
fwrite(STDOUT, "\nUpload includes/setup-token.php with your site files. Do not upload this token anywhere.\n");
