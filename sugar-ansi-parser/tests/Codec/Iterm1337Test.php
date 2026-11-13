<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Tests\Codec;

use PHPUnit\Framework\TestCase;
use SugarCraft\AnsiParser\Codec\Iterm1337;
use SugarCraft\AnsiParser\Parser\Handler;
use SugarCraft\AnsiParser\Parser\Parser;

/**
 * Iterm1337 codec — inline-image transfer header decode (the OSC arm)
 * plus the tmux DCS passthrough arm.
 */
final class Iterm1337Test extends TestCase
{
    public function testNon1337OscIsRejected(): void
    {
        self::assertNull(Iterm1337::parse(''));
        self::assertNull(Iterm1337::parse('8;;https://example.com'));
        self::assertNull(Iterm1337::parse('1337;')); // empty body
    }

    public function testFileTransferHeaderIsDecoded(): void
    {
        $seq = Iterm1337::parse('1337;File=name=hello%20world.png;size=3;inline=1:QUJD');

        self::assertNotNull($seq);
        self::assertTrue($seq->isFile());
        self::assertSame('File', $seq->command());
        self::assertSame('hello world.png', $seq->name());
        self::assertSame(3, $seq->declaredSize());
        self::assertTrue($seq->inline());
        self::assertSame('QUJD', $seq->payloadBase64());
        self::assertSame(4, $seq->payloadLength());
        self::assertSame(3, $seq->estimatedBytes());
        self::assertNull($seq->malformed());
        self::assertSame(
            'iTerm2 inline image "hello world.png" size=3 inline=1 3 bytes',
            $seq->describe(),
        );
    }

    public function testFullArgumentSetIsExposed(): void
    {
        $seq = Iterm1337::parse(
            '1337;File=name=x.tiff;width=10px;height=5%;preserveAspectRatio=1;type=tiff;inline=1:QQ==',
        );

        self::assertNotNull($seq);
        self::assertSame('10px', $seq->width());
        self::assertSame('5%', $seq->height());
        self::assertTrue($seq->preserveAspectRatio());
        self::assertSame('tiff', $seq->type());
        self::assertSame(1, $seq->estimatedBytes());
        self::assertSame('x.tiff', $seq->name());
    }

    public function testControlCommandsCarryNoArguments(): void
    {
        $pop = Iterm1337::parse('1337;Pop');

        self::assertNotNull($pop);
        self::assertTrue($pop->isPop());
        self::assertFalse($pop->isFile());
        self::assertSame([], $pop->args());
        self::assertSame('iTerm2 Pop', $pop->describe());

        $mark = Iterm1337::parse('1337;SetMark');

        self::assertNotNull($mark);
        self::assertSame('SetMark', $mark->command());
        self::assertSame('iTerm2 SetMark', $mark->describe());
    }

    public function testMissingPayloadSeparatorIsRecordedNotThrown(): void
    {
        $seq = Iterm1337::parse('1337;File=inline=1');

        self::assertNotNull($seq);
        self::assertTrue($seq->inline());
        self::assertSame('', $seq->payloadBase64());
        self::assertSame('missing payload separator', $seq->malformed());
        self::assertSame(
            'iTerm2 inline image inline=1 0 bytes (malformed: missing payload separator)',
            $seq->describe(),
        );
    }

    public function testParameterWithoutEqualsIsRecorded(): void
    {
        $seq = Iterm1337::parse('1337;File=inline=1;bogus:QUJD');

        self::assertNotNull($seq);
        self::assertTrue($seq->inline());
        self::assertSame('parameter without "="', $seq->malformed());
        self::assertSame('QUJD', $seq->payloadBase64());
    }

    public function testInlineDefaultsToFalseWhenAbsent(): void
    {
        $seq = Iterm1337::parse('1337;File=name=a.png;size=3:QUJD');

        self::assertNotNull($seq);
        self::assertFalse($seq->inline());
        self::assertSame('iTerm2 inline image "a.png" size=3 3 bytes', $seq->describe());
    }

    public function testPlusInNameIsKeptVerbatim(): void
    {
        // urldecode() would have folded the plus into a space; the protocol
        // percent-encodes, so a literal '+' survives raw decoding.
        $seq = Iterm1337::parse('1337;File=name=a+b.png;size=3:QUJD');

        self::assertNotNull($seq);
        self::assertSame('a+b.png', $seq->name());
        self::assertNull($seq->malformed());
        self::assertSame('iTerm2 inline image "a+b.png" size=3 3 bytes', $seq->describe());
    }

    public function testPercentEscapesStillDecode(): void
    {
        $seq = Iterm1337::parse('1337;File=name=%2Bx%20y.png;size=3:QUJD');

        self::assertNotNull($seq);
        self::assertSame('+x y.png', $seq->name());
    }

    public function testNegativeSizeIsRecordedNotDeclared(): void
    {
        $seq = Iterm1337::parse('1337;File=size=-5:QUJD');

        self::assertNotNull($seq);
        self::assertNull($seq->declaredSize());
        self::assertSame('negative size', $seq->malformed());
        self::assertStringContainsString('(malformed: negative size)', $seq->describe());
    }

    public function testPayloadShortOfDeclaredSizeIsFlaggedTruncated(): void
    {
        // QUJD decodes to 3 bytes; the header claims 100 (the stand-in for a
        // transfer the parser's cap or an early stream end cut short).
        $seq = Iterm1337::parse('1337;File=name=a.png;size=100:QUJD');

        self::assertNotNull($seq);
        self::assertSame(100, $seq->declaredSize());
        self::assertSame(3, $seq->estimatedBytes());
        self::assertSame('payload truncated', $seq->malformed());
        self::assertStringContainsString('(malformed: payload truncated)', $seq->describe());
    }

