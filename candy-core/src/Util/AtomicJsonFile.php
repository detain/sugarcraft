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
 */
final class AtomicJsonFile
{
    private function __construct(
        private readonly string $path,
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
        if (!is_dir($dir)) {
            // Race-safe: mkdir may fail because a concurrent writer just made it.
            if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new \RuntimeException("Cannot create state directory: {$dir}");
            }
        }

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

            if (!rename($tmp, $this->path)) {
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
