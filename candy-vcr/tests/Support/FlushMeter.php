<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Support;

use SugarCraft\Vcr\Support\ObjectMap;

/**
 * In-process fflush meter (finding 113 acceptance).
 *
 * The durability doors of {@see \SugarCraft\Vcr\Recorder} are flush
 * POLICY, not write policy: bytes reach the stream on every line either
 * way, so a memory stream cannot show the difference. This wrapper
 * separates the two syscalls — write() lands bytes in a static buffer
 * while flush() only TICKS a counter — so tests can pin exactly how many
 * drains a burst, an idle gap, or a byte threshold costs. A real file
 * pays the same count in fflush(3)/fsync-adjacent syscalls.
 *
 * Registered under the `vcrmeter://` scheme. State is static because the
 * engine instantiates the wrapper class itself (newer PHP versions do not
 * forward fopen's context argument to stream_open), so `openFresh()`
 * resets the counters as part of minting each handle.
 */
final class FlushMeter
{
    public const SCHEME = 'vcrmeter';

    /** @var resource|null */
    public $context;

    private static int $flushes = 0;
    private static string $buffer = '';

    /**
     * @return resource A writable metered stream with counters at zero.
     */
    public static function openFresh()
    {
        self::register();
        self::$flushes = 0;
        self::$buffer = '';
        $fh = @fopen(self::SCHEME . '://session', 'wb');
        if ($fh === false) {
            throw new \RuntimeException('vcrmeter stream could not be opened');
        }
        return $fh;
    }

    public static function flushCount(): int
    {
        return self::$flushes;
    }

    /**
     * @return list<array<string, mixed>> Every JSONL line written so far.
     */
    public static function lines(): array
    {
        $lines = [];
        foreach (explode("\n", self::$buffer) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('metered line is not JSON: ' . $line);
            }
            $lines[] = ObjectMap::of($decoded, 'metered line');
        }
        return $lines;
    }

    private static function register(): void
    {
        if (!in_array(self::SCHEME, \stream_get_wrappers(), true)) {
            if (!@stream_wrapper_register(self::SCHEME, self::class)) {
                throw new \RuntimeException('vcrmeter wrapper could not be registered');
            }
        }
    }

    /**
     * @param string $path        Wrapper URL (unused beyond the scheme)
     * @param string $mode        fopen mode — only write modes are meaningful
     * @param int    $options     STREAM_* flags
     * @param string|null $opened_path Out param — leave null; a non-null
     *                             reference with an empty path makes fopen
     *                             reject the handle.
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        return str_contains($mode, 'w') || str_contains($mode, 'a');
    }

    /**
     * @param string $data
     */
    public function stream_write($data): int
    {
        self::$buffer .= $data;
        return strlen($data);
    }

    public function stream_flush(): bool
    {
        self::$flushes++;
        return true;
    }

    public function stream_close(): void
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function stream_stat(): array
    {
        return ['size' => strlen(self::$buffer), 'mode' => 0600, 'mtime' => time()];
    }
}
