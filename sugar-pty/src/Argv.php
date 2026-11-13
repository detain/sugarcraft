<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

/**
 * Redacted argv rendering for failure messages.
 *
 * Commands routinely carry secrets as ARGUMENTS (tokens on a CLI, `-pPass`,
 * connection strings), and every consumer that logs throwables logs whatever
 * the message interpolates. So the lib's spawn-failure messages name the
 * PROGRAM and count the arguments, never echo them.
 *
 * The full line is still reachable when someone is debugging at their own
 * terminal: `SUGAR_PTY_DEBUG_ARGV=1` opts in per-process. That is a
 * debug escape hatch, not a sandbox — the env var is documented in the
 * README table, and reading this class is the whole contract.
 *
 * Windows sites that already hold pre-joined strings (CreateProcessW's
 * `lpCommandLine` shape) go through {@see describeWide()} so the policy has
 * one home across every backend.
 */
final class Argv
{
    private function __construct()
    {
    }

    /**
     * One-line rendering of an argv list: the program, then how many
     * arguments were withheld (unless debug mode is opted in, which renders
     * the real join — that mode's whole purpose).
     *
     * @param list<string> $cmd
     */
    public static function describe(array $cmd): string
    {
        $program = (string) ($cmd[0] ?? '');
        $rest = \count($cmd) - 1;

        if (self::debugAllowed()) {
            return \implode(' ', $cmd);
        }

        return $rest > 0
            ? \sprintf('"%s" (+%d arg%s redacted — set SUGAR_PTY_DEBUG_ARGV=1 to include)', $program, $rest, $rest === 1 ? '' : 's')
            : \sprintf('"%s"', $program);
    }

    /**
     * Windows twin of {@see describe()}: the failure point holds the
     * already-joined command line (and optionally an application name), not
     * an argv list. The application name is program-identifying and stays;
     * the joined line is the argument-bearing secret surface and drops,
     * replaced by its length in wide code units.
     */
    public static function describeWide(string $applicationName, string $commandLine): string
    {
        if (self::debugAllowed()) {
            return $commandLine !== '' ? $commandLine : $applicationName;
        }

        if ($applicationName !== '') {
            return \sprintf(
                '"%s" (command line of %d characters redacted — set SUGAR_PTY_DEBUG_ARGV=1 to include)',
                $applicationName,
                \mb_strlen($commandLine),
            );
        }

        return \sprintf(
            'a command line of %d characters (redacted — set SUGAR_PTY_DEBUG_ARGV=1 to include)',
            \mb_strlen($commandLine),
        );
    }

    /**
     * The single opt-in question. Literal env name: tests/EnvRosterTest.php
     * token-scans reads like this one and demands the README row.
     */
    public static function debugAllowed(): bool
    {
        $value = \getenv('SUGAR_PTY_DEBUG_ARGV');

        return $value !== false && $value !== '' && $value !== '0';
    }
}
