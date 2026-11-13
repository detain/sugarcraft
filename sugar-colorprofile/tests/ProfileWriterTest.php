<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\ColorProfile\Palette;
use SugarCraft\ColorProfile\Profile;
use SugarCraft\ColorProfile\ProfileWriter;

/**
 * ProfileWriter::write() / printf() tests.
 */
final class ProfileWriterTest extends TestCase
{
    public function testWriteDegradesTrueColorToANSI(): void
    {
        $mem = \fopen('php://memory', 'r+');
        $writer = new ProfileWriter($mem, Profile::ANSI);
        $writer->write("\x1b[38;2;255;0;0mred\x1b[0m");
        \rewind($mem);
        $out = \stream_get_contents($mem);
        \fclose($mem);

        // 255,0,0 is exactly xterm slot 9 (bright red) — not black, not basic red.
        $this->assertSame("\x1b[91mred\x1b[0m", $out);
        $this->assertStringContainsString('red', $out);
        $this->assertStringNotContainsString("\x1b[38;2;", $out);
        $this->assertStringNotContainsString("\x1b[\x1b[", $out);
    }

    public function testWriteDegradesTrueColorToANSI256(): void
    {
        $mem = \fopen('php://memory', 'r+');
        $writer = new ProfileWriter($mem, Profile::ANSI256);
        $writer->write("\x1b[38;2;255;0;0mred\x1b[0m");
        \rewind($mem);
        $out = \stream_get_contents($mem);
        \fclose($mem);

        $this->assertStringStartsWith("\x1b[38;5;", $out);
        $this->assertStringNotContainsString("\x1b[\x1b[", $out);
    }

    public function testWriteHonorsConfiguredProfileNotAmbientEnv(): void
    {
        $mem = \fopen('php://memory', 'r+');
        $writer = new ProfileWriter($mem, Profile::ANSI);
        $writer->write("\x1b[38;2;0;0;255mblue\x1b[0m");
        \rewind($mem);
        $out = \stream_get_contents($mem);
        \fclose($mem);

        // 0,0,255 is nearest basic blue slot 4 (0,0,238), d=289 vs slot 12 d=16928.
        $this->assertSame("\x1b[34mblue\x1b[0m", $out);
        $this->assertStringNotContainsString("\x1b[38;2;", $out);
    }

    public function testWriteStripsAllAnsiForNoTTYProfile(): void
    {
        $mem = \fopen('php://memory', 'r+');
        $writer = new ProfileWriter($mem, Profile::NoTTY);
        $writer->write("\x1b[38;2;255;0;0mred\x1b[0m");
        \rewind($mem);
        $out = \stream_get_contents($mem);
        \fclose($mem);

        $this->assertSame('red', $out);
        $this->assertStringNotContainsString("\x1b", $out);
    }

    public function testPrintfFormatsAndDegrades(): void
    {
        $mem = \fopen('php://memory', 'r+');
        $writer = new ProfileWriter($mem, Profile::ANSI);
        $writer->printf("%s", "\x1b[38;2;0;0;255mblue\x1b[0m");
        \rewind($mem);
        $out = \stream_get_contents($mem);
        \fclose($mem);

        $this->assertSame("\x1b[34mblue\x1b[0m", $out);
        $this->assertStringNotContainsString("\x1b[38;2;", $out);
    }

    public function testWritePassthroughForTrueColorProfile(): void
    {
        $mem = \fopen('php://memory', 'r+');
        $writer = new ProfileWriter($mem, Profile::TrueColor);
        $input = "\x1b[38;2;100;50;255mX\x1b[0m";
        $writer->write($input);
        \rewind($mem);
        $out = \stream_get_contents($mem);
        \fclose($mem);

        $this->assertSame($input, $out);
    }

