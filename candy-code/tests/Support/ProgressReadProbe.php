<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

/**
 * A stream wrapper that passes every operation through to a real directory and
 * counts how many times a file was opened for reading.
 *
 * WHY THIS EXISTS. AgentWorkerPool::pumpProgress() has no observable effect on
 * an idle poll (the mirror, the status and the stall deadline are all unchanged),
 * so the only way to pin that an idle poll does not read the progress file is to
 * count the opens. A test points the pool's result directory at a
 * `scprobe://<real dir>` URI and reads {@see self::$opens} around a poll.
 */
final class ProgressReadProbe
{
    public const SCHEME = 'scprobe';

    /** Opens (of any mode) since the counter was last reset. */
    public static int $opens = 0;

    /** @var resource|null PHP sets this on wrapper instances. */
    public $context;

    /** @var resource|null */
    private $handle = null;

    public static function register(): void
    {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
    }

    public static function uri(string $realDir): string
    {
        return self::SCHEME . '://' . $realDir;
    }

    private static function real(string $path): string
    {
        return substr($path, strlen(self::SCHEME) + 3);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$opens++;
        $handle = @fopen(self::real($path), $mode);
        if ($handle === false) {
            return false;
        }
        $this->handle = $handle;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        return $this->handle === null ? false : fread($this->handle, $count);
    }

    public function stream_write(string $data): int|false
    {
        return $this->handle === null ? false : fwrite($this->handle, $data);
    }

    public function stream_eof(): bool
    {
        return $this->handle === null || feof($this->handle);
    }

    public function stream_stat(): array|false
    {
        return $this->handle === null ? false : fstat($this->handle);
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        return $this->handle !== null && fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_tell(): int
    {
        return $this->handle === null ? 0 : (int) ftell($this->handle);
    }

    public function stream_close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function url_stat(string $path, int $flags): array|false
    {
        return @stat(self::real($path));
    }

    public function unlink(string $path): bool
    {
        return @unlink(self::real($path));
    }

    public function rmdir(string $path, int $options): bool
    {
        return @rmdir(self::real($path));
    }
}
