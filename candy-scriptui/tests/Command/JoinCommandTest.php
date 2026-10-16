<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Command;

use SugarCraft\ScriptUi\Command\JoinCommand;
use PHPUnit\Framework\TestCase;

final class JoinCommandTest extends TestCase
{
    public function testHorizontalJoinPadsShorterBlocks(): void
    {
        $a = "row1a\nrow2a";
        $b = "row1b";
        $out = JoinCommand::joinHorizontal([$a, $b], ' | ');
        $this->assertSame("row1a | row1b\nrow2a | ", $out);
    }

    public function testHorizontalJoinEmpty(): void
    {
        $this->assertSame('', JoinCommand::joinHorizontal([]));
    }

    public function testHorizontalJoinSingleBlock(): void
    {
        $this->assertSame("a\nb", JoinCommand::joinHorizontal(["a\nb"]));
    }

    public function testHorizontalAlignTopIsDefault(): void
    {
        $a = "a1\na2\na3";
        $b = "b1";
        $out = JoinCommand::joinHorizontal([$a, $b], '|', 'top');
        $this->assertSame("a1|b1\na2|\na3|", $out);
    }

    public function testHorizontalAlignMiddleCentersShorterBlock(): void
    {
        $a = "a1\na2\na3";
        $b = "b1";
        $out = JoinCommand::joinHorizontal([$a, $b], '|', 'middle');
        $this->assertSame("a1|\na2|b1\na3|", $out);
    }

    public function testHorizontalAlignBottomPadsTop(): void
    {
        $a = "a1\na2\na3";
        $b = "b1";
        $out = JoinCommand::joinHorizontal([$a, $b], '|', 'bottom');
        $this->assertSame("a1|\na2|\na3|b1", $out);
    }

    public function testVerticalAlignLeftIsDefault(): void
    {
        $out = JoinCommand::joinVertical(['ab', 'short'], "\n", 'left');
        $this->assertSame("ab\nshort", $out);
    }

    public function testVerticalAlignCenterPadsLeftAndRight(): void
    {
        $out = JoinCommand::joinVertical(['ab', 'short'], "\n", 'center');
        $lines = explode("\n", $out);
        $this->assertCount(2, $lines);
        $this->assertSame('short', $lines[1]);
        $this->assertStringContainsString('ab', $lines[0]);
        $this->assertSame(5, mb_strlen($lines[0], 'UTF-8'));
    }

    public function testVerticalAlignRightPadsLeft(): void
    {
        $out = JoinCommand::joinVertical(['ab', 'short'], "\n", 'right');
        $lines = explode("\n", $out);
        $this->assertSame('   ab', $lines[0]);
        $this->assertSame('short', $lines[1]);
    }
}
