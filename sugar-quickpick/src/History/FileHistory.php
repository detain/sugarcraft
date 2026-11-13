<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick\History;

use SugarCraft\QuickPick\FilteredItem;
use SugarCraft\QuickPick\Item;

/**
 * File-backed persistent history for QuickPick items.
 *
 * Stores items as JSON-encoded lines in a flat file, one item per line.
 * Each line encodes the item's number and value.
 *
 * Trust contract (history files are frequently shared, world-writable on
 * multi-user boxes, and their contents flow straight back into terminal
 * output via {@see \SugarCraft\QuickPick\QuickPick::view()}, where the
 * render ingress guards them — but the FILE side still needs its own):
 *
 *  - Files this instance CREATES are opened exclusively (fopen 'x') and
 *    chmod'ed 0600 BEFORE the first payload line rides the inode. The chmod
 *    is best-effort, but the effect is no longer unobserved: {@see
 *    self::verifyHandle()} re-checks the mode on the open descriptor, so a
 *    refused tightening fails LOUD instead of publishing an umask-wide file.
 *  - A symlinked history path is refused by every operation, and every
 *    successful fopen is re-verified against the OPEN descriptor (dev+inode
 *    identity vs a fresh lstat of the path, private ownership on non-create
 *    arms): an attacker who plants `/tmp/quickpick.jsonl` as a link before
 *    the victim's first append gets the write refused either by the pre-open
 *    check or by the post-open identity check. The residual race window runs
 *    only from the pre-open check to the identity check — path-only checks
 *    cannot close it, fd-based verification can shrink it. (Construction
 *    with $baseDir adds the confine jail; see {@see self::__construct()}.)
 *  - append() fails LOUD ({@see \RuntimeException}) instead of silently
 *    dropping data; a PHP fopen warning printed into a live TUI frame is
 *    worse than useless.
 *  - {@see self::clear()} truncates in place under an exclusive lock rather
 *    than unlinking, so a concurrent writer's new file cannot be swapped
 *    underneath the clear.
 *
 * On shared hosts pass a private $baseDir (e.g. your home directory) so the
 * confinement jail also applies: see {@see self::__construct()}.
 */
final class FileHistory
{
    /** @var string */
    private string $path;

    /**
     * @param string      $path    Path to the history file (created lazily on first append(),
     *                             exclusive-open then a best-effort chmod to 0600 before
     *                             payload; a symlink at this path is refused).
     * @param string|null $baseDir When non-null, $path is confined beneath this
     *                             directory: any path that resolves outside it
     *                             (via `..` traversal, an absolute path elsewhere,
     *                             or a symlinked parent) is rejected, as is a
     *                             symlink standing AT the leaf path. Pass null to
     *                             keep the legacy unconfined behaviour.
     *
     * @throws \InvalidArgumentException when $baseDir is set and $path escapes it
     *                                  or the confined leaf is a symlink
     */
    public function __construct(string $path, ?string $baseDir = null)
    {
        $this->path = $baseDir === null ? $path : self::confine($path, $baseDir);
    }

    /**
     * Confine $path beneath $baseDir, rejecting any path that escapes it.
     *
     * The base directory must already exist so it can be resolved with realpath()
     * (collapsing symlinks + `..`). The history file itself need not exist yet —
     * append() creates it — so confinement is enforced against the file's PARENT
     * directory, which must resolve to $baseDir or a directory beneath it. A
     * non-existent parent is rejected: append()'s fopen('a') never creates
     * intermediate directories, so there is no legitimate writable target there.
     * A SYMLINK AT THE LEAF is rejected too: checking only the parent lets an
     * attacker plant base/confined.jsonl as a link to a file OUTSIDE the jail,
     * which every later operation would then happily follow (review M17 —
     * the parent jail is meaningless without the leaf check).
     *
     * @throws \InvalidArgumentException when $baseDir is missing, $path escapes it,
     *                                   or the confined leaf is a symlink
     */
    private static function confine(string $path, string $baseDir): string
    {
        $realBase = \realpath($baseDir);
        if ($realBase === false || \is_dir($realBase) === false) {
            throw new \InvalidArgumentException(
                \sprintf('QuickPick history base directory does not exist: %s', $baseDir),
            );
        }

        $absolute = self::isAbsolute($path)
            ? $path
            : $realBase . \DIRECTORY_SEPARATOR . $path;

        $realDir = \realpath(\dirname($absolute));
        $prefix  = $realBase . \DIRECTORY_SEPARATOR;
        if ($realDir === false
            || ($realDir !== $realBase && \strncmp($realDir . \DIRECTORY_SEPARATOR, $prefix, \strlen($prefix)) !== 0)) {
            throw new \InvalidArgumentException(
                \sprintf('QuickPick history path escapes its base directory %s: %s', $baseDir, $path),
            );
        }

        $confined = $realDir . \DIRECTORY_SEPARATOR . \basename($absolute);
        if (\is_link($confined)) {
            throw new \InvalidArgumentException(
                \sprintf('QuickPick history path is a symlink and was refused: %s', $confined),
            );
        }

        return $confined;
    }

