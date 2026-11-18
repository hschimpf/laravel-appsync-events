<?php

declare(strict_types=1);

use HDSSolutions\Laravel\AppSyncEvents\AppSyncEventsServiceProvider;

it('registers the package service provider', function (): void {
    expect(app()->providerIsLoaded(AppSyncEventsServiceProvider::class))->toBeTrue();
});
