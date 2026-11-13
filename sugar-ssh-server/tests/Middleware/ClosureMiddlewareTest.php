<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Tests\Middleware;

use PHPUnit\Framework\TestCase;
use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Middleware\ClosureMiddleware;
use SugarCraft\SshServer\Session;

/**
 * Pins for the one-liner stack door (audit 075): the closure's return
 * value passes through untouched — nothing, a promise, or (refused by
 * the dispatcher) a stray.
 */
final class ClosureMiddlewareTest extends TestCase
{
    private function session(): Session
    {
        return new Session(user: 'alice', clientHost: '10.0.0.1');
    }

    public function testTheClosureRunsAndGetTheFullContractArguments(): void
    {
        $seen  = [];
        $mw    = new ClosureMiddleware(
            function (Context $ctx, Session $session, callable $next) use (&$seen): void {
                $seen[] = ['done' => $ctx->done(), 'user' => $session->user];
            },
        );

        $mw->handle(Context::background(), $this->session(), function (): void {
            $seen[] = 'next';
        });

        $this->assertSame([['done' => false, 'user' => 'alice']], $seen, 'ran, and did not call next for us');
    }

    public function testAPromiseReturnedByTheClosurePassesThroughIdentically(): void
    {
        $promise = (new \React\Promise\Deferred())->promise();
        $mw      = new ClosureMiddleware(function () use ($promise) {
            return $promise;
        });

        $this->assertSame($promise, $mw->handle(Context::background(), $this->session(), function (): void {}));
    }

    public function testANullReturnMeansSyncVoidLikeAnyOtherNullReturningDoor(): void
    {
        $mw = new ClosureMiddleware(function (): void {});

        $this->assertNull($mw->handle(Context::background(), $this->session(), function (): void {}));
    }
}
