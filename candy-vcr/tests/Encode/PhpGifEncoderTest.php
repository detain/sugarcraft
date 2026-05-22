<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Encode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Encode\PhpGifEncoder;

/**
 * Tests for PhpGifEncoder.
 */
final class PhpGifEncoderTest extends TestCase
{
    public function testEncodeThrowsRuntimeException(): void
    {
        $encoder = new PhpGifEncoder();

        $framesIter = new \EmptyIterator();
        $frameHolds = [];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Pure-PHP GIF encoder not yet implemented; use FfmpegGifEncoder');

        $encoder->encode($framesIter, 10, 10, $frameHolds, '/tmp/test.gif');
    }
}
