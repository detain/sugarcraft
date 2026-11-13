<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Cli;

use SugarCraft\Core\Recorder;
use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\Contract\MasterPty;

/**
 * The `record` byte loop for native Windows, where the POSIX pump cannot run.
 *
 * PosixPump select()s over stream fds; a ConPTY master has none (its pipes
 * are kernel32 handles), and a Windows console stdin cannot be selected or
 * read without blocking. This loop polls instead, one short slice per turn:
 *
 *  - child output: `$master->read()` with a short timeout (the ConPTY master
 *    peeks its pipe, so the read never parks) → host stdout + the recorder;
 *  - host input: `$readInput()`, a non-blocking source the caller picks for
 *    the stdin it has (console records, pipe bytes, file bytes) → the master
 *    + the recorder;
 *  - host resizes: `$hostSize()` polled, forwarded to the pseudo console and
 *    recorded (Windows has no SIGWINCH);
 *  - exit: once the child is gone, output it wrote before exiting is drained
 *    for at most {@see EXIT_DRAIN_SECONDS}, then the loop returns its code.
 *
 * The loop ends only when the child exits, the same event-driven contract as
 * PosixPump; a caller that needs a wall-clock bound kills the child.
 */
final class WindowsRecordLoop
{
    /** One poll slice: the master read waits at most this long. */
    public const READ_TIMEOUT_SECONDS = 0.02;

    /** How long output is still drained after the child exits. */
    public const EXIT_DRAIN_SECONDS = 0.5;

    /** The post-exit drain ends once no output has arrived for this long. */
    public const EXIT_QUIET_SECONDS = 0.1;

    /** Host size is polled every this many turns (GetConsoleScreenBufferInfo is cheap but not free). */
    public const RESIZE_POLL_TURNS = 10;

    /**
     * @param \Closure(): string $readInput  Pending host input bytes; '' when none. Must not block.
     * @param null|\Closure(): ?array{0: int, 1: int} $hostSize  Host [cols, rows], or null when unknown
     * @param null|\Closure(): float $clock  Seconds, monotonic
     */
    public function __construct(
        private readonly \Closure $readInput,
        private readonly ?\Closure $hostSize = null,
        private readonly ?\Closure $clock = null,
    ) {
    }

    /**
     * Pump until the child exits; returns its exit code.
     *
     * @param resource $stdout
     */
    public function run(MasterPty $master, $stdout, Child $child, Recorder $recorder, int $cols, int $rows): int
    {
        $now = $this->clock ?? static fn (): float => \hrtime(true) / 1e9;
        $size = [$cols, $rows];
        $turn = 0;

        while (true) {
            $out = $master->read(8192, self::READ_TIMEOUT_SECONDS);
            if (\is_string($out) && $out !== '') {
                $this->deliver($stdout, $recorder, $out);
            }

            $in = ($this->readInput)();
            if ($in !== '' && !$child->exited()) {
                $this->forward($master, $recorder, $in);
            }

            if ($this->hostSize !== null && ++$turn % self::RESIZE_POLL_TURNS === 0) {
                $host = ($this->hostSize)();
                if ($host !== null && $host !== $size && $host[0] > 0 && $host[1] > 0) {
                    $size = $host;
                    $master->resize($host[0], $host[1]);
                    $recorder->recordResize($host[0], $host[1]);
                }
            }

            // '' means the output pipe is gone, which only happens after the
            // pseudo console closed it; either way the child decides the end.
            if ($child->exited() || $out === '') {
                break;
            }
        }

        // Bounded twice: the whole drain by EXIT_DRAIN_SECONDS, and it ends as
        // soon as the pipe has been quiet for EXIT_QUIET_SECONDS, so the
        // recorded quit lands right after the child's last bytes rather than
        // a fixed half-second later (which a realtime replay would then wait).
        $until = $now() + self::EXIT_DRAIN_SECONDS;
        $quietSince = $now();
        while ($now() < $until && $now() - $quietSince < self::EXIT_QUIET_SECONDS) {
            $out = $master->read(8192, self::READ_TIMEOUT_SECONDS);
            if ($out === '') {
                break;
            }
            if ($out !== null) {
                $this->deliver($stdout, $recorder, $out);
                $quietSince = $now();
            }
        }

        return $child->exitCode() ?? $child->wait();
    }

    /**
     * A non-blocking reader over a stdin STREAM (pipe or file): a pipe gives
     * only what fstat() reports waiting (PeekNamedPipe on Windows), a file is
     * read straight to EOF. A console stdin wants
     * {@see \SugarCraft\Core\Util\Tty\WindowsConsoleInput} instead.
     *
     * @param resource $stdin
     * @return \Closure(): string
     */
    public static function streamInput($stdin): \Closure
    {
        $stat = @\fstat($stdin);
        $isFile = \is_array($stat) && (($stat['mode'] ?? 0) & 0170000) === 0100000;

        return static function () use ($stdin, $isFile): string {
            if (!\is_resource($stdin) || \feof($stdin)) {
                return '';
            }
            if ($isFile) {
                $chunk = \fread($stdin, 8192);

                return \is_string($chunk) ? $chunk : '';
            }
            $stat = @\fstat($stdin);
            $ready = \is_array($stat) ? \min(8192, \max(0, (int) ($stat['size'] ?? 0))) : 0;
            if ($ready === 0) {
                return '';
            }
            $chunk = \fread($stdin, $ready);

            return \is_string($chunk) ? $chunk : '';
        };
    }

    /**
     * @param resource $stdout
     */
    private function deliver($stdout, Recorder $recorder, string $bytes): void
    {
        @\fwrite($stdout, $bytes);
        $recorder->recordOutput($bytes);
    }

    private function forward(MasterPty $master, Recorder $recorder, string $bytes): void
    {
        $sent = 0;
        $length = \strlen($bytes);
        // A pipe write into the pseudo console takes it all or fails; the loop
        // guards against a short count anyway rather than dropping keystrokes.
        for ($tries = 0; $sent < $length && $tries < 100; $tries++) {
            $wrote = $master->write(\substr($bytes, $sent));
            if ($wrote <= 0) {
                break;
            }
            $sent += $wrote;
        }
        if ($sent > 0) {
            $recorder->recordInputBytes(\substr($bytes, 0, $sent));
        }
    }
}
