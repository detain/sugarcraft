<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Runtime\CursorMode;

final class CursorModeTest extends TestCase
{
    public function testGumVocabularyIsKnown(): void
    {
        foreach (['blink', 'static', 'hidden'] as $mode) {
            $this->assertTrue(CursorMode::isKnown($mode), $mode);
        }
    }

    public function testUnknownModeIsRefused(): void
    {
        $this->assertFalse(CursorMode::isKnown('invisible'));
        $this->assertFalse(CursorMode::isKnown(''));
    }

    public function testOnlyHiddenHidesTheCursor(): void
    {
        $this->assertTrue(CursorMode::hidesCursor('hidden'));
        $this->assertFalse(CursorMode::hidesCursor('blink'));
        $this->assertFalse(CursorMode::hidesCursor('static'));
    }

    public function testVisibleArmsCarryDistinctDecscusrStyles(): void
    {
        // scriptui-73 (finding 099): the vocabulary was hollow until the
        // two visible arms got their own DECSCUSR bytes.
        $this->assertSame("\e[1 q", CursorMode::styleSequence('blink'));
        $this->assertSame("\e[2 q", CursorMode::styleSequence('static'));
    }

    public function testStyleChannelStaysSilentOutsideTheVisibleArms(): void
    {
        // 'hidden' is owned by the ?25l hide mechanism; an unknown mode
        // must never paint.
        $this->assertNull(CursorMode::styleSequence('hidden'));
        $this->assertNull(CursorMode::styleSequence('invisible'));
        $this->assertNull(CursorMode::styleSequence(''));
    }

    public function testResetRestoresTheTerminalDefault(): void
    {
        $this->assertSame("\e[0 q", CursorMode::resetSequence());
    }
}
