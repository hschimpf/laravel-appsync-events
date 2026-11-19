<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Support;

use InvalidArgumentException;

/**
 * Maps a Laravel broadcasting channel name to an AWS AppSync Events
 * channel path of the form `/{namespace}/{type}/{segment}`.
 *
 * `{type}` is `private` or `public`, taken from the Laravel/Echo channel
 * prefix (`private-`, `private-encrypted-` or none). Presence channels
 * are out of scope for this package: a name starting with `presence-`
 * is rejected with an {@see InvalidArgumentException}. The rest of the
 * name is split on `.`: the first part becomes the AppSync channel
 * namespace (falling back to the default namespace when there is no dot)
 * and each remaining part becomes a path segment. Dashes stay literal,
 * so `orders.1.items` (`/orders/public/1/items`) and `orders.1-items`
 * (`/orders/public/1-items`) are different channels.
 *
 * AppSync channel paths are limited to 1–5 segments, each 1–50
 * characters alphanumeric with interior dashes (`[A-Za-z0-9-]`, no
 * underscores — see the Event API WebSocket protocol reference at
 * https://docs.aws.amazon.com/appsync/latest/eventapi/event-api-websocket-protocol.html).
 * Names that would produce more than five path segments or an invalid
 * segment are rejected with an {@see InvalidArgumentException} instead
 * of being sent to an endpoint that would refuse them.
 */
final class ChannelPath
{
    /**
     * The charset every produced path segment must satisfy: 1–50 chars,
     * alphanumeric at both ends, alphanumerics and dashes inside.
     */
    private const SEGMENT_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,48}[A-Za-z0-9])?$/';

    /**
     * The channel prefixes that mark guarded Laravel channels, longest
     * first so `private-encrypted-` wins over `private-`.
     *
     * @var array<string, string>
     */
    private const TYPES = [
        'private-encrypted-' => 'private',
        'private-' => 'private',
    ];

    /**
     * Build the AppSync channel path for a Laravel channel name.
     *
     * @param  string  $channelName  The channel name as it appears on the wire
     *                               (e.g. `private-orders.1`, `orders`).
     * @param  string  $defaultNamespace  The AppSync namespace used when the name
     *                                    carries no `.`-separated namespace part.
     * @return string The channel path `/{namespace}/{type}/{segment}`.
     *
     * @throws InvalidArgumentException When the name is a presence channel or
     *                                  would produce an invalid channel path.
     */
    public static function from(string $channelName, string $defaultNamespace = 'default'): string
    {
        if (str_starts_with($channelName, 'presence-')) {
            throw new InvalidArgumentException(sprintf(
                'Channel name "%s" is not supported: presence channels (presence- prefix) are not supported by AppSync Events.',
                $channelName,
            ));
        }

        $type = 'public';
        $name = $channelName;

        foreach (self::TYPES as $prefix => $prefixType) {
            if (str_starts_with($channelName, $prefix)) {
                $type = $prefixType;
                $name = substr($channelName, strlen($prefix));
                break;
            }
        }

        if (str_contains($name, '.')) {
            $parts = explode('.', $name);
            $namespace = array_shift($parts);
            $segments = $parts;
        } else {
            $namespace = $defaultNamespace;
            $segments = [$name];
        }

        if (count($segments) > 3) {
            throw new InvalidArgumentException(sprintf(
                'Channel name "%s" produces too many AppSync channel path segments: %d exceeds the limit of 5 segments (namespace, type and at most 3 name segments).',
                $channelName,
                count($segments) + 2,
            ));
        }

        self::assertValidSegment($namespace, 'namespace', $channelName);

        foreach ($segments as $segment) {
            self::assertValidSegment($segment, 'channel', $channelName);
        }

        return "/{$namespace}/{$type}/".implode('/', $segments);
    }

    /**
     * Ensure a produced path segment is non-empty and well formed.
     *
     * @param  string  $segment  The segment to validate.
     * @param  string  $kind  What the segment represents, for the error message.
     * @param  string  $channelName  The original channel name, for the error message.
     *
     * @throws InvalidArgumentException When the segment is empty or malformed.
     */
    private static function assertValidSegment(string $segment, string $kind, string $channelName): void
    {
        if (! (preg_match(self::SEGMENT_PATTERN, $segment) === 1)) {
            throw new InvalidArgumentException(sprintf(
                'Channel name "%s" produces an invalid AppSync channel path: the %s segment "%s" is empty, over 50 characters or contains characters outside [A-Za-z0-9-].',
                $channelName,
                $kind,
                $segment,
            ));
        }
    }
}
