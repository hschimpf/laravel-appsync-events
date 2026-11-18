<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests\Feature;

use HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures\TempBasePathTestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

/**
 * A PHPUnit test because the temp base path must be installed before the
 * application boots — see {@see TempBasePathTestCase}.
 */
final class InstallCommandTest extends TempBasePathTestCase
{
    #[Test]
    public function it_publishes_the_config_file(): void
    {
        file_put_contents($this->tempDir.'/.env', '');

        $this->assertSame(0, Artisan::call('appsync-events:install', ['--no-interaction' => true]));
        $this->assertFileExists($this->tempDir.'/config/appsync-events.php');
    }

    #[Test]
    public function it_appends_the_placeholders_to_env_example_only_once(): void
    {
        file_put_contents($this->tempDir.'/.env', '');
        file_put_contents($this->tempDir.'/.env.example', "APP_NAME=Testing\n");

        Artisan::call('appsync-events:install', ['--no-interaction' => true]);
        Artisan::call('appsync-events:install', ['--no-interaction' => true]);

        $example = (string) file_get_contents($this->tempDir.'/.env.example');

        $this->assertSame(1, substr_count($example, 'APPSYNC_HTTP_ENDPOINT='));
        $this->assertSame(1, substr_count($example, 'APPSYNC_REALTIME_ENDPOINT='));
        $this->assertSame(1, substr_count($example, 'APPSYNC_CHANNEL_SIGNING_SECRET='));
    }

    #[Test]
    public function it_generates_a_secret_non_interactively_when_none_is_set(): void
    {
        file_put_contents($this->tempDir.'/.env', '');

        $this->assertSame(0, Artisan::call('appsync-events:install', ['--no-interaction' => true]));
        $this->assertMatchesRegularExpression(
            '/^APPSYNC_CHANNEL_SIGNING_SECRET=[0-9a-f]{64}$/m',
            (string) file_get_contents($this->tempDir.'/.env'),
        );
    }

    #[Test]
    public function it_skips_secret_generation_when_one_already_exists_in_env(): void
    {
        file_put_contents($this->tempDir.'/.env', "APPSYNC_CHANNEL_SIGNING_SECRET=already-there\n");

        $this->assertSame(0, Artisan::call('appsync-events:install', ['--no-interaction' => true]));
        $this->assertStringContainsString(
            'APPSYNC_CHANNEL_SIGNING_SECRET=already-there',
            (string) file_get_contents($this->tempDir.'/.env'),
        );
    }

    #[Test]
    public function it_skips_secret_generation_when_the_secret_is_configured(): void
    {
        config()->set('appsync-events.signing_secret', 'configured-secret');
        file_put_contents($this->tempDir.'/.env', '');

        $this->assertSame(0, Artisan::call('appsync-events:install', ['--no-interaction' => true]));
        $this->assertStringNotContainsString(
            'APPSYNC_CHANNEL_SIGNING_SECRET',
            (string) file_get_contents($this->tempDir.'/.env'),
        );
    }
}
