<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Support;

use SugarCraft\Pty\Posix\PosixPtySystem;

/**
 * Real-PTY child driver for the example smoke legs (finding 001; the
 * same harness is the base for the golden example legs, D-124).
 *
 * Why this exists: examples like dashboard-live.php take an early-exit
 * guard on a non-TTY STDOUT, so every pipe-based harness in this repo
 * never reaches the render path. A bug that only fires under a real
 * terminal (renderHorizontal() returned a SizeError object through an
 * `array|string` union) was invisible to CI. This helper spawns the
 * child on a genuine PTY slave so the TTY branches actually execute.
 *
 * The capability probe deliberately asks the operational question —
 * "can this host open a PTY pair at all?" — instead of re-deriving the
 * ext-ffi + /dev/ptmx limbs (extension_loaded('ffi') is a known false
 * signal when ffi.enable=0, see sugar-pty's PtyCapability).
 */
final class PtyProbe
{
    private static ?bool $usable = null;

    /** Can this host spawn a child on a real PTY through sugar-pty? */
    public static function usable(): bool
    {
        if (self::$usable !== null) {
            return self::$usable;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return self::$usable = false;
        }

        try {
            $pair = (new PosixPtySystem())->open(8, 8);
            $pair->master()->close();
            self::$usable = true;
        } catch (\Throwable) {
            self::$usable = false;
        }

        return self::$usable;
    }

    /**
     * Run `$cmd` on a `cols`×`rows` PTY; once `$watch` sees the wanted
     * bytes in the stream, write `$input` to the master (repeatedly, at
     * ~0.5 s intervals, because the child installs raw mode only after
     * its banner and a single early write can stall in the canonical
     * queue). Returns the collected output and the exit status.
     *
     * @param list<string>           $cmd
     * @param callable(string): bool $watch receives the accumulated output
     * @return array{rc: int, output: string, timedOut: bool, writes: int}
     */
    public static function run(
        array $cmd,
        int $cols,
        int $rows,
        string $input,
        callable $watch,
        float $timeoutSeconds = 20.0,
        ?array $env = null,
    ): array {
        $pair = (new PosixPtySystem())->open($cols, $rows);
        $master = $pair->master();
        $stream = $master->stream();
        stream_set_blocking($stream, false);

        $child = $pair->slave()->spawn($cmd, $env, $cols, $rows, true);

        $buffer = '';
        $sent = 0;
        $nextSend = 0.0;
        $deadline = microtime(true) + $timeoutSeconds;
        $timedOut = true;

        while (microtime(true) < $deadline) {
            $chunk = @fread($stream, 65536);
            if (is_string($chunk) && $chunk !== '') {
                $buffer .= $chunk;
            }

            if ($watch($buffer) && microtime(true) >= $nextSend) {
                @fwrite($stream, $input);
                @fflush($stream);
                $sent++;
                $nextSend = microtime(true) + 0.5;
            }

            if ($child->exited()) {
                $timedOut = false;
                break;
            }

            usleep(20000);
        }

        if ($timedOut) {
            $child->kill(9); // SIGKILL — bounded, no zombie linger
        }

        // Drain the tail: the final frame (and any fatal banner) can sit
        // in the master queue when the child exits, and losing it would
        // blind the very assertion this helper exists for.
        for ($drain = 0; $drain < 50; $drain++) {
            $chunk = @fread($stream, 65536);
            if (!is_string($chunk) || $chunk === '') {
                break;
            }
            $buffer .= $chunk;
        }

        $rc = $child->wait();
        $master->close();

        return ['rc' => $rc, 'output' => $buffer, 'timedOut' => $timedOut, 'writes' => $sent];
    }
}
