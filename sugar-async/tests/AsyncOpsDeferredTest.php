<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Async\Await;
use SugarCraft\Async\AsyncOps;

final class AsyncOpsDeferredTest extends TestCase
{
    public function testTheStepRunsOnTheNextTickNotInline(): void
    {
        $loop = new StreamSelectLoop();
        $ran = false;
        $promise = AsyncOps::deferred(static function () use (&$ran): string {
            $ran = true;

            return 'value';
        }, $loop);

        self::assertFalse($ran, 'deferred() must not run the step on the caller\'s stack');
        self::assertSame('value', Await::until($promise, 2.0, $loop));
        self::assertTrue($ran);
    }

    public function testAThrowingStepRejectsWithItsException(): void
    {
        $loop = new StreamSelectLoop();
        $promise = AsyncOps::deferred(static function (): never {
            throw new \RuntimeException('step failed');
        }, $loop);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('step failed');
        Await::until($promise, 2.0, $loop);
    }
}
