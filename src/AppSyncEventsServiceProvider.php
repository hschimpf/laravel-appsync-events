<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the AWS AppSync Events broadcasting driver for Laravel.
 */
final class AppSyncEventsServiceProvider extends ServiceProvider
{
    /**
     * Register any package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/appsync-events.php', 'appsync-events');

        $config = $this->app['config'];

        if ($config->get('broadcasting.connections.appsync') === null) {
            $config->set('broadcasting.connections.appsync', ['driver' => 'appsync']);
        }
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        //
    }
}
