<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Modal\Msg;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Components\Modal\Msg\ClosedMsg;
use SugarCraft\Dash\Components\Modal\Msg\AnsweredYesMsg;
use SugarCraft\Dash\Components\Modal\Msg\AnsweredNoMsg;
use SugarCraft\Dash\Components\Modal\Msg\AnsweredEditMsg;
use SugarCraft\Dash\Components\Modal\Msg\AnsweredDeleteMsg;

final class MsgTest extends TestCase
{
    public function testClosedMsg(): void
    {
        $msg = new ClosedMsg();
        $this->assertInstanceOf(ClosedMsg::class, $msg);
    }

    public function testAnsweredYesMsg(): void
    {
        $msg = new AnsweredYesMsg();
        $this->assertInstanceOf(AnsweredYesMsg::class, $msg);
    }

    public function testAnsweredNoMsg(): void
    {
        $msg = new AnsweredNoMsg();
        $this->assertInstanceOf(AnsweredNoMsg::class, $msg);
    }

    public function testAnsweredEditMsg(): void
    {
        $item = 'test-item';
        $msg = new AnsweredEditMsg($item, 5);
        $this->assertInstanceOf(AnsweredEditMsg::class, $msg);
        $this->assertSame($item, $msg->item);
        $this->assertSame(5, $msg->index);
    }

    public function testAnsweredDeleteMsg(): void
    {
        $item = ['key' => 'value'];
        $msg = new AnsweredDeleteMsg($item, 10);
        $this->assertInstanceOf(AnsweredDeleteMsg::class, $msg);
        $this->assertSame($item, $msg->item);
        $this->assertSame(10, $msg->index);
    }
}
