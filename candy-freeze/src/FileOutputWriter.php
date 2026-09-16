<?php

declare(strict_types=1);

namespace SugarCraft\Freeze;

/**
 * Output writer that streams chunks directly to a file.
 * Use for large outputs where buffering in memory is undesirable.
 */
final class FileOutputWriter implements OutputWriter
{
    /** @var resource|null */
    private $fp;

    public function __construct(string $path)
    {
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
        if ($this->fp !== null) {
            fwrite($this->fp, $chunk);
        }
    }

    public function flush(): void
    {
        if ($this->fp !== null) {
            fflush($this->fp);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        if ($this->fp !== null) {
            fclose($this->fp);
            $this->fp = null;
        }
    }
}
