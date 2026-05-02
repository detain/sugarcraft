<?php

declare(strict_types=1);

namespace CandyCore\Core\Tests;

use CandyCore\Core\BatchMsg;
use CandyCore\Core\Cmd;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\QuitMsg;
use PHPUnit\Framework\TestCase;

final class CmdTest extends TestCase
{
    public function testQuitReturnsQuitMsg(): void
    {
        $cmd = Cmd::quit();
        $this->assertInstanceOf(QuitMsg::class, $cmd());
    }

    public function testSendWrapsMsg(): void
    {
        $msg = new class implements Msg {};
        $this->assertSame($msg, Cmd::send($msg)());
    }

    public function testBatchReturnsBatchMsg(): void
    {
        $a = static fn() => null;
        $b = static fn() => null;
        $result = Cmd::batch($a, $b)();
        $this->assertInstanceOf(BatchMsg::class, $result);
        $this->assertCount(2, $result->cmds);
    }

    public function testBatchFiltersFalsy(): void
    {
        $a = static fn() => null;
        $result = Cmd::batch($a, null, $a)();
        $this->assertInstanceOf(BatchMsg::class, $result);
        $this->assertCount(2, $result->cmds);
    }

    public function testTickReturnsTickRequest(): void
    {
        $msg = new class implements Msg {};
        $cmd = Cmd::tick(0.5, static fn() => $msg);
        $req = $cmd();
        $this->assertInstanceOf(\CandyCore\Core\TickRequest::class, $req);
        $this->assertSame(0.5, $req->seconds);
        $this->assertSame($msg, ($req->produce)());
    }
}
