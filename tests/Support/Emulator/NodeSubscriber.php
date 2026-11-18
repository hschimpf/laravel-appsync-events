<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests\Support\Emulator;

use Closure;
use RuntimeException;

/**
 * Runs `tests/emulator/subscriber.mjs` — a Node process using the built
 * JS connector — and exposes its JSON-lines stdout to the tests.
 *
 * Stdout is read non-blockingly so {@see waitFor} can poll with a bounded
 * deadline; stderr is captured for diagnostics on failure. The process is
 * always terminated via {@see close} (or the destructor).
 */
final class NodeSubscriber
{
    /**
     * The subscriber script path.
     */
    private const SCRIPT = __DIR__.'/../../emulator/subscriber.mjs';

    /**
     * The built connector entry point consumed by the script.
     */
    private const CONNECTOR = __DIR__.'/../../../js/dist/index.js';

    /**
     * @var resource The child process.
     */
    private $process;

    /**
     * @var array<int, resource> The stdin/stdout/stderr pipes.
     */
    private array $pipes;

    /**
     * Unconsumed stdout bytes not yet forming a full line.
     */
    private string $buffer = '';

    /**
     * Every JSON line received so far.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $lines = [];

    /**
     * @param  array<string, mixed>  $config  The subscriber config (see the
     *                                        script header for the shape).
     * @param  string  $node  The Node binary to run.
     */
    public function __construct(
        private readonly array $config,
        private readonly string $node = 'node',
    ) {
        if (! is_file(self::CONNECTOR)) {
            throw new RuntimeException(
                'js/dist/index.js is missing — run `npm --prefix js run build` before the emulator tests.',
            );
        }

        $pipes = [];
        $process = proc_open(
            [$this->node, self::SCRIPT, (string) json_encode($this->config)],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if ($process === false) {
            throw new RuntimeException('Failed to spawn the subscriber process (is `node` on PATH?).');
        }

        $this->process = $process;
        $this->pipes = $pipes;

        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
    }

    /**
     * Wait for a JSON line of the given type, optionally filtered.
     *
     * @param  string  $type  The `type` field to wait for.
     * @param  Closure|null  $match  Receives the parsed line; return `true` to accept.
     * @param  float  $timeoutSeconds  The maximum wait.
     * @return array<string, mixed> The matching line.
     *
     * @throws RuntimeException On timeout, including captured output.
     */
    public function waitFor(string $type, ?Closure $match = null, float $timeoutSeconds = 5.0): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $index = 0;

        while (microtime(true) < $deadline) {
            $this->drain();

            for (; $index < count($this->lines); $index++) {
                $line = $this->lines[$index];
                if (($line['type'] ?? null) === $type && ($match === null || $match($line))) {
                    return $line;
                }
            }

            usleep(20_000);
        }

        throw new RuntimeException(sprintf(
            "Timed out after %.1fs waiting for a '%s' line.\nstdout: %s\nstderr: %s",
            $timeoutSeconds,
            $type,
            (string) json_encode($this->lines),
            $this->stderr(),
        ));
    }

    /**
     * Collect output for `$milliseconds` and return the lines received;
     * used to assert the *absence* of events (a bounded quiet period).
     *
     * @param  int  $milliseconds  How long to listen.
     * @return array<int, array<string, mixed>> The lines seen meanwhile.
     */
    public function collect(int $milliseconds): array
    {
        $start = count($this->lines);
        $deadline = microtime(true) + $milliseconds / 1000;

        while (microtime(true) < $deadline) {
            $this->drain();
            usleep(20_000);
        }

        return array_slice($this->lines, $start);
    }

    /**
     * Every JSON line received so far.
     *
     * @return array<int, array<string, mixed>>
     */
    public function lines(): array
    {
        $this->drain();

        return $this->lines;
    }

    /**
     * The captured stderr so far.
     */
    public function stderr(): string
    {
        $chunk = stream_get_contents($this->pipes[2]);

        return is_string($chunk) ? $chunk : '';
    }

    /**
     * Close stdin (the script exits cleanly), wait, and reap the process.
     */
    public function close(): void
    {
        if (isset($this->pipes[0]) && is_resource($this->pipes[0])) {
            fclose($this->pipes[0]);
        }

        $status = proc_get_status($this->process);

        if ($status['running']) {
            proc_terminate($this->process);
        }

        foreach ([1, 2] as $pipe) {
            if (is_resource($this->pipes[$pipe])) {
                fclose($this->pipes[$pipe]);
            }
        }

        proc_close($this->process);
    }

    public function __destruct()
    {
        if (isset($this->process) && is_resource($this->process)) {
            $this->close();
        }
    }

    /**
     * Read whatever stdout bytes are available and append complete
     * JSON lines to {@see $lines}.
     */
    private function drain(): void
    {
        $chunk = stream_get_contents($this->pipes[1]);

        if (is_string($chunk) && $chunk !== '') {
            $this->buffer .= $chunk;
        }

        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 1);

            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $this->lines[] = $decoded;
            }
        }
    }
}
