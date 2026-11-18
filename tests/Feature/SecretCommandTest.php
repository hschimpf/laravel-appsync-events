<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/appsync-events-test-'.bin2hex(random_bytes(8));
    (new Filesystem)->ensureDirectoryExists($this->tempDir);

    $this->app->useEnvironmentPath($this->tempDir);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->tempDir);
});

it('prints a 64-character hexadecimal secret with --show and writes nothing', function (): void {
    expect(Artisan::call('appsync-events:secret', ['--show' => true]))->toBe(0)
        ->and(Artisan::output())->toMatch('/^[0-9a-f]{64}\s*$/')
        ->and(file_exists($this->tempDir.'/.env'))->toBeFalse();
});

it('appends the secret line to an .env file without one', function (): void {
    file_put_contents($this->tempDir.'/.env', "APP_NAME=Testing\n");

    expect(Artisan::call('appsync-events:secret'))->toBe(0);

    $contents = file_get_contents($this->tempDir.'/.env');

    expect($contents)->toContain('APP_NAME=Testing')
        ->toMatch('/^APPSYNC_CHANNEL_SIGNING_SECRET=[0-9a-f]{64}$/m');
});

it('replaces an empty secret value', function (): void {
    file_put_contents($this->tempDir.'/.env', "APPSYNC_CHANNEL_SIGNING_SECRET=\n");

    expect(Artisan::call('appsync-events:secret'))->toBe(0)
        ->and(file_get_contents($this->tempDir.'/.env'))
        ->toMatch('/^APPSYNC_CHANNEL_SIGNING_SECRET=[0-9a-f]{64}$/m');
});

it('refuses to overwrite a non-empty secret without --force', function (): void {
    file_put_contents($this->tempDir.'/.env', "APPSYNC_CHANNEL_SIGNING_SECRET=existing-value\n");

    expect(Artisan::call('appsync-events:secret'))->toBe(1)
        ->and(Artisan::output())->toContain('APPSYNC_CHANNEL_SIGNING_SECRET is already set')
        ->and(file_get_contents($this->tempDir.'/.env'))->toContain('APPSYNC_CHANNEL_SIGNING_SECRET=existing-value');
});

it('overwrites a non-empty secret with --force', function (): void {
    file_put_contents($this->tempDir.'/.env', "APPSYNC_CHANNEL_SIGNING_SECRET=existing-value\n");

    expect(Artisan::call('appsync-events:secret', ['--force' => true]))->toBe(0)
        ->and(file_get_contents($this->tempDir.'/.env'))
        ->toMatch('/^APPSYNC_CHANNEL_SIGNING_SECRET=[0-9a-f]{64}$/m')
        ->not->toContain('existing-value');
});

it('fails clearly when the .env file does not exist', function (): void {
    expect(Artisan::call('appsync-events:secret'))->toBe(1)
        ->and(Artisan::output())->toContain('Environment file not found');
});
