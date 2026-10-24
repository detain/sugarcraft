<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\AnsiParser\Parser\DebugHandler;
use SugarCraft\AnsiParser\Parser\Parser;

/**
 * Fidelity contract: CAN (0x18) / SUB (0x1A) inside a string state cancel
 * the sequence, but the payload collected so far must DISPATCH first —
 * a cancelled OSC/SOS/PM/APC never vanishes unlisted (the pre-fix None ->
 * Ground edge dropped the whole in-flight sequence). The cancel byte is
 * consumed as the terminator, never echoed; the parser lands in Ground.
 *
 * The DCS-body leg is pinned in DcsTruncationTest; CAN/SUB inside CSI
 * params keep their Execute echo (the survival shape this fix mirrors
 * nothing — it is the untouched premise, pinned by TransitionsTest's
 * Ground rows and downstream sugar-vterm).
 */
final class StringCancelFidelityTest extends TestCase
{
    public function testCanInsideOscDispatchesTheTruncatedPayload(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        $parser->feed("\x1b]0;title\x18");

        $oscs = $handler->filter('osc');
        self::assertCount(1, $oscs, 'Cancelled OSC must surface its payload truncated at the cancel');
        self::assertSame('0;title', $oscs[0]['detail']);
        self::assertSame([], $handler->filter('execute'), 'The CAN byte is consumed as the terminator, never echoed');
    }

    public function testSubInsideOscDispatchesTheTruncatedPayload(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        $parser->feed("\x1b]8;;http://example.com\x1a");

        $oscs = $handler->filter('osc');
        self::assertCount(1, $oscs, 'SUB cancels exactly like CAN');
        self::assertSame('8;;http://example.com', $oscs[0]['detail']);
        self::assertSame([], $handler->filter('execute'));
    }

    public function testCancelledOscEmitsNothingMoreForTrailingBytes(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        // The empty-string cancel case: cancel is a terminator like ST and
        // dispatch is unconditional, so an OSC cancelled before any payload
        // dispatches the empty string (matching "\x1b]\x9c") — but nothing
        // leaks: the cancel byte is not echoed and Ground resumes, so the
        // trailing rune prints.
        $parser->feed("\x1b]\x18x");

        $oscs = $handler->filter('osc');
        self::assertCount(1, $oscs, 'Empty cancel dispatches the empty payload, as every terminator does');
        self::assertSame('', $oscs[0]['detail']);
        self::assertSame([], $handler->filter('execute'), 'The CAN byte is consumed, never echoed');
        $prints = $handler->filter('print');
        self::assertCount(1, $prints);
        self::assertSame('x', $prints[0]['detail'], 'Parser is in Ground after the cancel');
    }

    public function testStAfterCancelIsAPlainGroundExecuteNotADoubleDispatch(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        // CAN already landed the parser in Ground, so the ST that follows
        // hits the anywhere-table Execute row — it must not dispatch twice.
        $parser->feed("\x1b]a\x18\x9c");

        self::assertCount(1, $handler->filter('osc'), 'Exactly one dispatch: the truncated payload');
        $executes = $handler->filter('execute');
        self::assertCount(1, $executes, 'The late ST echoes as one Execute byte');
        self::assertSame(0x9C, $executes[0]['detail']);
    }

    /**
     * SOS / PM / APC legs through the codec dispatcher.
     *
     * @return array{string, string} [opener byte, handler type]
     */
    public static function stringKindProvider(): array
    {
        return [
            'SOS' => ["\x1bX", 'sos'],
            'PM'  => ["\x1b^", 'pm'],
            'APC' => ["\x1b_", 'apc'],
        ];
    }

    /**
     * @dataProvider stringKindProvider
     */
    public function testCanDispatchesTruncatedPayloadInEveryNonOscStringState(string $opener, string $type): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        $parser->feed($opener . 'payload' . "\x18");

        $rows = $handler->filter($type);
        self::assertCount(1, $rows, strtoupper($type) . ' cancel must dispatch, not vanish');
        self::assertSame('payload', $rows[0]['detail']);
        self::assertSame([], $handler->filter('execute'), 'The cancel byte is consumed in every string state');
    }

    public function testGroundFollowsCancelledOscAndNextSequenceParsesClean(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        // After the cancel the parser must be in Ground: a following CSI
        // parses normally, its dispatch carries no stale string state.
        $parser->feed("\x1b]a\x18\x1b[1;2H");

        $oscs = $handler->filter('osc');
        self::assertSame('a', $oscs[0]['detail']);
        $csis = $handler->filter('csi');
        self::assertCount(1, $csis, 'The sequence after a cancelled string parses in Ground');
        self::assertSame(0x48, $csis[0]['detail']['final']);
    }
}
