<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Msg;

use SugarCraft\Core\Msg\CursorColorMsg;
use PHPUnit\Framework\TestCase;

final class CursorColorMsgTest extends TestCase
{
    public function testHexFormat(): void
    {
        $this->assertSame('#ff8000', (new CursorColorMsg(255, 128, 0))->hex());
    }

    public function testRgbFields(): void
    {
        $msg = new CursorColorMsg(1, 2, 3);
        $this->assertSame(1, $msg->r);
        $this->assertSame(2, $msg->g);
        $this->assertSame(3, $msg->b);
    }
}
