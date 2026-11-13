<?php

declare(strict_types=1);

namespace SugarCraft\AnsiInspect\Exec;

use SugarCraft\AnsiInspect\Inspector;
use SugarCraft\AnsiInspect\Lang;
use SugarCraft\AnsiInspect\StreamingInspector;

/**
 * Executes a command on a fresh pseudo-terminal and reports the raw bytes
 * it prints, segment by segment, as they dispatch.
 *
 * This is the thin CLI leg only: spawning, pumping and reaping are entirely
 * sugar-pty's job ({@see \SugarCraft\Pty\Pty::open()} → `spawn()` → `read()`
 * with a finite timeout → {@see \SugarCraft\Pty\Child::wait()}). The runner
 * never reimplements a PTY primitive — it reads the master the way the CLI
 * reads stdin (AIP-04) and feeds the very same {@see StreamingInspector}, so
 * `-- cmd` output and piped output share one byte-fidelity implementation.
 *
 * Exit-code contract: the child's status is the tool's status once the
 * stream has been reported. A PTY that cannot be opened at all is a refusal
 * with exit 1 — the command never ran, so there is no child status to carry.
 *
 * The session factory is an injectable seam: {@see run()} calls it with the
 * command and expects back a `[session, child]` pair — objects exposing the
 * structural shapes `read(int, ?float): ?string`, `close(): void` and
 * `exited(): bool`, `wait(): int`. Tests hand in fakes, so the contract is
 * pinned without requiring a real PTY on the runner.
 */
final class ExecRunner
{
    /** Per-poll read bound handed to the master (sugar-pty read taxonomy). */
    public const POLL_TIMEOUT_SEC = 0.05;

    /** Exit status when no pseudo-terminal could be opened. */
    public const EXIT_UNAVAILABLE = 1;

    /**
     * @param list<string>                      $cmd        command + args to run
     * @param callable(string): void            $emitLine  one report line per segment
     * @param (callable(list<string>): array{0: object, 1: object})|null $sessionFactory
     *        seam returning [pty-session, child]; defaults to a real sugar-pty pair
     * @param resource                        $errorStream refusal sink (STDERR by default)
     */
    public static function run(array $cmd, callable $emitLine, ?callable $sessionFactory = null, $errorStream = STDERR): int
    {
        if ($cmd === []) {
            throw new \InvalidArgumentException('ExecRunner::run() requires a non-empty command');
        }
        $factory = $sessionFactory ?? self::sugarPtyFactory();

        try {
            [$session, $child] = $factory($cmd);
        } catch (\Throwable $e) {
            fwrite($errorStream, Lang::t('cli.pty_unavailable', [
                'reason' => Inspector::sanitizeLabelBytes($e->getMessage()),
            ]) . "\n");
            return self::EXIT_UNAVAILABLE;
        }

        $inspector = new StreamingInspector();
        $gotBytes = false;
        $printed = false;

        $consume = static function (string $chunk) use ($inspector, $emitLine, &$gotBytes, &$printed): void {
            $gotBytes = true;
            foreach ($inspector->feed($chunk) as $segment) {
                $printed = true;
                $emitLine($segment->describe());
            }
        };

        try {
            while (true) {
                $chunk = $session->read(8192, self::POLL_TIMEOUT_SEC);
                if ($chunk === null) {
                    // Quiet, not gone: only an exited child ends the session.
                    if ($child->exited()) {
                        break;
                    }
                    continue;
                }
                if ($chunk === '') {
                    break; // END-OF-FILE — every slave closed, so the child is done.
                }
                $consume($chunk);
            }
            // Bytes can sit in the master buffer between the last read and the
            // exit/EOF observation; drain once with a zero bound so nothing
            // the command printed goes unreported.
            while (true) {
                $chunk = $session->read(8192, 0.0);
                if ($chunk === null || $chunk === '') {
                    break;
                }
                $consume($chunk);
            }
            $status = $child->wait();
        } finally {
            $session->close();
        }

        foreach ($inspector->finish() as $segment) {
            $printed = true;
            $emitLine($segment->describe());
        }
        if ($gotBytes && !$printed) {
            // Non-empty stream that dispatched nothing (a lone invalid byte —
            // README deviation 10): the slurp echoed one empty report line.
            $emitLine('');
        }

        return $status;
    }

    /**
     * The real seam: a sugar-pty master + child, refused early (throws) when
     * this platform gives us no pseudo-terminal at all.
     *
     * @return callable(list<string>): array{0: object, 1: object}
     */
    private static function sugarPtyFactory(): callable
    {
        return static function (array $cmd): array {
            if (!class_exists(\SugarCraft\Pty\Pty::class)) {
                throw new \RuntimeException('sugar-pty is not installed');
            }
            $pty = \SugarCraft\Pty\Pty::open();
            try {
                $child = $pty->spawn($cmd);
            } catch (\Throwable $e) {
                $pty->close();
                throw $e;
            }
            return [$pty, $child];
        };
    }
}
