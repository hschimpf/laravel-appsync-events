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
`valid` entries map `input → expected` per `defaultNamespace`; `invalid`
entries must throw in both implementations. To add a case, extend the JSON
only — the tests pick it up automatically.

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
