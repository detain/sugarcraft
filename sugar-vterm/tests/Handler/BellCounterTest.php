<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Tests\Handler;

use PHPUnit\Framework\TestCase;
use SugarCraft\AnsiParser\Parser\Parser;
use SugarCraft\Vterm\Buffer\Buffer;
use SugarCraft\Vterm\Handler\ScreenHandler;
use SugarCraft\Vterm\Terminal\Terminal as FullTerminal;

/**
 * BEL handling on the emulator path (audit M13): the attention cue was
 * dropped on the floor with no affordance. It is now counted — a
 * monotonic, session-level counter exposed as Terminal::bells().
 */
final class BellCounterTest extends TestCase
{
    private function handler(string $bytes): ScreenHandler
    {
        $h = new ScreenHandler(new Buffer(20, 5));
        (new Parser($h))->feed($bytes);

        return $h;
    }

    public function testBareBelCharactersAreCounted(): void
    {
        self::assertSame(3, $this->handler("\x07\x07\x07")->bells);
    }

    public function testOscTerminatorBelDoesNotCount(): void
    {
        $h = $this->handler("\x1b]2;title\x07");
        self::assertSame(0, $h->bells, 'the BEL ending an OSC string is a terminator, not a bell');
        self::assertSame('title', $h->windowTitle);
    }

    public function testMixedStreamCountsOnlyRealBells(): void
    {
        // bare bell, OSC terminated by bell, another bare bell -> 2.
        self::assertSame(2, $this->handler("\x07\x1b]2;t2\x07\x07")->bells);
    }

    public function testResetDoesNotEraseTheBellCounter(): void
    {
        $h = $this->handler("\x07");
        (new Parser($h))->feed("\x1bc");
        self::assertSame(1, $h->bells, 'RIS resets screen state, not a cue already owed to the user');
    }

    public function testFacadeExposesTheCounter(): void
    {
        $t = FullTerminal::new(20, 5);
        self::assertSame(0, $t->bells());
        $t->feed("ding\x07\x07");
        self::assertSame(2, $t->bells());
    }

    public function testCloneStartsAtTheParentCountAndDivergesFromThere(): void
    {
        $t = FullTerminal::new(20, 5);
        $t->feed("\x07");
        $clone = clone $t;
        self::assertSame(1, $clone->bells(), 'the scalar counter carries over by value');
        $clone->feed("\x07");
        self::assertSame(2, $clone->bells());
        self::assertSame(1, $t->bells(), 'feeding the clone must not ring the original');
    }
}