    public function testExactDeclaredSizeIsNotFlagged(): void
    {
        $seq = Iterm1337::parse('1337;File=size=3:QUJD');

        self::assertNotNull($seq);
        self::assertNull($seq->malformed());
    }

    public function testPaddingAwareTruncationEdge(): void
    {
        // 'QQ==' decodes to 1 byte; size=2 is short by one, size=1 is exact.
        $short = Iterm1337::parse('1337;File=size=2:QQ==');
        $exact = Iterm1337::parse('1337;File=size=1:QQ==');

        self::assertNotNull($short);
        self::assertNotNull($exact);
        self::assertSame(1, $short->estimatedBytes());
        self::assertSame('payload truncated', $short->malformed());
        self::assertNull($exact->malformed());
    }

    public function testDecodedNameControlBytesAreEscapedInTheLabel(): void
    {
        $seq = Iterm1337::parse('1337;File=name=%1B%5B31m;size=1:QUJD');

        self::assertNotNull($seq);
        self::assertSame("\x1b[31m", $seq->name());
        self::assertStringContainsString('\x1B[31m', $seq->describe());
        self::assertStringNotContainsString("\x1b", $seq->describe());
    }

    public function testTmuxDcsPassthroughArmUnwrapsTheInnerOsc(): void
    {
        $inner = "\x1b]1337;File=name=x.png;size=5;inline=1:QUJD\x1b\\";
        $data = 'mux;' . str_replace("\x1b", "\x1b\x1b", $inner);

        $seq = Iterm1337::fromDcs(\ord('t'), $data);

        self::assertNotNull($seq);
        self::assertSame('x.png', $seq->name());
        self::assertSame(5, $seq->declaredSize());
        self::assertTrue($seq->inline());
        self::assertSame('QUJD', $seq->payloadBase64());
    }

    public function testTmuxArmIsDeadThroughTheOwnParserAndAliveOnlyOutOfBand(): void
    {
        // Finding sugar-ansi-parser-016, documented as a live envelope: walk
        // the real tmux wire shape (ESC P tmux; ESC-doubled inner …) through
        // the real parser and prove the two halves of the truth —
        // (1) the dispatched DCS data contains NO ESC byte (DcsString stops
        // at 0x1B), so the introducer the arm searches for cannot be present;
        // (2) fromDcs() on that parser-made payload therefore returns null,
        // while the hand-built positive leg above keeps working — which is
        // exactly why the docblocks now say "out-of-band only".
        $handler = new class implements Handler {
            /** @var list<array{final:int,data:string}> */
            public array $dcs = [];

            public function printChar(string $rune): void
            {
            }

            public function execute(int $byte): void
            {
            }

            public function csiDispatch(int $final, array $params, int $prefix, int $intermediate): void
            {
            }

            public function escDispatch(int $final, int $intermediate): void
            {
            }

            public function oscDispatch(string $data): void
            {
            }

            public function dcsDispatch(int $final, array $params, int $prefix, int $intermediate, string $data): void
            {
                $this->dcs[] = ['final' => $final, 'data' => $data];
            }

            public function sosPmApcDispatch(string $kind, string $data): void
            {
            }
        };

        $inner = "\x1b]1337;File=name=x.png;size=5;inline=1:QUJD\x1b\\";
        $wire = "\x1bPtmux;" . str_replace("\x1b", "\x1b\x1b", $inner);
        (new Parser($handler))->feed($wire);

        $dcs = $handler->dcs;

        self::assertNotEmpty($dcs, 'the envelope prefix still dispatches a DCS');
        foreach ($dcs as $dispatch) {
            self::assertStringNotContainsString("\x1b", $dispatch['data'], 'parser payloads carry no ESC');
        }
        self::assertNull(Iterm1337::fromDcs($dcs[0]['final'], $dcs[0]['data']));
    }

    public function testTmuxDcsArmAcceptsTheBelTerminator(): void
    {
        $inner = "\x1b]1337;Pop\x07";
        $data = 'mux;' . str_replace("\x1b", "\x1b\x1b", $inner);

        $seq = Iterm1337::fromDcs(\ord('t'), $data);

        self::assertNotNull($seq);
        self::assertTrue($seq->isPop());
    }

    public function testTmuxDcsArmRejectsNonPassthroughDcs(): void
    {
        self::assertNull(Iterm1337::fromDcs(\ord('p'), 'mux;abc'));
        self::assertNull(Iterm1337::fromDcs(\ord('t'), 'other;abc'));
        self::assertNull(Iterm1337::fromDcs(\ord('t'), 'mux;plain-text'));
    }

    public function testDescribeNeutralizesControlBytesFromTheDcsArm(): void
    {
        // Via fromDcs the DCS body admits 0x00-0x17 Put bytes
        // (the OSC path is protected by Transitions dropping them), so the
        // non-File command verb must be escaped like the URL-decoded name.
        $inner = "\x1b]1337;Po\x0b\n\x07";
        $data = 'mux;' . str_replace("\x1b", "\x1b\x1b", $inner);

        $seq = Iterm1337::fromDcs(\ord('t'), $data);

        self::assertNotNull($seq);
        self::assertSame("Po\x0b\n", $seq->command());
        self::assertSame('iTerm2 Po\x0B\x0A', $seq->describe());
        self::assertStringNotContainsString("\n", $seq->describe());
    }
}
