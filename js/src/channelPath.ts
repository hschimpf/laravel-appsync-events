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
 * rejected with an {@link InvalidChannelNameError} as well. The rest of
 * the name is split on `.` and each part becomes a path segment. Dashes
 * stay literal, so `orders.1.items` (`/public/orders/1/items`) and
 * `orders.1-items` (`/public/orders/1-items`) are different channels.
 *
 * AppSync channel paths are limited to 1–5 segments, each 1–50
 * characters alphanumeric with interior dashes (`[A-Za-z0-9-]`, no
 * underscores).
 *
 * This mirrors `HDSSolutions\Laravel\AppSyncEvents\Support\ChannelPath`
 * in the PHP package — including the `presence-` and `private-encrypted-`
 * rejections; both implementations are verified against the same fixture
 * (`tests/fixtures/channel-paths.json`).
 */

const SEGMENT_PATTERN = /^[A-Za-z0-9](?:[A-Za-z0-9-]{0,48}[A-Za-z0-9])?$/;

/**
 * Thrown when a Laravel channel name cannot be mapped to a valid AppSync
 * channel path.
 */
export class InvalidChannelNameError extends Error {
    /**
     * Create a new error instance.
     */
    public constructor(message: string) {
        super(message);
        this.name = 'InvalidChannelNameError';
    }
}

/**
 * Build the AppSync channel path for a Laravel channel name.
 *
 * @param channelName - The channel name as it appears on the wire
 *     (e.g. `private-orders.1`, `orders`).
 * @param publicNamespace - The AppSync namespace for public channels.
 * @param privateNamespace - The AppSync namespace for private channels.
 * @returns The channel path `/{namespace}/{segment}`.
 *
 * @throws {@link InvalidChannelNameError} When the namespaces are invalid
 *     or equal, the name is a presence or private-encrypted channel, would
 *     exceed five path segments, or would produce an invalid segment
 *     (empty, over 50 characters, or characters outside `[A-Za-z0-9-]`).
 */
export function toAppSyncChannel(
    channelName: string,
    publicNamespace = 'public',
    privateNamespace = 'private',
): string {
    assertNamespace(publicNamespace, 'public');
    assertNamespace(privateNamespace, 'private');

    if (publicNamespace === privateNamespace) {
        throw new InvalidChannelNameError(
            `The public and private namespaces must differ: both are "${publicNamespace}".`,
        );
    }

    if (channelName.startsWith('presence-')) {
        throw new InvalidChannelNameError(
            `Channel name "${channelName}" is not supported: ` +
            'presence channels (presence- prefix) are not supported by AppSync Events.',
        );
    }

    if (channelName.startsWith('private-encrypted-')) {
        throw new InvalidChannelNameError(
            `Channel name "${channelName}" is not supported: ` +
            'payload encryption is not implemented by this package, ' +
            'so private-encrypted channels are not supported (use private-).',
        );
    }

    let namespace: string;
    let name: string;

    if (channelName.startsWith('private-')) {
        namespace = privateNamespace;
        name = channelName.slice('private-'.length);
    } else {
        namespace = publicNamespace;
        name = channelName;
    }

    const segments = name.split('.');

    if (segments.length > 4) {
        throw new InvalidChannelNameError(
            `Channel name "${channelName}" produces too many AppSync channel path segments: ` +
            `${segments.length + 1} exceeds the limit of 5 segments ` +
            '(namespace and at most 4 channel name segments).',
        );
    }

    for (const segment of segments) {
        assertSegment(segment, 'channel', channelName);
    }

    return `/${namespace}/${segments.join('/')}`;
}

/**
 * Ensure a configured namespace is a valid AppSync path segment.
 *
 * @param namespace - The namespace to validate.
 * @param kind - Which namespace it is, for the error message.
 * @throws {@link InvalidChannelNameError} When the namespace is malformed.
 */
function assertNamespace(namespace: string, kind: string): void {
    if (!SEGMENT_PATTERN.test(namespace)) {
        throw new InvalidChannelNameError(
            `The ${kind} namespace "${namespace}" is not a valid AppSync channel namespace: ` +
            'it must be 1–50 characters, alphanumeric at both ends ' +
            'with dashes allowed inside ([A-Za-z0-9-]).',
        );
    }
}

/**
 * Ensure a produced path segment is non-empty and well formed.
 *
 * @param segment - The segment to validate.
 * @param kind - What the segment represents, for the error message.
 * @param channelName - The original channel name, for the error message.
 * @throws {@link InvalidChannelNameError} When the segment is empty or malformed.
 */
function assertSegment(segment: string, kind: string, channelName: string): void {
    if (!SEGMENT_PATTERN.test(segment)) {
        throw new InvalidChannelNameError(
            `Channel name "${channelName}" produces an invalid AppSync channel path: ` +
            `the ${kind} segment "${segment}" is empty, over 50 characters ` +
            'or contains characters outside [A-Za-z0-9-].',
        );
    }
}
