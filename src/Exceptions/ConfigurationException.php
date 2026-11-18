<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when the package is used without required configuration —
 * e.g. a missing signing secret or Event API endpoint — with a message
 * naming the environment variable or config key to set.
 */
final class ConfigurationException extends InvalidArgumentException
{
    /**
     * Build an exception for a missing config value.
     *
     * @param  string  $what  A human description of what is missing.
     * @param  string  $configKey  The `appsync-events.*` config key to set.
     * @param  string  $envVar  The environment variable that feeds it.
     */
    public static function missing(string $what, string $configKey, string $envVar): self
    {
        return new self(sprintf(
            '%s is not configured. Set the %s environment variable or the %s config value.',
            $what,
            $envVar,
            $configKey,
        ));
    }
}
