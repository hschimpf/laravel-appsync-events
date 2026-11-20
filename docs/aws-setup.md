# AWS setup

This guide walks through everything that must exist in AWS before the
`appsync` broadcast driver works end to end. Infrastructure-as-code examples
live in [`infrastructure/`](infrastructure/): CloudFormation, a Serverless
Framework fragment, Terraform and CDK.

## Overview

| Component | Responsibility |
| --- | --- |
| AppSync **Event API** | Managed WebSocket endpoint; routes published events to subscribers. |
| **Channel namespaces** | The authorization boundary inside the API — `public` and `private` (configurable). |
| **Lambda authorizer** | Verifies the HMAC connect/subscribe tokens the Laravel app issues. |
| **IAM role + policy** | Lets your Laravel servers `appsync:EventPublish` to the namespaces. |

## 1. Create the Event API

Create an [AppSync Event API](https://docs.aws.amazon.com/appsync/latest/eventapi/event-api-concepts.html)
with this auth configuration:

| Setting | Value |
| --- | --- |
| Auth providers | `AWS_LAMBDA`, `AWS_IAM` (+ `API_KEY` only if you need public channels) |
| Connection auth mode | `AWS_LAMBDA` (+ `API_KEY` for public channels) |
| Default publish auth mode | `AWS_IAM` |
| Default subscribe auth mode | `AWS_LAMBDA` (+ `API_KEY` for public channels) |

- `AWS_IAM` publishing is what the broadcaster uses: it SigV4-signs the
  `POST /event` request with your app's IAM credentials.
- `AWS_LAMBDA` connection/subscribe modes delegate connect and subscribe
  authorization to your authorizer function, which verifies the HMAC tokens
  issued by the Laravel application.
- `API_KEY` is only needed for **public** channels read by anonymous
  clients (the JS connector's `publicApiKey` option). Omit it otherwise —
  private channels never use it.

## 2. Create channel namespaces

Channel names map to `/{namespace}/{segment…}` paths where the namespace is
the AppSync authorization boundary: `private-` channels resolve under the
private namespace, everything else under the public namespace. Create two
[Channel Namespaces](https://docs.aws.amazon.com/appsync/latest/eventapi/event-api-concepts.html#channel-namespaces)
matching your `appsync-events.namespaces` config (defaults `public` and
`private`), and give each its own auth modes:

- `public` — publish `AWS_IAM`; subscribe `AWS_LAMBDA` (and `API_KEY` when
  anonymous public readers are allowed).
- `private` — publish `AWS_IAM`; subscribe `AWS_LAMBDA` (your authorizer —
  never `API_KEY`).

If a client subscribes to a channel whose namespace does not exist, AppSync
rejects the subscription with a `subscribe_error` and the broadcaster's
publish fails with a 404 `BroadcastException` naming the missing path.

## 3. Deploy the Lambda authorizer

Deploy a Lambda function that verifies the connect/subscribe tokens. The
package ships the verifier: `AppSyncAuthorizer::authorize(array $event): array`
in `HDSSolutions\Laravel\AppSyncEvents\Lambda`. Full copy-paste handlers
(plain PHP and a Bref example) are in the
[README](../README.md#the-lambda-authorizer).

The function needs one secret, plus an optional list of previous secrets
during rotation:

```dotenv
APPSYNC_CHANNEL_SIGNING_SECRET=<the same secret as the Laravel app>
# Optional — comma-separated secrets still accepted for verification:
# APPSYNC_CHANNEL_PREVIOUS_SIGNING_SECRETS=<old secret>
```

> **Do not let the authorizer broadcast.** If the function boots a Laravel
> container (e.g. under Bref), set `BROADCAST_CONNECTION=log` and leave the
> AppSync endpoint env vars empty. An authorizer that broadcasts on the same
> API would invoke itself recursively.

Grant AppSync permission to invoke it, scoped to the Event API:

```json
{
    "Effect": "Allow",
    "Principal": { "Service": "appsync.amazonaws.com" },
    "Action": "lambda:InvokeFunction",
    "Resource": "arn:aws:lambda:<region>:<account>:function:<authorizer-name>",
    "Condition": {
        "ArnLike": {
            "AWS:SourceArn": "arn:aws:appsync:<region>:<account>:apis/<api-id>"
        }
    }
}
```

## 4. Publish permissions for the Laravel app

The IAM identity your servers use needs `appsync:EventPublish` on every
channel namespace (both `public` and `private` with the defaults):

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": "appsync:EventPublish",
            "Resource": [
                "arn:aws:appsync:<region>:<account>:apis/<api-id>/channelNamespace/public",
                "arn:aws:appsync:<region>:<account>:apis/<api-id>/channelNamespace/private"
            ]
        }
    ]
}
```

Because IAM scopes at the channel-namespace level, the two namespaces are
also the publish-isolation boundary — per-domain IAM isolation (a policy
limited to `orders`, for example) is no longer expressible.

## 5. Wire up the environment

Take the endpoints from the API's `Dns` attribute:

| Env var | Value |
| --- | --- |
| `APPSYNC_HTTP_ENDPOINT` | `https://<id>.appsync-api.<region>.amazonaws.com/event` |
| `APPSYNC_REALTIME_ENDPOINT` | `wss://<id>.appsync-realtime-api.<region>.amazonaws.com/event/realtime` |
| `APPSYNC_CHANNEL_SIGNING_SECRET` | 64 hex chars, generated by `php artisan appsync-events:secret` |
| `APPSYNC_CHANNEL_PREVIOUS_SIGNING_SECRETS` | Optional — comma-separated previous secrets still accepted for verification during rotation |
| `APPSYNC_REGION` | e.g. `us-east-1` (falls back to `AWS_DEFAULT_REGION`) |
| `BROADCAST_CONNECTION` | `appsync` |

The signing secret must be identical in the Laravel app **and** the
authorizer Lambda — it is never sent to AWS or to clients.

### Rotating the signing secret

`APPSYNC_CHANNEL_PREVIOUS_SIGNING_SECRETS` lets the authorizer verify
tokens signed with an outgoing secret while the app already signs with
the new one — deploy the authorizer first, then the app, then remove the
previous secret once the longest token TTL has passed. The full
procedure is in the [README](../README.md#security-model) under "Secret
hygiene".

## Infrastructure examples

- [`infrastructure/cloudformation.yml`](infrastructure/cloudformation.yml) —
  self-contained template: Event API, namespaces, authorizer invoke
  permission, publish managed policy.
- [`infrastructure/serverless.yml`](infrastructure/serverless.yml) —
  Serverless Framework fragment including the authorizer function.
- [`infrastructure/terraform/main.tf`](infrastructure/terraform/main.tf) —
  `aws_appsync_api` + `aws_appsync_channel_namespace` (AWS provider >= 6.9.0).
- [`infrastructure/cdk/event-api-stack.ts`](infrastructure/cdk/event-api-stack.ts) —
  CDK v2 L1 constructs.

Each file states in its header what was validated and what was not.
