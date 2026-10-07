# Service Request Management System

A PHP and MariaDB application for submitting, assigning, tracking, and reviewing university service requests.

## Requirements

- PHP 8.2 or newer with `mysqli`
- MariaDB 10.4 or MySQL 8.0 or newer
- Apache with PHP enabled (XAMPP is suitable for local development)

## Local setup with XAMPP

1. Place the project under `C:\xampp\htdocs\srms` and start Apache and MySQL from the XAMPP Control Panel.
2. Create a database named `srms_db` in phpMyAdmin, select it, then import [`srms_schema.sql`](./srms_schema.sql). The schema seeds service categories only; it does not contain user accounts or request history.
3. Copy `config.example.php` to `config.local.php` and set the values for your local database. `config.local.php` is ignored by Git. The example's blank-password `root` account is for a local XAMPP installation only; use a dedicated database user with a strong password outside local development.
4. Create the first superadmin account without using a default password:
   - Use PHP CLI to generate a password hash with `password_hash($password, PASSWORD_DEFAULT)`.
   - Insert a row in `users` with that hash, a unique username, and role `superadmin`.
   - Sign in and create any further users from the admin interface.
5. Visit `http://localhost/srms/login.php`.

For an existing installation, make a private database backup first, then apply [`migrations/001_security_hardening.sql`](./migrations/001_security_hardening.sql). It widens the request category column without deleting values and creates the login-attempts table. Do not import the clean schema over an existing database.

## Free-hosting demo deployment

Use a PHP host that supports PHP 8.2+, MySQL/MariaDB, `mysqli`, Apache `.htaccess`, and HTTPS. Free hosting is suitable for a demo with fictional data only; do not use it for real student records or production service requests.

1. Create a hosting account and a MySQL database/user in its control panel. Use the exact database host, name, username, and password shown by the host; shared hosts often add a username prefix and do not use `localhost`.
2. Select the new database in the host's phpMyAdmin and import `srms_schema.sql`.
3. Upload the application files to the site's document root, preserving their lowercase filenames. Do not upload `.git/`, `.qodo/`, `backups/`, `srms_schema1.sql`, or `config.local.php`.
4. Configure the DB without adding credentials to GitHub. Prefer a `config.local.php` created directly on the host in the application root, returning an array with the host-provided `host`, `user`, `password`, `database`, and `port` values. The included `.htaccess` denies direct access to that file. If the host does not honor `.htaccess`, keep the config file outside the document root and set `SRMS_DB_CONFIG` to its absolute path, or use the `SRMS_DB_*` environment variables. Do not deploy if the host cannot protect the configuration.
5. Generate a password hash with PHP and insert the first superadmin into the new database. Use only fictional demo accounts and records.
6. Visit the host's HTTPS URL and test sign-in, request submission, admin/technician actions, and logout.

Free hosting may have resource limits, weak backups, and no production support. Keep local backups private and verify the provider's data-handling and acceptable-use terms before uploading the application.

## Security and publishing

- Use HTTPS in production. Session cookies are HTTP-only, SameSite=Lax, and marked Secure when PHP detects HTTPS.
- The login flow limits repeated failures by username and IP address.
- State-changing forms use CSRF tokens; request actions validate roles and record ownership where applicable.
- Database settings belong in the ignored `config.local.php` or server environment variables (`SRMS_DB_HOST`, `SRMS_DB_USER`, `SRMS_DB_PASSWORD`, `SRMS_DB_NAME`, and optional `SRMS_DB_PORT`).
- Keep real database exports private. `backups/`, `srms_schema1.sql`, and `config.local.php` are excluded by `.gitignore`; Apache is also configured to deny direct access to SQL files and the local configuration.
- `.gitignore` does not remove files already tracked by Git. Before publishing, inspect `git status` and the staged file list; remove any private files from the index and rotate credentials if they were ever pushed.
- The Apache `.htaccess` protection does not configure Nginx or other servers. In production, keep database exports and local configuration outside the web root and configure equivalent access controls.
- Confirm you have permission to publish the university logo, photos, and other third-party assets.

## Checks

GitHub Actions runs `php -l` on all PHP files for pushes and pull requests. Locally, run:

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { & C:\xampp\php\php.exe -l $_.FullName }
```
