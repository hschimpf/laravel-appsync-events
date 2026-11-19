import { readFileSync } from 'node:fs';

import { describe, expect, it } from 'vitest';

import { InvalidChannelNameError, toAppSyncChannel } from '../src/channelPath.js';

/**
 * The shared fixture, also consumed by the PHP test suite — it defines
 * the expected channel-path mapping for both implementations.
 */
type FixtureCase = {
    channel: string;
    publicNamespace?: string;
    privateNamespace?: string;
    path?: string;
    throws?: boolean;
};

const fixture = JSON.parse(
    readFileSync(new URL('../../tests/fixtures/channel-paths.json', import.meta.url), 'utf8'),
) as FixtureCase[];

describe('toAppSyncChannel', () => {
    it.each(fixture.filter((c) => !c.throws))(
        'maps $channel to $path',
        ({ channel, publicNamespace, privateNamespace, path }) => {
            expect(toAppSyncChannel(
                channel,
                publicNamespace ?? 'public',
                privateNamespace ?? 'private',
            )).toBe(path);
        },
    );

    it.each(fixture.filter((c) => c.throws))(
        'rejects $channel',
        ({ channel, publicNamespace, privateNamespace }) => {
            expect(() => toAppSyncChannel(
                channel,
                publicNamespace ?? 'public',
                privateNamespace ?? 'private',
            )).toThrow(InvalidChannelNameError);
        },
    );

    it.each(['presence-room.5', 'presence-lobby', 'presence-'])(
        'rejects %s as an unsupported presence channel',
        (channel) => {
            expect(() => toAppSyncChannel(channel))
                .toThrow('presence channels (presence- prefix) are not supported');
        },
    );

    it('rejects private-encrypted- channel names as unsupported', () => {
        expect(() => toAppSyncChannel('private-encrypted-orders.9'))
            .toThrow(
                'payload encryption is not implemented by this package, ' +
                'so private-encrypted channels are not supported (use private-)',
            );
    });

    it('rejects channel names that exceed the 5-segment AppSync limit', () => {
        expect(() => toAppSyncChannel('private-org.1.team.2.board'))
            .toThrow('6 exceeds the limit of 5 segments (namespace and at most 4 channel name segments)');
    });
});
