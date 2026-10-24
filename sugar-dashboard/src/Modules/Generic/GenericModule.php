<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Modules\Generic;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Util\Proc\BoundedShutdown;
use SugarCraft\Dashboard\Module\BaseModule;
use SugarCraft\Core\Util\Sanitize;

/**
 * Generic module that runs an arbitrary shell command and displays output.
 *
 * Uses Cmd::tick() for periodic refresh based on intervalSeconds.
 */
final class GenericModule extends BaseModule
{
    /**
     * Wall-clock budget for the argv child to produce its output. On expiry
     * the TERM→KILL ladder runs and whatever arrived so far is displayed.
     * A tick must never hand the dashboard's liveness to a child process —
     * the old blocking read froze the whole UI for a hung command's entire
     * lifetime (same lesson sugar-dashboard's ExternalModule learned in
     * E366, applied here to the fire-and-read path).
     */
    private const READ_TIMEOUT_SECONDS = 5.0;

    /** Combined stdout+stderr ceiling — a chatty child cannot exhaust memory. */
    private const MAX_OUTPUT_BYTES = 1048576;

    /** fread granularity inside one readable round. */
    private const READ_CHUNK_BYTES = 8192;

    /** stream_select slice: fine enough that EOF is noticed quickly, coarse enough to not spin. */
    private const SELECT_SLICE_US = 50000;

    private string $output = '';

    /**
     * @param string|array<string> $command  Trusted shell string OR argv list (no shell).
     *                                      When array, runs via proc_open with no shell
     *                                      interpolation — the safe form for untrusted args.
     *                                      When string, passed verbatim to the shell —
     *                                      callers MUST treat as trusted-only input.
     */
    public function __construct(
        private readonly string|array $command,
        private readonly int $intervalSeconds = 5,
    ) {
    }

    public function name(): string
    {
        return 'generic';
    }

    public function init(): ?\Closure
    {
        return Cmd::tick((float) $this->intervalSeconds, static fn(): Msg => new TickMsg());
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof TickMsg) {
            $newOutput = $this->runCommand();
            $nextModule = $this->withOutput($newOutput);
            return [$nextModule, Cmd::tick((float) $this->intervalSeconds, static fn(): Msg => new TickMsg())];
        }
        return [$this, null];
    }

    public function view(): string
    {
        return Sanitize::untrusted($this->output);
    }

    public function minSize(): array
    {
        return [20, 3];
    }

    private function withOutput(string $output): static
    {
        $clone = clone $this;
        $clone->output = $output;
        return $clone;
    }

    /**
     * Run the configured command and return trimmed stdout+stderr.
     * When $command is an array: proc_open with no shell (safe for untrusted args).
     * When $command is a string: shell_exec verbatim (trusted-only path).
     */
    private function runCommand(): string
    {
        if (is_array($this->command)) {
            return $this->runArgv($this->command);
        }
        // Trusted-only string path — callers must not pass user input here.
        $output = @shell_exec($this->command . ' 2>&1');
        return $output !== null ? trim($output) : 'Command failed';
    }

    /**
     * Run an argv array via proc_open with no shell involvement.
     * stderr is merged into stdout so `2>&1` semantics are preserved.
     *
     * Every path out is bounded: reads run against READ_TIMEOUT_SECONDS with
     * a MAX_OUTPUT_BYTES cap (excess is drained and discarded, so the child
     * never EPIPEs mid-spam), then the sugar-core ladder guarantees the
     * child is gone before the lexical proc_close reap.
     *
     * @param list<string> $argv
     */
    private function runArgv(array $argv): string
    {
        if ($argv === []) {
            return 'Command failed';
        }

        // @ is honest suppression here, not evasion: a missing binary makes
        // proc_open() raise an E_WARNING and return false, and the guarded
        // false path below is the designed outcome, not an unhandled state.
        $process = @proc_open(
            $argv,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            return 'Command failed';
        }

        // Close stdin immediately — no interactive input expected.
        fclose($pipes[0]);

        $buffers = ['out' => '', 'err' => ''];
        $streams = ['out' => $pipes[1], 'err' => $pipes[2]];
        $deadline = hrtime(true) + (int) (self::READ_TIMEOUT_SECONDS * 1_000_000_000);

        while ($streams !== []) {
            if (hrtime(true) >= $deadline) {
                break;
            }

            $read = array_values($streams);
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, self::SELECT_SLICE_US) === false) {
                break; // interrupted — the ladder below still guarantees the reap
            }

            foreach ($read as $stream) {
                $key = $stream === $streams['out'] ? 'out' : 'err';
                $chunk = fread($stream, self::READ_CHUNK_BYTES);
                if ($chunk === false || $chunk === '') {
                    if (feof($stream)) {
                        unset($streams[$key]);
                    }
                    continue;
                }

                $room = self::MAX_OUTPUT_BYTES - strlen($buffers['out']) - strlen($buffers['err']);
                if ($room > 0) {
                    $buffers[$key] .= strlen($chunk) > $room ? substr($chunk, 0, $room) : $chunk;
                }
            }
        }

        foreach ($streams as $stream) {
            fclose($stream);
        }

        // TERM → bounded poll → KILL → bounded poll, THEN the reap:
        // proc_close() alone waits out a child that ignores the closed
        // stdin, which is exactly the hang this path used to inherit.
        BoundedShutdown::terminateAndAwaitExit($process);
        proc_close($process);

        $stdout = $buffers['out'];
        $stderr = $buffers['err'];
        $combined = $stdout . ($stderr !== '' ? "\n$stderr" : '');
        return trim($combined) !== '' ? trim($combined) : 'Command failed';
    }
}
