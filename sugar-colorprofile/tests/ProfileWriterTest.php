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

    public function testWriteChasesShortWritesUntilThePayloadIsDelivered(): void
    {
        // -073 loop pin, deterministic half: a stream that accepts only 1 KiB
        // per fwrite (the PTY/pipe-under-pressure shape sugar-core's Renderer
        // already proves against). The old single-fwrite write() returned the
        // FIRST capped count and the rest of the frame silently vanished;
        // the loop must deliver every byte and answer with the true total.
        Cp53Stream::arm(chunk: 1024, saturate: PHP_INT_MAX);
        $stream = Cp53Stream::open();
        $writer = new ProfileWriter($stream, Profile::TrueColor);

        $payload = str_repeat("\x1b[38;2;10;20;30mABCD\x1b[0m ", 625); // ~16.8 KB: 17 capped writes, TrueColor passthrough
        $written = $writer->write($payload);

        $this->assertSame(strlen($payload), $written, 'every byte must be delivered once progress continues');
        $this->assertSame($payload, Cp53Stream::$captured, 'delivered bytes are byte-exact');
        $this->assertGreaterThan(1, Cp53Stream::$writes, 'the chase ran — a single capped write is the bug');
        fclose($stream);
    }

    public function testWriteStopsSpinFreeAtZeroProgressAndReportsTheDeliveredPrefix(): void
    {
        // The other half of the contract: a destination that goes FULL (next
        // write would accept nothing) must NOT spin and must NOT flip the
        // caller's stream mode — write() returns the bytes actually
        // delivered, and exactly that prefix is what the peer holds.
        Cp53Stream::arm(chunk: 1024, saturate: 3000);
        $stream = Cp53Stream::open();
        $writer = new ProfileWriter($stream, Profile::TrueColor);

        $payload = str_repeat('x', 9000);
        $written = $writer->write($payload);

        $this->assertSame(3000, $written, 'the zero-progress stop answers with the partial count');
        $this->assertSame(substr($payload, 0, 3000), Cp53Stream::$captured, 'delivered prefix byte-exact, remainder never accepted');
        fclose($stream);
    }

    public function testWriteRetriesTheStreamThatRefusesOnceAndWouldTakeMore(): void
    {
        // THE discriminating shape (earned by mutation, not by trust): PHP's
        // own stream layer already chases a COOPERATIVE capped writer inside
        // one fwrite — so the chase pin alone cannot tell this lib's loop
        // from the old unlooped line. The case the old code genuinely lost
        // is a destination that refuses ONE call (buffer momentarily full,
        // the non-blocking-pipe rhythm) while more could go later: the core
        // stops at that refusal, so only OUR retry rung delivers the rest.
        Cp53Stream::arm(chunk: 1024, saturate: PHP_INT_MAX, refuseCall: 2);
        $stream = Cp53Stream::open();
        $writer = new ProfileWriter($stream, Profile::TrueColor);

        $payload = str_repeat('y', 3072);
        $written = $writer->write($payload);

        $this->assertSame(3072, $written, 'the refusal must be retried, not accepted as the end of the payload');
        $this->assertSame($payload, Cp53Stream::$captured, 'every byte lands after the retry');
        $this->assertGreaterThanOrEqual(3, Cp53Stream::$writes, 'the retry rung ran');
        fclose($stream);
    }

    public function testWriteOfAnEmptyPayloadIsZeroNotFalse(): void
    {
        $mem = fopen('php://memory', 'r+');
        $writer = new ProfileWriter($mem, Profile::TrueColor);

        $this->assertSame(0, $writer->write(''), 'the single-fwrite shape answered 0; so does the loop');
        fclose($mem);
    }

    public function testWriteChasesARealKernelShortWriteLinuxOnly(): void
    {
        // The OS-behind-the-wrapper face: a non-blocking unix socketpair whose
        // reader idles cannot absorb a 32 MiB burst — the kernel short-writes
        // and the loop must stop spin-free with a partial that is byte-exact
        // at the peer. Linux is the proven CI surface; the Windows socketpair
        // semantics stay unprobed by contract.
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('kernel short-write proof is Linux-shaped (Windows face unprobed, annotation note)');
        }

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->assertIsArray($pair);
        [$sink, $source] = $pair;
        stream_set_blocking($sink, false);

        $writer = new ProfileWriter($sink, Profile::TrueColor);
        $payload = str_repeat('abcdef0123456789', 2 * 1024 * 1024); // 32 MiB, no ANSI: passthrough
        $written = $writer->write($payload);

        $this->assertIsInt($written);
        $this->assertGreaterThan(0, $written, 'an empty send buffer must accept the first burst');
        $this->assertLessThan(strlen($payload), $written, '32 MiB outruns any socket buffer — a full count here means the short write never happened');

        stream_set_blocking($source, true);
        $received = '';
        while (strlen($received) < $written) {
            $chunk = fread($source, $written - strlen($received));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $received .= $chunk;
        }
        $this->assertSame(substr($payload, 0, strlen($received)), $received, 'what the count promises, the peer holds');

        fclose($sink);
        fclose($source);
    }
}

/**
 * Test-only stream wrapper capped at {@see self::$chunk} accepted bytes per
 * write, going permanently full after {@see self::$saturate} bytes — the
 * pipe-under-pressure and full-buffer shapes in one honest double (the
 * sugar-core RendererTest::ShortWriteStream precedent, widened).
 *
 * @internal
 */
final class Cp53Stream
{
    public static string $captured = '';
    public static int $writes = 0;
    public static int $chunk = 1024;
    public static int $saturate = PHP_INT_MAX;
    public static int $refuseCall = 0;

    /** @var resource */
    public $context;

    public static function arm(int $chunk, int $saturate, int $refuseCall = 0): void
    {
        self::$captured = '';
        self::$writes = 0;
        self::$chunk = $chunk;
        self::$saturate = $saturate;
        self::$refuseCall = $refuseCall;
        if (!in_array('cp53', stream_get_wrappers(), true)) {
            stream_wrapper_register('cp53', self::class);
        }
    }

    /** @return resource */
    public static function open()
    {
        $stream = fopen('cp53://sink', 'w');
        if ($stream === false) {
            throw new \RuntimeException('cp53:// sink did not open');
        }
        return $stream;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        self::$writes++;
        if (self::$refuseCall === self::$writes) {
            return 0;
        }
        $room = self::$saturate - strlen(self::$captured);
        $take = min(self::$chunk, $room, strlen($data));
        if ($take > 0) {
            self::$captured .= substr($data, 0, $take);
        }
        return max($take, 0);
    }

    public function stream_close(): void
    {
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return true;
    }
}
