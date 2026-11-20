<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Support;

use InvalidArgumentException;

/**
 * Maps a Laravel broadcasting channel name to an AWS AppSync Events
 * channel path of the form `/{namespace}/{segment}`.
 *
 * `{namespace}` is the public or private namespace, taken from the
 * Laravel/Echo channel prefix (`private-` or none). Namespaces are the
 * AppSync authorization boundary, so the channel type is mapped onto
 * them directly. `private-encrypted-` channels are rejected because
 * payload encryption is not implemented by this package, and presence
 * channels are out of scope: a name starting with `presence-` is
 * rejected with an {@see InvalidArgumentException} as well. The rest of
 * the name is split on `.` and each part becomes a path segment. Dashes
 * stay literal, so `orders.1.items` (`/public/orders/1/items`) and
 * `orders.1-items` (`/public/orders/1-items`) are different channels.
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
     * Build the AppSync channel path for a Laravel channel name.
     *
     * @param  string  $channelName  The channel name as it appears on the wire
     *                               (e.g. `private-orders.1`, `orders`).
     * @param  string  $publicNamespace  The AppSync namespace for public channels.
     * @param  string  $privateNamespace  The AppSync namespace for private channels.
     * @return string The channel path `/{namespace}/{segment}`.
     *
     * @throws InvalidArgumentException When the namespaces are invalid or equal,
     *                                  the name is a presence or private-encrypted
     *                                  channel, or would produce an invalid
     *                                  channel path.
     */
    public static function from(
        string $channelName,
        string $publicNamespace = 'public',
        string $privateNamespace = 'private',
    ): string {
        self::assertValidNamespace($publicNamespace, 'public');
        self::assertValidNamespace($privateNamespace, 'private');

        if ($publicNamespace === $privateNamespace) {
            throw new InvalidArgumentException(sprintf(
                'The public and private namespaces must differ: both are "%s".',
                $publicNamespace,
            ));
        }

        if (str_starts_with($channelName, 'presence-')) {
            throw new InvalidArgumentException(sprintf(
                'Channel name "%s" is not supported: presence channels (presence- prefix) are not supported by AppSync Events.',
                $channelName,
            ));
        }

        if (str_starts_with($channelName, 'private-encrypted-')) {
            throw new InvalidArgumentException(sprintf(
                'Channel name "%s" is not supported: payload encryption is not implemented by this package, so private-encrypted channels are not supported (use private-).',
                $channelName,
            ));
        }

        if (str_starts_with($channelName, 'private-')) {
            $namespace = $privateNamespace;
            $name = substr($channelName, strlen('private-'));
        } else {
            $namespace = $publicNamespace;
            $name = $channelName;
        }

        $segments = explode('.', $name);

        if (count($segments) > 4) {
            throw new InvalidArgumentException(sprintf(
                'Channel name "%s" produces too many AppSync channel path segments: %d exceeds the limit of 5 segments (namespace and at most 4 channel name segments).',
                $channelName,
                count($segments) + 1,
            ));
        }

        foreach ($segments as $segment) {
            self::assertValidSegment($segment, 'channel', $channelName);
        }

        return "/{$namespace}/".implode('/', $segments);
    }

    /**
     * Ensure a configured namespace is a valid AppSync path segment.
     *
     * @param  string  $namespace  The namespace to validate.
     * @param  string  $kind  Which namespace it is, for the error message.
     *
     * @throws InvalidArgumentException When the namespace is malformed.
     */
    private static function assertValidNamespace(string $namespace, string $kind): void
    {
        if (! (preg_match(self::SEGMENT_PATTERN, $namespace) === 1)) {
            throw new InvalidArgumentException(sprintf(
                'The %s namespace "%s" is not a valid AppSync channel namespace: it must be 1–50 characters, alphanumeric at both ends with dashes allowed inside ([A-Za-z0-9-]).',
                $kind,
                $namespace,
            ));
        }
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
