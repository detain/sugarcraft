<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot;

/**
 * Output writer that forwards chunks straight to a file handle as they
 * arrive. Standalone helper — the renderers return complete bytes and
 * never write through this class themselves.
 *
 * Honesty about durability (candy-codeshot-051): constructing this writer
 * opens the target with mode 'w', which TRUNCATES an existing file
 * immediately — before a single {@see write()} call happens. A failure in
 * the middle of streaming therefore leaves a partial file on disk where the
 * old content used to be; nothing is restored. There is deliberately no
 * temp-file-plus-rename machinery here: the CLI's single write pass either
 * completes or reports a short-write RuntimeException, and callers that
 * need atomic replacement must provide it themselves. The refusal pre-checks
 * in {@see refuseUnopenablePath()} name common open failures before fopen()
 * emits its E_WARNING; they are diagnostics, not a confinement capability —
 * path confinement lives in the CLI jail (see the --unsafe-paths docs), and
 * this class cannot see over the caller's shoulder.
 */
final class FileOutputWriter implements OutputWriter
{
    private string $path;

    /** @var resource|null */
    private $fp;

    public function __construct(string $path)
    {
        $this->path = $path;
        // E739: fopen() reports an unopenable path with an E_WARNING *before* it returns
        // false, so the refusal below used to arrive after the noise — under this library's
        // failOnWarning gate that made the honest door look like a defect, and in a log
        // pipeline it put a PHP warning ahead of the message that explains it. The shapes a
        // write-open can fail in are enumerable, so name them loudly here and leave fopen()
        // for the bytes. The === false guard stays as the fail-closed net for whatever the
        // pre-checks cannot see (a permission flip in between, a read-only remount, a
        // symlink racing out from under dirname()); a warning there is a real surprise.
        $this->refuseUnopenablePath($path);

        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new \RuntimeException("Failed to open file for writing: {$path}");
        }

        $this->fp = $handle;
    }

    /**
     * @throws \RuntimeException when no write-open on $path can possibly succeed
     */
    private static function refuseUnopenablePath(string $path): void
    {
        $directory = dirname($path);

        // candy-codeshot-051: fopen('w') follows symlinks, and every check
        // below follows the link too — a path that IS a symlink would pass
        // all of them while truncating whatever it points at, anywhere the
        // process can write. The output directory being writable is no
        // defence when the victim lives outside it, so refuse the link
        // itself before anything dereferences it.
        if (is_link($path)) {
            throw new \RuntimeException("Failed to open file for writing: {$path} (path is a symbolic link)");
        }

        if (is_dir($path)) {
            throw new \RuntimeException("Failed to open file for writing: {$path} (path is a directory)");
        }

        if (!is_dir($directory)) {
            throw new \RuntimeException("Failed to open file for writing: {$path} (directory does not exist: {$directory})");
        }

        if (!is_writable($directory)) {
            throw new \RuntimeException("Failed to open file for writing: {$path} (directory is not writable: {$directory})");
        }

        if (file_exists($path) && !is_writable($path)) {
            throw new \RuntimeException("Failed to open file for writing: {$path} (file is not writable)");
        }
    }

    public function write(string $chunk): void
    {
        if ($this->fp === null) {
            throw new \LogicException("FileOutputWriter: write() called after close(): {$this->path}");
        }
        $written = fwrite($this->fp, $chunk);
        if ($written === false || $written !== strlen($chunk)) {
            throw new \RuntimeException("FileOutputWriter: short write to {$this->path}");
        }
    }

    /**
     * Push buffered bytes to disk. Unlike {@see write()}, flush() on an already
     * closed writer stays a silent no-op instead of raising — the asymmetry is
     * deliberate and narrower than it looks.
     *
     * Why write() is loud: a dropped post-close write means bytes the caller
     * intended to emit never land, and they land nowhere discoverable — the
     * only honest answer is a LogicException. Why flush() is quiet: closing a
     * stream already flushes it (fclose drains the buffer), so a late flush()
     * has no pending payload to lose; it is asking for completion of something
     * that is already true. That makes it safe to keep the call unconditional
     * in teardown-shaped code paths that cannot know whether close() already
     * ran — a throw there would turn a redundant call into a fatal during
     * destruction.
     *
     * The remaining failure mode stays reported: flush() over a LIVE handle
     * whose fflush() answers false raises.
     */
    public function flush(): void
    {
        if ($this->fp !== null && !fflush($this->fp)) {
            throw new \RuntimeException("FileOutputWriter: flush() failed on {$this->path}");
        }
    }

    public function __destruct()
    {
        // PHP cannot propagate an exception out of a destructor (it becomes a
        // fatal during shutdown), so the best-effort close here swallows the
        // loud close() failure. Callers who care about the final flush result
        // must call close() explicitly — that is the documented loud surface.
        try {
            $this->close();
        } catch (\RuntimeException) {
            // Disclosed by design: the explicit-close contract carries the error.
        }
    }

    public function close(): void
    {
        if ($this->fp !== null) {
            // candy-codeshot-051: fclose() returns false when the final flush
            // fails (disk full, mounted read-only mid-stream, hung device) —
            // silently nulling the handle reported a completed file that was
            // truncated on disk. Detach first so neither close() nor the
            // destructor can double-close the resource, then answer loudly.
            $handle = $this->fp;
            $this->fp = null;
            if (!fclose($handle)) {
                throw new \RuntimeException("FileOutputWriter: close() failed on {$this->path}");
            }
        }
    }
}
