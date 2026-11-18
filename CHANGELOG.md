# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/hschimpf/laravel-appsync-events/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/hschimpf/laravel-appsync-events/releases/tag/v0.1.0
