<?php

declare(strict_types=1);

namespace CandyCore\Shell\Tests\Command;

use CandyCore\Shell\Command\JoinCommand;
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
}
