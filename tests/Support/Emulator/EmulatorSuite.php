<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests\Support\Emulator;

use RuntimeException;

/**
 * Lazily provisions the single Event API shared by the PHP emulator
 * suite (one API keeps the run inside the emulator's listener port
 * budget; tests isolate themselves with unique channel names and
 * namespaces instead).
 */
final class EmulatorSuite
{
    /**
     * Whether the shared API creation was already attempted.
     */
    private static bool $resolved = false;

    /**
     * The shared API, or `null` when the emulator is unreachable.
     */
    private static ?EmulatorEventApi $api = null;

    /**
     * The signing secret shared by the broadcaster and the authorizer in
     * these tests.
     */
    public const SIGNING_SECRET = 'emulator-test-secret';

    /**
     * The namespaces created on the shared API. `ghost` is deliberately
     * absent — it exists only in the unknown-namespace failure tests.
     *
     * @var array<int, string>
     */
    private const NAMESPACES = ['public', 'private'];

    /**
     * The shared Event API, or `null` when no emulator is reachable.
     *
     * @return EmulatorEventApi|null The provisioned API.
     *
     * @throws RuntimeException When the emulator answers but API
     *                          provisioning fails (a real failure, not
     *                          a reason to skip).
     */
    public static function api(): ?EmulatorEventApi
    {
        if (! self::$resolved) {
            self::$resolved = true;

            $config = EmulatorConfig::load();

            if ($config !== null && $config->isAvailable()) {
                self::$api = EmulatorEventApi::create($config, 'pest-'.bin2hex(random_bytes(4)));

                foreach (self::NAMESPACES as $namespace) {
                    self::$api->createNamespace($namespace);
                }

                register_shutdown_function(static function (): void {
                    self::$api?->delete();
                });
            }
        }

        return self::$api;
    }

    /**
     * The skip/fail reason when no emulator is usable: `fail` under
     * `EMULATOR_REQUIRED=1` (CI), `skip` locally.
     *
     * @param  object  $test  The running test (`$this` in Pest).
     */
    public static function requireApi(object $test): EmulatorEventApi
    {
        $api = self::api();

        if ($api !== null) {
            return $api;
        }

        $message = 'AWS Emulator unreachable. Start it with tests/emulator/start.sh (see tests/emulator/README.md).';

        if (method_exists($test, 'markTestSkipped') && ! EmulatorConfig::isRequired()) {
            $test->markTestSkipped($message);
        }

        throw new RuntimeException("EMULATOR_REQUIRED=1 and {$message}");
    }

    /**
     * Reset the cached API between suite runs (tests only).
     */
    public static function reset(): void
    {
        self::$resolved = false;
        self::$api = null;
    }
}
