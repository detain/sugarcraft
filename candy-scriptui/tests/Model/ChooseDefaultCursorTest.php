<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Model;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\ScriptUi\Application;
use SugarCraft\ScriptUi\Model\ChooseModel;

/** scriptui-83: the default cursor glyph is gum's arrow, the golden move is deliberate. */
final class ChooseDefaultCursorTest extends TestCase
{
    public function testBareModelRendersTheArrowOnTheHighlightedRow(): void
    {
        $rows = explode("\n", Ansi::strip(ChooseModel::fromOptions(['alpha', 'beta'])->view()));

        self::assertStringStartsWith('→ alpha', $rows[0]);
        self::assertStringStartsWith('  beta', $rows[1]);
    }

    public function testExplicitCursorStillWins(): void
    {
        $view = Ansi::strip(ChooseModel::fromOptions(['alpha'], cursorPrefix: '> ')->view());

        self::assertStringStartsWith('> alpha', $view);
    }

    public function testCommandDefaultAdvertisesTheArrow(): void
    {
        $option = (new Application())->find('choose')->getDefinition()->getOption('cursor');

        self::assertSame(['→ '], $option->getDefault());
    }
}
