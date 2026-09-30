<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

/**
 * Atomic tmp+flock+rename JSON store; consolidates the durable-state save
 * pattern hand-rolled across candy-mines, candy-hermit, candy-metrics,
 * sugar-stash and 5+ other libs (each with subtly different locking /
 * cleanup / decode-guard behaviour).
 *
 * The persist contract: a reader must never observe a half-written file, so
 * we write to a sibling temp file in the SAME directory (rename is only
 * atomic within one filesystem) and rename it over the target — the rename
 * swaps the directory entry in a single syscall.
 *
 * Optional base-dir confinement guards callers that build the path from
 * untrusted components: the resolved target must stay inside $baseDir, and a
 * symlink at the final component pointing outside is rejected.
 *
 * {@see withPermissions()} is the second, independent axis: who may READ the
 * published state. Without it the file lands on whatever the process umask
 * happens to allow, so a caller storing tokens has to re-roll the
 * umask/chmod dance per library (phlix's TokenStore, sugar-crush's Session) or,
 * more often, forget it.
 */
final class AtomicJsonFile
{
    /**
     * Ceiling of plain permission bits. Setuid/setgid/sticky are out of this
     * class's vocabulary: a durable JSON file has no business carrying them.
     */
    private const MODE_CEILING = 0777;

    private function __construct(
        private readonly string $path,
        private readonly ?int $fileMode = null,
    ) {
    }

    /**
     * Canonical factory. When $baseDir is non-null the target is confined to
     * it (see class docblock); a path escaping $baseDir throws immediately so
     * a caller cannot be tricked into reading/writing outside the sandbox.
     * Confinement is resolved once here — the store then holds the collapsed,
     * proven-safe path.
     *
     * @throws \RuntimeException When $baseDir is set and $path escapes it.
     */
    public static function new(string $path, ?string $baseDir = null): self
    {
        if ($baseDir !== null) {
            $path = self::confine($path, $baseDir);
        }

        return new self($path);
    }

    /**
     * Derive a store that publishes with $mode permission bits, leaving this one
     * alone.
     *
     * The mode lands on the temp inode BEFORE the payload is written and
     * therefore before the rename that publishes it (see {@see write()}), which
     * is the security-critical ordering: rename carries the temp's mode onto the
     * target, so the published path never exists with looser bits than $mode and
     * a replace of an already-loose file tightens it in the same syscall.
     *
     * Unset — the default every existing consumer keeps — means "the umask
     * decides", exactly as before this method existed.
     *
     * Mirrors the 0600 secret-store discipline phlix hand-rolls in
     * `phlix-console-client/src/Config/TokenStore.php::persist()`.
     *
     * @throws \InvalidArgumentException When $mode is not plain permission bits.
     */
    public function withPermissions(int $mode): self
    {
        if ($mode < 0 || $mode > self::MODE_CEILING) {
            throw new \InvalidArgumentException(
                'AtomicJsonFile mode must be plain permission bits (0000..0777), got '
                . \sprintf('%o', $mode) . ' for ' . $this->path,
            );
        }

        return new self($this->path, $mode);
    }

    /**
     * The permission bits this store publishes with, or null when the umask
     * decides.
     */
    public function permissions(): ?int
    {
        return $this->fileMode;
    }

