<?php

declare(strict_types=1);

namespace SugarCraft\Core\Test;

use PHPUnit\Framework\TestCase;

/**
 * Helpers for testing stream-based output.
 */
trait StreamHelper
{
    /**
     * Capture bytes written to a stream after the current position.
     *
     * @param resource $fp  Stream opened in 'w+' or 'a+' mode
     * @param callable $writeFn  Function that writes to the stream
     * @return string  The bytes written
     */
    protected function captureStreamWrite($fp, callable $writeFn): string
    {
        $pos = ftell($fp);
        $writeFn();
        fflush($fp);
        fseek($fp, $pos);
        return stream_get_contents($fp);
    }

    /**
     * Create a temporary stream for testing.
     *
     * @return array{0: resource, 1: string}  [stream, temp file path]
     */
    protected function createTempStream(): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sugarcraf_test_');
        $fp = fopen($tmp, 'w+');
        return [$fp, $tmp];
    }
}