# AppSync Events emulator test infra

Runs [AWS Emulator](https://github.com/aws/aws-emulator) in Docker so the
package's AppSync Events code paths can be exercised without an AWS account.

## Usage

```bash
tests/emulator/start.sh   # builds the image if needed, starts the container,
                          # waits on /__admin/health, prints the env vars to export
tests/emulator/stop.sh    # stops the container
```

Environment overrides:

- `EMULATOR_PORT` — control-plane port (default `9297`). Must match on both
  sides of `docker -p` and the emulator's own `-p`.
- `EMULATOR_LISTENER_PORT_RANGE` — port range for the per-API data-plane
  sockets (default `9400-9419`).

Generated credentials and the CA certificate land in
`tests/emulator/.runtime/<port>/` (git-ignored). The container is ephemeral
(`--rm`, `--idle-timeout 3600`); all state is in memory and lost on stop.

## Emulator behaviour notes

Findings from a manual spike against the emulator (LTS package,
Nov 2026). They describe the emulator, not real AWS — real AppSync may be
stricter.

### Control plane (`https://127.0.0.1:9297`)

- `CreateApi` with an `eventConfig` (authProviders `API_KEY` + `AWS_IAM`,
  connectionAuthModes `API_KEY`, defaultPublishAuthModes `AWS_IAM` +
  `API_KEY`, defaultSubscribeAuthModes `API_KEY`) succeeds and returns
  `api.dns.HTTP` / `api.dns.REALTIME` as `127.0.0.1:<port>` — one port per
  endpoint, allocated from `EMULATOR_LISTENER_PORT_RANGE` (OS-ephemeral
  otherwise).
- `CreateChannelNamespace` (`default`, `private`) and `CreateApiKey` work
  as documented; the returned `apiKey.id` (`da2-…`) is the value clients
  send as `x-api-key`.
- The control plane verifies SigV4 strictly: an unsigned request is
  refused with `404 UnknownService`; requests must be signed with the
  per-run credentials from `.runtime/<port>/emulator_credentials`.

### HTTP publish endpoint (`<dns.HTTP>/event`)

- `POST http://<dns.HTTP>/event` with `x-api-key: <key>` and body
  `{"channel":"/default/test","events":["{\"hello\":1}"]}` →
  `200 {"failed":[],"successful":[{"identifier":"…","index":0}]}`.
- A SigV4-signed request (service `appsync`, region `us-east-1`, emulator
  credentials) → `200`. **The signature is not verified**: an
  `Authorization: AWS4-HMAC-SHA256 Credential=FAKE/…` header with a bogus
  signature also returns `200`. The data plane only checks *which* auth
  mechanism was presented.
- No auth header at all → `401 UnauthorizedException`; a wrong
  `x-api-key` → `401`.
- Unknown channel namespace (`/nonexistent/test`) → `404 NotFoundException
  "Channel namespace not found."`; a channel without the
  `/namespace/…` prefix (e.g. plain `test`) → same `404`. The
  `/<namespace>/<segment…>` path form is required.
- The same socket serves HTTPS: `https://<dns.HTTP>/event` with the
  run's `ca.pem` works too (the listener sniffs TLS vs plaintext on the
  first byte). `ws://`/`wss://` on the realtime port likewise.

### Realtime endpoint (`<dns.REALTIME>/event/realtime`)

- WebSocket subprotocols must be `aws-appsync-event-ws` **and**
  `header-<base64url(JSON)>`, e.g.
  `header-` + base64url(`{"host":"<dns.HTTP>","x-api-key":"<key>"}`).
  The negotiated subprotocol comes back as `aws-appsync-event-ws`.
- An invalid `x-api-key` in the `header-` payload → HTTP `401` at the
  upgrade (no socket). The API key *is* validated here.
- The `host` value in the `header-` JSON is **not** validated — a bogus
  host still connects.
- `connection_init` → `{"type":"connection_ack","connectionTimeoutMs":300000}`.
- `subscribe` → `{"type":"subscribe_success","id":"…"}`. Both a wrong
  `host` inside `authorization` and a *missing* `authorization` object
  still succeed — subscribe authorization is lenient.
- Subscribing to `/default`, `/private/test`, `/default/a/b/c`
  (multi-segment) all succeed.
- Publishing via the HTTP endpoint then delivers
  `{"type":"data","id":"<sub-id>","event":"<the event string>"}` on the
  socket.

### Port allocation (Docker)

- Data-plane ports are chosen by the emulator core, not the service;
  `EMULATOR_LISTENER_PORT_RANGE=LOW-HIGH` (env, or single `N`) confines
  them so `docker run -p 127.0.0.1:LOW-HIGH:LOW-HIGH` publishes them
  deterministically. `--network host` is therefore unnecessary on Linux.
- With `-b 0.0.0.0` inside the container, `dns.HTTP`/`dns.REALTIME` are
  still reported as `127.0.0.1:<port>` (a wildcard authority is published
  as loopback) and the Host-header guard accepts any Host, so
  `http://127.0.0.1:<published-port>` from the host reaches the right
  socket.
