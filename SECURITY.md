# Security Policy

## Supported versions

| Version | Supported |
| --- | --- |
| `0.x` (latest minor) | :white_check_mark: |
| older `0.x` minors | :x: |

While the package is pre-1.0, only the latest `0.x` release receives fixes.

## Reporting a vulnerability

**Do not open a public issue for security vulnerabilities.**

Please report privately through
[GitHub private vulnerability reporting](https://github.com/hschimpf/laravel-appsync-events/security/advisories/new)
on this repository, or by email to **hschimpf@hds-solutions.net**.

Include, when possible: affected versions, a description of the issue, and
reproduction steps or a proof of concept. You can expect an acknowledgment
within a few days; we'll coordinate a fix and disclosure timeline with you.

## Notes on scope

- The channel signing secret (`APPSYNC_CHANNEL_SIGNING_SECRET`) is a shared
  HMAC secret between your application and the Lambda authorizer — keep it
  out of version control and rotate it on any suspected exposure.
- The AWS emulator used by the test suite is **not** a security-testing
  tool: it does not verify SigV4 signatures, invoke Lambda authorizers, or
  validate token contents. Do not draw security conclusions from emulator
  behavior.
