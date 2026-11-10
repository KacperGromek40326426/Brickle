<?php
declare(strict_types=1);

$storagePath = getenv('PRIVATE_STORAGE_PATH');
if ($storagePath === false || $storagePath === '') {
    $storagePath = '/var/lib/brickle';
}

$databasePath = rtrim($storagePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'quiz.sqlite';
if (!is_file($databasePath) || !is_readable($databasePath)) {
    fwrite(STDERR, "The Brickle database is missing or unreadable.\n");
    exit(1);
}

$database = new PDO('sqlite:' . $databasePath);
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->exec('PRAGMA busy_timeout = 5000');

$temporaryPath = tempnam(sys_get_temp_dir(), 'brickle-backup-');
if ($temporaryPath === false || !unlink($temporaryPath)) {
    fwrite(STDERR, "A temporary backup file could not be created.\n");
    exit(1);
}

try {
    $database->exec('VACUUM INTO ' . $database->quote($temporaryPath));

    $backup = new PDO('sqlite:' . $temporaryPath);
    $backup->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($backup->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
        throw new RuntimeException('The generated backup did not pass SQLite integrity checking.');
    }

    $stream = fopen($temporaryPath, 'rb');
    if ($stream === false) {
        throw new RuntimeException('The generated backup could not be opened.');
    }

    if (fpassthru($stream) === false) {
        throw new RuntimeException('The backup could not be written to standard output.');
    }
    fclose($stream);
} finally {
    if (is_file($temporaryPath) && !unlink($temporaryPath)) {
        throw new RuntimeException('The temporary backup file could not be removed.');
    }
}
