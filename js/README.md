# @hds-solutions/laravel-echo-appsync

[Laravel Echo](https://github.com/laravel/echo) 2 connector for **AWS AppSync
Event APIs** — the browser-side companion of the
[`hds-solutions/laravel-appsync-events`](https://github.com/hschimpf/laravel-appsync-events)
Composer package. Speaks the AppSync Events WebSocket protocol
(`aws-appsync-event-ws` subprotocol + `header-…` authorization), including
reconnect with jittered exponential backoff and Echo-compatible channel
auth through `/broadcasting/auth`.

## Install

```bash
npm install laravel-echo @hds-solutions/laravel-echo-appsync
```

Requires `laravel-echo ^2` (peer dependency). Runs in browsers; Node >= 22
is only needed to build or test the package itself.

## Usage

```ts
import Echo from 'laravel-echo';
import { appSyncEchoOptions } from '@hds-solutions/laravel-echo-appsync';

const echo = new Echo(appSyncEchoOptions({
    wsEndpoint: 'wss://<id>.appsync-realtime-api.<region>.amazonaws.com/event/realtime',
    connectToken: () => fetchConnectToken(), // re-evaluated on every (re)connect
}));

echo.private('orders.1').listen('.OrderShipped', (e) => {
    console.log(e);
});
```

The plain Echo option shape works too:
`new Echo({ broadcaster: AppSyncConnector, ...options })`.

## Options

| Option | Type | Default | Description |
| --- | --- | --- | --- |
| `wsEndpoint` | `string` | — | **Required.** `wss://…/event/realtime` endpoint of the Event API. |
| `connectToken` | `string \| () => string \| Promise<string>` | — | Token for the WebSocket handshake. A function is re-evaluated on every (re)connect — use it to refresh tokens. |
| `httpHost` | `string` | derived | `host[:port]` sent in the handshake `host` field. Defaults to the host derived from `wsEndpoint` (`appsync-realtime-api` → `appsync-api`, port preserved). |
| `authEndpoint` | `string` | `/broadcasting/auth` | Where private-channel authorization is POSTed (`{socket_id, channel_name}` → `{auth: token}`). |
| `auth` | `{ headers: Record<string, string> }` | — | Extra headers on the auth request (e.g. `X-CSRF-TOKEN`). |
| `bearerToken` | `string \| () => string \| Promise<string>` | — | `Authorization: Bearer …` on the auth request (Sanctum token auth). |
| `credentials` | `RequestCredentials` | — | `fetch` credentials mode for the auth request (`'include'` for cross-origin cookies). |
| `authorizer` | `(channelName, socketId) => Promise<string>` | — | Custom per-channel authorizer, replacing the `authEndpoint` POST. |
| `publicApiKey` | `string` | — | AppSync API key used to connect and subscribe **public** channels without tokens. |
| `defaultNamespace` | `string` | `'default'` | AppSync namespace for channel names without a dot. Must match the backend's `appsync-events.default_namespace`. |
| `namespace` | `string \| false` | `'App.Events'` | Echo event-namespace prefix; `false` disables Echo's namespace formatting. |
| `debug` | `boolean` | `false` | Log protocol frames to the console. |
| `WebSocket` | constructor | global `WebSocket` | Custom WebSocket implementation (tests, non-browser runtimes). |
| `fetch` | `typeof fetch` | global `fetch` | Custom fetch implementation for the auth request. |
| `reconnect` | `{ baseMs?: number; capMs?: number }` | `{ baseMs: 1000, capMs: 30000 }` | Jittered exponential backoff bounds for reconnects. |

Unknown options pass through to Echo.

## Behavior notes

- `echo.private('orders.1')` and `echo.channel('orders.1')` work as usual;
  `echo.join()` (presence) throws — presence channels aren't supported.
- Channel names map to AppSync paths like the PHP side:
  `private-orders.1` → `/orders/private/1`.
- `disconnect()` stops reconnects; `connect()` re-subscribes every pending
  channel and re-fetches tokens/auth.
- `socketId()` is a stable id reported to the auth endpoint — it is not an
  AppSync-assigned id.

## Documentation

Backend setup, the AWS provisioning guide, channel-path rules and the
security model live in the
[main repository README](https://github.com/hschimpf/laravel-appsync-events#readme)
and [docs/aws-setup.md](https://github.com/hschimpf/laravel-appsync-events/blob/main/docs/aws-setup.md).

## License

MIT — see [LICENSE](LICENSE).
