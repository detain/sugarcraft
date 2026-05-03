<?php

declare(strict_types=1);

namespace CandyCore\Tetris\Tests;

use CandyCore\Core\Msg;
use CandyCore\Tetris\GravityMsg;
use PHPUnit\Framework\TestCase;

final class GravityMsgTest extends TestCase
{
    public function testIsAMsg(): void
    {
        $this->assertInstanceOf(Msg::class, new GravityMsg());
    }
}
