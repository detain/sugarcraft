<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Encode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Encode\PhpGifEncoder;

/**
 * F2 (round 90): buildDelayArray divides by $fps twice — 0 was a modulo
 * ArithmeticError and the general fps<=0 class degraded into a hang rather
 * than a message. The encoder rejects at its own door so even callers that
 * bypass the CLI get a named-argument error before any file is touched.
 */
final class PhpGifEncoderFpsDoorTest extends TestCase
{
    /** @return array<array{int}> */
    public static function forbiddenFps(): array
    {
        return [[0], [-1], [-30]];
    }

    /** @dataProvider forbiddenFps */
    public function testEncodeRefusesNonPositiveFpsBeforeTouchingFrames(int $fps): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PhpGifEncoder fps must be a positive');
        // 'missing.png' is never read: the door fires first, which is also
        // why this test needs no ext-gd and no fixture frames.
        (new PhpGifEncoder())->encode(['missing.png'], '/tmp/candy-vcr-must-not-exist.gif', $fps);
    }

    public function testEmptyFrameListStillRejectsWithThePreExistingError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No frames provided');
        (new PhpGifEncoder())->encode([], '/tmp/candy-vcr-must-not-exist.gif', 0);
    }
}
