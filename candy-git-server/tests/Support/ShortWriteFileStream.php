<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\Support;

/**
 * Test seam for a disk-full short write against a real file.
 *
 * Paths under the `cgshort://` scheme pass straight through to the plain
 * filesystem, except that the next `*.tmp` file opened after
 * {@see armNextTempWrite()} accepts half its payload and then reports zero
 * progress on the retry, the way a full disk answers write(2). A plain-path
 * fopen cannot be made to short-write, so the UserStore publish path is
 * driven through this wrapper instead.
 */
final class ShortWriteFileStream
{
    public const SCHEME = 'cgshort';

    private const PREFIX = self::SCHEME . '://';

    /** @var resource|null */
    public $context;

    /** @var resource|null */
    private $handle = null;

    private bool $armed = false;

    private bool $blocked = false;

    private static bool $armedForNextTemp = false;

    public static function register(): void
    {
        if (!\in_array(self::SCHEME, \stream_get_wrappers(), true)) {
            \stream_wrapper_register(self::SCHEME, self::class);
        }
    }

    public static function armNextTempWrite(): void
    {
        self::$armedForNextTemp = true;
    }

    public static function real(string $path): string
    {
        return \str_starts_with($path, self::PREFIX) ? \substr($path, \strlen(self::PREFIX)) : $path;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $real = self::real($path);
        $handle = @\fopen($real, $mode);
        if ($handle === false) {
            return false;
        }
        $this->handle = $handle;
        if (\str_ends_with($real, '.tmp') && self::$armedForNextTemp) {
            self::$armedForNextTemp = false;
            $this->armed = true;
        }

        return true;
    }

    public function stream_write(string $data): int
    {
        if ($this->armed) {
            $this->armed = false;
            $this->blocked = true;

            return (int) \fwrite($this->handle, \substr($data, 0, \intdiv(\strlen($data), 2)));
        }
        if ($this->blocked) {
            $this->blocked = false;

            return 0;
        }

        return (int) \fwrite($this->handle, $data);
    }

    public function stream_read(int $count): string|false
    {
        return \fread($this->handle, $count);
    }

    public function stream_eof(): bool
    {
        return \feof($this->handle);
    }

    /** @return array<int|string, int>|false */
    public function stream_stat(): array|false
    {
        return \fstat($this->handle);
    }

    public function stream_flush(): bool
    {
        return \fflush($this->handle);
    }

    public function stream_close(): void
    {
        \fclose($this->handle);
    }

    /** @return array<int|string, int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        return @\stat(self::real($path));
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        return $option === \STREAM_META_ACCESS && @\chmod(self::real($path), (int) $value);
    }

    public function rename(string $from, string $to): bool
    {
        return @\rename(self::real($from), self::real($to));
    }

    public function unlink(string $path): bool
    {
        return @\unlink(self::real($path));
    }
}
