<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server;

/**
 * The shared tail reader for the server's own 0600 log file.
 *
 * Two doors read a server.log through the SAME code (C-WEB-5.f): the CLI
 * `serve logs` verb ({@see \SugarCraft\Code\Cli\Serve::logs()}, which keeps
 * its own lstat refusal branch because it owns its exit codes and Lang
 * messages) and the admin-scoped `server.logs` RPC
 * ({@see \SugarCraft\Code\Protocol\Methods\ServerMethods}, which answers a
 * flattened, capped {@see self::answer()}). Lifting the byte-window read
 * here keeps the two from ever disagreeing about WHICH bytes the tail is.
 *
 * Trust boundary, settled by the owner (plan §6.1 ruling R13): every line
 * {@see ErrorLog} writes says the log lines "go to the 0600 server.log and
 * never onto the wire" — and that law holds unchanged for every surface
 * except exactly one. The owner accepted Admin-scope log lines on the wire:
 * the `server.logs` RPC reads them, flattened ({@see ErrorLog::flatten()},
 * control bytes collapsed, each line clipped to its message bound) and
 * capped by line count and total bytes, behind {@see Scope::Admin}. Log
 * lines can quote prompts or paths, so the Admin door is the whole gate;
 * an Admin principal can already stop the server and read its transcripts,
 * which the same lines cannot out-secret. When the live file is absent or
 * not a regular file (a foreground launch never wrote one, or something was
 * planted at its name), the answer is the empty list with the `no_log`
 * machine reason token — never prose, because server text stays
 * untranslated and the browser maps the token to its own chrome string.
 */
final class LogTail
{
    /** How far back from the end of a single file {@see self::tail()} reads. */
    public const WINDOW_BYTES = 256 * 1024;

    /** Lines `server.logs` answers with when the request names none. */
    public const DEFAULT_LINES = 100;

    /** The most lines any `server.logs` answer carries. */
    public const MAX_LINES = 200;

    /** The most bytes (joined, flattened) any `server.logs` answer carries. */
    public const ANSWER_MAX_BYTES = 32_768;

    /** Machine reason token: this server has no regular server.log to read. */
    public const REASON_NO_LOG = 'no_log';

    private function __construct()
    {
    }

    /**
     * The last $count lines of $path and the byte offset reading stopped at.
     *
     * @return array{0: list<string>, 1: int}
     */
    public static function tail(string $path, int $count): array
    {
        $handle = @\fopen($path, 'r');
        if ($handle === false) {
            return [[], 0];
        }
        $size = (int) (\fstat($handle)['size'] ?? 0);
        $window = self::WINDOW_BYTES;
        $from = \max(0, $size - $window);
        \fseek($handle, $from);
        $text = (string) \stream_get_contents($handle, $size - $from);
        \fclose($handle);

        $lines = \explode("\n", \rtrim($text, "\n"));
        if ($from > 0) {
            \array_shift($lines);
        }
        if ($lines === ['']) {
            $lines = [];
        }

        return [\array_slice($lines, -$count), $size];
    }

    /**
     * The wire answer for one state directory: the newest $count flattened
     * lines across the rotated generations a server writes
     * ({@see StateDir::rotateLog()} stacks `server.log` over `.1` over `.2`),
     * trimmed from the OLDEST end until the joined answer fits
     * {@see self::ANSWER_MAX_BYTES}.
     *
     * The live file's regular-file shape is the door: absent, or a symlink,
     * fifo or directory planted at `server.log`, and the answer is the empty
     * list with {@see self::REASON_NO_LOG}. Rotated generations that are
     * missing or non-regular are simply not read — the live file alone
     * decides whether a log exists.
     *
     * @return array{lines: list<string>, reason: ?string}
     */
    public static function answer(string $stateDir, int $count): array
    {
        $live = \rtrim($stateDir, '/') . '/' . StateDir::LOG_FILE;
        if (!self::isRegular($live)) {
            return ['lines' => [], 'reason' => self::REASON_NO_LOG];
        }

        $want = \max(1, \min($count, self::MAX_LINES));

        /** @var list<string> $newestFirst flattened lines, newest first */
        $newestFirst = [];
        for ($generation = 0; $generation < StateDir::LOG_FILES && \count($newestFirst) < $want; ++$generation) {
            $path = $generation === 0 ? $live : $live . '.' . $generation;
            if ($generation > 0 && !self::isRegular($path)) {
                continue;
            }

            [$lines] = self::tail($path, $want - \count($newestFirst));
            for ($index = \count($lines) - 1; $index >= 0 && \count($newestFirst) < $want; --$index) {
                $newestFirst[] = ErrorLog::flatten($lines[$index]);
            }
        }

        /** @var list<string> $kept newest-first subset inside the byte budget */
        $kept = [];
        $bytes = 0;
        foreach ($newestFirst as $line) {
            $cost = \strlen($line) + ($kept === [] ? 0 : 1);
            if ($bytes + $cost > self::ANSWER_MAX_BYTES) {
                break;
            }

            $bytes += $cost;
            $kept[] = $line;
        }

        return ['lines' => \array_reverse($kept), 'reason' => null];
    }

    /**
     * The regular-file shape check both doors run before reading — lstat,
     * never stat, so a symlink at the log name is refused rather than
     * followed out of the state dir.
     */
    private static function isRegular(string $path): bool
    {
        \clearstatcache(true, $path);
        $stat = @\lstat($path);

        return $stat !== false && ((int) $stat['mode'] & 0o170000) === 0o100000;
    }
}
