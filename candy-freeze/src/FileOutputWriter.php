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
        $this->fp = fopen($path, 'w');
        if ($this->fp === false) {
            throw new \RuntimeException("Failed to open file for writing: {$path}");
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
