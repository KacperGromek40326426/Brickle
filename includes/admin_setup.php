<?php
declare(strict_types=1);

function is_initial_setup_complete(PDO $database): bool
{
    if ((int) $database->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0) {
        return true;
    }

    return (bool) $database->query('SELECT 1 FROM setup_state WHERE singleton = 1')->fetchColumn();
}

function create_initial_admin(PDO $database, string $email, string $password): void
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        throw new InvalidArgumentException('Please enter a valid email address (maximum 254 characters).');
    }
    if (strlen($password) < 14 || strlen($password) > 128) {
        throw new InvalidArgumentException('Choose a password between 14 and 128 characters.');
    }

    $database->beginTransaction();
    try {
        $database->exec('UPDATE setup_lock SET singleton = singleton WHERE singleton = 1');
        if (is_initial_setup_complete($database)) {
            throw new DomainException('Initial administrator setup has already been completed.');
        }

        $statement = $database->prepare(
            'INSERT INTO admins (email, password_hash, created_at)
             VALUES (:email, :password_hash, :created_at)'
        );
        $statement->execute([
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'created_at' => date('c'),
        ]);

        $statement = $database->prepare(
            'INSERT INTO setup_state (singleton, completed_at) VALUES (1, :completed_at)'
        );
        $statement->execute(['completed_at' => date('c')]);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
}
