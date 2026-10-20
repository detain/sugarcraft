<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Tests\Codec;

use PHPUnit\Framework\TestCase;
use SugarCraft\AnsiParser\Codec\Dcs;

/**
 * F-16: Dcs codec classification + labels.
 */
final class DcsTest extends TestCase
{
    public function testTruncatedSequenceIsReportedAsSuchInsteadOfGuessed(): void
    {
        $dcs = Dcs::parse(0, [1, 2]);

        self::assertTrue($dcs->isTruncated());
        self::assertSame(Dcs::KIND_TRUNCATED, $dcs->kind());
        self::assertFalse($dcs->isSixel());
        self::assertSame('DCS truncated (no final byte; 2 param slot(s), 0 data bytes)', $dcs->describe());
    }

    public function testSixelIsClassifiedByItsFinalByte(): void
    {
        $dcs = Dcs::parse(\ord('q'), [0, 1, 0], 0, 0, '"1;1;2;2###');

        self::assertFalse($dcs->isTruncated());
        self::assertTrue($dcs->isSixel());
        self::assertSame(Dcs::KIND_SIXEL, $dcs->kind());
        self::assertSame('sixel image (11 bytes)', $dcs->describe());
    }

    public function testDecrqssRequestSharesTheFinalByteButNotTheIntermediate(): void
    {
        $dcs = Dcs::parse(\ord('q'), [], 0, \ord('$'), '"p');

        self::assertSame(Dcs::KIND_DECRQSS, $dcs->kind());
        self::assertFalse($dcs->isSixel());
        self::assertSame('DECRQSS request "p', $dcs->describe());
    }

    public function testDecrpssReplyIsClassifiedByFinalAndIntermediate(): void
    {
        $dcs = Dcs::parse(\ord('r'), [1], 0, \ord('$'), '0$p');

        self::assertSame(Dcs::KIND_DECRPSS, $dcs->kind());
        self::assertSame('DECRPSS reply 1$r0$p', $dcs->describe());
    }

    public function testXtversionReplyIsClassifiedByPrefixAndFinal(): void
    {
        $dcs = Dcs::parse(\ord('|'), [], \ord('>'), 0, '1.20.0');

        self::assertSame(Dcs::KIND_XTVERSION, $dcs->kind());
        self::assertSame('terminal version (XTVERSION reply): 1.20.0', $dcs->describe());
    }

    public function testTmuxPassthroughEnvelopeIsRecognised(): void
    {
        $dcs = Dcs::parse(\ord('t'), [], 0, 0, 'mux;abc');

        self::assertSame(Dcs::KIND_TMUX, $dcs->kind());
        self::assertSame('tmux passthrough (7 bytes)', $dcs->describe());
    }

    public function testGenericDcsKeepsItsFinalByteInTheLabel(): void
    {
        $dcs = Dcs::parse(\ord('p'), [], 0, 0, 'data');

        self::assertSame(Dcs::KIND_DCS, $dcs->kind());
        self::assertSame('DCS pdata', $dcs->describe());
    }

    public function testPayloadControlBytesAreEscapedNotCopied(): void
    {
        $dcs = Dcs::parse(\ord('p'), [], 0, 0, "\x1b[31m");

        self::assertStringContainsString('\x1B[31m', $dcs->describe());
        self::assertStringNotContainsString("\x1b", $dcs->describe());
    }

    public function testLongPayloadsAreClippedInTheLabel(): void
    {
        $dcs = Dcs::parse(\ord('p'), [], 0, 0, str_repeat('A', 200));

        $label = $dcs->describe();
        self::assertStringEndsWith('…', $label);
        // 'DCS p' prefix + 80 clipped bytes + the 3-byte ellipsis.
        self::assertSame(\strlen('DCS p') + 80 + 3, \strlen($label));
    }
}
