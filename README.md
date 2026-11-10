# Brickle

A lightweight daily prompt website for a small group of friends. Each daily
prompt is a brickle. Anyone can read today's brickle and its answers without
an account, or browse the five most recent past brickles on **Brickles of the
Yesterdays**. Visitors can answer with an optional display name; the
administrator can schedule brickles and review all responses from a private
admin area.

## Requirements

- PHP 8.1 or later
- PDO SQLite (`pdo_sqlite`) and GD with WebP support enabled
- A web host with PHP support; no Node.js, build step, or paid database required

## Local setup

### Run with Docker (recommended)

Install and start Docker Desktop, then open PowerShell in the project folder.
The Compose setup serves the site only on your own computer at
`http://localhost:8080`, and keeps its SQLite database in a persistent Docker
volume outside the web root.

```powershell
docker compose build
docker compose run --rm --user www-data -it web php setup.php
docker compose up -d
```

Enter the administrator email when prompted, then save the one-time password
printed in the terminal. The setup command runs as the same unprivileged user
as the web server so both can access the database. Open `http://localhost:8080`
and choose **Admin** to sign in. The generated Compose configuration disables
secure cookies only for this local HTTP setup; do not use that setting for a
public HTTPS deployment.

To stop the local server, run `docker compose down`. Your database remains in
the `quiz_data` Docker volume, so starting the server again with
`docker compose up -d` keeps your brickles and answers. To change the daily
cutoff timezone, create a `.env` file next to `docker-compose.yml` with, for
example, `APP_TIMEZONE=Europe/London`.

### Docker deployment behind a TLS reverse proxy

The default `docker-compose.yml` is deliberately local-only: it bind-mounts the
working tree, binds to loopback, and disables secure cookies for HTTP
development. For a self-managed host, build and run the production Compose file
instead:

```powershell
docker compose -f docker-compose.production.yml build
docker compose -f docker-compose.production.yml run --rm --user www-data -it web php setup.php
docker compose -f docker-compose.production.yml up -d
```

This image contains only the runtime PHP files and serves on
`127.0.0.1:8081`; put it behind a reverse proxy that handles HTTPS and redirects
HTTP to HTTPS. Do not expose the container directly to the public internet over
plain HTTP. Configure the web server so PHP's `REMOTE_ADDR` is the actual
client address, or a trusted-proxy-aware address; do not trust a
user-supplied forwarding header. The named `quiz_data` volume persists the
database and rate-limiting secret. Back up that volume securely.

In the admin panel, schedule brickles for today or a future date. The daily
lineup shows upcoming, today's, and past brickles; edit one to change its
prompt without changing its date. Use **Delete**, then confirm, to permanently
remove a brickle and all of its answers.

### Run with a local PHP installation

1. Point a PHP-enabled web server's document root at this project folder.
2. Make sure PHP can create and write to the private directory beside the
   project folder. Alternatively, set `PRIVATE_STORAGE_PATH` to a writable
   directory **outside** the document root.
3. Set `APP_TIMEZONE` to the timezone used to decide when a new daily brickle
   starts (for example, `Europe/London`). The default is `UTC`.
4. Run `php setup.php` from a terminal. Enter the administrator's email address.
   The command creates the SQLite database and prints a one-time generated
   password. Save it securely; the admin can change it after signing in.
5. Visit the site's homepage and sign in at **Admin**.

The database schema is created automatically. The `setup.php` command is
terminal-only and refuses to create another administrator if one already
exists. For a PHP development server, set `APP_SECURE_COOKIES=0` in the local
environment, run `php -S 127.0.0.1:8000` from the project folder, and open
`http://127.0.0.1:8000`.

## Hosting and security

- Enable HTTPS and an HTTP-to-HTTPS redirect in the hosting control panel
  before collecting real responses. Secure session cookies and HSTS are enabled
  by default; set `APP_SECURE_COOKIES=0` only for local HTTP development. Do
  not rely on a user-controlled proxy header to determine whether a request is
  secure.
- The default SQLite directory is outside the project folder. If you set
  `PRIVATE_STORAGE_PATH`, point it outside the public document root. The
  included Apache rules also deny web access to the project `storage/` and
  `includes/` folders; verify that your host honors `.htaccess`.
- Configure the host to run a supported PHP version and enable `pdo_sqlite`.
  Enable GD with WebP support and set PHP upload limits to at least
  `upload_max_filesize=2M`, `post_max_size=12M`, and `max_file_uploads=5` for
  image uploads. Restrict write access to the database directory to the PHP
  account, and arrange regular backups of the SQLite database.
- Disable PHP error display in production and enable server-side error logging.
  Keep the database and session files out of public backups and web-accessible
  directories.
- Set a production timezone explicitly. The application stores brickle dates and
  timestamps using that timezone.
- Answers are displayed publicly. Do not ask visitors to include sensitive
  personal information. The app stores keyed hashes of client IP addresses,
  not raw addresses, for rate limiting; old rate-limit records are removed
  after 24 hours.
- The public form has CSRF protection, output escaping, input limits, a
  per-session cooldown, and a persistent limit of 10 answers per client address
  per minute. Admin sign-in attempts are limited to 5 per email/address pair
  per 10 minutes and 30 per client address per 15 minutes. These controls
  reduce abuse but are not a substitute for host-level firewall/WAF limits,
  backups, HTTPS, and keeping PHP current.
- Admins can add up to five JPEG, PNG, or WebP images per brickle (2 MB each).
  Images are decoded and re-encoded as WebP before being saved with the brickle
  in SQLite. Visitors can slide through today's images and images on the five
  most recent past brickles; older brickle images are only served to the
  signed-in administrator.
- The admin editor includes a live visitor-perspective preview of the brickle
  prompt, scheduled date, and selected images before saving.

## Configuration

Set these variables in the hosting provider's environment configuration. A
`.env.example` is included as a reference; the application does not load `.env`
files itself.

- `APP_TIMEZONE` — PHP timezone identifier; defaults to `UTC`.
- `APP_SECURE_COOKIES` — set to `0` only for local HTTP development; defaults
  to `1`.
- `PRIVATE_STORAGE_PATH` — optional absolute path to the private SQLite
  database directory; defaults to `brickle-private-storage/` beside this
  project.

No third-party runtime packages or external fonts/scripts are used.
