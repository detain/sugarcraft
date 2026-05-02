<?php

declare(strict_types=1);

namespace CandyCore\Charts\Tests\Picture;

use CandyCore\Charts\Picture\Picture;
use CandyCore\Charts\Picture\Protocol;
use CandyCore\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class PictureTest extends TestCase
{
    public function testFromGridWithSixelEmitsDcs(): void
    {
        $pixels = [[Color::rgb(255, 0, 0), Color::rgb(0, 255, 0)]];
        $out = Picture::fromGrid($pixels)->withProtocol(Protocol::Sixel)->view();
        $this->assertStringStartsWith("\x1bPq", $out);
        $this->assertStringEndsWith("\x1b\\", $out);
    }

    public function testFromPngWithKittyEmitsApc(): void
    {
        $fakePng = "\x89PNG\r\n\x1a\n" . str_repeat('A', 256);
        $out = Picture::fromPng($fakePng)->withProtocol(Protocol::Kitty)->view();
        $this->assertStringContainsString("\x1b_G", $out);
        $this->assertStringContainsString("\x1b\\", $out);
        $this->assertStringContainsString('f=100', $out);
    }

    public function testFromPngWithITerm2EmitsOsc1337(): void
    {
        $fakePng = "\x89PNG\r\n\x1a\n" . str_repeat('A', 64);
        $out = Picture::fromPng($fakePng)->withProtocol(Protocol::ITerm2)->view();
        $this->assertStringStartsWith("\x1b]1337;File=", $out);
        $this->assertStringContainsString('inline=1', $out);
        $this->assertStringEndsWith("\x07", $out);
    }

    public function testKittyWithoutPngFallsBackToText(): void
    {
        $out = Picture::fromGrid([[Color::rgb(0, 0, 0)]])
            ->withProtocol(Protocol::Kitty)
            ->view();
        $this->assertStringNotContainsString("\x1b_G", $out);
        $this->assertStringContainsString('PNG', $out);
    }

    public function testDetectITerm2(): void
    {
        $this->assertSame(
            Protocol::ITerm2,
            Picture::detect(['TERM_PROGRAM' => 'iTerm.app']),
        );
    }

    public function testDetectKitty(): void
    {
        $this->assertSame(
            Protocol::Kitty,
            Picture::detect(['TERM' => 'xterm-kitty']),
        );
    }

    public function testDetectFootSpeaksSixel(): void
    {
        $this->assertSame(
            Protocol::Sixel,
            Picture::detect(['TERM' => 'foot']),
        );
    }

    public function testDetectUnknownReturnsNull(): void
    {
        $this->assertNull(Picture::detect(['TERM' => 'dumb']));
    }

    public function testNoProtocolNoDetectionFallsBackToText(): void
    {
        $out = Picture::fromGrid([[Color::rgb(0, 0, 0)]])
            ->withProtocol(null)
            ->view();
        // When neither pixel grid path nor detect() lands a protocol,
        // we get a plain-text fallback. Run with no env hints set:
        $bareEnv = [];
        $protocol = Picture::detect($bareEnv);
        if ($protocol !== null) {
            $this->markTestSkipped('Detection succeeded; environment leaked.');
        }
        $this->assertStringContainsString('image', $out);
    }
}
