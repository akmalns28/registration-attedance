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
- Set `upload_max_filesize` to cover the largest allowed individual file. Set PHP `post_max_size` and Nginx `client_max_body_size` to cover the largest intended upload request, including multipart overhead. Set `max_file_uploads` to cover the larger configured file count plus an event logo if applicable. Application limits are in `config/event_assets.php` and `.env.example`.
- A queued export evaluates current eligibility as it processes each batch and excludes registrations created after the request. The resulting count can change if staff amend registrations or invited days during processing.
- Verify on staging with production-like artwork, Keycloak roles, and target mobile browsers. Check video playback and ranges through Nginx, scan a printed PDF QR code, and confirm the worker completes a 300-person export and the scheduler removes expired archives.

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
