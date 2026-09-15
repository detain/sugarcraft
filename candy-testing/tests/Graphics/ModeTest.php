<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests\Graphics;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCraft\Testing\Graphics\MalformedGraphicsException;
use SugarCraft\Testing\Graphics\Mode;

/**
 * @covers \SugarCraft\Testing\Graphics\Mode
 * @covers \SugarCraft\Testing\Graphics\MalformedGraphicsException
 */
final class ModeTest extends TestCase
{
    public function testDetectRecognisesSixelDcs(): void
    {
        self::assertSame(Mode::Sixel, Mode::detect(Fixture::bytes('sixel_red.six')));
    }

    public function testDetectRecognisesKittyDcs(): void
    {
        self::assertSame(Mode::Kitty, Mode::detect(Fixture::bytes('kitty_red.kitty')));
    }

    public function testDetectRecognisesIterm2Osc(): void
    {
        self::assertSame(Mode::Iterm2, Mode::detect(Fixture::bytes('iterm2_red.iterm2')));
    }

    public function testDetectFailsFastOnUnknownProtocol(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        Mode::detect('just some plain terminal text');
    }

    public function testExceptionIsARuntimeException(): void
    {
        // The decoders throw MalformedGraphicsException; callers commonly rescue
        // the broader RuntimeException, so the subtype relationship matters.
        self::assertInstanceOf(RuntimeException::class, new MalformedGraphicsException('x'));
    }

    public function testCaseValues(): void
    {
        self::assertSame('sixel', Mode::Sixel->value);
        self::assertSame('kitty', Mode::Kitty->value);
        self::assertSame('iterm2', Mode::Iterm2->value);
    }
}
