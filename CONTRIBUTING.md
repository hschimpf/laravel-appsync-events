# Contributing

Thanks for your interest. This is a monorepo: the PHP package lives at the
root, the TypeScript Echo connector in `js/`.

## Development setup

```bash
composer install
npm --prefix js install
```

Requires PHP >= 8.2 and Node >= 22.

## Scripts

| PHP (Composer) | JS (`npm --prefix js run …`) |
| --- | --- |
| `composer test` — Pest: Unit + Feature | `npm test` — Vitest unit tests |
| `composer test:unit` / `test:feature` | `npm run typecheck` — strict `tsc --noEmit` |
| `composer test:emulator` — builds `js/` then runs the emulator suite | `npm run build` — dual ESM/CJS + declarations (tsup) |
| `composer lint` — Pint (check mode) | `npm run test:emulator` — emulator integration tests |
| `composer analyse` — PHPStan | `npm run test:package` — ESM/CJS smoke test |

To auto-fix style: `vendor/bin/pint` (no `--test`).

## Emulator tests

Integration tests run against the AWS emulator; see
[tests/emulator/README.md](tests/emulator/README.md) for details. Quick start:

```bash
tests/emulator/start.sh        # starts the emulator on port 9297
composer test:emulator         # PHP suite
npm --prefix js run test:emulator   # JS suite
tests/emulator/stop.sh
```

- **Port**: default `9297`; override with `EMULATOR_PORT` when starting the
  emulator and running the tests.
- **Discovery**: tests read `tests/emulator/.runtime/<port>/emulator.json`
  (written by `start.sh`), or the five `EMULATOR_*` env vars.
- **Skip vs fail**: without a reachable emulator, emulator tests are skipped.
  Set `EMULATOR_REQUIRED=1` to fail instead (CI does).
- Default `composer test` and `npm test` pass **without** an emulator.

## Style and quality gates

- PHP: Laravel Pint (`composer lint`), PHPStan via Larastan
  (`composer analyse`), PHP 8.2 compatibility.
- TS: strict mode (`npm run typecheck`), documented public API.
- **PHPDoc/TSDoc**: all public classes, methods and helpers carry docblocks
  matching the surrounding style — intent first, `@param`/`@return`/
  `@throws` where they add information.
- Commits follow [Conventional Commits](https://www.conventionalcommits.org)
  (`feat:`, `fix:`, `test:`, `docs:`, …) and are GPG-signed.

## The shared channel-path fixture

PHP (`ChannelPath`) and TS (`toAppSyncChannel`) must map channel names
identically. Both test suites consume
[`tests/fixtures/channel-paths.json`](tests/fixtures/channel-paths.json):
each entry gives a `channel` name plus either `path` (the expected
`/{namespace}/{segment…}` mapping) or `throws: true`, with optional
`publicNamespace`/`privateNamespace` overrides (defaults `public` /
`private`). To add a case, extend the JSON only — the tests pick it up
automatically.

## Pull request checklist

- [ ] `composer lint`, `composer analyse`, `composer test` green.
- [ ] `npm --prefix js run typecheck`, `npm --prefix js test`,
      `npm --prefix js run build` green.
- [ ] Emulator suites green (or justified skip) if the change touches
      protocol, auth or channel-path code.
- [ ] New/changed behavior covered by tests; shared-fixture cases added for
      channel-path changes.
- [ ] PHPDoc/TSDoc on new public API; README/CHANGELOG updated when
      user-facing behavior changes.
- [ ] Conventional Commit messages; no unrelated churn in the diff.

## Releasing

Both packages are released in lockstep as `vX.Y.Z` tags. The Composer
package needs no release job — Packagist publishes tags automatically once
the package is registered; the npm package is published by
`.github/workflows/release.yml` on tag push.

Steps:

1. Bump the npm version (kept in `js/package.json` only):
   `npm --prefix js version X.Y.Z --no-git-tag-version`
2. Move the `CHANGELOG.md` `[Unreleased]` entries under a new
   `## [X.Y.Z] - YYYY-MM-DD` heading.
3. Commit (`chore(release): vX.Y.Z`), create a **signed** tag:
   `git tag -s vX.Y.Z -m "vX.Y.Z"`, push the commit and the tag.
4. The release workflow verifies the tag matches `js/package.json`
   (fails otherwise), runs the full CI workflow, publishes
   `@hds-solutions/laravel-echo-appsync` via npm trusted publishing
   (OIDC — no token is used and provenance is issued automatically),
   and creates the GitHub release with the changelog section as notes.
   If the version already exists on npm the publish job skips it with a
   notice, so re-running a tag is safe.

Rehearse with **Actions → Release → Run workflow** (`dry_run: true`):
it runs verification, the test suite and `npm publish --dry-run` without
publishing or creating a release (no OIDC is needed for a dry run).

Prerequisites to configure once:

- **npm trusted publishing** for `@hds-solutions/laravel-echo-appsync`
  on npmjs.com: GitHub provider, owner `hschimpf`, repository
  `laravel-appsync-events`, workflow file `release.yml`, **no
  environment**. Configure it under the package's *Settings →
  Publishing access*, or from the CLI (npm >= 11.15, 2FA-enabled
  account):

  ```bash
  npm trust github @hds-solutions/laravel-echo-appsync --file release.yml --repo hschimpf/laravel-appsync-events --allow-publish
  ```
- Under *Publishing access*, choose **"Require two-factor
  authentication and disallow tokens"** so that only OIDC publishes
  succeed.
- The package must exist on npm before the first OIDC publish — see the
  bootstrap below. The first trusted publish is additionally subject to
  npm's validation window of up to two days.
- Trusted publishing requires a **public** repository; the workflow
  grants `id-token: write` to the publish job for the OIDC token.
- The Packagist package must be registered with the GitHub webhook/app
  enabled so tags publish to Packagist automatically.

### First publish (one-time bootstrap)

A trusted publisher can only be attached to a package that already
exists on npm, so the very first version is published by hand once from
`js/` with an npm account that owns the `@hds-solutions` scope
(2FA/OTP required):

```bash
npm ci && npm run build
npm publish --access public --provenance=false
```

`--provenance=false` is needed because `js/package.json` sets
`publishConfig.provenance: true`, and provenance can only be issued
inside a supported CI OIDC environment (a local `npm publish --dry-run`
still works — it performs no attestation). Configure the trusted
publisher right after; every release from then on goes through the
workflow.

### Troubleshooting

- `E404` on an OIDC publish — the trusted publisher is not configured
  for the package, or the configured repository/workflow file does not
  match `hschimpf/laravel-appsync-events` + `release.yml`.
- "Cannot publish over a previously published version" — the version in
  `js/package.json` already exists on npm; the workflow detects this
  and skips the publish, but a manual publish still fails.
