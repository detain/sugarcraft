<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;

/**
 * Byte-exact coverage for the Kitty graphics protocol emitters.
 *
 * ANSI audit defect #10: the old {@see Ansi::kittyGraphicsBegin()} emitted
 * a DCS `ESC P q` header — byte-identical to a DECSIXEL start (ansicode:277
 * `Pq`=SIXEL), so Kitty graphics never activated and sixel terminals got
 * raster garbage. The real protocol is APC `ESC _ G` (which
 * {@see Ansi::kittyGraphicsClear()} always used correctly). These tests pin
 * every frame's exact bytes: introducer, comma-joined attrs, `;` data
 * separator, `m=` more-flag, and the ST terminator AFTER the payload.
 *
 * Mirrors charmbracelet/x/ansi. GraphicsBegin/GraphicsData/GraphicsEnd.
 */
final class AnsiKittyGraphicsTest extends TestCase
{
    private const APC = "\x1b_";   // 0x1b 0x5f
    private const ST  = "\x1b\\";  // 0x1b 0x5c

    public function testBeginUsesApcGIntroducerNotSixelDcs(): void
    {
        $seq = Ansi::kittyGraphicsBegin(['a' => 'T', 'f' => 100]);

        // The regression guard: it is NOT `ESC P q` (SIXEL).
        $this->assertStringStartsWith(self::APC . 'G', $seq);
        $this->assertFalse(
            str_starts_with($seq, "\x1bPq"),
            'begin must not emit the DECSIXEL introducer (ANSI audit #10)',
        );
        // Begin ends the header with `,m=1;` (empty first chunk) so the
        // transaction stays open for kittyGraphicsChunk() frames.
        $this->assertSame(self::APC . 'G' . 'a=T,f=100,m=1;' . self::ST, $seq);
    }

    public function testBeginRespectsExplicitMoreFlag(): void
    {
        // An explicit m in opts is honored verbatim — never double-appended.
        $this->assertSame(
            self::APC . 'G' . 'a=t,m=0;' . self::ST,
            Ansi::kittyGraphicsBegin(['a' => 't', 'm' => 0]),
        );
    }

    public function testBeginSkipsNullValues(): void
    {
        $this->assertSame(
            self::APC . 'G' . 'c=8,r=4,m=1;' . self::ST,
            Ansi::kittyGraphicsBegin(['c' => 8, 'i' => null, 'r' => 4]),
        );
    }

    public function testChunkFramedAsSelfContainedApcSequence(): void
    {
        // Each chunk is its own APC…ST frame with payload after the ';'.
        $this->assertSame(
            self::APC . 'G' . 'm=1;AAAA' . self::ST,
            Ansi::kittyGraphicsChunk('AAAA', true),
        );
        $this->assertSame(
            self::APC . 'G' . 'm=0;BBBB' . self::ST,
            Ansi::kittyGraphicsChunk('BBBB', false),
        );
    }

    public function testEndIsEmptyM0Chunk(): void
    {
        // The terminal frame: `m=0;` with no payload, then ST.
        $this->assertSame(self::APC . 'G' . 'm=0;' . self::ST, Ansi::kittyGraphicsEnd());
    }

    public function testClearUnchangedApcGForm(): void
    {
        $this->assertSame(self::APC . 'G' . 'a=d,i=3' . self::ST, Ansi::kittyGraphicsClear(3));
        $this->assertSame(self::APC . 'G' . 'a=d,i=0' . self::ST, Ansi::kittyGraphicsClear());
    }

    public function testControlIsSingleFrameNoTransaction(): void
    {
        $this->assertSame(
            self::APC . 'G' . 'a=p,i=5,z=2' . self::ST,
            Ansi::kittyGraphicsControl(['a' => 'p', 'i' => 5, 'z' => 2]),
        );
    }

    public function testBeginChunkEndRoundTripsUnderStrip(): void
    {
        // Everything the emitter produces is a valid escape sequence, so
        // Ansi::strip() removes it entirely and leaves only visible text.
        $frame = 'before'
            . Ansi::kittyGraphicsBegin(['a' => 'T', 'f' => 100])
            . Ansi::kittyGraphicsChunk('AAECgkI=', true)
            . Ansi::kittyGraphicsChunk('END', false)
            . Ansi::kittyGraphicsEnd()
            . 'after';

        $this->assertSame('beforeafter', Ansi::strip($frame));
    }

    public function testFullTransmissionSplitsIntoBalancedFrames(): void
    {
        $out = Ansi::kittyGraphicsBegin(['a' => 'T'])
            . Ansi::kittyGraphicsChunk('AAA', true)
            . Ansi::kittyGraphicsChunk('BBB', false);

        // 3 frames, each `\ESC _ G … ESC \`, exactly one m=0 (the closer).
        $this->assertSame(3, substr_count($out, self::APC . 'G'));
        $this->assertSame(3, substr_count($out, self::ST));
        $this->assertSame(1, substr_count($out, 'm=0;'));
        $this->assertSame(2, substr_count($out, 'm=1;'));
    }
}
