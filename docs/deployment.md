# Deploying to Production

VeyroPHP is pre-alpha. This guide describes how a VeyroPHP application should be run on a server; it does not make the framework production-ready. Security background: [threat model](security/threat-model.md).

## 1. PHP

- **A supported branch at a patched release.** The branch alone is not enough: PHP2Shell (CVE-2026-109618/109619, October 2026) affected every branch and is fixed only in 8.2.35, 8.3.36, 8.4.27, 8.5.12 and later. Framework code cannot protect against bugs in PHP itself. Check with `php -v` on the server, for the PHP that serves web requests (PHP-FPM or the Apache module), not only the CLI.
- Extensions:
  - required (Composer checks them): `ctype`, `filter`, `mbstring`, `openssl`; for the starter also `pdo`;
  - the database driver you use: `pdo_mysql`, `pdo_pgsql` or `pdo_sqlite`;
  - for the features you use: `fileinfo` (upload type detection), `redis` (Redis cache, sessions or queue), `simplexml` (S3 disk), `pcntl` (queue job timeouts), `tokenizer` (`view:lint`).

  A missing optional extension stops the feature with a message naming the extension, not with "class not found".
- `php.ini`:

```ini
expose_php = Off
display_errors = Off
log_errors = On
memory_limit = 256M          ; enough for one request; avoid multi-GB values
post_max_size = 8M           ; at least your largest upload plus form fields
upload_max_filesize = 5M     ; match the max_size validation rules

opcache.enable = 1
opcache.validate_timestamps = 0   ; reload PHP-FPM / Apache on every deploy
opcache.memory_consumption = 128
opcache.max_accelerated_files = 20000
```

Without opcache, PHP recompiles every file on every request; the Phase 4 measurement showed about 4× fewer requests per second.

## 2. Files and permissions

- Deploy the project **outside** the web root and point the document root at `public/`. Nothing else (`.env`, `config/`, `vendor/`, `storage/`, `database/`) may be reachable over HTTP. On shared hosting where that is impossible, keep the starter app's root `.htaccess`, which denies everything outside `public/`.
- Writable by the PHP user only: `storage/logs`, `storage/cache`, `storage/uploads`, and `database/` when using SQLite.
- `.env` readable by the PHP user only (`chmod 640` or stricter); never commit it.
- Install dependencies without development tools:

```bash
composer install --no-dev --optimize-autoloader --classmap-authoritative
```

## 3. Environment

```ini
APP_ENV=production
APP_DEBUG=0
APP_KEY=base64:...       ; php veyro key:generate --show; secret, the same on every server
APP_URL=https://example.com ; public address, used for links in mails
QUEUE_CONNECTION=database ; and keep a worker running (see Queue workers)
LOG_LEVEL=warning
DB_CONNECTION=mysql
DB_HOST=...
DB_DATABASE=...
DB_USERNAME=...           ; a user with access to this database only
DB_PASSWORD=...
CACHE_STORE=file          ; or redis; never array (sessions and rate limits would not persist)
MAIL_MAILER=smtp          ; never log in production (mail contains personal data and reset links)
MAIL_HOST=...             ; then: php veyro mail:test you@example.com
```

Real environment variables override `.env`, so secrets can come from the server or container instead of a file.

## 4. Web server

nginx:

```nginx
server {
    listen 443 ssl http2;
    server_name example.com;
    root /var/www/app/public;
    index index.php;

    client_max_body_size 8m;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }

    location ~ /\. { deny all; }
}
```

Apache: `DocumentRoot /var/www/app/public` with `AllowOverride All` for that directory, so `public/.htaccess` routes requests to `index.php`. Set `LimitRequestBody` to match the upload limit.

### Static assets and caching

Files in `public/` (CSS, JS, images, fonts) are served by the web server, not by PHP. Link them with `$urls->asset('/css/site.css')`: the URL then carries `?v=` from the file's modification time, so it changes whenever the file does. That makes long caching safe:

