<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures;

use HDSSolutions\Laravel\AppSyncEvents\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

/**
 * Boots the application with its base path pointed at a fresh temporary
 * directory, so file-writing commands (`vendor:publish`,
 * `appsync-events:secret`, `appsync-events:install`) never touch the
 * Testbench skeleton or the package tree. The base path must be switched
 * in {@see TestCase::defineEnvironment()} — before providers boot — so
 * `config_path()` inside `publishes()` resolves into the temp dir.
 */
abstract class TempBasePathTestCase extends TestCase
{
    /**
     * The temporary base path for the test application.
     */
    protected string $tempDir;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/appsync-events-test-'.bin2hex(random_bytes(8));

        parent::setUp();
    }

    /**
     * {@inheritDoc}
     */
    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    /**
     * {@inheritDoc}
     */
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        (new Filesystem)->ensureDirectoryExists($this->tempDir.'/config');

        $app->setBasePath($this->tempDir);
    }
}
