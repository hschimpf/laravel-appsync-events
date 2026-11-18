<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Facade for the AppSync Events broadcasting driver.
 */
final class AppSyncEvents extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'appsync-events';
    }
}
