<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

/**
 * The one way a shell script becomes a spawnable argv, so every spawn site
 * takes the same Windows ladder the Bash tool does (C-TUI-6.a).
 *
 * POSIX keeps the shape PHP's string form has always produced — `/bin/sh -c
 * <script>` — but spelled as an argv, so proc_open never routes it through
 * a shell it did not choose. Windows never sees cmd.exe: the script runs
 * under bash.exe through proc_open's ARRAY form, and a host with no bash.exe
 * gets a refusal, not a silent cmd.exe run. cmd.exe's quoting grammar cannot
 * carry a shell script, and `escapeshellarg()` output is meaningless to it,
 * so "it ran but mangled `%` and `!`" would read as success.
 */
final class ShellInvocation
{
    /**
     * The argv that runs $script through a shell, or null when this is a
     * Windows host with no bash.exe — the caller answers with {@see refusal()}.
     *
     * $bashOnPosix keeps the callers that have always said `bash -c` on POSIX
     * saying it: bash-only syntax in a user's command runs the same as before
     * rather than failing under /bin/sh.
     *
     * @return non-empty-list<string>|null
     */
    public static function argv(string $script, bool $bashOnPosix = false): ?array
    {
        if (!WindowsShell::isWindows()) {
            return [$bashOnPosix ? 'bash' : '/bin/sh', '-c', $script];
        }

        $bash = WindowsShell::bash();

        return $bash === '' ? null : [$bash, '-c', $script];
    }

    /** What a caller says when {@see argv()} refused: the missing piece and its remedy. */
    public static function refusal(): string
    {
        return WindowsShell::BASH_MISSING_NOTICE;
    }

    /**
     * The runCaptured()-shaped result for a command that never started
     * because {@see argv()} refused — the same keys a capture returns, so a
     * caller reads it like any other failed run, with the refusal as stderr.
     *
     * @return array{stdout: string, stderr: string, exitCode: int, truncatedBytes: int, stdoutDropped: int, stderrDropped: int, stdoutMidLine: bool, stderrMidLine: bool, timedOut: bool, groupKilled: bool}
     */
    public static function refusedRun(): array
    {
        return [
            'stdout' => '',
            'stderr' => self::refusal(),
            'exitCode' => 127,
            'truncatedBytes' => 0,
            'stdoutDropped' => 0,
            'stderrDropped' => 0,
            'stdoutMidLine' => false,
            'stderrMidLine' => false,
            'timedOut' => false,
            'groupKilled' => false,
        ];
    }
}
