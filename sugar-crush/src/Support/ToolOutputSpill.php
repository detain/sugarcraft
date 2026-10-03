<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Util\TokenEstimate;

/**
 * Where a tool result that is over its budget keeps the bytes the model was not
 * shown (roadmap 2.8, spill-to-file).
 *
 * BEFORE THIS, OVER-BUDGET MEANT GONE. {@see \SugarCraft\Crush\Tools\Concerns\TruncatesOutput}
 * announced every cut honestly ("This result is PARTIAL") but the bytes it cut
 * were discarded, so the only way for the model to see line 4,000 of a build log
 * was to run the build again with a narrower command. A spilled result instead
 * carries a head-and-tail PREVIEW plus the path of a file holding everything the
 * tool captured, and the model pages through it with `Read` (`offset`/`limit`) or
 * searches it with `Grep` — the two tools it already uses for files.
 *
 * THE STORE. One per-user directory under the system temp directory,
 * `sc-tool-out-<euid>`, under {@see PrivateRetainedDir}'s rules: created `0700`,
 * refused (never written through) when it is a symlink, foreign-owned or loose,
 * files written `0600` through a `.partial` + `rename`. A file is first written at
 * the top level, because the tool that produced it runs without knowing the
 * session; {@see forModel()}, on the runtime's settle path where the session id
 * IS known, then moves it into `s-<session>/` and rewrites the path in the
 * result before the model reads it. So every pointer the model is handed names a
 * SESSION-SCOPED file.
 *
 * RETENTION. Seven days by modification time, swept at most once per process,
 * lazily, the first time this process spills ({@see sweepOnce()}). A file is not
 * deleted when the turn ends because the model may legitimately come back to it
 * several turns later, the same lifetime argument {@see HookContextFiles} makes.
 *
 * READING IT. A spill file lives outside every workspace root, so
 * {@see \SugarCraft\Crush\Tools\PathJail::resolve()} — the jail `Read` goes
 * through — consults {@see readablePath()} for a path that escapes the root: a
 * regular file named like a spill file, directly inside this user's verified
 * store or one of its session directories, resolves; nothing else outside the
 * root does. `Grep` asks the same question for a file path. `Edit` refuses one
 * (saved output is evidence, not source), and `Write`/`Glob` never see the
 * store at all (their jail methods are untouched).
 *
 * NAMES ARE UNGUESSABLE. `out-` + 128 bits of a SHA-256 over a per-process
 * random salt and the content: the allow-list grants a read on a name only this
 * process could have produced, and the same text spilled twice by one call (a
 * probe-then-final clip, see Grep and Glob) lands in one file rather than two.
 */
final class ToolOutputSpill
{
    /** Stem of the per-user store under `sys_get_temp_dir()`. */
    public const DIR_NAME = 'sc-tool-out';

    /** Seven days: how long a spilled file outlives its last write. */
    public const RETENTION_SECONDS = 7 * 86_400;

    /**
     * The smallest cap a tool-side spill is attempted at.
     *
     * The pointer sentence plus its path is a few hundred bytes. Below this a
     * spill would spend a visible share of a deliberately tiny cap on a path and
     * shrink the preview it accompanies, so a tool built with a cap this small
     * keeps the plain announced truncation it always had. Every shipped default
     * cap is far above it (64 KiB for the capped tools).
     */
    public const MIN_CAP_BYTES = 8192;

    /**
     * How much of a process's output a spilling tool captures (per stream):
     * 4 MiB. The cap decides what the model is SHOWN; this decides what the
     * saved file can hold. Bounded all the same, because the capture is held in
     * memory, and a `find /` has no natural end.
     */
    public const CAPTURE_BYTES = 4 * 1024 * 1024;

    /**
     * The largest share of the model's context window ONE tool result may take
     * before {@see forModel()} spills it, in percent. A result past it is a
     * third of the window replayed into every following request of the turn.
     */
    public const WINDOW_SHARE_PERCENT = 30;

