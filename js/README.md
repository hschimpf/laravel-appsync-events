# @hds-solutions/laravel-echo-appsync

[Laravel Echo](https://github.com/laravel/echo) connector for **AWS AppSync
Events** — the browser-side companion of the
[`hds-solutions/laravel-appsync-events`](https://github.com/hschimpf/laravel-appsync-events)
Composer package.

```ts
import Echo from 'laravel-echo';
import { appSyncEchoOptions } from '@hds-solutions/laravel-echo-appsync';

const echo = new Echo(appSyncEchoOptions({
    wsEndpoint: 'wss://xxxx.appsync-realtime-api.eu-west-1.amazonaws.com/event/realtime',
    connectToken: () => fetchConnectToken(), // re-evaluated on every (re)connect
}));

echo.private('orders.1').listen('.Shipped', (payload) => { /* ... */ });
```

Full documentation — backend setup, options, channel naming and protocol
notes — lives in the
[main repository README](https://github.com/hschimpf/laravel-appsync-events#readme).
