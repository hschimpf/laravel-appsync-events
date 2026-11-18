import { existsSync, readFileSync } from 'node:fs';
import { Agent, request as httpsRequest } from 'node:https';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
    AppSyncClient,
    AuthenticationType,
    CreateApiCommand,
    CreateApiKeyCommand,
    CreateChannelNamespaceCommand,
    DeleteApiCommand,
} from '@aws-sdk/client-appsync';
import { NodeHttpHandler } from '@smithy/node-http-handler';
import { it } from 'vitest';
import type { TestContext } from 'vitest';

/**
 * Emulator harness for the JS integration suite — mirrors the PHP side
 * (`tests/Support/Emulator`). The emulator is located with the same
 * precedence: env vars `EMULATOR_ENDPOINT`, `EMULATOR_CA_BUNDLE`,
 * `EMULATOR_ACCESS_KEY_ID`, `EMULATOR_SECRET_ACCESS_KEY`,
 * `EMULATOR_REGION` (all five) > `tests/emulator/.runtime/<port>/emulator.json`
 * written by `tests/emulator/start.sh` (`EMULATOR_PORT`, default 9297).
 */

/** The resolved emulator connection details. */
export interface EmulatorConfig {
    endpoint: string;
    caBundle: string;
    accessKeyId: string;
    secretAccessKey: string;
    region: string;
}

/** A provisioned Event API on the emulator. */
export interface EmulatorEventApi {
    apiId: string;
    httpEndpoint: string;
    realtimeEndpoint: string;
    httpHost: string;
    apiKey: string;
    delete(): Promise<void>;
}

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');

/**
 * Load the emulator configuration, or `null` when none is configured.
 *
 * @returns The resolved configuration.
 */
export function loadEmulatorConfig(): EmulatorConfig | null {
    const env = {
        endpoint: process.env.EMULATOR_ENDPOINT,
        caBundle: process.env.EMULATOR_CA_BUNDLE,
        accessKeyId: process.env.EMULATOR_ACCESS_KEY_ID,
        secretAccessKey: process.env.EMULATOR_SECRET_ACCESS_KEY,
        region: process.env.EMULATOR_REGION,
    };

    if (Object.values(env).every((value) => value !== undefined && value !== '')) {
        return env as EmulatorConfig;
    }

    const port = process.env.EMULATOR_PORT ?? '9297';
    const file = path.join(repoRoot, 'tests/emulator/.runtime', port, 'emulator.json');

    if (!existsSync(file)) {
        return null;
    }

    return JSON.parse(readFileSync(file, 'utf8')) as EmulatorConfig;
}

/** Whether a missing emulator must fail tests (CI sets EMULATOR_REQUIRED=1). */
export function isEmulatorRequired(): boolean {
    return process.env.EMULATOR_REQUIRED === '1' || process.env.EMULATOR_REQUIRED === 'true';
}

/**
 * Probe the control plane's health route.
 *
 * @param config - The resolved configuration.
 * @returns `true` when `/__admin/health` answers within 1.5s.
 */
export async function isEmulatorAvailable(config: EmulatorConfig): Promise<boolean> {
    return new Promise((resolve) => {
        const req = httpsRequest(`${config.endpoint}/__admin/health`, {
            agent: new Agent({ ca: readFileSync(config.caBundle) }),
            timeout: 1500,
        }, (res) => resolve(res.statusCode !== undefined && res.statusCode < 300));
        req.on('error', () => resolve(false));
        req.on('timeout', () => { req.destroy(); resolve(false); });
        req.end();
    });
}

let sharedApi: Promise<EmulatorEventApi | null> | null = null;

/**
 * The single Event API shared by the JS emulator suite — created lazily,
 * `null` when the emulator is not reachable. Mirrors the production auth
 * shape as closely as the emulator accepts (it never invokes Lambda
 * authorizers and does not verify signatures).
 *
 * @returns The provisioned API.
 */
