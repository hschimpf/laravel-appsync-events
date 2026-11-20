# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.1] - 2026-11-19

### Fixed

- **Re-release of 0.2.0** — the Packagist `v0.2.0` was published from a commit
  that predates the `js/package.json` version bump, and the tag was then moved
  to the corrected commit (which Packagist refuses to follow). `0.2.1` brings
  Packagist, npm and GitHub back in line. No PHP or JS code changes since
  `0.2.0`; the only difference is the version in `js/package.json`.

## [0.2.0] - 2026-11-19

This release reworks how Laravel channel names map to AppSync channel paths
and contains **several breaking changes** (see _Changed_ and the migration
steps below). Review them before upgrading — the PHP package and the
`@hds-solutions/laravel-echo-appsync` connector must be upgraded together,
since both sides must agree on the channel path.

### Added

- **`->toOthers()` sender exclusion** — the broadcaster lifts the payload's
  `socket` (the sender's socket id set by `InteractsWithSockets`) into a
  top-level `socket` field of the published event envelope instead of
  leaking it into `data`, and the Echo connector drops events whose
  envelope socket matches its own `socketId()`. AppSync has no
  server-side sender exclusion, so this is client-side filtering.
- **Signing-secret rotation** — `previous_signing_secrets`
  (`APPSYNC_CHANNEL_PREVIOUS_SIGNING_SECRETS`, comma-separated) lists
  outgoing secrets that `TokenSigner`/`AppSyncAuthorizer` still accept
  for verification; signing always uses the current `signing_secret`.
  Deploy the authorizer first, rotate the app secret, then drop the
  previous list after the longest token TTL for zero-downtime rotation.
- **Configurable channel namespaces** — new `namespaces.public` /
  `namespaces.private` config keys (`APPSYNC_PUBLIC_NAMESPACE` /
  `APPSYNC_PRIVATE_NAMESPACE`, both default to `public` / `private`), and
  matching `publicNamespace` / `privateNamespace` connector options, now
  returned by `AppSyncEvents::clientConfig()`.
- **Authorization types and platform notes in the README** — a table
  comparing AppSync auth types against what the package supports, plus notes
  on how Vapor, Docker and ECS apps obtain publish credentials and where the
  Lambda authorizer must be deployed (derived from platform docs, not tested
  on real Vapor/ECS deployments).
- **`bref/bref` suggestion** — added to `composer.json` `suggest` for running
  the Lambda authorizer.
- **README banner image.**

### Changed

- **Subscribe tokens must carry a `channel` claim** — `AppSyncAuthorizer`
  now denies `EVENT_SUBSCRIBE` unless the token has a non-empty string
  `channel` claim equal to the requested channel. Tokens issued by this
  package always include it.
- **`presence-` channel names are rejected** — `ChannelPath` /
  `toAppSyncChannel` now throw `InvalidArgumentException` /
  `InvalidChannelNameError` instead of mapping them to a `presence`
  path; presence channels are out of scope for the package.
- **Dots in channel names now map to path separators (BREAKING)** —
  `private-orders.1.items` publishes to `/private/orders/1/items`
  instead of `/private/orders/1-items`, fixing a collision where
  `orders.1.items` and `orders.1-items` mapped to the same path while
  subscribe tokens are scoped per path. Segments also follow AppSync
  limits now: max 5 path segments, each 1–50 chars `[A-Za-z0-9-]` —
  underscores and over-long/too-many segments are rejected.
  Subscribers or handlers hard-coding dash-mapped paths must be
  updated.
- **Channel paths are now `/{namespace}/{name…}` — type-as-namespace
  (BREAKING)** — `private-` channels resolve under the private namespace
  and everything else under the public namespace; the rest of the name
  splits on dots into path segments (`private-orders.1` →
  `/private/orders/1`, `orders` → `/public/orders`). The `{domain}/{type}`
  mapping, domain-as-namespace and the `default_namespace` fallback are
  gone, `private-encrypted-` names are now rejected (payload encryption
  is not implemented), and a name may use at most 4 dot-segments. To
  migrate:
    1. Recreate the Event API's channel namespaces as `public` and
       `private` — namespaces are the AppSync authorization boundary, so
       set auth modes per namespace (e.g. `API_KEY` + Lambda for
       `public`, the Lambda authorizer for `private`) — and drop the old
       per-prefix namespaces (`orders`, `default`, …).
    2. Replace `default_namespace`/`APPSYNC_DEFAULT_NAMESPACE` with
       `namespaces.public`/`namespaces.private`
       (`APPSYNC_PUBLIC_NAMESPACE`/`APPSYNC_PRIVATE_NAMESPACE`).
    3. Replace the connector's `defaultNamespace` option with
       `publicNamespace`/`privateNamespace` (`clientConfig()` now ships
       both keys).
    4. Re-scope `appsync:EventPublish` IAM statements to the two
       `channelNamespace/{public,private}` ARNs — per-domain namespace
       isolation is gone.
    5. Update any subscriber or handler that hard-codes an old channel
       path, and redeploy the Lambda authorizer and the Echo connector
       together with the app.
- **JS connector only authorizes `private-` channels** — `presence-` handling
  was dropped from `AppSyncConnector`, and `presenceChannel()` no longer
  claims presence support is coming.
- **Infrastructure templates and docs updated** — the CDK, CloudFormation,
  Serverless and Terraform examples, `docs/aws-setup.md`, `README.md`,
  `js/README.md` and `CONTRIBUTING.md` now describe the `public` / `private`
  namespaces, signing-secret rotation and the new channel mapping.
- The README "Roadmap" section was removed.

### Fixed

- **Subscribe token without a `channel` claim authorized any channel-less
  request** (security) — a token lacking a `channel` claim matched a request
  that also carried no channel (`null === null`); `EVENT_SUBSCRIBE` now
  requires a non-empty string claim equal to the requested channel.
- **Channel-path collisions** — mapping dots to path separators (see
  _Changed_) removes the collision between `orders.1.items` and
  `orders.1-items`, which previously let one channel's subscribe token
  authorize the other.

### Development

- Shared PHP/TypeScript channel-path fixture, unit tests and emulator
  integration tests rewritten for the type-as-namespace mapping and
  provisioning of the `public` / `private` namespaces.
- Bumped `@aws-sdk/client-appsync` dev dependency to 3.1169.0.

## [0.1.2] - 2026-11-18

### Fixed

- **JS connector authorization request never fired in browsers** — the
  default `globalThis.fetch` was invoked with the options object as its
  receiver, and a browser's `fetch` throws `TypeError: Illegal invocation`
  for any other receiver, so the `/broadcasting/auth` POST was never sent
  and private channels never subscribed. The default fetch is now a lazy
  wrapper, and `fetch`/custom `authorizer` callables are invoked detached.

## [0.1.1] - 2026-11-18

### Changed

- **GitHub Actions updated to current majors** — `actions/checkout` v7,
  `actions/setup-node` v7, `actions/cache` v6, `actions/upload-artifact` v7;
  `shivammathur/setup-php` stays on the floating `v2` line.

### Removed

- The "Migrating from `georgeboot/laravel-echo-api-gateway`" README section —
  it documented one consuming app's migration, not the library itself.

### Fixed

- README TypeScript snippets now annotate callback parameters so they compile
  under `noImplicitAny` (`(e: unknown)`).

## [0.1.0] - 2026-11-18

### Added

- **`appsync` broadcasting driver** — publishes `ShouldBroadcast` events to an
  AppSync Event API over SigV4-signed HTTP `POST /event`, with per-connection
  overrides, configurable timeouts and region resolution from the endpoint.
- **Lambda authorizer** (`AppSyncAuthorizer`) — verifies `EVENT_CONNECT` and
  `EVENT_SUBSCRIBE` tokens, denies `EVENT_PUBLISH` unconditionally, and returns
  `ttlOverride`/`handlerContext` per the AppSync authorizer contract.
- **HMAC token signer** (`TokenSigner`) — connect and subscribe tokens in
  `base64url(claims).hex-hmac` form with `purpose`, `uid`, `exp` and `channel`
  claims.
- **Channel-path mapping** (`ChannelPath` / `toAppSyncChannel`) — identical
  PHP and TypeScript implementations mapping Laravel channel names to
  `/{namespace}/{type}/{segment}`, verified against a shared JSON fixture.
- **Service provider + auto-registration** — config merge, `appsync` driver
  registration, `/broadcasting/auth` route (never shadows an app-defined
  route) and opt-in client-config route.
- **Console commands** — `appsync-events:install` (config publish + `.env`
  placeholders) and `appsync-events:secret` (generates the signing secret).
- **Client config helper** — `AppSyncEvents::clientConfig()` /
  `AppSyncEventsManager` facade returning connector options including a minted
  connect token; optional `GET broadcasting/appsync` route for decoupled SPAs.
- **Echo connector** (`@hds-solutions/laravel-echo-appsync`) —
  `AppSyncConnector`/`AppSyncChannel` implementing the AppSync Events
  WebSocket protocol (subprotocol handshake, `connection_init`/`ka`
  keep-alives, subscribe/data frames), Echo channel auth via
  `/broadcasting/auth`, reconnect with jittered exponential backoff,
  `publicApiKey` public channels, injectable `WebSocket`/`fetch`, and the
  `appSyncEchoOptions()` helper — dual ESM/CJS builds with type declarations.
- **Emulator test suite** — PHP + JS integration tests against the AWS
  emulator (`tests/emulator/`), covering control-plane provisioning and
  end-to-end broadcast delivery through the real connector.
- **CI** — PHP 8.2–8.5 tests, lint and static analysis; Node 22/24 JS
  typecheck, build and tests.

[Unreleased]: https://github.com/hschimpf/laravel-appsync-events/compare/v0.2.1...HEAD
[0.2.1]: https://github.com/hschimpf/laravel-appsync-events/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/hschimpf/laravel-appsync-events/compare/v0.1.2...v0.2.0
[0.1.2]: https://github.com/hschimpf/laravel-appsync-events/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/hschimpf/laravel-appsync-events/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/hschimpf/laravel-appsync-events/releases/tag/v0.1.0
