<?php

namespace App\Providers;

use App\Services\KeycloakProvider;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $queues = implode(',', array_unique([(string) config('event_notifications.queue', 'emails'), 'default']));
            $connection = config('event_notifications.connection') ?: config('queue.default');
            DevCommands::artisan(sprintf(
                'queue:listen %s --queue=%s --tries=%d --timeout=%d',
                escapeshellarg($connection),
                escapeshellarg($queues),
                max(1, (int) config('event_notifications.tries', 3)),
                max(1, (int) config('event_notifications.timeout', 60)),
            ), 'queue');
        }

        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite(
                'keycloak',
                KeycloakProvider::class
            );
        });
    }
}
