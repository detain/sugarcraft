<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Async\AsyncOps;
use SugarCraft\Async\CallbackSubscription;
use SugarCraft\Async\CancellationSource;
use SugarCraft\Async\Subscriptions;

/**
 * @covers \SugarCraft\Async\AsyncOps
 */
final class AsyncOpsLogSinkTest extends TestCase
{
    protected function tearDown(): void
    {
        // The sink is process-wide static state: never let a capture leak
        // into another test's run.
        AsyncOps::setLogSink(null);
        parent::tearDown();
    }

    public function testSwallowedCancellationFailureRoutesToTheSink(): void
    {
        $notices = [];
        AsyncOps::setLogSink(static function (string $line) use (&$notices): void {
            $notices[] = $line;
        });

        $source = CancellationSource::new();
        $source->token()->onCancel(static function (): never {
            throw new \RuntimeException('hook exploded');
        });
        $source->cancel();

        // The throwing hook neither propagated (we are still here) nor hit
        // stderr: its line landed in the collected sink array.
        $this->assertCount(1, $notices);
        $this->assertStringStartsWith('sugar-async: cancellation callback failed: hook exploded', $notices[0]);
    }

    public function testSwallowedDisposalFailureRoutesToTheSink(): void
    {
        $notices = [];
        AsyncOps::setLogSink(static function (string $line) use (&$notices): void {
            $notices[] = $line;
        });

        $subscriptions = new Subscriptions();
        $subscriptions->add(CallbackSubscription::of(static function (): never {
            throw new \RuntimeException('unsubscribe exploded');
        }));
        $subscriptions->disposeAll();

        $this->assertCount(1, $notices);
        $this->assertStringStartsWith('sugar-async: subscription disposal failed: unsubscribe exploded', $notices[0]);
    }

    public function testThrowingSinkIsSwallowedAndNeverPropagates(): void
    {
        AsyncOps::setLogSink(static function (string $line): never {
            throw new \RuntimeException('sink is down');
        });

        // A notice-seam failure must not turn a contained hook failure into
        // a propagating one: cancel() completes silently even with a broken
        // sink (the same reason the seam can never be a failure source).
        $source = CancellationSource::new();
        $source->token()->onCancel(static function (): never {
            throw new \RuntimeException('hook exploded');
        });
        $source->cancel();

        // Reaching this line is the assertion; the restore happens in tearDown.
        $this->assertTrue($source->isCancelled());
    }
}
