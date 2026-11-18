<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Module;

use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use SugarCraft\Async\ChildResult;
use SugarCraft\Async\ChildRun;
use SugarCraft\Core\Util\Proc\BoundedShutdown;

use function React\Promise\resolve;

/**
 * One bounded child-process run: argv, no shell interpolation, wall-clock
 * deadline, output ceiling, and a guaranteed TERM→KILL reap before return.
 *
 * This is the shared engine behind every child the dashboard spawns. A
 * Model update reaches it only through {@see runAsync()}, which runs the
 * child on the loop; {@see run()} blocks and is for callers outside the
 * loop. A tick must never hand the dashboard's liveness to a child
 * process: the reads stop at the deadline, excess
 * output is drained and DISCARDED (so a spamming child never EPIPEs
 * mid-write and never exhausts memory), and the kill ladder guarantees the
 * direct child is gone on return. The pump and the ladder are sugar-async's
 * {@see ChildRun}; this class only maps its result onto the dashboard's
 * output/failure contract.
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

    /**
     * Longest a run started now can take to settle: the deadline, then the
     * TERM and KILL grace budgets. A module that latches "a command is in
     * flight" may trust that latch only this long, because the result is
     * the only thing that clears it and a host is free to drop the Cmd.
     */
    public const SETTLE_CEILING_SECONDS = self::DEFAULT_TIMEOUT_SECONDS
        + BoundedShutdown::TERMINATE_GRACE_SECONDS
        + BoundedShutdown::KILL_GRACE_SECONDS;

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
     * @throws \InvalidArgumentException for a non-positive timeout or a negative output ceiling
     */
    public static function run(
        array $argv,
        float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        int $maxOutputBytes = self::MAX_OUTPUT_BYTES,
    ): array {
        if ($argv === []) {
            return ['output' => '', 'failure' => 'No command configured'];
        }

        try {
            $result = ChildRun::new($argv)
                ->withTimeout($timeoutSeconds)
                ->withMaxBytes($maxOutputBytes)
                ->run();
        } catch (\RuntimeException) {
            // ChildRun raises RuntimeException only when the child cannot be spawned.
            return self::notStarted();
        }

        return self::outcome($result, $maxOutputBytes);
    }

    /**
     * The loop-driven {@see run()}: the same outcome, delivered on a promise, so a
     * tick can hand its child to the loop instead of blocking the dashboard on it.
     * The deadline and the TERM/KILL ladder are ChildRun's, running on $loop.
     *
     * @param list<string> $argv
     * @return PromiseInterface<array{output: string, failure: ?string}>
     * @throws \InvalidArgumentException for a non-positive timeout or a negative output ceiling
     */
    public static function runAsync(
        LoopInterface $loop,
        array $argv,
        float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        int $maxOutputBytes = self::MAX_OUTPUT_BYTES,
    ): PromiseInterface {
        if ($argv === []) {
            return resolve(['output' => '', 'failure' => 'No command configured']);
        }

        return ChildRun::new($argv)
            ->withTimeout($timeoutSeconds)
            ->withMaxBytes($maxOutputBytes)
            ->launch($loop)
            ->then(
                static fn (ChildResult $result): array => self::outcome($result, $maxOutputBytes),
                static function (\Throwable $e): array {
                    if ($e instanceof \RuntimeException) {
                        return self::notStarted();
                    }

                    throw $e;
                },
            );
    }

    /** @return array{output: string, failure: string} */
    private static function notStarted(): array
    {
        return ['output' => '', 'failure' => 'Command could not be started'];
    }

    /** @return array{output: string, failure: ?string} */
    private static function outcome(ChildResult $result, int $maxOutputBytes): array
    {
        // The dashboard's contract is ONE combined ceiling across both streams,
        // not one per stream, so stdout keeps its bytes first and stderr gets
        // whatever room is left. ChildRun keeps up to $maxOutputBytes per stream.
        $stdout = substr($result->stdout, 0, $maxOutputBytes);
        $stderr = substr($result->stderr, 0, $maxOutputBytes - strlen($stdout));
        $combined = trim($stdout . ($stderr !== '' ? "\n$stderr" : ''));

        if ($combined !== '') {
            return ['output' => $combined, 'failure' => null];
        }

        // Nothing arrived — name WHY, in the one cause the run actually saw.
        return match ($result->status) {
            ChildResult::TIMED_OUT => ['output' => '', 'failure' => 'Command timed out'],
            ChildResult::PIPE_FAILED => ['output' => '', 'failure' => 'Command read interrupted'],
            default => $result->exitCode !== 0 && $result->exitCode !== null
                ? ['output' => '', 'failure' => "Command failed (exit {$result->exitCode})"]
                : ['output' => '', 'failure' => null],
        };
    }
}
