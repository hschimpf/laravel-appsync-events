<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Generates a new `APPSYNC_CHANNEL_SIGNING_SECRET` — a 64-character
 * hexadecimal string — and writes it into the application `.env` file.
 *
 * Use `--show` to print a secret without touching any file, and `--force`
 * to overwrite an existing non-empty value.
 */
final class SecretCommand extends Command
{
    /**
     * {@inheritDoc}
     */
    protected $signature = 'appsync-events:secret
        {--show : Print the generated secret without writing it to the .env file}
        {--force : Overwrite an existing non-empty secret}';

    /**
     * {@inheritDoc}
     */
    protected $description = 'Generate a new AppSync Events channel signing secret';

    /**
     * The environment variable managed by this command.
     */
    private const ENV_KEY = 'APPSYNC_CHANNEL_SIGNING_SECRET';

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
        $secret = bin2hex(random_bytes(32));

        if ($this->option('show')) {
            $this->line($secret);

            return self::SUCCESS;
        }

        $path = $this->laravel->environmentFilePath();

        if (! $this->files->exists($path)) {
            $this->components->error(sprintf('Environment file not found: %s', $path));

            return self::FAILURE;
        }

        $contents = $this->files->get($path);

        if (preg_match(sprintf('/^%s=(.*)$/m', preg_quote(self::ENV_KEY, '/')), $contents, $matches) === 1) {
            if (trim($matches[1]) !== '' && ! $this->option('force')) {
                $this->components->error(sprintf(
                    '%s is already set. Use --force to overwrite it.',
                    self::ENV_KEY,
                ));

                return self::FAILURE;
            }

            $contents = (string) preg_replace(
                sprintf('/^%s=.*$/m', preg_quote(self::ENV_KEY, '/')),
                sprintf('%s=%s', self::ENV_KEY, $secret),
                $contents,
            );
        } else {
            if ($contents !== '' && ! str_ends_with($contents, "\n")) {
                $contents .= "\n";
            }

            $contents .= sprintf("%s=%s\n", self::ENV_KEY, $secret);
        }

        $this->files->put($path, $contents);

        $this->components->info(sprintf('%s set in %s', self::ENV_KEY, $path));

        return self::SUCCESS;
    }
}
