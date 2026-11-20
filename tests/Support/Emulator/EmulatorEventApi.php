<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests\Support\Emulator;

use Aws\AppSync\AppSyncClient;
use Aws\Credentials\Credentials;
use Aws\Result;
use RuntimeException;

/**
 * An Event API provisioned on the emulator, plus the control-plane
 * calls used by the tests.
 *
 * The API mirrors the production configuration as closely as the
 * emulator accepts: `AWS_LAMBDA` connection/subscribe authorization
 * (with a syntactically valid but unreachable authorizer URI — the
 * emulator never invokes it), `AWS_IAM` + `API_KEY` publishing, and
 * `API_KEY` available for connecting/subscribing public channels.
 */
final class EmulatorEventApi
{
    /**
     * @param  AppSyncClient  $client  The emulator control-plane client.
     * @param  string  $apiId  The created API id.
     * @param  string  $httpEndpoint  The `http://…/event` publish endpoint.
     * @param  string  $realtimeEndpoint  The `ws://…/event/realtime` endpoint.
     * @param  string  $httpHost  The `host[:port]` of the HTTP endpoint.
     * @param  string  $apiKey  An API key (`da2-…`) for `x-api-key` auth.
     */
    public function __construct(
        private readonly AppSyncClient $client,
        public readonly string $apiId,
        public readonly string $httpEndpoint,
        public readonly string $realtimeEndpoint,
        public readonly string $httpHost,
        public readonly string $apiKey,
    ) {}

    /**
     * Create a new Event API (and API key) on the emulator.
     *
     * @param  EmulatorConfig  $config  The emulator connection details.
     * @param  string  $name  A unique API name.
     * @return self The created API.
     *
     * @throws RuntimeException When the emulator rejects the request.
     */
    public static function create(EmulatorConfig $config, string $name): self
    {
        $client = new AppSyncClient([
            'version' => 'latest',
            'region' => $config->region,
            'endpoint' => $config->endpoint,
            'credentials' => new Credentials($config->accessKeyId, $config->secretAccessKey),
            'http' => ['verify' => $config->caBundle],
        ]);

        try {
            $api = $client->createApi([
                'name' => $name,
                'eventConfig' => [
                    'authProviders' => [
                        [
                            'authType' => 'AWS_LAMBDA',
                            'lambdaAuthorizerConfig' => [
                                'authorizerUri' => 'arn:aws:lambda:us-east-1:123456789012:function:appsync-events-tests',
                                'authorizerResultTtlInSeconds' => 0,
                            ],
                        ],
                        ['authType' => 'AWS_IAM'],
                        ['authType' => 'API_KEY'],
                    ],
                    'connectionAuthModes' => [
                        ['authType' => 'AWS_LAMBDA'],
                        ['authType' => 'API_KEY'],
                    ],
                    'defaultPublishAuthModes' => [
                        ['authType' => 'AWS_IAM'],
                        ['authType' => 'API_KEY'],
                    ],
                    'defaultSubscribeAuthModes' => [
                        ['authType' => 'AWS_LAMBDA'],
                        ['authType' => 'API_KEY'],
                    ],
                ],
            ]);
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                "CreateApi failed against the emulator: {$exception->getMessage()}",
                0,
                $exception,
            );
        }

        $dns = (array) $api->get('api')['dns'];
        $http = (string) ($dns['HTTP'] ?? '');
        $realtime = (string) ($dns['REALTIME'] ?? '');

        if ($http === '' || $realtime === '') {
            throw new RuntimeException('CreateApi returned no HTTP/REALTIME dns entries.');
        }

        $key = $client->createApiKey(['apiId' => (string) $api->get('api')['apiId']]);

        return new self(
            client: $client,
            apiId: (string) $api->get('api')['apiId'],
            httpEndpoint: "http://{$http}/event",
            realtimeEndpoint: "ws://{$realtime}/event/realtime",
            httpHost: $http,
            apiKey: (string) $key->get('apiKey')['id'],
        );
    }

    /**
     * Create a channel namespace on this API.
     *
     * @param  string  $name  The namespace name (e.g. `public`, `private`).
     * @param  array<int, array{authType: string}>|null  $publishAuthModes  Optional
     *                                                                      publish auth mode override.
     * @param  array<int, array{authType: string}>|null  $subscribeAuthModes  Optional
     *                                                                        subscribe auth mode override.
     * @return Result The CreateChannelNamespace result.
     */
    public function createNamespace(string $name, ?array $publishAuthModes = null, ?array $subscribeAuthModes = null): Result
    {
        $request = ['apiId' => $this->apiId, 'name' => $name];

        if ($publishAuthModes !== null) {
            $request['publishAuthModes'] = $publishAuthModes;
        }

        if ($subscribeAuthModes !== null) {
            $request['subscribeAuthModes'] = $subscribeAuthModes;
        }

        return $this->client->createChannelNamespace($request);
    }

    /**
     * Delete this API (best effort — frees its data-plane ports).
     */
    public function delete(): void
    {
        try {
            $this->client->deleteApi(['apiId' => $this->apiId]);
        } catch (\Throwable) {
            // The container is ephemeral; a failed delete is harmless.
        }
    }
}
