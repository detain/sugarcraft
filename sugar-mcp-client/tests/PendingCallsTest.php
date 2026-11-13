<?php

declare(strict_types=1);

namespace SugarCraft\McpClient\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\McpClient\PendingCalls;

final class PendingCallsTest extends TestCase
{
    /** @return array{PendingCalls, \ArrayObject<int, array{?\Throwable, mixed}>} */
    private function fixture(): array
    {
        return [new PendingCalls(), new \ArrayObject()];
    }

    private static function sink(\ArrayObject $log): \Closure
    {
        return static function (?\Throwable $f, mixed $r) use ($log): void {
            $log[] = [$f, $r];
        };
    }

    public function testSettleDeliversOnceAndForgets(): void
    {
        [$p, $log] = $this->fixture();
        $p->register('a1', self::sink($log));
        self::assertTrue($p->has('a1'));
        self::assertSame(1, $p->count());
        self::assertTrue($p->settle('a1', ['ok' => 1]));
        self::assertSame([[null, ['ok' => 1]]], $log->getArrayCopy());
        self::assertFalse($p->settle('a1', 'again'));
        self::assertSame(0, $p->count());
    }

    public function testUnknownIdIsReportedNotThrown(): void
    {
        self::assertFalse((new PendingCalls())->settle('nope', 1));
        self::assertFalse((new PendingCalls())->fail('nope', new \RuntimeException('x')));
    }

    public function testFailDeliversTheThrowable(): void
    {
        [$p, $log] = $this->fixture();
        $e = new \RuntimeException('boom');
        $p->register('a', self::sink($log));
        self::assertTrue($p->fail('a', $e));
        self::assertSame($e, $log[0][0]);
    }

    public function testFailAllRejectsEveryEntryWithTheReason(): void
    {
        [$p, $log] = $this->fixture();
        $p->register('a', self::sink($log));
        $p->register('b', self::sink($log));
        self::assertSame(2, $p->failAll('socket closed'));
        self::assertSame(0, $p->count());
        self::assertCount(2, $log);
        self::assertInstanceOf(\RuntimeException::class, $log[0][0]);
        self::assertSame('socket closed', $log[1][0]->getMessage());
        self::assertSame(0, $p->failAll('again'));
    }

    public function testFailAllAcceptsAThrowableAsIs(): void
    {
        [$p, $log] = $this->fixture();
        $e = new \LogicException('x');
        $p->register('a', self::sink($log));
        $p->failAll($e);
        self::assertSame($e, $log[0][0]);
    }

    public function testRetryRegisteredFromAFailAllCallbackLandsInAFreshTable(): void
    {
        $p = new PendingCalls();
        $p->register('a', static function () use ($p): void {
            $p->register('a', static fn () => null);
        });
        $p->failAll('down');
        self::assertTrue($p->has('a'));
        self::assertSame(1, $p->count());
    }

    public function testDuplicateIdIsRefused(): void
    {
        $p = new PendingCalls();
        $p->register('a', static fn () => null);
        $this->expectException(\LogicException::class);
        $p->register('a', static fn () => null);
    }

    public function testPreservingFlagReadsBackUntilSettled(): void
    {
        $p = new PendingCalls();
        $p->register('a', static fn () => null, true);
        $p->register('b', static fn () => null);
        self::assertTrue($p->isPreserving('a'));
        self::assertFalse($p->isPreserving('b'));
        $p->settle('a', null);
        self::assertFalse($p->isPreserving('a'));
    }
}
