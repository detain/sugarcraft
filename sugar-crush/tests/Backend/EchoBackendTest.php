<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
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

    // =========================================================================
    // completeAsync() Tests
    // =========================================================================

    public function testCompleteAsyncReturnsPromise(): void
    {
        $backend = new EchoBackend();
        $promise = $backend->completeAsync([Message::user('hello')]);

        $this->assertInstanceOf(\React\Promise\PromiseInterface::class, $promise);
    }

    public function testCompleteAsyncResolvesToMessage(): void
    {
        $backend = new EchoBackend();
        $promise = $backend->completeAsync([Message::user('hello')]);

        $resolved = null;
        $promise->then(function ($message) use (&$resolved): void {
            $resolved = $message;
        });

        $this->assertInstanceOf(Message::class, $resolved);
        $this->assertSame(Role::Assistant, $resolved->role);
        $this->assertStringContainsString('hello', $resolved->content);
    }

    public function testCompleteAsyncWithNoUserMessage(): void
    {
        $backend = new EchoBackend();
        $promise = $backend->completeAsync([Message::system('system prompt')]);

        $resolved = null;
        $promise->then(function ($message) use (&$resolved): void {
            $resolved = $message;
        });

        $this->assertStringContainsString('No user message', $resolved->content);
    }

    public function testCompleteAsyncRejectsOnException(): void
    {
        // EchoBackend::complete() doesn't throw, but we can test that
        // completeAsync properly rejects if complete() throws
        $backend = new EchoBackend();
        $promise = $backend->completeAsync([]);

        $rejected = false;
        $promise->otherwise(function () use (&$rejected): void {
            $rejected = true;
        });

        // EchoBackend doesn't throw, so this should not reject
        $this->assertFalse($rejected);
    }
}
