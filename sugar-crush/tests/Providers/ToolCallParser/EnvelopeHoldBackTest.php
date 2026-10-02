<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers\ToolCallParser;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Providers\ToolCallParser\EnvelopeHoldBack;

/**
 * Audit 15a A8: the streaming split between "paint now" and "hold until the
 * end of the stream decides whether this is a recovered envelope".
 */
final class EnvelopeHoldBackTest extends TestCase
{
    private const DSML_MARKER = "<\xEF\xBD\x9CDSML\xEF\xBD\x9Ctool_calls";

    private const MINIMAX_MARKER = '<minimax:tool_call';

    public function testWithoutMarkersEverythingIsEmittedUnchanged(): void
    {
        $this->assertSame(["text ending in a blank line\n\n<", '', false], EnvelopeHoldBack::split("text ending in a blank line\n\n<", []));
    }

    public function testACompleteMarkerHoldsItselfAndTheWhitespaceInFrontOfIt(): void
    {
        $pending = "Let me read it.\n\n" . self::DSML_MARKER . '>';

        $this->assertSame(
            ['Let me read it.', "\n\n" . self::DSML_MARKER . '>', true],
            EnvelopeHoldBack::split($pending, [self::DSML_MARKER]),
        );
    }

    public function testTheEarliestOfSeveralMarkersWins(): void
    {
        $pending = 'a ' . self::MINIMAX_MARKER . '> b ' . self::DSML_MARKER;

        [$emit, $hold, $found] = EnvelopeHoldBack::split($pending, [self::DSML_MARKER, self::MINIMAX_MARKER]);

        $this->assertSame('a', $emit);
        $this->assertTrue($found);
        $this->assertStringStartsWith(' ' . self::MINIMAX_MARKER, $hold);
    }

    /**
     * A chunk that ends part-way into a marker - including part-way into its
     * multi-byte token - waits for the next chunk to settle it.
     */
    #[DataProvider('partialMarkers')]
    public function testAPartialMarkerAtTheEndIsHeld(string $partial): void
    {
        $this->assertSame(
            ['prose ', $partial, false],
            EnvelopeHoldBack::split('prose ' . $partial, [self::DSML_MARKER, self::MINIMAX_MARKER]),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function partialMarkers(): iterable
    {
        yield 'just the angle bracket' => ['<'];
        yield 'into the DSML token' => ["<\xEF\xBD\x9CDS"];
        yield 'all but the last byte' => [substr(self::DSML_MARKER, 0, -1)];
        yield 'into the MiniMax tag' => ['<minimax:tool'];
    }

    public function testATrailingLineBreakIsHeldButATrailingSpaceIsNot(): void
    {
        $markers = [self::DSML_MARKER];

        $this->assertSame(['Paragraph.', "\n\n", false], EnvelopeHoldBack::split("Paragraph.\n\n", $markers));
        $this->assertSame(['Let me ', '', false], EnvelopeHoldBack::split('Let me ', $markers));
    }

    public function testALineBreakInFrontOfAPartialMarkerIsHeldWithIt(): void
    {
        $this->assertSame(
            ['Done.', "\n\n<", false],
            EnvelopeHoldBack::split("Done.\n\n<", [self::DSML_MARKER]),
        );
    }

    public function testTextThatCannotStartAMarkerIsReleased(): void
    {
        $this->assertSame(['<div> and more', '', false], EnvelopeHoldBack::split('<div> and more', [self::DSML_MARKER]));
    }

    /**
     * The invariant the provider's offset bookkeeping rests on: nothing is
     * lost or reordered, whichever branch is taken.
     */
    #[DataProvider('anyPending')]
    public function testEmitFollowedByHoldIsAlwaysThePendingText(string $pending): void
    {
        [$emit, $hold] = EnvelopeHoldBack::split($pending, [self::DSML_MARKER, self::MINIMAX_MARKER]);

        $this->assertSame($pending, $emit . $hold);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function anyPending(): iterable
    {
        yield 'empty' => [''];
        yield 'only whitespace' => ["\n \n"];
        yield 'marker first' => [self::DSML_MARKER . '>body'];
        yield 'marker after prose' => ["x\n\n" . self::MINIMAX_MARKER . '>'];
        yield 'partial after newline' => ["x\n<min"];
        yield 'plain' => ['nothing to see'];
    }
}
