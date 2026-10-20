<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;

/**
 * Byte-exact pins for the iTerm2 OSC 1337 envelope, F-13 edition: the
 * `type` argument is emitted only when explicitly set, so every existing
 * PNG transmission keeps its historical byte shape (absent `type` means
 * PNG by protocol).
 */
final class AnsiIterm2InlineImageTest extends TestCase
{
    public function testThePngEnvelopeIsByteStableWhenTypeIsAbsent(): void
    {
        self::assertSame(
            "\x1b]1337;File=width=5;height=3;preserveAspectRatio=1;inline=1:QUJD\x07",
            Ansi::iterm2InlineImage('QUJD', [
                'width'               => 5,
                'height'              => 3,
                'preserveAspectRatio' => true,
            ]),
        );
    }

    public function testTheTypeArgumentRidesBeforeTheInlineFlag(): void
    {
        self::assertSame(
            "\x1b]1337;File=width=5;height=3;preserveAspectRatio=1;type=tiff;inline=1:QUJD\x07",
            Ansi::iterm2InlineImage('QUJD', [
                'type'                => 'tiff',
                'width'               => 5,
                'height'              => 3,
                'preserveAspectRatio' => true,
            ]),
        );
    }
}
