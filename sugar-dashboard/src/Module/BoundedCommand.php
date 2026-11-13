<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Module;

use SugarCraft\Core\Util\Proc\WindowsPipes;
use SugarCraft\Core\Util\Proc\BoundedShutdown;

/**
 * One bounded child-process run: argv, no shell interpolation, wall-clock
 * deadline, output ceiling, and a guaranteed TERM→KILL reap before the
 * lexical proc_close.
 *
 * This is the shared engine behind every synchronous child the dashboard
 * spawns from a Model update. A tick must never hand the dashboard's
 * liveness to a child process: the reads stop at the deadline, excess
 * output is drained and DISCARDED (so a spamming child never EPIPEs
 * mid-write and never exhausts memory), and the sugar-core ladder
 * guarantees the direct child is gone on return.
 *
 * Honest limits, disclosed rather than papered over:
 * - Grandchildren outlive the ladder. The TERM/KILL pair targets the
 *   direct child's pid; a shell (or a daemonizing binary) can leave a
 *   detached descendant holding its own inherited descriptors. Config
 *   authors who need tree-killing should wrap the command themselves.
 * - The child inherits the FULL parent environment. Unlike untrusted
 *   plugin binaries (see ExternalModule::pluginEnvironment(), which gets
 *   an explicit allow-list), these commands are operator-authored
 *   configuration — trusted by construction, like a crontab line — and
 *   scripts legitimately expect PATH and friends.
 * - The exit code is only consulted when the child produced no output;
 *   output always wins (tools like grep exit 1 while printing the
 *   answer, and the answer is the point of a dashboard panel).
 */
final class BoundedCommand
{
    /** Default wall-clock budget for the child to produce its output. */
    public const DEFAULT_TIMEOUT_SECONDS = 5.0;

    /** Combined stdout+stderr ceiling — a chatty child cannot exhaust memory. */
    public const MAX_OUTPUT_BYTES = 1048576;

    /** fread granularity inside one readable round. */
    private const READ_CHUNK_BYTES = 8192;

    /** stream_select slice: fine enough that EOF is noticed quickly, coarse enough to not spin. */
    private const SELECT_SLICE_US = 50000;

    /**
     * Run $argv and report the outcome as output plus, when the run could
     * not complete, ONE specific human-readable cause. The four former
     * blanket 'Command failed' shapes (no command / could not start /
     * interrupted / timed out) are named separately; empty output from a
     * zero-exit child is a SUCCESS with nothing to show (failure === null),
     * not a disguised error.
     *
     * @param list<string> $argv
     * @return array{output: string, failure: ?string}
     */
    public static function run(
        array $argv,
        float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        int $maxOutputBytes = self::MAX_OUTPUT_BYTES,
    ): array {
        if ($argv === []) {
            return ['output' => '', 'failure' => 'No command configured'];
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
            return ['output' => '', 'failure' => 'Command could not be started'];
        }

        // Close stdin immediately — no interactive input expected.
        fclose($pipes[0]);

        $buffers = ['out' => '', 'err' => ''];
        $streams = ['out' => $pipes[1], 'err' => $pipes[2]];
        $deadline = hrtime(true) + (int) ($timeoutSeconds * 1_000_000_000);
        $timedOut = false;
        $interrupted = false;

        if (WindowsPipes::runtimeNeedsIt()) {
            // Windows: the pipes stay blocking there and select() calls them
            // ready while empty, so the fread below parked until the command
            // exited and the deadline never fired (a hung command ran 30 s past
            // a 15 s bound). Read what fstat reports waiting until it exits.
            $drained = WindowsPipes::drainUntilExit($streams, WindowsPipes::runningProbe($process), microtime(true) + $timeoutSeconds, $maxOutputBytes);
            $buffers['out'] = $drained['buffers']['out'];
            $buffers['err'] = substr($drained['buffers']['err'], 0, max(0, $maxOutputBytes - strlen($buffers['out'])));
            $timedOut = $drained['timedOut'];
        }

        while ($streams !== [] && !WindowsPipes::runtimeNeedsIt()) {
            if (hrtime(true) >= $deadline) {
                $timedOut = true;
                break;
            }

            $read = array_values($streams);
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, self::SELECT_SLICE_US) === false) {
                $interrupted = true; // the ladder below still guarantees the reap
                break;
            }

            foreach ($read as $stream) {
                // stream_select reindexes $read, so recover the channel by
                // identity over the live map — a positional guess against
                // $streams['out'] read an already-unset key once stdout hit
                // EOF first (E_WARNING mid-frame).
                $key = array_search($stream, $streams, true);
                if ($key === false) {
                    continue;
                }

                $chunk = fread($stream, self::READ_CHUNK_BYTES);
                if ($chunk === false || $chunk === '') {
                    if (feof($stream)) {
                        unset($streams[$key]);
                    }
                    continue;
                }

                $room = $maxOutputBytes - strlen($buffers['out']) - strlen($buffers['err']);
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
        // stdin, which is exactly the hang a bounded run must not inherit.
        BoundedShutdown::terminateAndAwaitExit($process);
        $exitCode = proc_close($process);

        $stdout = $buffers['out'];
        $stderr = $buffers['err'];
        $combined = trim($stdout . ($stderr !== '' ? "\n$stderr" : ''));

        if ($combined !== '') {
            return ['output' => $combined, 'failure' => null];
        }

        // Nothing arrived — name WHY, in the one cause the loop actually saw.
        if ($timedOut) {
            return ['output' => '', 'failure' => 'Command timed out'];
        }
        if ($interrupted) {
            return ['output' => '', 'failure' => 'Command read interrupted'];
        }
        if ($exitCode !== 0) {
            return ['output' => '', 'failure' => "Command failed (exit {$exitCode})"];
        }
        return ['output' => '', 'failure' => null];
    }
}
