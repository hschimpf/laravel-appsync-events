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
 * name is split on the first `.`: the left side becomes the AppSync
 * channel namespace (falling back to the default namespace when there
 * is no dot) and the right side becomes the path segment with every
 * remaining `.` replaced by `-`.
 *
 * AppSync requires every segment of a channel path to be non-empty, and a
 * namespace name to be alphanumeric with interior dashes (max 50 chars,
 * see the `Namespace` shape of the AppSync API and
 * https://docs.aws.amazon.com/appsync/latest/eventapi/channel-namespaces.html).
 * Underscores are additionally allowed inside any segment, since the
 * AppSync data plane accepts them. Names that would produce an invalid
 * path are rejected with an {@see InvalidArgumentException} instead of
 * being sent to an endpoint that would refuse them.
 */
final class ChannelPath
{
    /**
     * The charset every produced path segment must satisfy: alphanumeric
     * at both ends, alphanumerics, dashes and underscores inside.
     */
    private const SEGMENT_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9_-]*[A-Za-z0-9])?$/';

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
            [$namespace, $remainder] = explode('.', $name, 2);
            $segment = str_replace('.', '-', $remainder);
        } else {
            $namespace = $defaultNamespace;
            $segment = $name;
        }

        self::assertValidSegment($namespace, 'namespace', $channelName);
        self::assertValidSegment($segment, 'channel', $channelName);

        return "/{$namespace}/{$type}/{$segment}";
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
                'Channel name "%s" produces an invalid AppSync channel path: the %s segment "%s" is empty or contains characters outside [A-Za-z0-9_-].',
                $channelName,
                $kind,
                $segment,
            ));
        }
    }
}
