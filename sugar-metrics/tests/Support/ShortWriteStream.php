<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Tests\Support;

/**
 * Test seam for a genuinely truncated write (sugar-metrics-009): the next
 * stream_write() is capped to arm() bytes, the retry that follows gets one
 * 0-progress answer — the way a full pipe answers a non-blocking write(2) —
 * and every call after that lands normally. Without this seam the old
 * "partial write" tests wrote to php://memory and never exercised a short
 * fwrite at all.
 */
final class ShortWriteStream
{
    /** @var resource|null */
    public $context;

    public static string $buf = '';
    public static int $faultBytes = 0;
    public static int $blockOnce = 0;
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered === false) {
            stream_wrapper_register('metrics-shortwrite', self::class);
            self::$registered = true;
        }
    }

    /** Reset the captured bytes and arm a fault: next N bytes accepted, then one hard block. */
    public static function arm(int $bytes): void
    {
        self::$buf = '';
        self::$faultBytes = $bytes;
        self::$blockOnce = 0;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        if (self::$faultBytes > 0) {
            $allowed = min(strlen($data), self::$faultBytes);
            self::$faultBytes -= $allowed;
            self::$buf .= substr($data, 0, $allowed);
            if (self::$faultBytes === 0) {
                self::$blockOnce = 1;
            }
            return $allowed;
        }
        if (self::$blockOnce > 0) {
            self::$blockOnce = 0;
            return 0;
        }
        self::$buf .= $data;
        return strlen($data);
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        return '';
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }
}
