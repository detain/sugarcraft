<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools;

use SugarCraft\Crush\Tools\BuiltIn\ApplyPatch;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\BuiltIn\Write;
use SugarCraft\Crush\Tools\Catalog\ToolBuildContext;

/**
 * The session's read ledger (roadmap 3.I-2): for every file the model has
 * seen, the state it was in when it saw it — modification time, size, inode
 * and a content hash — so an Edit or an overwriting Write can tell that the
 * file changed on disk afterwards and refuse to act on a stale picture of it.
 *
 * WHY. Edit's `old_string` proves the model saw SOME version of the text it
 * replaces, not the current one: a formatter, a `git checkout`, the user's
 * own editor or the model's own `sed` through Bash can rewrite everything
 * around that text between the Read and the Edit, and the edit then lands in
 * a file the model is reasoning about wrongly. Write's overwrite flag carries
 * no evidence at all. opencode's description CLAIMS read-before-edit and
 * enforces nothing; Zed compares the file's mtime with the read time; Claude
 * Code refuses with "modified since read". This is the last of those, keyed on
 * content rather than on the clock alone (crush_report 3.I, 02-opencode,
 * 07-zed).
 *
 * WHAT IS RECORDED, AND WHEN.
 *  - {@see Read} records the file it paged, hashing the very bytes its
 *    line-count pass read ({@see observe()}).
 *  - {@see Edit}, {@see Write} and {@see ApplyPatch} record the bytes they
 *    wrote ({@see record()}), so the model's own change never reads as
 *    somebody else's on its next call; a file ApplyPatch deleted or moved
 *    away is dropped ({@see forget()}).
 * A file the model never read has no entry and is never refused: staleness
 * is about a picture going out of date, not a read-before-write rule.
 *
 * HOW "CHANGED" IS DECIDED. Content, not the clock: an Edit or Write already
 * holds the current bytes, so it compares their hash with the recorded one
 * ({@see staleness()}), and a `touch` or an atomic rewrite of identical bytes
 * is not a change. Only without a recorded hash (a file read before it grew
 * past {@see HASH_MAX_BYTES}) or without current bytes (an overwrite whose old
 * contents were too large to read) does the stat signature decide alone.
 *
 * ACROSS THE FORKS. The ledger is session state living outside the tools'
 * readonly properties, so it crosses both process boundaries a read can sit
 * behind: a parallel Read child hands it home through
 * {@see CarriesSessionState} (Read exports {@see toArray()} under
 * {@see SESSION_STATE_KEY}), and the turn child hands the whole ledger to the
 * TUI parent on its result frame
 * ({@see \SugarCraft\Crush\Backend\EngineBackend}'s `readLedger` key), which
 * is what lets it outlive the turn that recorded it. Both payloads are plain
 * arrays, and {@see merge()} is order-independent: per path the entry
 * recorded LAST wins, so applying an export twice, or two children's exports
 * in either order, lands on the same ledger.
 *
 * ONE PER TOOL BUILD. Read, Edit and Write must share an instance or a read
 * through one would be invisible to the others; {@see forContext()} hands
 * every tool built from the same {@see ToolBuildContext} the same ledger,
 * which is the same scope the instruction loader and nudge trackers have. A
 * tool constructed without one keeps its pre-3.I-2 behaviour exactly.
 *
 * BOUNDED: at most {@see MAX_ENTRIES} paths, the least recently recorded
 * evicted first, so neither the result frame nor the notice scan grows with a
 * long session.
 */
final class ReadLedger
{
    /** The key Read's {@see CarriesSessionState} export carries the ledger under. */
    public const SESSION_STATE_KEY = 'readLedger';

    /** Most paths held; the least recently recorded go first. */
    public const MAX_ENTRIES = 256;

    /**
     * Largest file {@see changedSinceRead()} re-hashes to tell a real change
     * from a touch. Past it a moved stat signature counts as a change.
     */
    public const HASH_MAX_BYTES = 8 * 1024 * 1024;

    /** Most paths {@see notice()} names before it summarises the rest. */
    public const MAX_NOTICE_PATHS = 10;

    /** The digest every entry is keyed by: fast, and collisions are not an attack surface here. */
    private const ALGO = 'xxh128';

    /**
     * Canonical path => what the model last saw of it.
     *
     * @var array<string, array{mtime: int, size: int, ino: int, hash: ?string, at: float}>
     */
    private array $entries = [];

