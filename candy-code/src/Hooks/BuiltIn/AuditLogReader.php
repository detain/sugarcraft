<?php

declare(strict_types=1);

namespace SugarCraft\Code\Hooks\BuiltIn;

/**
 * Reads the tail of the audit log {@see AuditHook} writes (C-SEC-6.d).
 *
 * The log has lived unwritten-to by anyone; this is the read half. The shape
 * it parses is the header of {@see AuditHook}: one record per line,
 *
 *     [Y-m-d H:i:s] <session> <tool> <input> => <output excerpt>
 *     [Y-m-d H:i:s] <session> <tool> <input> =! DENY <kind>: <reason>
 *     [Y-m-d H:i:s] <session> <tool> <input> =! WITHHELD ["<hook>"]: <reason>
 *
 * BOUNDED BY CONSTRUCTION. The audit log is a plain file a long-lived server
 * can push to its 5 MiB rotation size and roll within seconds of heavy work,
 * so a reader that slurped it would let one panel request read megabytes.
 * This reader walks backwards from the end in {@see WINDOW_BYTES} windows and
 * never reads past what one rotation generation can hold; it stops growing
 * the moment the window begins just after a newline, because a window that
 * cut mid-line would make the head fragment look like a record — the same
 * seek discipline `Cli\Serve::tail()` applies to `server.log`. A record long
 * enough that the walk must reach the file start to begin whole at all (the
 * reason field is uncapped) is read in full, because that IS the bounded
 * window by then. A last line torn by a crash between the write and its
 * newline is dropped — the record is incomplete and the log carries no
 * checksum to trust it by.
 *
 * ROTATION: {@see AuditHook} keeps exactly one rolled generation,
 * `audit.log.1`. Freshly started or freshly rotated logs are short, so the
 * rows a viewer wants after a roll live in the rolled file; the reader reads
 * the current file first and fills any unfilled part of its limit from
 * `audit.log.1`, oldest first. One generation is the whole history available,
 * and one generation is what this reads.
 *
 * THE AMBIGUITY, STATED. A record's four fields are separated by single
 * spaces and {@see AuditHook} does not escape spaces, so a tool input that
 * itself contains `" => "` or `" =! "` (a Bash command quoting another audit
 * line, say) is indistinguishable from the verdict marker that follows it.
 * The parser takes the FIRST such marker after the tool name as the split,
 * which is what the producer's own construction order guarantees for every
 * line it writes; a hand-edged line can disagree, and no reader could do
 * better against an unescaped format. Tool names and timestamps are read as
 * whitespace-free tokens for the same reason.
 *
 * UNREADABLE IS EMPTY, NEVER AN ERROR. A missing log, a locked log, a serve
 * process under another `HOME`/`TMPDIR` whose audit directory this server
 * never wrote: all answer as no rows. The audit trail is a diagnostic view,
 * and a view that refuses to open because its source is absent is worse than
 * one that says "nothing here" — which is also true of {@see AuditHook}
 * itself, whose every write is best-effort. {@see tail()} reports
 * `unreadable` when the named file exists but could not be opened at all, so
 * the caller can distinguish "empty" from "refused" if it wants to say more.
 */
final class AuditLogReader
{
    /** Hard ceiling on returned rows; the wire schema caps the `limit` param at it. */
    public const MAX_ROWS = 200;

    /** Default limit when the caller asks for none: a full screen, not a full file. */
    public const DEFAULT_ROWS = 50;

    /**
     * Bytes read per backward window. One window already holds far more than
     * {@see MAX_ROWS} ordinary records; it doubles only to reach whole lines.
     */
    public const WINDOW_BYTES = 65_536;

    /** Suffix {@see AuditHook} rotates the default log under (one generation). */
    public const ROTATED_SUFFIX = '.1';

    /** @var list<string> */
    private const VERDICTS = ['allow', 'deny', 'withheld'];

    private function __construct()
    {
    }

    /**
     * The newest audit records, oldest first, from the tail of $logFile
     * (defaulting to {@see AuditHook::defaultLogFile()}) and, to fill the
     * limit, from the rotated `audit.log.1` beside it.
     *
     * @param int         $limit   rows wanted, clamped to {@see MAX_ROWS}
     * @param string|null $logFile an explicit log path (the pin seam tests use); null reads the default
     * @param string|null $session when set, only records whose session field equals it
     *
     * @return array{rows: list<array{time: string, session: string, tool: string, verdict: string, kind: ?string, reason: ?string}>, unreadable: bool}
     */
    public static function tail(
        int $limit = self::DEFAULT_ROWS,
        ?string $logFile = null,
        ?string $session = null,
    ): array {
        $limit = \max(1, \min(self::MAX_ROWS, $limit));
        $path = $logFile ?? AuditHook::defaultLogFile();

        $rows = [];
        $unreadable = false;
        // Current file first, then the one rotated generation — the newest
        // rows live at a file's END, so when the current tail alone fills the
        // limit, the rolled file is never opened at all.
        foreach ([$path, $path . self::ROTATED_SUFFIX] as $position => $candidate) {
            $lines = self::lastCompleteLines($candidate, $limit);
            if ($lines === null) {
                $unreadable = true;

                continue;
            }

            $parsed = [];
            foreach ($lines as $line) {
                $row = self::parseLine($line);
                if ($row === null || ($session !== null && $row['session'] !== $session)) {
                    continue;
                }
                $parsed[] = $row; // the file's own oldest-first order
            }

            // The rotated generation is older history: it PREPENDS.
            $rows = $position === 0 ? $parsed : [...$parsed, ...$rows];

            if ($position === 0 && \count($rows) >= $limit) {
                break;
            }
        }

        return ['rows' => \array_slice($rows, -$limit), 'unreadable' => $unreadable];
    }

