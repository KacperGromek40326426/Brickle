<?php
declare(strict_types=1);

function database_table_exists(PDO $database, string $table): bool
{
    if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $statement = $database->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table"
        );
        $statement->execute(['table' => $table]);

        return (bool) $statement->fetchColumn();
    }

    $statement = $database->prepare(
        'SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = :table'
    );
    $statement->execute(['table' => $table]);

    return (bool) $statement->fetchColumn();
}

function database_column_exists(PDO $database, string $table, string $column): bool
{
    if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $columns = $database->query('PRAGMA table_info(' . $table . ')')->fetchAll();

        return in_array($column, array_column($columns, 'name'), true);
    }

    $statement = $database->prepare(
        'SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column_name'
    );
    $statement->execute(['table' => $table, 'column_name' => $column]);

    return (bool) $statement->fetchColumn();
}

function database_index_exists(PDO $database, string $table, string $index): bool
{
    if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $statement = $database->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = :index_name"
        );
        $statement->execute(['index_name' => $index]);

        return (bool) $statement->fetchColumn();
    }

    $statement = $database->prepare(
        'SELECT 1 FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index_name'
    );
    $statement->execute(['table' => $table, 'index_name' => $index]);

    return (bool) $statement->fetchColumn();
}

function ensure_database_index(PDO $database, string $table, string $index, string $columns, bool $unique = false): void
{
    if (database_index_exists($database, $table, $index)) {
        return;
    }

    $sql = 'CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX ' . $index
        . ' ON ' . $table . ' (' . $columns . ')';
    try {
        $database->exec($sql);
    } catch (PDOException $exception) {
        if (!database_index_exists($database, $table, $index)) {
            throw $exception;
        }
    }
}