    /** @var \WeakMap<ToolBuildContext, self>|null */
    private static ?\WeakMap $byContext = null;

    public static function new(): self
    {
        return new self();
    }

    /**
     * The ledger every tool built from $context shares, created on first ask.
     * Keyed weakly, so a discarded tool build takes its ledger with it.
     */
    public static function forContext(ToolBuildContext $context): self
    {
        self::$byContext ??= new \WeakMap();

        return self::$byContext[$context] ??= new self();
    }

    /**
     * The ledger $tools share, or null when none of them carries one.
     *
     * @param iterable<mixed> $tools
     */
    public static function in(iterable $tools): ?self
    {
        foreach ($tools as $tool) {
            if (($tool instanceof Read || $tool instanceof Edit || $tool instanceof Write || $tool instanceof ApplyPatch) && $tool->readLedger() !== null) {
                return $tool->readLedger();
            }
        }

        return null;
    }

    /**
     * Record what the model now holds of $path from a stat signature and the
     * hash of the bytes it saw — {@see Read}'s half, which hashes as it reads.
     * $hash null means "not hashed"; staleness then rests on the signature.
     */
    public function observe(string $path, int $mtime, int $size, int $ino, ?string $hash): void
    {
        $this->put(self::key($path), ['mtime' => $mtime, 'size' => $size, 'ino' => $ino, 'hash' => $hash, 'at' => microtime(true)]);
    }

    /**
     * Record $path as it is on disk now, whose bytes are $bytes — the writers'
     * half, called after their write landed so the model's own change is the
     * new baseline. Without $bytes the file is hashed when it is small enough.
     */
    public function record(string $path, ?string $bytes = null): void
    {
        $key = self::key($path);
        clearstatcache(true, $key);
        $stat = @stat($key);
        if ($stat === false) {
            return;
        }
        $hash = $bytes !== null ? hash(self::ALGO, $bytes) : self::hashFile($key, (int) $stat['size']);
        $this->put($key, ['mtime' => (int) $stat['mtime'], 'size' => (int) $stat['size'], 'ino' => (int) $stat['ino'], 'hash' => $hash, 'at' => microtime(true)]);
    }

    /**
     * Drop $path: the model itself deleted or moved it ({@see ApplyPatch}), so
     * its absence is not a change somebody else made behind its back.
     */
    public function forget(string $path): void
    {
        unset($this->entries[self::key($path)], $this->entries[$path]);
    }

    /** Whether the model has seen $path this session. */
    public function has(string $path): bool
    {
        return isset($this->entries[self::key($path)]);
    }

    /** A fresh hashing context in the ledger's algorithm, for {@see Read}'s pass over the file. */
    public static function hashContext(): \HashContext
    {
        return hash_init(self::ALGO);
    }

    /**
     * Why $path is stale — 'deleted' or 'modified' — or null when it is not,
     * including when the model never read it.
     *
     * $currentBytes is the file's content as the caller just read it, when it
     * has it: the comparison is then by hash, so only a real change counts.
     */
    public function staleness(string $path, ?string $currentBytes = null): ?string
    {
        $key = self::key($path);
        $entry = $this->entries[$key] ?? null;
        if ($entry === null) {
            return null;
        }
        clearstatcache(true, $key);
        $stat = @stat($key);
        if ($stat === false) {
            return 'deleted';
        }
        if ($currentBytes !== null && $entry['hash'] !== null) {
            return hash_equals($entry['hash'], hash(self::ALGO, $currentBytes)) ? null : 'modified';
        }

        return self::sameSignature($entry, $stat) ? null : 'modified';
    }

    /**
     * Every recorded path that is no longer what the model saw, most recently
     * read first, as path => 'deleted'|'modified'. A moved stat signature over
     * unchanged bytes (a touch, an atomic rewrite of the same content) is not
     * a change, and its entry is re-based so the next scan skips the hash.
     *
     * @return array<string, string>
     */
    public function changedSinceRead(): array
    {
        $changed = [];
        foreach ($this->entries as $path => $entry) {
            clearstatcache(true, $path);
            $stat = @stat($path);
            if ($stat === false) {
                $changed[$path] = 'deleted';

                continue;
            }
            if (self::sameSignature($entry, $stat)) {
                continue;
            }
            $size = (int) $stat['size'];
            if ($entry['hash'] !== null && $size === $entry['size']) {
                $hash = self::hashFile($path, $size);
                if ($hash !== null && hash_equals($entry['hash'], $hash)) {
                    $this->entries[$path] = ['mtime' => (int) $stat['mtime'], 'size' => $size, 'ino' => (int) $stat['ino']] + $entry;

                    continue;
                }
            }
            $changed[$path] = 'modified';
        }

        uksort($changed, fn (string $a, string $b): int => $this->entries[$b]['at'] <=> $this->entries[$a]['at']);

        return $changed;
    }

