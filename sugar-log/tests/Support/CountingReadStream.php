<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests\Support;

/**
 * Test stream wrapper over a real file that counts the bytes read through it.
 *
 * Registered under the sugarlog-count protocol: `sugarlog-count:///abs/path`
 * opens `/abs/path`. LogReaderTest uses $bytesRead to pin that tail() reads
 * the end of a large file and never the whole of it.
 */
final class CountingReadStream
{
    public const PROTOCOL = 'sugarlog-count';

    public static int $bytesRead = 0;

    /** @var resource|null */
    public $context;

    /** @var resource|null */
    private $inner = null;

    public static function register(): void
    {
        if (!\in_array(self::PROTOCOL, \stream_get_wrappers(), true)) {
            \stream_wrapper_register(self::PROTOCOL, self::class);
        }
        self::$bytesRead = 0;
    }

    public static function unregister(): void
    {
        if (\in_array(self::PROTOCOL, \stream_get_wrappers(), true)) {
            \stream_wrapper_unregister(self::PROTOCOL);
        }
    }

    private static function realPath(string $url): string
    {
        return \substr($url, \strlen(self::PROTOCOL) + 3);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $inner = \fopen(self::realPath($path), $mode);
        if ($inner === false) {
            return false;
        }
        $this->inner = $inner;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        $bytes = \fread($this->inner, $count);
        if (\is_string($bytes)) {
            self::$bytesRead += \strlen($bytes);
        }

        return $bytes;
    }

    public function stream_seek(int $offset, int $whence = \SEEK_SET): bool
    {
        return \fseek($this->inner, $offset, $whence) === 0;
    }

    public function stream_tell(): int
    {
        return (int) \ftell($this->inner);
    }

    public function stream_eof(): bool
    {
        return \feof($this->inner);
    }

    /** @return array<int|string, int>|false */
    public function stream_stat(): array|false
    {
        return \fstat($this->inner);
    }

    /** @return array<int|string, int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        return @\stat(self::realPath($path));
    }

    public function stream_close(): void
    {
        if ($this->inner !== null) {
            \fclose($this->inner);
        }
    }
}
