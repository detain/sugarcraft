<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\Gpu;

use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use SugarCraft\Top\Collect\Gpu\Settled;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Settled is the GPU collectors' sync-read trick (candy-top-087): the
 * pending and rejecting arms were never exercised — a promise read through
 * value() while unresolved must be the loud LogicException, never a silent
 * null, and a rejection must surface the original thrower.
 */
final class SettledTest extends TestCase
{
    public function testAResolvedPromiseUnwrapsItsValue(): void
    {
        $this->assertSame([true, 42], Settled::peek(resolve(42)));
        $this->assertSame(42, Settled::value(resolve(42)));
    }

    public function testAPendingPromisePeeksAsUnsettled(): void
    {
        [$done, $value] = Settled::peek((new Deferred())->promise());
        $this->assertFalse($done);
        $this->assertNull($value);
    }

    public function testAPendingPromiseReadAsValueThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('still pending');
        Settled::value((new Deferred())->promise());
    }

    public function testARejectedPromiseReraisesThroughBothReaders(): void
    {
        $boom = new \RuntimeException('collector exploded');
        foreach ([reject($boom), reject($boom)] as $i => $promise) {
            try {
                $i === 0 ? Settled::peek($promise) : Settled::value($promise);
                $this->fail('the rejection must rethrow');
            } catch (\RuntimeException $e) {
                $this->assertSame($boom, $e, 'the original thrower, unwrapped');
            }
        }
    }
}
