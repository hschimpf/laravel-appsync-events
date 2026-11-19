/**
 * Maps a Laravel broadcasting channel name to an AWS AppSync Events
 * channel path of the form `/{namespace}/{type}/{segment}`.
 *
 * `{type}` is `private` or `public`, taken from the Laravel/Echo channel
 * prefix (`private-`, `private-encrypted-` or none). Presence channels
 * are out of scope for this package: a name starting with `presence-` is
 * rejected with an {@link InvalidChannelNameError}. The rest of the name
 * is split on the first `.`: the left side becomes the AppSync channel
 * namespace (falling back to the default namespace when there is no dot)
 * and the right side becomes the path segment with every remaining `.`
 * replaced by `-`.
 *
 * This mirrors `HDSSolutions\Laravel\AppSyncEvents\Support\ChannelPath`
 * in the PHP package — including the `presence-` rejection; both
 * implementations are verified against the same fixture
 * (`tests/fixtures/channel-paths.json`).
 */

const SEGMENT_PATTERN = /^[A-Za-z0-9](?:[A-Za-z0-9_-]*[A-Za-z0-9])?$/;

/**
 * The channel prefixes that mark guarded Laravel channels, longest first
 * so `private-encrypted-` wins over `private-`.
 */
const TYPES: ReadonlyArray<readonly [prefix: string, type: 'private']> = [
    ['private-encrypted-', 'private'],
    ['private-', 'private'],
];

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
 * @param defaultNamespace - The AppSync namespace used when the name
 *     carries no `.`-separated namespace part.
 * @returns The channel path `/{namespace}/{type}/{segment}`.
 *
 * @throws {@link InvalidChannelNameError} When the name is a presence
 *     channel or would produce an invalid channel path (empty segments
 *     or characters outside `[A-Za-z0-9_-]`).
 */
export function toAppSyncChannel(channelName: string, defaultNamespace = 'default'): string {
    if (channelName.startsWith('presence-')) {
        throw new InvalidChannelNameError(
            `Channel name "${channelName}" is not supported: ` +
            'presence channels (presence- prefix) are not supported by AppSync Events.',
        );
    }

    let type: 'private' | 'public' = 'public';
    let name = channelName;

    for (const [prefix, prefixType] of TYPES) {
        if (channelName.startsWith(prefix)) {
            type = prefixType;
            name = channelName.slice(prefix.length);
            break;
        }
    }

    let namespace: string;
    let segment: string;

    const dot = name.indexOf('.');
    if (dot >= 0) {
        namespace = name.slice(0, dot);
        segment = name.slice(dot + 1).replaceAll('.', '-');
    } else {
        namespace = defaultNamespace;
        segment = name;
    }

    assertSegment(namespace, 'namespace', channelName);
    assertSegment(segment, 'channel', channelName);

    return `/${namespace}/${type}/${segment}`;
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
            `the ${kind} segment "${segment}" is empty or contains characters outside [A-Za-z0-9_-].`,
        );
    }
}
