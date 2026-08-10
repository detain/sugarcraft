<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Palette;

use SugarCraft\Crush\Palette\PaletteAction;
use PHPUnit\Framework\TestCase;

final class PaletteActionTest extends TestCase
{
    public function testEveryActionHasALabelAndCategory(): void
    {
        foreach (PaletteAction::all() as $action) {
            $this->assertNotSame('', $action->label());
            $this->assertNotSame('', $action->category());
        }
    }

    public function testByLabelResolvesBackToTheSameAction(): void
    {
        foreach (PaletteAction::all() as $action) {
            $this->assertSame($action, PaletteAction::byLabel($action->label()));
        }
    }

    public function testByLabelReturnsNullForUnknownLabel(): void
    {
        $this->assertNull(PaletteAction::byLabel('Not a real action'));
    }

    public function testOnlyExitHasAShortcut(): void
    {
        foreach (PaletteAction::all() as $action) {
            if ($action === PaletteAction::Exit) {
                $this->assertNotNull($action->shortcut());
            } else {
                $this->assertNull($action->shortcut());
            }
        }
    }
}
