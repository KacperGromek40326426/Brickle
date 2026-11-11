# Brickle

Brickle is a lightweight daily-question website for a small group of friends.
Visitors can read and answer the daily question anonymously, with an alias, or
using an optional account. Public profiles, answer timelines, streaks, recent
past questions, image galleries, and the private administrator panel are
implemented in native PHP. Static CSS and JavaScript are served directly from
`assets/`; there is no frontend build step or runtime Node.js dependency.

## Hetzner konsoleH Webhosting S

The shared-hosting deployment uses Apache-compatible PHP files and one
database. It does not use Docker, a VPS, SSH, Node.js, a worker, or a required
cron job. PHP 8.1 or later is supported by the application.

### PHP requirements

Required:

- PHP 8.1 or later.
- PDO and one available database driver:
  - `pdo_mysql` for the MariaDB/MySQL database provided by the hosting plan; or
  - `pdo_sqlite` if you have verified it is enabled and can provide writable
    SQLite storage outside the public document root.
- PHP sessions.

Optional:

- GD is used to resize and encode uploaded images as WebP when available.
  Without GD or `imagewebp()`, Brickle safely stores and serves validated
  JPEG, PNG, or WebP originals instead.
- Fileinfo strengthens image MIME verification. If it is unavailable, image
  signatures and dimensions are still checked using PHP's image inspection
  functions.

Brickle stores uploaded images as database blobs; it does not need writable
public upload directories, ImageMagick, FFmpeg, or other server binaries. Image
uploads are limited to 1 MB each, five files total, and 4 megapixels per image
to fit shared-host memory limits.

### Prepare configuration and setup token on Windows

1. In konsoleH, create the plan's database and note the database name, user,
   password, and host. Use the database host shown by Hetzner; do not assume it
   is `localhost`.
2. Copy `includes/config.example.php` to `includes/config.php`. Fill in the
   database details. If using SQLite, set `storage_path` to an absolute,
   PHP-writable path outside the public web root. The database credentials are private: do not
   commit or share this file. The application's `.htaccess` denies HTTP access
   to `includes/`. If Hetzner lets you store a configuration file outside the
   web root, you can instead upload it as `brickle-config.php` next to the web
   root; the application checks that location first.
3. Generate the one-time setup token on your Windows computer, without PHP CLI
   or Docker, using PowerShell from the project folder:

   ```powershell
   powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\Prepare-HetznerSetup.ps1
   ```

   Save the displayed token in a password manager. The script creates
   `includes/setup-token.php`, which contains only its SHA-256 hash and is
   excluded from Git. Never put the plaintext token in a URL or upload it.
4. Upload the site files by SFTP to the domain's document root. Upload
   `index.php`, `image.php`, `install.php`, `.htaccess`, `assets/`, and
   `includes/` (including your private `config.php` and generated
   `setup-token.php`). Do not upload `.env`, `Dockerfile`, Docker Compose files,
   `docker/`, `tools/`, or the local-only `includes/config.local.php`. Ensure
   Hetzner's Apache rules honor `.htaccess`.
5. Open `https://your-domain.example/install.php`, enter the token, and create
   the administrator password (14-128 characters). The application creates
   its schema and admin atomically. On success it records a permanent
   completion marker and removes the server's setup-token hash file. Delete
   the local `includes/setup-token.php` and discard the plaintext token.
6. Visit the site and sign in through **Admin**. Keep HTTPS enabled.

The app's default SQLite path is a private directory beside the project, but
shared-host document-root layouts vary. The runtime verifies that SQLite
storage is outside the public document root and writable. If you cannot
provide that path, configure the Hetzner database using MySQL instead. The
`includes/config.example.php` file shows the expected PDO DSN format.

The CLI-only `setup.php` and `generate-setup-token.php` remain optional for
hosts where PHP CLI is available. The shared-hosting flow above does not
depend on either command.

### Database backups and restore

No background backup process is required for the site to operate. Use
Hetzner's hosting backup facilities and periodically export the database using
the database administration tool provided in konsoleH (commonly phpMyAdmin).
Download the backup to a separate, private location before application
upgrades.

