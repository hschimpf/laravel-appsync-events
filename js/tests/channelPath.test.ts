import { readFileSync } from 'node:fs';

import { describe, expect, it } from 'vitest';

import { InvalidChannelNameError, toAppSyncChannel } from '../src/channelPath.js';

/**
 * The shared fixture, also consumed by the PHP test suite — it defines
 * the expected channel-path mapping for both implementations.
 */
type FixtureCase = {
    channel: string;
    defaultNamespace?: string;
    path?: string;
    throws?: boolean;
};

const fixture = JSON.parse(
    readFileSync(new URL('../../tests/fixtures/channel-paths.json', import.meta.url), 'utf8'),
) as FixtureCase[];

describe('toAppSyncChannel', () => {
    it.each(fixture.filter((c) => !c.throws))(
        'maps $channel to $path',
        ({ channel, defaultNamespace, path }) => {
            expect(toAppSyncChannel(channel, defaultNamespace ?? 'default')).toBe(path);
        },
    );

    it.each(fixture.filter((c) => c.throws))(
        'rejects $channel',
        ({ channel, defaultNamespace }) => {
            expect(() => toAppSyncChannel(channel, defaultNamespace ?? 'default'))
                .toThrow(InvalidChannelNameError);
        },
    );
});