export function emulatorApi(): Promise<EmulatorEventApi | null> {
    sharedApi ??= (async () => {
        const config = loadEmulatorConfig();

        if (config === null || !(await isEmulatorAvailable(config))) {
            return null;
        }

        const client = new AppSyncClient({
            region: config.region,
            endpoint: config.endpoint,
            credentials: { accessKeyId: config.accessKeyId, secretAccessKey: config.secretAccessKey },
            requestHandler: new NodeHttpHandler({
                httpsAgent: new Agent({ ca: readFileSync(config.caBundle) }),
            }),
        });

        const api = await client.send(new CreateApiCommand({
            name: `vitest-${crypto.randomUUID().slice(0, 8)}`,
            eventConfig: {
                authProviders: [
                    {
                        authType: AuthenticationType.AWS_LAMBDA,
                        lambdaAuthorizerConfig: {
                            authorizerUri: 'arn:aws:lambda:us-east-1:123456789012:function:appsync-events-tests',
                            authorizerResultTtlInSeconds: 0,
                        },
                    },
                    { authType: AuthenticationType.AWS_IAM },
                    { authType: AuthenticationType.API_KEY },
                ],
                connectionAuthModes: [
                    { authType: AuthenticationType.AWS_LAMBDA },
                    { authType: AuthenticationType.API_KEY },
                ],
                defaultPublishAuthModes: [
                    { authType: AuthenticationType.AWS_IAM },
                    { authType: AuthenticationType.API_KEY },
                ],
                defaultSubscribeAuthModes: [
                    { authType: AuthenticationType.AWS_LAMBDA },
                    { authType: AuthenticationType.API_KEY },
                ],
            },
        }));

        const dns = (api.api?.dns ?? {}) as Record<string, string>;
        const apiId = api.api?.apiId ?? '';
        const key = await client.send(new CreateApiKeyCommand({ apiId }));

        for (const namespace of ['orders', 'default', 'notify']) {
            await client.send(new CreateChannelNamespaceCommand({ apiId, name: namespace }));
        }

        return {
            apiId,
            httpEndpoint: `http://${dns.HTTP}/event`,
            realtimeEndpoint: `ws://${dns.REALTIME}/event/realtime`,
            httpHost: dns.HTTP,
            apiKey: key.apiKey?.id ?? '',
            delete: async () => {
                try { await client.send(new DeleteApiCommand({ apiId })); } catch { /* ephemeral */ }
            },
        };
    })();

    return sharedApi;
}

/**
 * `it` that skips (or fails under EMULATOR_REQUIRED=1) when no emulator
 * is reachable. The provisioned API is passed to the test body.
 *
 * @param name - The test name.
 * @param fn - The test body; receives the shared API.
 */
export function emulatorIt(
    name: string,
    fn: (api: EmulatorEventApi, ctx: TestContext) => void | Promise<void>,
): void {
    it(name, async (ctx) => {
        const api = await emulatorApi();

        if (api === null) {
            if (isEmulatorRequired()) {
                throw new Error('EMULATOR_REQUIRED=1 but the emulator is unreachable (see tests/emulator/README.md).');
            }

            return ctx.skip('AWS Emulator not running; see tests/emulator/README.md to start it.');
        }

        await fn(api, ctx);
    });
}

/**
 * Publish an event to a channel path via the HTTP endpoint using the
 * `x-api-key` mechanism; the inner event message mirrors what
 * `AppSyncBroadcaster` writes (`{event, channel, data}`).
 *
 * @param api - The API to publish to.
 * @param channelPath - The `/{namespace}/…` channel path.
 * @param event - The event name.
 * @param data - The event payload.
 */
export async function publishEvent(
    api: EmulatorEventApi,
    channelPath: string,
    event: string,
    data: unknown,
): Promise<void> {
    const response = await fetch(api.httpEndpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'x-api-key': api.apiKey },
        body: JSON.stringify({
            channel: channelPath,
            events: [JSON.stringify({ event, channel: channelPath, data })],
        }),
    });

    if (!response.ok) {
        throw new Error(`Publish to ${channelPath} failed with HTTP ${response.status}: ${await response.text()}`);
    }
}

/**
 * Wait until `predicate` returns truthy, polling every 25 ms.
 *
 * @param predicate - The condition to await.
 * @param timeoutMs - The maximum wait.
 * @param what - What is being awaited (for the error message).
 * @returns The first truthy value.
 */
export async function waitFor<T>(
    predicate: () => T,
    timeoutMs = 10_000,
    what = 'condition',
): Promise<T> {
    const deadline = Date.now() + timeoutMs;

    for (;;) {
        const value = predicate();
        if (value) {
            return value;
        }

        if (Date.now() > deadline) {
            throw new Error(`Timed out after ${timeoutMs}ms waiting for ${what}`);
        }

        await new Promise((resolve) => setTimeout(resolve, 25));
    }
}