For a MySQL/MariaDB backup, export the Brickle database as SQL from the
database administration tool. To restore, create/select the target database
and import the saved SQL file. For SQLite, download the private database file
only when no Brickle requests are writing to it; keep the file outside the web
root. Never place backups under the public document root.

## Local development with Docker

Docker is an optional local development convenience only; it is not part of
the Hetzner deployment.

```powershell
docker compose build
docker compose run --rm --user www-data -it web php setup.php
docker compose up -d
```

Open `http://localhost:8080`. Local Compose stores the SQLite database in a
persistent Docker volume and disables secure cookies only for local HTTP.
Stop the service with `docker compose down`; the volume remains. Do not use
the local HTTP cookie setting in production.

## Security and operations

- The browser installer requires HTTPS, CSRF protection, a 256-bit one-time
  token, and no existing administrator. Admin creation and the persistent
  setup-completion marker are committed in one database transaction. The
  endpoint is disabled after successful setup.
- The database's schema is initialized once and versioned. SQL values use PDO
  prepared statements. Admin and user passwords use PHP password hashing.
- Sessions use strict mode, HTTP-only and same-site cookies, and Secure cookies
  by default. CSP, HSTS, no-sniff, frame-denial, and private/no-store response
  headers are enabled.
- `.htaccess` blocks access to private application files, setup scripts,
  configuration, database files, local tooling, and hidden files. Keep the
  private config/database outside the document root wherever the host permits
  it, and verify `.htaccess` is active.
- PHP error display is disabled for web requests. Failures are logged
  server-side and return a generic message, except safe database/extension/
  storage setup diagnostics.
- Answer forms have CSRF checks, validation, a session cooldown, and a
  persistent per-address rate limit. Admin and user login attempts are rate
  limited. Client IPs are stored only as keyed hashes for rate limiting.
- Admins can schedule and edit daily questions and review all answers.
  Question images are private in the database and served through an access
  checked PHP endpoint. Registered users can change their password and profile;
  their usernames, pictures, and registered answers are public.
- Use a production timezone in `includes/config.php`; the default is UTC.

## Troubleshooting

| Symptom | Check |
|---|---|
| "No usable database is configured" | Enable `pdo_sqlite`, or configure the database DSN, host, user, and password in `includes/config.php`. |
| "Configured PDO database driver is unavailable" | Enable `pdo_mysql` for a MySQL DSN or `pdo_sqlite` for SQLite in the PHP settings. |
| Database connection failure | Verify the database exists, credentials and host are exact, and the user has permission to create/alter tables and read/write rows. Check the hosting PHP error log for details. |
| SQLite private-directory error | Set `storage_path` to an absolute writable directory outside the web root. Prefer the hosting account's private directory, not `public_html`. |
| Installer says setup token is not configured | Upload the generated `includes/setup-token.php`; do not rename or edit it. |
| Installer unavailable or returns 404 after setup | Setup is permanently complete. Sign in at `index.php?page=admin`; do not recreate or re-upload a setup token. |
| Image upload is rejected | Use JPEG, PNG, or WebP under 1 MB and no larger than 4 megapixels. The PHP upload limit must be at least 1 MB, `max_file_uploads` at least 5, and `post_max_size` at least 6 MB for multipart overhead. |
| Image upload works but looks larger | Without GD and WebP support, Brickle stores the validated original image rather than resizing/encoding it. |
| Setup cannot create tables | Check that the database account has DDL permissions (`CREATE`, `ALTER`, and `INDEX`) as well as read/write permissions. |
| `.htaccess` rules appear ignored | Confirm the domain uses Apache-compatible configuration and that Hetzner allows `.htaccess` overrides. Contact support if protected paths are served directly. |

## Existing features

The public daily question and answers, anonymous/alias posting, optional
accounts, profile pictures, public answer timelines, streaks, recent-question
archive, question image galleries, responsive UI, and Easter egg remain part of
the application. No Node package manager, Composer package, or external
runtime service is required.
