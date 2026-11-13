<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests\Support;

/**
 * [SL-41] Test stream wrapper that scripts short writes.
 *
 * Registered under the sugarlog-sw protocol, it lets a test drive the ONE
 * code path the all-or-nothing failure tests could never reach: Logger's
 * writeRecord retry, where the first fwrite() lands fewer bytes than asked
 * and a second call must complete the record. Modes per slot (set before
 * fopen via $modes):
 *
 * PHP's stream core loops over stream_write() until an fwrite() is fully
 * consumed, so a short fwrite() can only be produced by STALLING: returning
 * 0 mid-drain makes fwrite() hand back the bytes accepted so far.
 *
 *  - retry-completes (default): the first fwrite() lands five bytes, then
 *    stalls, so writeRecord sees a short count; the retry lands whole.
 *  - always-fail: every fwrite() lands one byte then stalls, so even the
 *    retry cannot finish and the loss must be announced.
 *
 * Accumulated bytes are exposed per slot in $buffers so tests can assert
 * exactly what the stream actually received.
 */
final class ShortWriteStream
{
    /** @var array<string, string> */
    public static array $buffers = [];

    /** @var array<string, string> */
    public static array $modes = [];

    /** @var resource|null */
    public $context;

    private string $slot = '';

    private int $calls = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $this->slot = \substr($path, \strrpos($path, '/') + 1);
        self::$buffers[$this->slot] = '';

        return true;
    }

    public function stream_write(string $data): int
    {
        $this->calls++;

        if ('always-fail' === (self::$modes[$this->slot] ?? '')) {
            // Every fwrite() gets one byte then a stall, so neither the
            // original nor the retry can ever finish the record.
            if (1 === $this->calls % 2) {
                self::$buffers[$this->slot] .= $data[0];

                return 1;
            }

            return -1;
        }

        // retry-completes: the first fwrite() lands five bytes and then
        // stalls, so fwrite() returns short; the retry's fwrite() (call 3
        // onward) lands whole.
        if (1 === $this->calls) {
            self::$buffers[$this->slot] .= \substr($data, 0, 5);

            return 5;
        }

        if (2 === $this->calls) {
            return -1;
        }

        self::$buffers[$this->slot] .= $data;

        return \strlen($data);
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }
}