    /**
     * The turn-context paragraph naming the files that changed since the
     * model read them, or '' when none did. Paths are as recorded (canonical);
     * the caller's fence escapes them like every other payload.
     */
    public function notice(): string
    {
        $changed = $this->changedSinceRead();
        if ($changed === []) {
            return '';
        }

        $lines = ['Files changed on disk since you last read them (Read them again before editing; an Edit or overwrite of one is refused until you do):'];
        foreach (\array_slice($changed, 0, self::MAX_NOTICE_PATHS, true) as $path => $why) {
            $lines[] = '- ' . $path . ($why === 'deleted' ? ' (deleted)' : '');
        }
        $more = \count($changed) - self::MAX_NOTICE_PATHS;
        if ($more > 0) {
            $lines[] = "- … and {$more} more";
        }

        return implode("\n", $lines);
    }

    /**
     * The ledger as plain arrays, for a payload that is decoded with
     * `allowed_classes => false`.
     *
     * @return array<string, array{mtime: int, size: int, ino: int, hash: ?string, at: float}>
     */
    public function toArray(): array
    {
        return $this->entries;
    }

    /**
     * Fold a {@see toArray()} payload in: per path, the entry recorded last
     * wins. Malformed rows are skipped, never fatal — the payload crossed a
     * process boundary and may come from an older build.
     */
    public function merge(mixed $payload): void
    {
        if (!\is_array($payload)) {
            return;
        }
        foreach ($payload as $path => $row) {
            if (!\is_string($path) || $path === '' || !\is_array($row)) {
                continue;
            }
            $mtime = $row['mtime'] ?? null;
            $size = $row['size'] ?? null;
            $ino = $row['ino'] ?? null;
            $hash = $row['hash'] ?? null;
            $at = $row['at'] ?? null;
            if (!\is_int($mtime) || !\is_int($size) || !\is_int($ino) || !(\is_string($hash) || $hash === null) || !(\is_float($at) || \is_int($at))) {
                continue;
            }
            $current = $this->entries[$path] ?? null;
            if ($current !== null && $current['at'] >= (float) $at) {
                continue;
            }
            $this->put($path, ['mtime' => $mtime, 'size' => $size, 'ino' => $ino, 'hash' => $hash, 'at' => (float) $at]);
        }
    }

    /** @param array{mtime: int, size: int, ino: int, hash: ?string, at: float} $entry */
    private function put(string $key, array $entry): void
    {
        unset($this->entries[$key]);
        $this->entries[$key] = $entry;
        if (\count($this->entries) <= self::MAX_ENTRIES) {
            return;
        }
        // Evict by recording time, not by insertion order: a merge can insert
        // an older entry after a newer one.
        uasort($this->entries, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
        $this->entries = \array_slice($this->entries, \count($this->entries) - self::MAX_ENTRIES, null, true);
    }

    /**
     * @param array{mtime: int, size: int, ino: int, hash: ?string, at: float} $entry
     * @param array<int|string, mixed>                                        $stat
     */
    private static function sameSignature(array $entry, array $stat): bool
    {
        return (int) $stat['mtime'] === $entry['mtime']
            && (int) $stat['size'] === $entry['size']
            && (int) $stat['ino'] === $entry['ino'];
    }

    private static function hashFile(string $path, int $size): ?string
    {
        if ($size > self::HASH_MAX_BYTES || !is_file($path)) {
            return null;
        }
        $hash = @hash_file(self::ALGO, $path);

        return $hash === false ? null : $hash;
    }

    /** The canonical spelling of $path, so `./a`, `a` and a symlinked spelling share one entry. */
    private static function key(string $path): string
    {
        $real = @realpath($path);

        return $real === false ? $path : $real;
    }
}
