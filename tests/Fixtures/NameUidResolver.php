<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures;

/**
 * Invokable uid resolver used to verify the `appsync-events.uid_resolver`
 * class-string configuration.
 */
final class NameUidResolver
{
    /**
     * Resolve a uid from the user's `name` attribute.
     *
     * @param  object  $user  The user to resolve a uid for.
     */
    public function __invoke(object $user): string
    {
        /** @var object{name?: string} $user */
        return 'name-'.($user->name ?? 'anonymous');
    }
}