    /**
     * Bytes of preview allowed per token of that share. Three, not the four an
     * ASCII token averages, because {@see TokenEstimate} prices a CJK character
     * (three bytes) at a whole token; three keeps the preview inside the share
     * for every script it weighs.
     */
    private const PREVIEW_BYTES_PER_TOKEN = 3;

    /** Tools whose result is the answer itself and is never spilled centrally. */
    private const EXEMPT_TOOLS = ['Task', 'Skill'];

    private const LABEL = 'tool output spill';

    private const FILE_PATTERN = '/^out-[0-9a-f]{32}\.txt$/';

    /** @var array{pid: int, salt: string}|null */
    private static ?array $salt = null;

    private static ?int $sweptBy = null;

    /**
     * Test seam: where the store lives. Null is the real per-user default. The
     * same shape as {@see ClipboardImage::useDirectoryForTesting()}: the real
     * path hangs off `sys_get_temp_dir()`, which PHP caches per process, so a
     * test that plants a hostile entry at the store's name needs its own path.
     */
    private static ?string $directoryOverride = null;

    private function __construct()
    {
    }

    /** This user's store path, whether or not it exists yet. */
    public static function directory(): string
    {
        return self::$directoryOverride ?? PrivateRetainedDir::forCurrentUser(self::DIR_NAME);
    }

    public static function useDirectoryForTesting(?string $directory): void
    {
        self::$directoryOverride = $directory;
        self::$sweptBy = null;
    }