    /**
     * Absolute (or caller-supplied) path this store persists to.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Whether the target file currently exists on disk.
     */
    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * Read + decode the stored state.
     *
     * A missing file is the empty state — returns `[]` rather than throwing,
     * so first-run callers need no existence dance. A present-but-corrupt file
     * (malformed JSON, or a non-array top level) throws loudly: silently
     * treating garbage as `[]` would mask real corruption and drop live data
     * on the next write.
     *
     * @return array<mixed>
     *
     * @throws \RuntimeException On read failure or a non-array top level.
     * @throws \JsonException    On malformed JSON.
     */
    public function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            throw new \RuntimeException("Failed to read state file: {$this->path}");
        }

        return Json::decodeArray($raw);
    }

    /**
     * Atomically persist $data.
     *
     * Writes to a uniquely-named temp file in the target's own directory under
     * an exclusive lock, flushes to the OS, then renames it over the target so
     * a concurrent reader sees either the old or the new file, never a torn
     * one. The parent dir is created 0700 (not 0755): durable state may hold
     * tokens/history and should not be world-readable. On any failure the temp
     * file is removed before the exception propagates.
     *
     * When {@see withPermissions()} set a mode, the temp inode is chmod'ed to it
     * immediately after creation — before a single payload byte is written — so
     * neither the temp nor the published target ever carries looser bits than
     * asked. Nothing is chmod'ed after the rename: the published file IS the
     * temp, mode and all.
     *
     * JSON is encoded pretty-printed with unescaped slashes so the on-disk
     * state stays human-diffable (these files are read in PRs / by operators).
     *
     * @param array<mixed> $data
     *
     * @throws \RuntimeException On any filesystem failure.
     * @throws \JsonException    When $data cannot be JSON-encoded.
     */
    public function write(array $data): void
    {
        $dir = \dirname($this->path);
        $this->ensureDirectory($dir);

        $payload = json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        $tmp = $dir . \DIRECTORY_SEPARATOR . '.' . basename($this->path) . '.tmp.' . bin2hex(random_bytes(8));

        $handle = @fopen($tmp, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Failed to open temp file: {$tmp}");
        }

        try {
            $this->applyPermissions($tmp);

            if (!flock($handle, \LOCK_EX)) {
                throw new \RuntimeException("Failed to lock temp file: {$tmp}");
            }

            if (fwrite($handle, $payload) === false) {
                throw new \RuntimeException("Failed to write temp file: {$tmp}");
            }

            fflush($handle);
            flock($handle, \LOCK_UN);
            fclose($handle);
            $handle = null;

            // @-silenced like every other syscall here: the RuntimeException
            // below is the single loud failure. A bare rename() ALSO prints a
            // raw PHP warning, double-reporting on stderr — and under this
            // lib's failOnWarning="true" it would redden any test that
            // exercises the publish-failure path. The structural pin in
            // AtomicJsonFileTest matches the call by substring, so the '@'
            // prefix keeps it green.
            if (!@rename($tmp, $this->path)) {
                throw new \RuntimeException("Failed to rename temp file onto: {$this->path}");
            }
        } catch (\Throwable $e) {
            if (\is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($tmp)) {
                @unlink($tmp);
            }

            throw $e;
        }
    }

    /**
     * Create the parent directory when missing; never touch one that exists.
     *
     * The default mode is 0700 — durable state may hold tokens or history and
     * should not be world-readable. A caller who asked for a wider FILE mode
     * gets a directory that can actually be traversed by the same audience
     * ({@see directoryMode()}), re-asserted with chmod because mkdir's mode is
     * filtered by the process umask. That re-assertion runs only on a directory
     * THIS call created: re-permissioning a directory someone else made is not
     * this class's business, and a caller wanting a pre-existing shared dir
     * group-readable sets it up themselves.
     */
    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        // Race-safe: mkdir may fail because a concurrent writer just made it.
        $created = @mkdir($dir, $this->directoryMode(), true);
        if (!$created && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create state directory: {$dir}");
        }

        if (!$created || $this->fileMode === null) {
            return;
        }

        $mode = $this->directoryMode();
        if (!@chmod($dir, $mode)) {
            throw new \RuntimeException(
                'Cannot set mode ' . \sprintf('%04o', $mode) . " on state directory: {$dir}"
            );
        }
    }

    /**
     * Settle the requested permission bits on the temp inode.
     *
     * Runs before the payload is written and long before the publish rename, so
     * the secret bytes never sit in an inode the caller did not authorize. This
     * is a no-op when no mode was requested, which keeps every pre-existing
     * consumer's on-disk bits umask-derived, byte for byte.
     *
     * @throws \RuntimeException When the mode cannot be applied — a caller who
     *                           asked for 0600 and did not get it must learn
     *                           now, not after the file ships world-open.
     */
    private function applyPermissions(string $tmp): void
    {
        if ($this->fileMode === null) {
            return;
        }

        if (!@chmod($tmp, $this->fileMode)) {
            throw new \RuntimeException(
                'Cannot set mode ' . \sprintf('%04o', $this->fileMode) . " on temp state file: {$tmp}"
            );
        }
    }

    /**
     * Directory bits matching the current file mode: every read class also gets
     * its execute bit, because a directory nobody can traverse is no directory
     * at all (0600 → 0700, 0640 → 0750, 0644 → 0755).
     */
    private function directoryMode(): int
    {
        if ($this->fileMode === null) {
            return 0700;
        }

        $mode = $this->fileMode;
        if (($mode & 0400) !== 0) {
            $mode |= 0100;
        }
        if (($mode & 0040) !== 0) {
            $mode |= 0010;
        }
        if (($mode & 0004) !== 0) {
            $mode |= 0001;
        }

        return $mode;
    }

    /**
     * Ensure $path resolves inside $baseDir.
     *
     * The target file itself may not exist yet, so we cannot realpath() it
     * directly. Instead we realpath the PARENT dir (which must exist — the
     * escape vector is a `..` or symlinked parent) and confirm it stays under
     * the resolved base. Parent-realpath ALONE is insufficient: an attacker
     * can pre-plant a symlink AT the final component pointing outside the
     * base, so we additionally reject a symlinked final component whose target
     * escapes. (Lesson carried from the Sanitize path-confinement hardening.)
     *
     * @throws \RuntimeException When $baseDir cannot be resolved or $path escapes it.
     */
    private static function confine(string $path, string $baseDir): string
    {
        $realBase = realpath($baseDir);
        if ($realBase === false) {
            throw new \RuntimeException("Base directory does not exist: {$baseDir}");
        }
        $realBase = rtrim($realBase, \DIRECTORY_SEPARATOR);

        $parent = \dirname($path);
        $realParent = realpath($parent);
        if ($realParent === false) {
            // Parent must already exist to be confined; a not-yet-created
            // parent cannot be proven inside the base.
            throw new \RuntimeException("Parent directory does not exist: {$parent}");
        }

        $prefix = $realBase . \DIRECTORY_SEPARATOR;
        if ($realParent !== $realBase && !str_starts_with($realParent . \DIRECTORY_SEPARATOR, $prefix)) {
            throw new \RuntimeException("Path escapes base directory: {$path}");
        }

        // Re-anchor to the resolved parent so downstream ops act on the real,
        // symlink-collapsed directory rather than the caller's `..`-laden path.
        $resolved = $realParent . \DIRECTORY_SEPARATOR . basename($path);

        // A pre-planted symlink at the final component can still point out of
        // the base even though its parent is confined — reject that.
        if (is_link($resolved)) {
            $linkReal = realpath($resolved);
            if ($linkReal === false) {
                throw new \RuntimeException("Dangling symlink at target: {$resolved}");
            }
            if ($linkReal !== $realBase && !str_starts_with($linkReal . \DIRECTORY_SEPARATOR, $prefix)) {
                throw new \RuntimeException("Symlinked target escapes base directory: {$resolved}");
            }
        }

        return $resolved;
    }
}