function initialize_brickle_schema(PDO $database): void
{
    $currentVersion = 2;
    $sqlite = $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    if ($sqlite) {
        $database->exec('PRAGMA foreign_keys = ON');
        $database->exec('PRAGMA busy_timeout = 5000');
        $database->exec(
            'CREATE TABLE IF NOT EXISTS schema_meta (
                singleton INTEGER PRIMARY KEY CHECK (singleton = 1),
                version INTEGER NOT NULL
            )'
        );
    } else {
        $database->exec(
            'CREATE TABLE IF NOT EXISTS schema_meta (
                singleton TINYINT PRIMARY KEY,
                version INT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    $version = $database->query('SELECT version FROM schema_meta WHERE singleton = 1')->fetchColumn();
    if ($version !== false && (int) $version >= $currentVersion) {
        return;
    }

    $lockName = null;
    if (!$sqlite) {
        $databaseName = (string) $database->query('SELECT DATABASE()')->fetchColumn();
        $lockName = 'brickle-schema-' . substr(hash('sha256', $databaseName), 0, 40);
        $lockStatement = $database->prepare('SELECT GET_LOCK(:lock_name, 10)');
        $lockStatement->execute(['lock_name' => $lockName]);
        if ((int) $lockStatement->fetchColumn() !== 1) {
            throw new RuntimeException('The database schema is being initialized by another request. Please try again.');
        }

        $version = $database->query('SELECT version FROM schema_meta WHERE singleton = 1')->fetchColumn();
        if ($version !== false && (int) $version >= $currentVersion) {
            $releaseStatement = $database->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $releaseStatement->execute(['lock_name' => $lockName]);
            return;
        }
    } else {
        $database->beginTransaction();
    }

    try {
        if ($sqlite) {
            $database->exec(
                'CREATE TABLE IF NOT EXISTS admins (
                    id INTEGER PRIMARY KEY,
                    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
                    password_hash TEXT NOT NULL,
                    created_at TEXT NOT NULL
                )'
            );
            $database->exec(
                'CREATE TABLE IF NOT EXISTS setup_state (
                    singleton INTEGER PRIMARY KEY CHECK (singleton = 1),
                    completed_at TEXT NOT NULL
                )'
            );
            $database->exec(
                'CREATE TABLE IF NOT EXISTS setup_lock (
                    singleton INTEGER PRIMARY KEY CHECK (singleton = 1)
                )'
            );
            $database->exec(
                'CREATE TABLE IF NOT EXISTS questions (
                    id INTEGER PRIMARY KEY,
                    quiz_date TEXT NOT NULL UNIQUE,
                    prompt TEXT NOT NULL,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                )'
            );
            $database->exec(
                'CREATE TABLE IF NOT EXISTS users (
                    id INTEGER PRIMARY KEY,
                    username TEXT NOT NULL UNIQUE COLLATE NOCASE,
                    password_hash TEXT NOT NULL,
                    avatar_data BLOB,
                    avatar_mime_type TEXT,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                )'
            );
            $database->exec(
                'CREATE TABLE IF NOT EXISTS answers (
                    id INTEGER PRIMARY KEY,
                    question_id INTEGER NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
                    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
                    display_name TEXT,
                    answer TEXT NOT NULL,
                    created_at TEXT NOT NULL
                )'
            );
            $database->exec(
                'CREATE TABLE IF NOT EXISTS question_images (
                    question_id INTEGER PRIMARY KEY REFERENCES questions(id) ON DELETE CASCADE,
                    image_data BLOB NOT NULL,
                    mime_type TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                )'
            );
            $database->exec(
                'CREATE TABLE IF NOT EXISTS question_image_gallery (
                    id INTEGER PRIMARY KEY,
                    question_id INTEGER NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
                    image_data BLOB NOT NULL,
                    mime_type TEXT NOT NULL,
                    sort_order INTEGER NOT NULL,
                    created_at TEXT NOT NULL,
                    UNIQUE (question_id, sort_order)
                )'
            );
            $database->exec(
                'CREATE TABLE IF NOT EXISTS request_rate_limits (
                    bucket_hash TEXT NOT NULL,
                    occurred_at INTEGER NOT NULL
                )'
            );
            $database->exec(
                'CREATE TABLE IF NOT EXISTS application_secrets (
                    secret_name TEXT PRIMARY KEY,
                    secret_value TEXT NOT NULL
                )'
            );
        } else {
            $engine = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            $database->exec(
                'CREATE TABLE IF NOT EXISTS admins (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(254) NOT NULL UNIQUE,
                    password_hash VARCHAR(255) NOT NULL,
                    created_at VARCHAR(40) NOT NULL
                )' . $engine
            );
            $database->exec(
                'CREATE TABLE IF NOT EXISTS setup_state (
                    singleton TINYINT NOT NULL PRIMARY KEY,
                    completed_at VARCHAR(40) NOT NULL
                )' . $engine
            );
            $database->exec(
                'CREATE TABLE IF NOT EXISTS setup_lock (
                    singleton TINYINT NOT NULL PRIMARY KEY
                )' . $engine
            );
            $database->exec(
                'CREATE TABLE IF NOT EXISTS questions (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    quiz_date CHAR(10) NOT NULL UNIQUE,
                    prompt TEXT NOT NULL,
                    created_at VARCHAR(40) NOT NULL,
                    updated_at VARCHAR(40) NOT NULL
                )' . $engine
            );
            $database->exec(
                'CREATE TABLE IF NOT EXISTS users (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(24) NOT NULL UNIQUE,
                    password_hash VARCHAR(255) NOT NULL,
                    avatar_data LONGBLOB NULL,
                    avatar_mime_type VARCHAR(32) NULL,
                    created_at VARCHAR(40) NOT NULL,
                    updated_at VARCHAR(40) NOT NULL
                )' . $engine
            );
            $database->exec(
                'CREATE TABLE IF NOT EXISTS answers (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    question_id BIGINT UNSIGNED NOT NULL,
                    user_id BIGINT UNSIGNED NULL,
                    display_name VARCHAR(128) NULL,
                    answer TEXT NOT NULL,
                    created_at VARCHAR(40) NOT NULL,
                    CONSTRAINT answers_question_fk FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
                    CONSTRAINT answers_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
                )' . $engine
            );
            $database->exec(
                'CREATE TABLE IF NOT EXISTS question_images (
                    question_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                    image_data LONGBLOB NOT NULL,
                    mime_type VARCHAR(32) NOT NULL,
                    updated_at VARCHAR(40) NOT NULL,
                    CONSTRAINT question_images_question_fk FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
                )' . $engine
            );
            $database->exec(
                'CREATE TABLE IF NOT EXISTS question_image_gallery (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    question_id BIGINT UNSIGNED NOT NULL,
                    image_data LONGBLOB NOT NULL,
                    mime_type VARCHAR(32) NOT NULL,
                    sort_order INT NOT NULL,
                    created_at VARCHAR(40) NOT NULL,
                    UNIQUE KEY question_image_gallery_order_unique (question_id, sort_order),
                    CONSTRAINT question_gallery_question_fk FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
                )' . $engine
            );
            $database->exec(
                'CREATE TABLE IF NOT EXISTS request_rate_limits (
                    bucket_hash CHAR(64) NOT NULL,
                    occurred_at BIGINT NOT NULL
                )' . $engine
            );
            $database->exec(
                'CREATE TABLE IF NOT EXISTS application_secrets (
                    secret_name VARCHAR(64) NOT NULL PRIMARY KEY,
                    secret_value VARCHAR(255) NOT NULL
                )' . $engine
            );
        }

        if (!database_column_exists($database, 'answers', 'user_id')) {
            $database->exec(
                'ALTER TABLE answers ADD COLUMN user_id '
                . ($sqlite
                    ? 'INTEGER REFERENCES users(id) ON DELETE SET NULL'
                    : 'BIGINT UNSIGNED NULL')
            );
        }
        if (!database_column_exists($database, 'users', 'avatar_mime_type')) {
            $database->exec('ALTER TABLE users ADD COLUMN avatar_mime_type ' . ($sqlite ? 'TEXT' : 'VARCHAR(32) NULL'));
        }
        $database->exec(
            "UPDATE users SET avatar_mime_type = 'image/webp'
             WHERE avatar_data IS NOT NULL AND avatar_mime_type IS NULL"
        );

        $ignore = $sqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $database->exec($ignore . ' INTO setup_lock (singleton) VALUES (1)');
        $database->exec(
            $ignore . ' INTO setup_state (singleton, completed_at)
             SELECT 1, MIN(created_at) FROM admins HAVING COUNT(*) > 0'
        );
        $database->exec(
            $ignore . ' INTO question_image_gallery (question_id, image_data, mime_type, sort_order, created_at)
             SELECT qi.question_id, qi.image_data, qi.mime_type, 0, qi.updated_at
             FROM question_images qi
             WHERE NOT EXISTS (
                SELECT 1 FROM question_image_gallery qig WHERE qig.question_id = qi.question_id
             )'
        );
        $database->exec(
            'DELETE FROM question_images
             WHERE question_id IN (SELECT DISTINCT question_id FROM question_image_gallery)'
        );

        ensure_database_index($database, 'answers', 'answers_user_question_unique_idx', 'user_id, question_id', true);
        ensure_database_index($database, 'answers', 'answers_question_id_idx', 'question_id, created_at');
        ensure_database_index($database, 'question_image_gallery', 'question_image_gallery_question_idx', 'question_id, sort_order');
        ensure_database_index($database, 'request_rate_limits', 'request_rate_limits_bucket_idx', 'bucket_hash, occurred_at');
        ensure_database_index($database, 'request_rate_limits', 'request_rate_limits_expiry_idx', 'occurred_at');

        $secretStatement = $database->prepare(
            $ignore . ' INTO application_secrets (secret_name, secret_value)
             VALUES (:secret_name, :secret_value)'
        );
        $secretStatement->execute([
            'secret_name' => 'rate_limit_hmac',
            'secret_value' => bin2hex(random_bytes(32)),
        ]);
        $database->exec('DELETE FROM schema_meta WHERE singleton = 1');
        $database->exec('INSERT INTO schema_meta (singleton, version) VALUES (1, ' . $currentVersion . ')');
        if ($sqlite) {
            $database->commit();
        }
    } catch (Throwable $exception) {
        if ($sqlite && $database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    } finally {
        if ($lockName !== null) {
            $releaseStatement = $database->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $releaseStatement->execute(['lock_name' => $lockName]);
        }
    }
}