    /**
     * Save $text and return the saved file's path, or null when nothing could be
     * saved (a refused or unwritable store: the caller then truncates exactly as
     * it would have without a store, and says nothing about a file).
     */
    public static function store(string $text): ?string
    {
        try {
            $dir = PrivateRetainedDir::verified(self::directory(), self::LABEL);
        } catch (\RuntimeException) {
            return null;
        }

        self::sweepOnce($dir);

        $name = 'out-' . substr(hash('sha256', self::salt() . "\0" . $text), 0, 32) . '.txt';
        $existing = @lstat($dir . '/' . $name);
        if ($existing !== false && (((int) $existing['mode']) & 0o170000) === 0o100000 && (int) $existing['size'] === strlen($text)) {
            // The same call already saved these bytes (a probe clip, then the
            // final one): one file, not two.
            return $dir . '/' . $name;
        }

        try {
            return PrivateRetainedDir::write($dir, $name, $text, self::LABEL);
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * The sentence that hands the model its saved output. Shared by the tool-side
     * spill and the central one so the model learns one signal.
     *
     * $neverCaptured is what the tool discarded BEFORE it could save anything (a
     * bounded process capture); the file cannot hold those bytes, and saying so
     * keeps "the full output is in the file" from being a false promise.
     */
    public static function pointer(string $path, int $savedBytes, int $neverCaptured = 0): string
    {
        return sprintf(
            '... [saved: the %d bytes this tool captured are in %s — Read it with offset/limit to page through it, '
            . 'or Grep that path, instead of re-running the call.%s]',
            $savedBytes,
            $path,
            $neverCaptured > 0 ? sprintf(' %d further bytes were never captured and are not in it.', $neverCaptured) : '',
        );
    }

    /**
     * The canonical path of $path when it is a spill file this process may let a
     * jailed reader open, or null.
     *
     * All of: the store exists and passes {@see PrivateRetainedDir::isTrusted()}
     * (checked without creating it — a question must not have a side effect);
     * $path resolves to a REGULAR file; that file sits directly in the store or
     * in one `s-*` session directory of it; and its name is a spill file's. A
     * `.partial`, a planted file of another shape, or a symlink pointing out of
     * the store all resolve elsewhere or fail the name, and answer null.
     */
    public static function readablePath(string $path): ?string
    {
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        $store = self::directory();
        if (!PrivateRetainedDir::isTrusted($store)) {
            return null;
        }

        $storeReal = realpath($store);
        $real = realpath($path);
        if ($storeReal === false || $real === false || !is_file($real)) {
            return null;
        }

        if (preg_match(self::FILE_PATTERN, basename($real)) !== 1) {
            return null;
        }

        $parent = \dirname($real);
        if ($parent === $storeReal) {
            return $real;
        }

        if (\dirname($parent) === $storeReal && str_starts_with(basename($parent), 's-')) {
            return $real;
        }

        return null;
    }

    /**
     * The result the model should read for $result: unspilled-file pointers
     * moved into the session's directory, and a result still over
     * {@see WINDOW_SHARE_PERCENT} of the context window spilled to a preview.
     *
     * Runs on {@see \SugarCraft\Crush\Runtime::settle()}'s path, AFTER the
     * PostToolUse hooks have observed the raw output, and BEFORE their notes are
     * appended (a hook's note is never cut off by this preview).
     *
     * The window cap is what makes the budget scale: a tool's own cap is a flat
     * byte figure chosen for a large window, while a model with an 8k window
     * cannot afford one 64 KiB result. Task and Skill are exempt — their result
     * IS the answer (a sub-agent's report, a skill's body), and a preview of it
     * would hide the thing the call existed to fetch. A `Read` of a spill file is
     * bounded WITHOUT a new spill, so paging a large saved file never chains into
     * saving it again.
     *
     * @param array<string, mixed> $arguments the call's arguments
     * @param \Closure(): int     $windowTokens asked only when the result is big enough to matter
     */
    public static function forModel(
        ToolResult $result,
        string $toolName,
        array $arguments,
        string $sessionId,
        \Closure $windowTokens,
    ): ToolResult {
        $content = self::adopt($result->content(), $sessionId);

        if (!\in_array($toolName, self::EXEMPT_TOOLS, true) && strlen($content) > 1024) {
            try {
                $window = $windowTokens();
            } catch (\Throwable) {
                $window = 0;
            }

            $limitTokens = $window > 0 ? intdiv($window * self::WINDOW_SHARE_PERCENT, 100) : 0;
            if ($limitTokens > 0 && strlen($content) > $limitTokens && TokenEstimate::ofText($content) > $limitTokens) {
                $readsSpill = $toolName === 'Read'
                    && is_string($arguments['file_path'] ?? null)
                    && self::readablePath($arguments['file_path']) !== null;

                $content = self::bound(
                    $content,
                    max(1024, $limitTokens * self::PREVIEW_BYTES_PER_TOKEN),
                    !$readsSpill,
                    $sessionId,
                );
            }
        }

        return $content === $result->content() ? $result : $result->withContent($content);
    }

    /**
     * $text cut to a head-and-tail preview within $cap bytes, followed by the
     * pointer to the saved whole (or, when $spill is false or nothing could be
     * saved, by a note that says what was left out and how to see less).
     */
    public static function bound(string $text, int $cap, bool $spill = true, string $sessionId = ''): string
    {
        if (strlen($text) <= $cap) {
            return $text;
        }

        $path = $spill ? self::store($text) : null;
        if ($path !== null && $sessionId !== '') {
            $path = self::moveIntoSession($path, $sessionId) ?? $path;
        }

        $note = $path !== null
            ? self::pointer($path, strlen($text))
            : sprintf(
                '... [window cap: %d bytes of this %d-byte result are shown, the start and the end; '
                . 'ask for less (a smaller Read limit, a narrower pattern) to see the middle.]',
                0,
                strlen($text),
            );
        $gap = "\n... [middle omitted]\n";

        $room = max(0, $cap - strlen($note) - strlen($gap) - 1);
        $head = mb_strcut($text, 0, intdiv($room * 3, 4), 'UTF-8');
        $tail = self::tailOf($text, $room - strlen($head));

        if ($path === null) {
            $note = (string) preg_replace('/window cap: \d+ bytes/', 'window cap: ' . (strlen($head) + strlen($tail)) . ' bytes', $note, 1);
        }

        return $head . $gap . $tail . "\n" . $note;
    }

    /**
     * At most $budget bytes from the END of $text, starting on a whole line when
     * the window holds a newline and never inside a UTF-8 sequence.
     */
    public static function tailOf(string $text, int $budget): string
    {
        $length = strlen($text);
        if ($budget <= 0) {
            return '';
        }
        if ($budget >= $length) {
            return $text;
        }

        $start = $length - $budget;
        $window = substr($text, $start);
        $newline = strpos($window, "\n");
        if ($newline !== false && $newline + 1 < strlen($window)) {
            return substr($window, $newline + 1);
        }

        // No line to align to: step forward past continuation bytes so the cut
        // lands on a sequence boundary, as mb_strcut does for a head.
        while ($start < $length && (\ord($text[$start]) & 0xC0) === 0x80) {
            $start++;
        }

        return substr($text, $start);
    }

    /**
     * $content with every top-level spill path in it moved into the session's
     * directory. A path that cannot be moved is left as it is — still a
     * readable spill file, only not a session-scoped one.
     */
    private static function adopt(string $content, string $sessionId): string
    {
        $store = self::directory();
        if ($sessionId === '' || !str_contains($content, $store . '/out-')) {
            return $content;
        }

        $pattern = '~' . preg_quote($store, '~') . '/out-[0-9a-f]{32}\.txt~';
        if (preg_match_all($pattern, $content, $found) < 1) {
            return $content;
        }

        foreach (array_unique($found[0]) as $pending) {
            $moved = self::moveIntoSession($pending, $sessionId);
            if ($moved !== null) {
                $content = str_replace($pending, $moved, $content);
            }
        }

        return $content;
    }

    /** Move one top-level spill file into the session directory; null on failure. */
    private static function moveIntoSession(string $path, string $sessionId): ?string
    {
        $store = self::directory();
        if (\dirname($path) !== $store || preg_match(self::FILE_PATTERN, basename($path)) !== 1) {
            return null;
        }

        try {
            $sessionDir = PrivateRetainedDir::verified($store . '/' . self::sessionDirName($sessionId), self::LABEL);
        } catch (\RuntimeException) {
            return null;
        }

        $target = $sessionDir . '/' . basename($path);
        if (@lstat($target) !== false) {
            @unlink($path);

            return $target;
        }

        return @rename($path, $target) ? $target : null;
    }

    /**
     * `s-<id>` for an id made of filename-safe characters (every id the session
     * store mints), `s-<hash>` for anything else, so an id can never name a path.
     */
    public static function sessionDirName(string $sessionId): string
    {
        return preg_match('/^[A-Za-z0-9_-]{1,100}$/', $sessionId) === 1
            ? 's-' . $sessionId
            : 's-' . substr(hash('sha256', $sessionId), 0, 32);
    }

    /**
     * Retention: the first spill of a process sweeps files older than
     * {@see RETENTION_SECONDS}. A forked child has its own pid and sweeps again
     * at most once — cheap (one directory listing) and never more than once per
     * process.
     */
    private static function sweepOnce(string $dir): void
    {
        $pid = getmypid() ?: 0;
        if (self::$sweptBy === $pid) {
            return;
        }

        self::$sweptBy = $pid;
        PrivateRetainedDir::sweep($dir, self::RETENTION_SECONDS, self::LABEL);
    }

    /**
     * The per-process salt, re-drawn in a forked child so sibling children never
     * share a name for identical output.
     */
    private static function salt(): string
    {
        $pid = getmypid() ?: 0;
        if (self::$salt === null || self::$salt['pid'] !== $pid) {
            self::$salt = ['pid' => $pid, 'salt' => bin2hex(random_bytes(16))];
        }

        return self::$salt['salt'];
    }
}