nginx, inside the `server` block:

```nginx
location ~* \.(?:css|js|mjs|svg|png|jpe?g|gif|webp|avif|ico|woff2?)$ {
    expires 1y;
    add_header Cache-Control "public, max-age=31536000, immutable";
    access_log off;
    try_files $uri =404;
}

gzip on;
gzip_types text/css application/javascript image/svg+xml application/json;
```

Apache (`mod_expires` and `mod_headers`; in the vhost or `public/.htaccess`):

```apache
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType text/css "access plus 1 year"
    ExpiresByType application/javascript "access plus 1 year"
    ExpiresByType image/svg+xml "access plus 1 year"
    ExpiresByType image/png "access plus 1 year"
    ExpiresByType image/webp "access plus 1 year"
    ExpiresByType font/woff2 "access plus 1 year"
</IfModule>
<IfModule mod_headers.c>
    <FilesMatch "\.(css|js|svg|png|jpe?g|webp|woff2?)$">
        Header set Cache-Control "public, max-age=31536000, immutable"
    </FilesMatch>
</IfModule>
```

- Use one year only for files linked through `asset()` (or with a hash in the name). A file linked without a version, such as `/favicon.ico`, keeps its old copy in browsers for that long.
- Deploys must keep modification times meaningful: copying with a tool that resets them all to one time is fine, restoring old times is not.
- HTML pages are not cached this way. For pages that change rarely, add `ConditionalGet` (see [HTTP](getting-started/http.md#etags-and-304-not-modified)) so browsers revalidate cheaply.
- Never set `public` caching on pages that show user data or set cookies.

## 5. HTTPS, proxies and headers

- Serve everything over HTTPS and redirect HTTP to HTTPS at the web server.
- **Behind a load balancer or CDN that ends TLS** (Cloudflare, AWS ALB, nginx in front of Apache), list its addresses in `security.trusted_proxies` and keep `TrustProxies` as the first global middleware. Otherwise PHP sees plain http: session cookies lose the `Secure` flag, HSTS is not sent and client IPs are the proxy's, so rate limits apply to everyone at once.
- Only list your own proxies. Listing `0.0.0.0/0` lets every client forge its IP and scheme.
- Once the whole site works over HTTPS, set `security.hsts_seconds` (start with 300, then 31536000).

## 6. Deploy steps

```bash
php veyro down --retry=120 --secret    # visitors get 503; the printed link lets you in
git pull                                  # or upload the release
composer install --no-dev --optimize-autoloader --classmap-authoritative
php veyro migrate --force              # --force is required in production
php veyro config:cache                 # after .env or config changes; see configuration guide
php veyro rbac:seed                    # safe to repeat; never deletes
sudo systemctl reload php8.2-fpm          # clears opcache when validate_timestamps=0
php veyro queue:restart                # workers finish their job and restart with the new code
php veyro storage:link                 # once per server, if you use the public disk
php veyro health                       # database, cache and storage/ with times and errors
php veyro up
```

### Maintenance mode

- `php veyro down` writes `storage/maintenance.json`; every web request then gets **503** with `Retry-After` and `Cache-Control: no-store`, before any session is started. `--message="..."` sets the page text, `--retry=` the seconds.
- **Getting in yourself:** `--allow=203.0.113.7,10.0.0.0/8` keeps access for addresses or ranges (the address comes from `ClientIp`, so set `security.trusted_proxies` behind a proxy). `--secret` prints a link once; opening it sets a cookie for 12 hours. Only a hash is stored, and the next `down` makes a new link.
- `/up` stays reachable during maintenance.
- **Queue workers and the scheduler keep running.** If a migration changes tables that jobs use, stop the workers first (`systemctl stop veyrophp-worker`) and start them after `up`.
- The page needs `BlockDuringMaintenance` in the global middleware (the starter has it, after `SecurityHeaders`).

### Health check

`GET /up` (starter route `health`) answers 200 `{"status":"ok","checks":{"database":"ok","cache":"ok","storage":"ok"}}`, or 503 with `"fail"` for a failed check. Error messages are never sent; they are logged, and `php veyro health` shows them. During maintenance the answer stays 200 and adds `"maintenance": true`, so load balancers do not drop every server. Add your own checks:

```php
use VeyroPHP\Http\Health\HealthCheck;

$app->container()->make(HealthCheck::class)->add('payments-api', static function (): void {
    // throw an exception to report a failure; keep it fast
});
```

Back up the database before running migrations. `migrate:rollback --force` exists, but rolling back can drop data that was written since the migration.

## 7. After deploying

- `curl -I https://example.com/` shows `Strict-Transport-Security` (if enabled), `X-Content-Type-Options` and `Content-Security-Policy`, and no `X-Powered-By`.
- `https://example.com/.env`, `/composer.json` and `/storage/logs/veyrophp.log` return 404.
- An error page shows no stack trace (`APP_DEBUG=0`).
- Log in once with a real account; then check that a few wrong passwords lead to the lockout message.

## 8. Operations

- **Run the scheduler** every minute from cron; it runs the tasks in `routes/schedule.php` ([scheduling guide](getting-started/scheduling.md)):

  ```bash
  * * * * * cd /var/www/app && php veyro schedule:run >> /dev/null 2>&1
  ```

  The starter app's schedule prunes the file cache every 30 minutes (expired sessions and rate-limit counters are otherwise only deleted when read, and sessions of visitors who never return would fill the disk) and deletes expired password reset tokens daily. On Windows use Task Scheduler every minute, or run `php veyro schedule:work` as a service.
- **Logs** (`storage/logs/`): keep them private; they contain IP addresses and emails. The logger never deletes or shrinks a file by itself, so choose one way to rotate:
  - **logrotate (Linux):** keep `LOG_DAYS=0` (one file). The logger opens the file for every entry, so logrotate's default move-and-create is safe; `copytruncate` is not needed.

    ```
    /var/www/app/storage/logs/veyrophp.log {
        daily
        rotate 14
        compress
        delaycompress
        missingok
        notifempty
        create 0640 www-data www-data
    }
    ```

  - **Without a rotation tool (Windows, shared hosting):** set `LOG_DAYS=14`. Entries then go to `veyrophp-YYYY-MM-DD.log`, and the first entry of a day deletes daily files older than 14 days. Files with other names (compressed copies, the old `veyrophp.log`) are left alone.
  - One entry is at most 256 KiB; a longer one is cut and ends with `[truncated N bytes]`.
  - Either way, watch free disk space: a flood of errors can still fill the disk within one day.
  - A failed log write (for example on a full disk) throws `LoggingException` instead of being ignored.
- **Updates:** apply PHP security releases promptly, and run `composer audit` regularly; update VeyroPHP patch releases without delay.
- **Backups:** database and `storage/uploads`, tested by restoring.
- **Do not** run `php veyro serve` in production; it is a single-threaded development server.

### Queue workers

With `QUEUE_CONNECTION=database` or `redis`, jobs only run while a worker runs. Keep one running with systemd (`/etc/systemd/system/veyrophp-worker.service`):

```ini
[Unit]
Description=VeyroPHP queue worker
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/app
ExecStart=/usr/bin/php veyro queue:work --max-time=3600
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

`sudo systemctl enable --now veyrophp-worker`. Supervisor works the same way (`autorestart=true`). Start more copies for more throughput; the queue hands each job to one worker only.

- `--max-time` makes the worker exit every hour so memory stays low; the process manager restarts it.
- Run `php veyro queue:restart` after each deploy, otherwise workers keep running old code.
- Watch `php veyro queue:failed`, or listen to `JobFailed` and alert someone.
- On Windows (no systemd) use Task Scheduler with `queue:work --stop-when-empty` every minute, or NSSM to run it as a service. Job timeouts are not enforced on Windows.
