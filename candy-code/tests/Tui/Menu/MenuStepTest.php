<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui\Menu;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tui\Menu\MenuCursor;
use SugarCraft\Code\Tui\Menu\MenuNode;
use SugarCraft\Code\Tui\Menu\MenuStep;

final class MenuStepTest extends TestCase
{
    public function testNewWithoutAChoiceReportsNone(): void
    {
        $cursor = MenuCursor::closed();
        $step = MenuStep::new($cursor);

        self::assertSame($cursor, $step->cursor);
        self::assertNull($step->chosen);
    }

    public function testNewCarriesTheChosenNode(): void
    {
        $node = MenuNode::command('quit', 'Quit');
        $step = MenuStep::new(MenuCursor::closed(), $node);

        self::assertSame($node, $step->chosen);
    }
}
