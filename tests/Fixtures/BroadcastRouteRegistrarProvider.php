<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

/**
 * Simulates an application that registers its own `Broadcast::routes()`
 * with custom middleware. The static $mode selects whether the
 * registration happens in this provider's `boot()` (which always runs
 * before application `booted` callbacks) or inside an `app->booted()`
 * callback queued after the package provider's own callback.
 */
final class BroadcastRouteRegistrarProvider extends ServiceProvider
{
    /**
     * When the app "registers" its broadcasting route: `boot` or `booted`.
     */
    public static string $mode = 'boot';

    /**
     * The route attributes the "application" uses.
     *
     * @var array<string, mixed>
     */
    public static array $attributes = ['middleware' => ['web', 'app-auth']];

    /**
     * {@inheritDoc}
     */
    public function boot(): void
    {
        if (self::$mode === 'booted') {
            $this->app->booted(static function (): void {
                Broadcast::routes(self::$attributes);
            });

            return;
        }

        Broadcast::routes(self::$attributes);
    }
}
