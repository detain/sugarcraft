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
}
