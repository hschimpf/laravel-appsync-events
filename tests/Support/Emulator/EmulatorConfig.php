<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests\Support\Emulator;

use GuzzleHttp\Client;
use Throwable;

/**
 * Locates the running AWS Emulator and its generated credentials.
 *
 * Configuration is resolved with this precedence:
 *  1. The environment variables `EMULATOR_ENDPOINT`, `EMULATOR_CA_BUNDLE`,
 *     `EMULATOR_ACCESS_KEY_ID`, `EMULATOR_SECRET_ACCESS_KEY` and
 *     `EMULATOR_REGION` (all five must be set).
 *  2. `tests/emulator/.runtime/<port>/emulator.json`, written by
 *     `tests/emulator/start.sh`, where `<port>` is `EMULATOR_PORT`
 *     (default `9297`).
 */
final class EmulatorConfig
{
    /**
     * @param  string  $endpoint  The control-plane HTTPS endpoint.
     * @param  string  $caBundle  Absolute path to the emulator CA certificate.
     * @param  string  $accessKeyId  The generated access key id.
     * @param  string  $secretAccessKey  The generated secret access key.
     * @param  string  $region  The signing region (always `us-east-1`).
     */
    public function __construct(
        public readonly string $endpoint,
        public readonly string $caBundle,
        public readonly string $accessKeyId,
        public readonly string $secretAccessKey,
        public readonly string $region,
    ) {}

    /**
     * Load the emulator configuration, or `null` when none is configured.
     *
     * @return self|null The resolved configuration.
     */
    public static function load(): ?self
    {
        $env = static fn (string $key): ?string => getenv($key) !== false
            ? getenv($key)
            : ($_SERVER[$key] ?? $_ENV[$key] ?? null);

        $endpoint = $env('EMULATOR_ENDPOINT');
        $caBundle = $env('EMULATOR_CA_BUNDLE');
        $accessKeyId = $env('EMULATOR_ACCESS_KEY_ID');
        $secretAccessKey = $env('EMULATOR_SECRET_ACCESS_KEY');
        $region = $env('EMULATOR_REGION');

        if ($endpoint !== null && $caBundle !== null && $accessKeyId !== null
            && $secretAccessKey !== null && $region !== null) {
            return new self($endpoint, $caBundle, $accessKeyId, $secretAccessKey, $region);
        }

        $port = $env('EMULATOR_PORT') ?? '9297';
        $file = dirname(__DIR__, 3)."/tests/emulator/.runtime/{$port}/emulator.json";

        if (! is_file($file)) {
            return null;
        }

        $config = json_decode((string) file_get_contents($file), true);

        return is_array($config) ? new self(
            (string) ($config['endpoint'] ?? ''),
            (string) ($config['caBundle'] ?? ''),
            (string) ($config['accessKeyId'] ?? ''),
            (string) ($config['secretAccessKey'] ?? ''),
            (string) ($config['region'] ?? 'us-east-1'),
        ) : null;
    }

    /**
     * Whether the configured control plane answers its health route.
     *
     * @return bool `true` when `/__admin/health` responds within a second.
     */
    public function isAvailable(): bool
    {
        try {
            $response = (new Client([
                'verify' => $this->caBundle,
                'timeout' => 1.0,
                'http_errors' => false,
            ]))->get("{$this->endpoint}/__admin/health");

            return $response->getStatusCode() < 300;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether emulator tests are mandatory (`EMULATOR_REQUIRED=1`, set in CI).
     *
     * @return bool `true` when missing config must fail rather than skip.
     */
    public static function isRequired(): bool
    {
        $value = getenv('EMULATOR_REQUIRED') !== false
            ? getenv('EMULATOR_REQUIRED')
            : ($_SERVER['EMULATOR_REQUIRED'] ?? $_ENV['EMULATOR_REQUIRED'] ?? '');

        return $value === '1' || $value === 'true';
    }
}
