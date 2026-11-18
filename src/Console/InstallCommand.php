<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Installs the package in the host application: publishes the config
 * file, adds the required `APPSYNC_*` placeholders to `.env.example`,
 * optionally generates a signing secret, and prints the next steps.
 */
final class InstallCommand extends Command
{
    /**
     * {@inheritDoc}
     */
    protected $signature = 'appsync-events:install';

    /**
     * {@inheritDoc}
     */
    protected $description = 'Install the AppSync Events broadcasting driver';

    /**
     * The environment variables the package needs.
     *
     * @var list<string>
     */
    private const ENV_KEYS = [
        'APPSYNC_HTTP_ENDPOINT',
        'APPSYNC_REALTIME_ENDPOINT',
        'APPSYNC_CHANNEL_SIGNING_SECRET',
    ];

    /**
     * Create a new command instance.
     *
     * @param  Filesystem  $files  The filesystem abstraction.
     */
    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'appsync-events-config']);

        $this->writeEnvPlaceholders();

        if (! $this->secretIsSet() && $this->confirm('Generate a signing secret now?', true)) {
            $this->call('appsync-events:secret');
        }

        $this->components->info('AppSync Events installed. Next steps:');
        $this->components->bulletList([
            'Set BROADCAST_CONNECTION=appsync in your .env and fill in the APPSYNC_* values.',
            'Define your broadcast channels in routes/channels.php.',
            'Install the JS connector: npm install @hds-solutions/laravel-echo-appsync.',
            'Deploy the AppSync Event API and configure the Lambda authorizer (see the package README).',
        ]);

        return self::SUCCESS;
    }

    /**
     * Append the APPSYNC_* placeholders to `.env.example`, once each.
     */
    private function writeEnvPlaceholders(): void
    {
        $path = $this->laravel->basePath('.env.example');
        $contents = $this->files->exists($path) ? $this->files->get($path) : '';
        $missing = false;

        foreach (self::ENV_KEYS as $key) {
            if (preg_match(sprintf('/^%s=/m', preg_quote($key, '/')), $contents) === 1) {
                continue;
            }

            if ($contents !== '' && ! str_ends_with($contents, "\n")) {
                $contents .= "\n";
            }

            $contents .= sprintf("%s=\n", $key);
            $missing = true;
        }

        if ($missing) {
            $this->files->put($path, $contents);
            $this->components->info(sprintf('Added APPSYNC_* placeholders to %s', $path));
        }
    }

    /**
     * Whether a signing secret is already configured — in the loaded
     * configuration or as a non-empty value in the `.env` file.
     */
    private function secretIsSet(): bool
    {
        $secret = $this->laravel['config']->get('appsync-events.signing_secret');

        if (is_string($secret) && trim($secret) !== '') {
            return true;
        }

        $path = $this->laravel->environmentFilePath();

        if (! $this->files->exists($path)) {
            return false;
        }

        return preg_match('/^APPSYNC_CHANNEL_SIGNING_SECRET=\S+/m', $this->files->get($path)) === 1;
    }
}
