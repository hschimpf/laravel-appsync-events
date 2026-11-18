<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Facades;

use Closure;
use HDSSolutions\Laravel\AppSyncEvents\AppSyncEventsManager;
use HDSSolutions\Laravel\AppSyncEvents\Auth\TokenSigner;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for the AppSync Events manager.
 *
 * @method static string uid(object $user) Resolve the uid claim for a user.
 * @method static static resolveUidUsing(Closure $resolver) Replace the uid resolution strategy at runtime.
 * @method static array{wsEndpoint: string, httpHost: string, connectToken: string, authEndpoint: string, defaultNamespace: string, inactivityTimeout: int}|null clientConfig(?Authenticatable $user = null) Build the JS connector configuration (null for guests).
 * @method static TokenSigner signer() The token signer built from the configured signing secret.
 * @method static string httpHost(string $endpoint) Extract the `host[:port]` authority from an endpoint URL.
 *
 * @see AppSyncEventsManager
 */
final class AppSyncEvents extends Facade
{
    /**
     * {@inheritDoc}
     */
    protected static function getFacadeAccessor(): string
    {
        return AppSyncEventsManager::class;
    }
}
