<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Tests\Middleware;

use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware\Subsystem;
use SugarCraft\Wish\Middleware\Subsystem\SftpStub;
use SugarCraft\Wish\Session;
use PHPUnit\Framework\TestCase;

final class SubsystemTest extends TestCase
{
    private function session(?string $command): Session
    {
        return new Session(
            user: 'alice', clientHost: '127.0.0.1', clientPort: 1,
            serverHost: '127.0.0.1', serverPort: 22, term: 'xterm',
            cols: 80, rows: 24, tty: null, command: $command,
            lang: 'C.UTF-8',
        );
    }

    public function testDispatchesToRegisteredHandlerOnSubsystemRequest(): void
    {
        $stub = new SftpStub();
        $sub = new Subsystem();
        $sub->register('sftp', $stub);

        $nextCalled = false;
        $sub->handle(
            Context::background(),
            $this->session('subsystem sftp'),
            function () use (&$nextCalled): void {
                $nextCalled = true;
            },
        );

        $this->assertTrue($stub->wasCalled());
        $this->assertFalse($nextCalled);
    }

    public function testPassesThroughWhenNoSubsystemPrefix(): void
    {
        $stub = new SftpStub();
        $sub = new Subsystem();
        $sub->register('sftp', $stub);

        $nextCalled = false;
        $sub->handle(
            Context::background(),
            $this->session('/bin/bash'),
            function () use (&$nextCalled): void {
                $nextCalled = true;
            },
        );

        $this->assertFalse($stub->wasCalled());
        $this->assertTrue($nextCalled);
    }

    public function testPassesThroughWhenNoHandlerRegistered(): void
    {
        $sub = new Subsystem();

        $nextCalled = false;
        $sub->handle(
            Context::background(),
            $this->session('subsystem unknown'),
            function () use (&$nextCalled): void {
                $nextCalled = true;
            },
        );

        $this->assertTrue($nextCalled);
    }

    public function testPassesThroughWhenCommandIsEmpty(): void
    {
        $stub = new SftpStub();
        $sub = new Subsystem();
        $sub->register('sftp', $stub);

        $nextCalled = false;
        $sub->handle(
            Context::background(),
            $this->session(null),
            function () use (&$nextCalled): void {
                $nextCalled = true;
            },
        );

        $this->assertFalse($stub->wasCalled());
        $this->assertTrue($nextCalled);
    }

    public function testHasReturnsTrueWhenHandlerRegistered(): void
    {
        $sub = new Subsystem();
        $sub->register('sftp', new SftpStub());

        $this->assertTrue($sub->has('sftp'));
        $this->assertFalse($sub->has('unknown'));
    }

    public function testMultipleHandlers(): void
    {
        $stub1 = new SftpStub();
        $stub2 = new class implements \SugarCraft\Wish\Middleware\Subsystem\SubsystemHandler {
            public bool $called = false;
            public function handle(Context $ctx, Session $session): void
            {
                $this->called = true;
            }
        };

        $sub = new Subsystem();
        $sub->register('sftp', $stub1);
        $sub->register('whatever', $stub2);

        $sub->handle(
            Context::background(),
            $this->session('subsystem whatever'),
            function (): void {},
        );

        $this->assertFalse($stub1->wasCalled());
        $this->assertTrue($stub2->called);
    }
}
