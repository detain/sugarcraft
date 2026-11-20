<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\CancellationToken;

/**
 * The push half of {@see CancellationToken}: what `WorkflowEngine` registers
 * so Chat's double-Escape kills a workflow stage's agents at once, rather
 * than waiting for a fiber suspended in the pool to look at a flag.
 */
final class CancellationTokenListenerTest extends TestCase
{
    public function testAListenerRunsOnceWhenTheTokenIsCancelled(): void
    {
        $token = new CancellationToken();
        $calls = 0;
        $token->onCancel(static function () use (&$calls): void {
            $calls++;
        });

        self::assertSame(0, $calls, 'registering does not run it');
        $token->cancel();
        $token->cancel();

        self::assertSame(1, $calls, 'a second cancel is a no-op');
        self::assertTrue($token->isCancelled());
    }

    public function testAListenerRegisteredAfterTheCancelRunsAtOnce(): void
    {
        $token = new CancellationToken();
        $token->cancel();
        $calls = 0;

        $token->onCancel(static function () use (&$calls): void {
            $calls++;
        });

        self::assertSame(1, $calls);
    }

    public function testADetachedListenerNeverRuns(): void
    {
        $token = new CancellationToken();
        $calls = 0;
        $detach = $token->onCancel(static function () use (&$calls): void {
            $calls++;
        });

        $detach();
        $token->cancel();

        self::assertSame(0, $calls);
    }

    /**
     * The token is a wrapper over sugar-async's CancellationSource, so a throwing
     * listener is logged and never propagates out of cancel(); the others still run.
     */
    public function testAThrowingListenerDoesNotStopTheOthersOrEscapeCancel(): void
    {
        $token = new CancellationToken();
        $ran = [];
        $token->onCancel(static function () use (&$ran): void {
            $ran[] = 'first';
        });
        $token->onCancel(static function (): void {
            throw new \RuntimeException('listener failure');
        });
        $token->onCancel(static function () use (&$ran): void {
            $ran[] = 'third';
        });

        $token->cancel();

        $this->assertTrue($token->isCancelled());
        $this->assertSame(['first', 'third'], $ran);
    }
}