    /**
     * Parse ONE record line into its row, or null when the line is not a
     * record this shape can name (junk, a torn final line, a future format).
     *
     * @return ?array{time: string, session: string, tool: string, verdict: string, kind: ?string, reason: ?string}
     */
    public static function parseLine(string $line): ?array
    {
        if (\preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (\S*) (\S+) (.*)$/', $line, $m) !== 1) {
            return null;
        }

        // The first verdict marker after the tool name splits input from
        // tail — see the class note on the format's one ambiguity.
        $arrow = \strpos($m[4], ' => ');
        $bang = \strpos($m[4], ' =! ');
        if ($arrow === false && $bang === false) {
            return null;
        }

        $base = ['time' => $m[1], 'session' => $m[2], 'tool' => $m[3]];

        if ($arrow !== false && ($bang === false || $arrow < $bang)) {
            return [...$base, 'verdict' => 'allow', 'kind' => null, 'reason' => null];
        }

        $tail = (string) \substr($m[4], (int) $bang + 4);

        if (\preg_match('/^DENY (refused|unanswered|hook): (.*)$/s', $tail, $d) === 1) {
            return [...$base, 'verdict' => 'deny', 'kind' => $d[1], 'reason' => $d[2]];
        }

        if (\preg_match('/^WITHHELD(?: "([^"]*)")?: (.*)$/s', $tail, $w) === 1) {
            $hook = ($w[1] ?? '') === '' ? null : $w[1];

            return [...$base, 'verdict' => 'withheld', 'kind' => $hook, 'reason' => $w[2]];
        }

        return null;
    }

    /**
     * The last $count complete (newline-terminated) lines of $path, oldest
     * first, read from a bounded backward window.
     *
     * @return ?list<string> null when the path exists but could not be opened
     */
    private static function lastCompleteLines(string $path, int $count): ?array
    {
        if ($count <= 0) {
            return [];
        }

        // The audit trail is appended by THIS very process, and PHP caches
        // filesize() results across calls — a cached size would silently
        // window the newest records out of every tail. Drop the cache for
        // this path before asking how big the file is.
        \clearstatcache(true, $path);
        $size = @\filesize($path);
        if ($size === false) {
            return []; // absent: an empty trail, not a failure
        }

        // Walk back in growing windows until the window either reaches the
        // file start or begins just after a newline — only then does it hold
        // whole records from its first byte, which is the law Serve::tail()
        // enforces by dropping what its fixed window cut.
        $start = \max(0, $size - self::WINDOW_BYTES);
        $span = \min($size, self::WINDOW_BYTES);
        while ($start > 0 && self::window($path, $start - 1, 1) !== "\n") {
            $span = \min($size, $span * 4);
            $start = $size - $span;
        }

        $text = self::window($path, $start, (int) $size - $start);
        if ($text === null) {
            return null;
        }

        // explode() on text ending at EOF: the piece after the last newline
        // is either '' (the file ended cleanly) or a torn half-record — both
        // go, and only newline-terminated records survive.
        $lines = \explode("\n", $text);
        \array_pop($lines);

        $complete = [];
        foreach ($lines as $line) {
            if ($line !== '') {
                $complete[] = $line;
            }
        }

        return \array_slice($complete, -$count);
    }

    /**
     * $length bytes of $path from $offset, or null when the file could not
     * be opened. A directory or symlink is refused before it is opened, the
     * way `Serve::tail()` refuses anything but a regular file.
     */
    private static function window(string $path, int $offset, int $length): ?string
    {
        if ($offset < 0 || $length <= 0) {
            return '';
        }

        $stat = @\lstat($path);
        if ($stat === false || ((int) $stat['mode'] & 0o170000) !== 0o100000) {
            return $stat === false ? '' : null;
        }

        $handle = @\fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            if (\fseek($handle, $offset) !== 0) {
                return null;
            }

            return (string) \stream_get_contents($handle, $length);
        } finally {
            \fclose($handle);
        }
    }

    /**
     * The verdict words {@see tail()} can emit — the wire schema's enum and
     * the tests' roster both read it here so a fourth shape must be decided
     * twice, on purpose.
     *
     * @return list<string>
     */
    public static function verdicts(): array
    {
        return self::VERDICTS;
    }
}
