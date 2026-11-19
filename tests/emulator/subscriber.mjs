/**
 * Subscribes to channels with the BUILT connector (js/dist/index.js) and
 * reports everything as JSON lines on stdout for the PHP harness.
 *
 * Usage: node subscriber.mjs '<json config>'
 *
 * Config:
 *   wsEndpoint       ws(s)://…/event/realtime endpoint
 *   httpHost         host[:port] for handshake/subscribe authorization
 *   connectToken?    token sent as Authorization in the handshake
 *   publicApiKey?    x-api-key for handshake + public subscriptions
 *   publicNamespace?  namespace for public channels (default 'public')
 *   privateNamespace? namespace for private- channels (default 'private')
 *   subscribeTokens? map of wire channel name → token, served by a custom
 *                    `authorizer` (the emulator does not validate it)
 *   channels         [{name: 'private-orders.1'|'orders.1', event: '.OrderShipped'}]
 *
 * Emitted lines: {"type":"status","status":…}, {"type":"subscribed","channel":…},
 * {"type":"ready"}, {"type":"event","channel":…,"event":…,"data":…},
 * {"type":"error","channel":…,"error":…}.
 * Exits on stdin close or SIGTERM.
 */
import { AppSyncConnector } from '../../js/dist/index.js';

const config = JSON.parse(process.argv[2]);
const print = (line) => process.stdout.write(`${JSON.stringify(line)}\n`);

const connector = new AppSyncConnector({
    broadcaster: AppSyncConnector,
    wsEndpoint: config.wsEndpoint,
    httpHost: config.httpHost,
    connectToken: config.connectToken,
    publicApiKey: config.publicApiKey,
    publicNamespace: config.publicNamespace,
    privateNamespace: config.privateNamespace,
    authorizer: async (channelName) => config.subscribeTokens?.[channelName] ?? 'emulator-subscribe-token',
});

connector.onConnectionChange((status) => print({ type: 'status', status }));

const pending = new Set();

for (const spec of config.channels ?? []) {
    pending.add(spec.name);

    const channel = spec.name.startsWith('private-')
        ? connector.privateChannel(spec.name.slice('private-'.length))
        : connector.channel(spec.name);

    channel
        .subscribed(() => {
            pending.delete(spec.name);
            print({ type: 'subscribed', channel: spec.name });
            if (pending.size === 0) {
                print({ type: 'ready' });
            }
        })
        .error((error) => print({ type: 'error', channel: spec.name, error }))
        .listen(spec.event, (data) => print({ type: 'event', channel: spec.name, event: spec.event, data }));
}

if (pending.size === 0) {
    print({ type: 'ready' });
}

const shutdown = () => {
    connector.disconnect();
    process.exit(0);
};

process.on('SIGTERM', shutdown);
process.stdin.on('end', shutdown);
process.stdin.resume();
