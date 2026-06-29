<?php

declare(strict_types=1);

namespace SugarCraft\Palette\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Palette\Palette;
use SugarCraft\Palette\Profile;
use SugarCraft\Palette\ProfileWriter;

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

        $this->assertStringStartsWith("\x1b[3", $out);
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

        $this->assertStringStartsWith("\x1b[3", $out);
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

        $this->assertStringStartsWith("\x1b[3", $out);
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

    public function testWithProfileCreatesNewWriterWithDifferentProfile(): void
    {
        $mem = \fopen('php://memory', 'r+');
        $writer = new ProfileWriter($mem, Profile::ANSI256);
        $writer->write("\x1b[38;2;255;0;0mred\x1b[0m");

        $ansiWriter = $writer->withProfile(Profile::ANSI);
        $this->assertSame(Profile::ANSI, $ansiWriter->profile());

        $mem2 = \fopen('php://memory', 'r+');
        $ansiWriter->write("\x1b[38;2;255;0;0mred\x1b[0m");
        \rewind($mem2);
        $out = \stream_get_contents($mem2);
        \fclose($mem2);

        $this->assertStringStartsWith("\x1b[3", $out);
        $this->assertStringNotContainsString("\x1b[38;2;", $out);
    }
}
