<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Support;

/**
 * Fail-fast stream/file openers for the test suite.
 *
 * `fopen()`/`file_get_contents()` return `resource|false`/`string|false`,
 * forcing every consumer (rewind, fwrite, fclose, run()) to re-check for
 * false. These helpers parse that result once at the boundary: callers get
 * a guaranteed `resource`/`string`, and a failed open blows up loudly at
 * its source instead of as a confusing TypeError three lines later.
 */
final class Stream
{
    private function __construct()
    {
    }

    /**
     * An in-memory stream handle.
     *
     * @return resource
     */
    public static function memory(string $mode = 'r+'): mixed
    {
        $handle = fopen('php://memory', $mode);
        if (!is_resource($handle)) {
            throw new \RuntimeException("tests: could not open php://memory ({$mode})");
        }
        return $handle;
    }

    /**
     * A read handle on /dev/null — sink for "output doesn't matter" calls.
     *
     * @return resource
     */
    public static function devNull(): mixed
    {
        $handle = fopen('/dev/null', 'r');
        if (!is_resource($handle)) {
            throw new \RuntimeException('tests: could not open /dev/null');
        }
        return $handle;
    }

    /**
     * Whole-file contents as string.
     */
    public static function read(string $path): string
    {
        $body = file_get_contents($path);
        if (!is_string($body)) {
            throw new \RuntimeException("tests: could not read {$path}");
        }
        return $body;
    }

    /**
     * First $length bytes of a file as string.
     *
     * @param int<0, max> $length
     */
    public static function readPrefix(string $path, int $length): string
    {
        $body = file_get_contents($path, false, null, 0, $length);
        if (!is_string($body)) {
            throw new \RuntimeException("tests: could not read {$path}");
        }
        return $body;
    }
}
