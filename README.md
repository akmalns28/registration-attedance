<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## RAMS event media and invitation exports

Apply the additive migrations and rebuild the frontend during deployment.

```sh
php artisan migrate --force --no-interaction
php artisan config:cache --no-interaction
npm run build
php artisan queue:restart --no-interaction
```

Run a supervised export worker alongside the existing workers. The dedicated database connection uses the application's database and `jobs` table. Web, worker, and scheduler processes need a shared, writable `storage/app/private` volume. Install PHP GD with WebP support, Fileinfo, ZIP, and CLI PCNTL in the appropriate containers.

```sh
php artisan queue:work invitation_exports --queue=invitation-exports --tries=3 --timeout=120 --sleep=1 --memory=128 --max-time=3600 --no-interaction
```

- The default direct-download limit is 25 invitations. Larger exports render five invitations per PDF and deliver one ZIP. Tune `INVITATION_EXPORT_DIRECT_LIMIT` (0–50) and `INVITATION_EXPORT_BATCH_SIZE` (1–25) after measuring with production artwork.
- Align the supervisor and container stop grace period with `INVITATION_EXPORT_JOB_TIMEOUT` (120 seconds by default). The connection's reservation expires 60 seconds after the configured job timeout. Run Laravel's scheduler every minute so it can prune expired exports hourly. `INVITATION_EXPORT_RETENTION_HOURS` defaults to 24.
- Posters accept JPG, JPEG, PNG, and WebP up to 30 MiB each, with a 64 MiB request limit including multipart overhead. The longest side can be up to 10,000 pixels with a 36-megapixel total cap. Originals stay in private storage alongside 400, 1200, and 1600-pixel JPEG variants for the grid, event detail, and invitations. The gallery shares PHP's 30 MiB per-file limit.
- A queued export evaluates current eligibility as it processes each batch and excludes registrations created after the request. The resulting count can change if staff amend registrations or invited days during processing.
- Verify on staging with production-like artwork, Keycloak roles, and target mobile browsers. Check video playback and ranges through Nginx, scan a printed PDF QR code, and confirm the worker completes a 300-person export and the scheduler removes expired archives.

### Deploy the poster upload fix

The repository's FrankenPHP `Dockerfile` installs GD with JPEG, PNG, and WebP support and loads `php.prod.ini` from PHP's scanned configuration directory. Keep the existing Compose stack, its service names, and its storage mounts. Check for existing PHP overrides or a custom `PHP_INI_SCAN_DIR` in the web service. Use the checked-in settings as the single source for these six directives.

After reviewing `git status --short` locally, stage the tracked hotfix changes and its new files, then push `develop`.

```sh
git add -u
git add -- .dockerignore Dockerfile php.prod.ini app/Services/EventPosterImages.php resources/views/errors/413.blade.php
git diff --cached --check
git commit -m "fix: support production-sized event posters"
git push origin develop
```

From the VPS checkout, use the existing web service and its configured local image tag. For a Compose service pinned to an image digest, update that pin through its deployment configuration instead.

```sh
git switch develop
git pull --ff-only origin develop
docker compose -f compose.prod.yml config --services
read -r -p 'FrankenPHP web service name: ' WEB_SERVICE
WEB_CONTAINER=$(docker compose -f compose.prod.yml ps -q "$WEB_SERVICE")
test -n "$WEB_CONTAINER" || exit 1
WEB_IMAGE=$(docker inspect --format '{{.Config.Image}}' "$WEB_CONTAINER")
docker build --pull --tag "$WEB_IMAGE" --file Dockerfile .
docker compose -f compose.prod.yml up -d --no-deps --no-build --pull never --force-recreate "$WEB_SERVICE"
docker compose -f compose.prod.yml exec -T "$WEB_SERVICE" php artisan config:cache --no-interaction
docker compose -f compose.prod.yml restart "$WEB_SERVICE"
docker compose -f compose.prod.yml exec -T "$WEB_SERVICE" php artisan queue:restart --no-interaction
docker compose -f compose.prod.yml exec -T "$WEB_SERVICE" php artisan config:show event_assets --no-interaction
```

Update any existing `EVENT_POSTER_MAX_KB=5120` deployment override to `30720` before rebuilding the config cache. Recreate any separate queue worker service using the rebuilt application image so it loads the updated PHP and application code. When the Compose service bind-mounts source over `/app`, also make the new production Vite build available through that mount. This fix has no schema migration.

In the existing Nginx virtual host for RAMS, set the following directive in the application server block or upload location, then test and reload Nginx. Any custom Caddy request-body limit and the Cloudflare zone upload limit also need to allow at least 64 MiB.

```nginx
client_max_body_size 64M;
```

```sh
sudo nginx -t && sudo systemctl reload nginx
```

For Nginx managed by the same Compose stack, use its existing service name.

```sh
read -r -p 'Nginx service name: ' NGINX_SERVICE
docker compose -f compose.prod.yml exec -T "$NGINX_SERVICE" nginx -t && \
    docker compose -f compose.prod.yml exec -T "$NGINX_SERVICE" nginx -s reload
```

Verify the active PHP settings through FrankenPHP HTTP inside the web container. This command creates a temporary loopback-only probe in the default document root and removes it on exit. It expects the web service to listen on port 80 inside the container.

```sh
docker compose -f compose.prod.yml exec -T "$WEB_SERVICE" sh <<'SH'
set -eu
name="rams-limits-$(php -r 'echo bin2hex(random_bytes(16));').php"
probe="/app/public/$name"
trap 'rm -f "$probe"' EXIT
cat > "$probe" <<'PHP'
<?php
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
header('Content-Type: application/json');
$keys = ['upload_max_filesize', 'post_max_size', 'memory_limit', 'max_file_uploads', 'max_execution_time', 'max_input_time'];
$limits = array_combine($keys, array_map('ini_get', $keys));
echo json_encode(['sapi' => PHP_SAPI, 'limits' => $limits, 'gd_webp' => gd_info()['WebP Support'] ?? false], JSON_PRETTY_PRINT);
PHP
php -r '$body = file_get_contents($argv[1]); if ($body === false) { exit(1); } echo $body, PHP_EOL;' "http://127.0.0.1/$name"
SH
```

Expect `frankenphp`, an enabled WebP decoder, and limits of `30M`, `64M`, `512M`, `30`, `120`, and `120`, in that order. Finish by uploading a 22 MiB poster through the public URL and opening the event thumbnail and a generated invitation.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