    private static function isAbsolute(string $path): bool
    {
        return $path !== '' && (
            $path[0] === '/'
            || $path[0] === '\\'
            || (\strlen($path) > 1 && \ctype_alpha($path[0]) && $path[1] === ':')
        );
    }

    /**
     * Refuse to operate through a symlink planted at the history path.
     *
     * Checked on every operation — not just at construction — because the
     * window that matters is between here and the first write: an attacker
     * with access to a world-writable /tmp location can plant the link after
     * the program started.
     *
     * @throws \RuntimeException when the path is currently a symlink
     */
    private function rejectSymlink(): void
    {
        if (\is_link($this->path)) {
            throw new \RuntimeException(
                \sprintf('Refusing to follow symlinked QuickPick history file: %s', $this->path),
            );
        }
    }

    /**
     * Verify a freshly-opened handle still points at a file we trust.
     *
     * Path-based checks (rejectSymlink, the confine jail) can only speak for
     * the moment they run; between a check and an fopen BY PATH an attacker
     * can swap the target. fstat() on the OPEN descriptor is immune: it
     * reports the inode the descriptor actually landed on, so a swap before
     * the open — or a symlink standing at the path — surfaces as an identity
     * mismatch, and a swap after the open cannot redirect the descriptor.
     *
     *  - (a) identity: the handle's device+inode must equal a fresh lstat of
     *    the path. lstat (not stat) is deliberate: a symlink at the path
     *    reports the LINK's inode, never the target's. Arms on filesystems
     *    that report a zeroed identity (some NFS mounts) skip (a) rather
     *    than refuse honest files.
     *  - (b) trust: on non-create arms the inode must belong to this user
     *    AND carry no group/other bits — a hostile 0644 pre-plant on a
     *    shared /tmp receives nothing, exactly like a link. The create arm
     *    reaches that state by construction (exclusive open, chmod 0600
     *    before payload); if the tightening is refused there, this check
     *    throws instead of publishing the file.
     *
     * Windows skips both arms: PHP's dev/ino reporting there is unreliable
     * and POSIX mode/uid semantics do not exist. The suite's CI floor is
     * Linux; this is POSIX strengthening, not cross-platform law.
     *
     * @param resource $handle
     *
     * @throws \RuntimeException on identity mismatch, foreign ownership, or
     *                           a group/other-accessible mode
     */
    private function verifyHandle($handle, string $op): void
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            return;
        }

        $fd   = \fstat($handle);
        \clearstatcache(true, $this->path);
        $path = \lstat($this->path);

        if ($fd === false || $path === false) {
            throw new \RuntimeException(
                \sprintf('Cannot verify QuickPick history file during %s: %s', $op, $this->path),
            );
        }

        $identityKnown = ($fd['ino'] !== 0 || $fd['dev'] !== 0) && ($path['ino'] !== 0 || $path['dev'] !== 0);
        if ($identityKnown && ($fd['dev'] !== $path['dev'] || $fd['ino'] !== $path['ino'])) {
            throw new \RuntimeException(
                \sprintf('QuickPick history handle does not match its path during %s (possible symlink swap): %s', $op, $this->path),
            );
        }

        if ($fd['uid'] !== \getmyuid() || ($fd['mode'] & 0077) !== 0) {
            throw new \RuntimeException(
                \sprintf('QuickPick history file is not private to this user during %s: %s (uid %d, mode %o)', $op, $this->path, $fd['uid'], $fd['mode'] & 0777),
            );
        }
    }

    /**
     * Append an item to the history file.
     *
     * Creates the file exclusively when it does not exist yet and chmod's it
     * 0600 before the first payload line; the chmod is best-effort (its
     * failure is suppressed), so the effective creation mode stays
     * umask-dependent wherever the tightening is refused. Values carrying
     * invalid UTF-8 are substituted (U+FFFD) rather than
     * crashing the writer — history is best-effort storage, the frame must
     * not die over one malformed byte.
     *
     * @throws \RuntimeException when the path is a symlink, the file cannot
     *                           be opened, the opened handle fails
     *                           {@see self::verifyHandle()}, the exclusive
     *                           lock cannot be taken, or the line cannot be
     *                           fully written (silent loss was the old
     *                           failure mode)
     */
    public function append(Item $item): void
    {
        $this->rejectSymlink();

        $line = \json_encode(
            ['n' => $item->number(), 'v' => $item->value()],
            \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE,
        ) . "\n";

        $creating = !\file_exists($this->path);
        $handle = false;
        if ($creating) {
            // Exclusive create: 'x' refuses (returns false) if anything
            // raced us to the path. What it cannot promise is WHICH inode
            // a later fopen('a') lands on — that is verifyHandle's job.
            $handle = @\fopen($this->path, 'x');
            if ($handle === false && \is_link($this->path)) {
                throw new \RuntimeException(
                    \sprintf('Refusing to follow symlinked QuickPick history file: %s', $this->path),
                );
            }
        }
        if ($handle === false) {
            $creating = false;
            $handle = @\fopen($this->path, 'a');
        }
        if ($handle === false) {
            throw new \RuntimeException(
                \sprintf('Cannot open QuickPick history file for append: %s', $this->path),
            );
        }

        try {
            if ($creating) {
                // Tighten the freshly created inode BEFORE any payload rides
                // it; rename/chmod-after-publish windows are exactly what a
                // shared /tmp must not offer.
                @\chmod($this->path, 0600);
            }
            $this->verifyHandle($handle, 'append');
            if (\flock($handle, \LOCK_EX) === false) {
                throw new \RuntimeException(
                    \sprintf('Cannot lock QuickPick history file: %s', $this->path),
                );
            }
            $written = \fwrite($handle, $line);
            \flock($handle, \LOCK_UN);
            if ($written !== \strlen($line)) {
                throw new \RuntimeException(
                    \sprintf('Short write to QuickPick history file: %s', $this->path),
                );
            }
        } finally {
            \fclose($handle);
        }
    }

    /**
     * Read all items from the history file.
     *
     * Lines that are not decodable JSON OBJECTS carrying a usable "v" value
     * are skipped — a bare scalar, array, or object without "v" is not an
     * entry that was ever written here, and minting FilteredItem(0, '')
     * ghosts out of it hides corpus corruption behind phantom rows. The
     * file handle is always closed, even if an exception escapes during
     * decoding.
     *
     * @return list<Item>
     *
     * @throws \RuntimeException when the path is a symlink (reading through
     *                           a planted link is how forged control strings
     *                           get delivered into the terminal) or the
     *                           opened handle fails {@see self::verifyHandle()}
     */
    public function all(): array
    {
        $this->rejectSymlink();

        if (\is_file($this->path) === false) {
            return [];
        }

        $handle = @\fopen($this->path, 'r');
        if ($handle === false) {
            return [];
        }

        $items = [];
        try {
            $this->verifyHandle($handle, 'read');
            \flock($handle, \LOCK_SH);
            while (($line = \fgets($handle)) !== false) {
                $line = \trim($line);
                if ($line === '') {
                    continue;
                }
                try {
                    $decoded = \json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue; // not JSON at all
                }
                if (\is_array($decoded) === false || \array_key_exists('v', $decoded) === false) {
                    continue; // decodable but never a written entry shape
                }
                $value = $decoded['v'];
                if (\is_string($value) === false && \is_int($value) === false) {
                    continue; // objects/arrays/null/bool are not item values
                }
                $items[] = new FilteredItem((int) ($decoded['n'] ?? 0), (string) $value);
            }
            \flock($handle, \LOCK_UN);
        } finally {
            \fclose($handle);
        }

        return $items;
    }

    /**
     * Clear the history file.
     *
     * Truncates in place under an exclusive lock instead of unlinking: a
     * concurrent append() either holds the lock and its data survives the
     * wait, or arrives after the truncate — but the unlink-then-recreate
     * shape could destroy (or be redirected through) a file the clearer
     * never owned.
     *
     * @throws \RuntimeException when the path is a symlink, the file
     *                           exists but cannot be opened for writing, or
     *                           the opened handle fails
     *                           {@see self::verifyHandle()}
     */
    public function clear(): void
    {
        $this->rejectSymlink();

        if (\is_file($this->path) === false) {
            return;
        }

        $handle = @\fopen($this->path, 'r+');
        if ($handle === false) {
            throw new \RuntimeException(
                \sprintf('Cannot open QuickPick history file to clear: %s', $this->path),
            );
        }

        try {
            $this->verifyHandle($handle, 'clear');
            \flock($handle, \LOCK_EX);
            \ftruncate($handle, 0);
            \flock($handle, \LOCK_UN);
        } finally {
            \fclose($handle);
        }
    }

    /**
     * Get the path to the history file.
     *
     * When constructed with a $baseDir this is the CONFINED absolute path
     * (symlinks in the parent collapsed by realpath), not the raw argument.
     */
    public function path(): string
    {
        return $this->path;
    }
}