    public function testWithProfileCreatesNewWriterWithDifferentProfile(): void    {
        $mem = \fopen('php://memory', 'r+');
        $writer = new ProfileWriter($mem, Profile::ANSI256);
        $writer->write("\x1b[38;2;255;0;0mred\x1b[0m");

        $ansiWriter = $writer->withProfile(Profile::ANSI);
        $this->assertSame(Profile::ANSI, $ansiWriter->profile());

        // Note: withProfile shares the same stream, so this writes to $mem
        $ansiWriter->write("\x1b[38;2;255;0;0mred\x1b[0m");
        \rewind($mem);
        $out = \stream_get_contents($mem);

        $this->assertStringStartsWith("\x1b[3", $out);
        $this->assertStringNotContainsString("\x1b[38;2;", $out);
    }

    public function testWriteDegradesTrueColorToASCII(): void
    {
        $mem = \fopen('php://memory', 'r+');
        $writer = new ProfileWriter($mem, Profile::Ascii);
        $writer->write("\x1b[38;2;255;0;0mred\x1b[0m");
        \rewind($mem);
        $out = \stream_get_contents($mem);
        \fclose($mem);

        // ASCII profile should degrade to black (30-37) or white (90-97) ANSI16
        $this->assertTrue(
            str_starts_with($out, "\x1b[3") || str_starts_with($out, "\x1b[9"),
            "Expected ANSI 4-bit foreground, got: " . bin2hex($out)
        );
        $this->assertStringNotContainsString("\x1b[38;2;", $out);
    }

    public function testPrintfPassthroughForTrueColor(): void
    {
        $mem = \fopen('php://memory', 'r+');
        $writer = new ProfileWriter($mem, Profile::TrueColor);
        $input = "\x1b[38;2;100;50;255mX\x1b[0m";
        $writer->printf("%s", $input);
        \rewind($mem);
        $out = \stream_get_contents($mem);
        \fclose($mem);

        $this->assertSame($input, $out);
    }

    public function testWriteDegradesAnsi256ForegroundToAnsi16(): void
    {
        // A sequence already in ANSI256 format degraded to ANSI
        $mem = \fopen('php://memory', 'r+');
        $writer = new ProfileWriter($mem, Profile::ANSI);
        $writer->write("\x1b[38;5;196mred\x1b[0m");
        \rewind($mem);
        $out = \stream_get_contents($mem);
        \fclose($mem);

        // Slot 196 (255,0,0) is exactly xterm slot 9 — a bright colour must
        // never collapse to black (the old basic-8 + luminance hack's failure).
        $this->assertSame("\x1b[91mred\x1b[0m", $out);
        $this->assertStringNotContainsString("\x1b[38;5;", $out);
    }

    public function testWrapRefusesANonResourceAtTheDoor(): void
    {
        // -057/DX-16: the shape check lives at wrap(), not inside the
        // palette detection three frames down — so the refusal must NAME
        // the seat it fires at (a label-only pin: the {label} parameter is
        // untranslated in every locale, the prose around it is not).
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('~ProfileWriter::wrap\(\)~');

        ProfileWriter::wrap('not-a-stream');
    }

    public function testAStringConstructorStreamIsRefusedToo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('~ProfileWriter~');

        new ProfileWriter(42, Profile::TrueColor);
    }

    public function testContentsReadsBackTheMemoryWriterWithoutTheFtellDance(): void
    {
        // DX-15 companion: the consumer-test ask — the accumulated bytes,
        // with the write position restored so further writes append.
        $mem = fopen('php://memory', 'r+b');
        $writer = new ProfileWriter($mem, Profile::TrueColor);
        $this->assertSame('', $writer->contents(), 'a fresh buffer reads empty, never null');

        $payload = "plain \x1b[38;2;255;0;0mred\x1b[0m text";
        $writer->write($payload);
        $this->assertSame($payload, $writer->contents());

        $writer->write(' and more');
        $this->assertSame($payload . ' and more', $writer->contents(), 'the position survived the read-back');
        fclose($mem);
    }

    public function testContentsIsHonestNullForNonSeekableStreams(): void
    {
        // php://output is the documented non-seekable face — no rewindable
        // buffer exists, and draining would steal the caller's bytes.
        $writer = new ProfileWriter(fopen('php://output', 'w'), Profile::TrueColor);

        $this->assertNull($writer->contents(), 'no seekable buffer, no promise');
    }
}
