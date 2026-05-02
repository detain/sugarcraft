<?php

declare(strict_types=1);

namespace CandyCore\Crush\Tests\Backend;

use CandyCore\Crush\Backend\EchoBackend;
use CandyCore\Crush\Message;
use CandyCore\Crush\Role;
use PHPUnit\Framework\TestCase;

final class EchoBackendTest extends TestCase
{
    public function testEchoesLastUserMessage(): void
    {
        $backend = new EchoBackend();
        $reply = $backend->complete([
            Message::system('be helpful'),
            Message::user('hello'),
        ]);
        $this->assertSame(Role::Assistant, $reply->role);
        $this->assertStringContainsString('hello', $reply->content);
    }

    public function testReturnsPlaceholderWhenNoUserTurn(): void
    {
        $backend = new EchoBackend();
        $reply = $backend->complete([Message::system('be helpful')]);
        $this->assertSame(Role::Assistant, $reply->role);
        $this->assertStringContainsString('No user message', $reply->content);
    }

    public function testEchoesMostRecentUserNotEarlierOnes(): void
    {
        $backend = new EchoBackend();
        $reply = $backend->complete([
            Message::user('first'),
            Message::assistant('reply 1'),
            Message::user('most recent'),
        ]);
        $this->assertStringContainsString('most recent', $reply->content);
        $this->assertStringNotContainsString('first', $reply->content);
    }
}
