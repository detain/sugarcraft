<?php

/**
 * Bounded child-process spawning for the candy-ansi-inspect test suite.
 *
 * WHY THIS EXISTS (step AIP-30). Every previous copy of this logic was
 * `proc_open` + `stream_get_contents`-to-EOF with no deadline at all: a CLI
 * that blocks (fleet incident history: stdin-wait hangs proved this path can
 * stall past 300 s) hangs the whole shard instead of failing one test. The
 * repo's named
 * law for this class of defect is "a test built on such loops can only hang,
 * never fail" — sugar-pty bounds it with a separate-process HangWatchdog,
 * candy-ansi-inspect previously carried nothing. Here the children are short-lived
 * plain CLI invocations, so one in-test `stream_select` poll plus
 * terminate-on-overrun is the right weight.
 *
 * The trait is the single spawn site: the poll loop enforces a soft deadline,
 * escalates SIGTERM → SIGKILL with a bounded grace after each, and only then
 * judges — so the deadline itself is testable (and tested, negatively, in
 * SpawnBoundedTest) and every child is reaped before the return.
 */

declare(strict_types=1);

namespace SugarCraft\AnsiInspect\Tests\Support;

trait SpawnBounded
{
    /** Comfortable ceiling for a real CLI invocation on a loaded CI shard. */
    private const SPAWN_DEADLINE_SECONDS = 30.0;

    /** Time the child gets to die politely before the signal escalates. */
    private const TERM_GRACE_SECONDS = 2.0;

    /** POSIX kill signal; PHP's proc_terminate takes the number portably. */
    private const SIGNAL_KILL = 9;

    /**
     * Run `$cmd` with both output pipes drained concurrently and a deadline.
     *
     * @param list<string>                $cmd      argv vector (no shell)
     * @param array<string, string>|null  $env      full env snapshot, null = inherit
     * @param string|null                 $stdin    bytes to feed, null closes stdin (EOF)
     * @param float                       $deadline seconds before termination
     *
     * @return array{stdout: string, stderr: string, exit: int, timedOut: bool}
     */
    protected static function spawnBounded(
        array $cmd,
        ?array $env = null,
        ?string $stdin = null,
        float $deadline = self::SPAWN_DEADLINE_SECONDS,
    ): array {
        $proc = \proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, $env);
        self::assertIsResource($proc, 'failed to launch ' . ($cmd[0] ?? '?'));

        if ($stdin !== null) {
            \fwrite($pipes[0], $stdin);
        }
        \fclose($pipes[0]); // stdin at EOF immediately: a child must never wait on a terminal.

        $stdout = '';
        $stderr = '';
        $open = [1 => $pipes[1], 2 => $pipes[2]];

        $phaseDeadline = \microtime(true) + $deadline;
        $timedOut = false;
        $terminated = false;
        $killed = false;

        while ($open !== []) {
            $remaining = $phaseDeadline - \microtime(true);
            if ($remaining <= 0.0) {
                $timedOut = true;
                if (!$terminated) {
                    $terminated = true;
                    \proc_terminate($proc);
                    $phaseDeadline = \microtime(true) + self::TERM_GRACE_SECONDS;
                    continue;
                }
                if (!$killed) {
                    $killed = true;
                    \proc_terminate($proc, self::SIGNAL_KILL);
                    $phaseDeadline = \microtime(true) + self::TERM_GRACE_SECONDS;
                    continue;
                }
                break; // an unkillable child cannot exist; refuse to loop forever anyway.
            }

            $read = \array_values($open);
            $write = null;
            $except = null;
            $ready = @\stream_select(
                $read,
                $write,
                $except,
                (int) $remaining,
                (int) (($remaining - (int) $remaining) * 1_000_000),
            );
            if ($ready === false) {
                break; // select error: collect what the drain already holds and judge.
            }

            foreach ($read as $stream) {
                $chunk = \fread($stream, 65_536);
                if ($chunk === false) {
                    continue;
                }
                if (isset($open[1]) && $stream === $pipes[1]) {
                    $stdout .= $chunk;
                } elseif (isset($open[2]) && $stream === $pipes[2]) {
                    $stderr .= $chunk;
                }
                if ($chunk === '' && \feof($stream)) {
                    unset($open[(int) \array_search($stream, $open, true)]);
                    \fclose($stream);
                }
            }
        }

        foreach ($open as $stream) {
            \fclose($stream);
        }
        $exit = \proc_close($proc); // reaps: SIGKILL above guarantees no indefinite wait.

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => $exit, 'timedOut' => $timedOut];
    }

    /**
     * Spawn `php bin/candy-ansi-inspect <args>` against an isolated XDG_CONFIG_HOME.
     *
     * @param list<string> $args
     *
     * @return array{stdout: string, stderr: string, exit: int, timedOut: bool}
     */
    protected static function spawnKv(array $args, string $xdgConfigHome, ?string $stdin = null): array
    {
        $env = \getenv();
        $env['XDG_CONFIG_HOME'] = $xdgConfigHome;

        return self::spawnBounded(
            \array_merge([\PHP_BINARY, \dirname(__DIR__, 2) . '/bin/candy-ansi-inspect'], $args),
            $env,
            $stdin,
        );
    }
}
