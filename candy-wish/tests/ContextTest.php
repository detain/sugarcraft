<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Wish\CancellationException;
use SugarCraft\Wish\Context;
use SugarCraft\Wish\DeadlineExceededException;

final class ContextTest extends TestCase
{
    public function testBackgroundIsNeverDone(): void
    {
        $ctx = Context::background();
        $this->assertFalse($ctx->done());
        $this->assertNull($ctx->err());
    }

    public function testWithValueAttachesKeyValue(): void
    {
        $ctx = Context::background();
        $ctx2 = $ctx->withValue('key', 'value');

        $this->assertNull($ctx->value('key'));
        $this->assertSame('value', $ctx2->value('key'));
    }

    public function testWithValueIsImmutable(): void
    {
        $ctx = Context::background();
        $ctx2 = $ctx->withValue('key', 'original');
        $ctx3 = $ctx2->withValue('key', 'changed');

        $this->assertNull($ctx->value('key'));
        $this->assertSame('original', $ctx2->value('key'));
        $this->assertSame('changed', $ctx3->value('key'));
    }

    public function testValueWalksParentChain(): void
    {
        $ctx1 = Context::background()->withValue('a', '1');
        $ctx2 = $ctx1->withValue('b', '2');
        $ctx3 = $ctx2->withValue('c', '3');

        $this->assertNull($ctx3->value('missing'));
        $this->assertSame('1', $ctx3->value('a'));
        $this->assertSame('2', $ctx3->value('b'));
        $this->assertSame('3', $ctx3->value('c'));
        $this->assertSame('1', $ctx2->value('a'));
        $this->assertNull($ctx1->value('c'));
    }

    public function testBackgroundIsNotCancelable(): void
    {
        $ctx = Context::background();
        $ctx->cancel();
        $this->assertFalse($ctx->done());
    }

    public function testWithCancelableCanBeCancelled(): void
    {
        $ctx = Context::background()->withCancelable();
        $this->assertFalse($ctx->done());

        $ctx->cancel();
        $this->assertTrue($ctx->done());
        $this->assertInstanceOf(CancellationException::class, $ctx->err());
    }

    public function testCancelWithCustomReason(): void
    {
        $reason = new \RuntimeException('custom cancel');
        $ctx = Context::background()->withCancelable();
        $ctx->cancel($reason);

        $this->assertTrue($ctx->done());
        $this->assertSame($reason, $ctx->err());
    }

    public function testDeadlineExpiryMarksContextDone(): void
    {
        $ctx = Context::background()->withDeadline(
            new \DateTimeImmutable('-1 second'),
        );
        $this->assertTrue($ctx->done());
        $this->assertInstanceOf(DeadlineExceededException::class, $ctx->err());
    }

    public function testDeadlineMarksDoneWhenExpired(): void
    {
        $ctx = Context::background()->withDeadline(
            new \DateTimeImmutable('-1 second'),
        )->withCancelable();

        $this->assertTrue($ctx->done());
        $this->assertInstanceOf(DeadlineExceededException::class, $ctx->err());
    }

    public function testFutureDeadlineIsNotDone(): void
    {
        $ctx = Context::background()->withDeadline(
            new \DateTimeImmutable('+1 hour'),
        );

        $this->assertFalse($ctx->done());
        $this->assertNull($ctx->err());
    }

    public function testWithDeadlineCancelable(): void
    {
        $ctx = Context::background()->withDeadline(
            new \DateTimeImmutable('+1 hour'),
        );

        $this->assertFalse($ctx->done());
        $ctx->cancel();
        $this->assertTrue($ctx->done());
        $this->assertInstanceOf(CancellationException::class, $ctx->err());
    }

    public function testDerivedContextIsIndependentForCancellation(): void
    {
        $base = Context::background()->withCancelable();
        $derived = $base->withValue('key', 'val');

        $this->assertFalse($base->done());
        $this->assertFalse($derived->done());

        $derived->cancel();

        $this->assertTrue($derived->done());
        $this->assertFalse($base->done());
    }

    public function testImmutabilityOfWithMethods(): void
    {
        $original = Context::background();
        $withVal = $original->withValue('k', 'v');
        $withDl = $original->withDeadline(new \DateTimeImmutable('+1h'));
        $withCan = $original->withCancelable();

        $this->assertNotSame($original, $withVal);
        $this->assertNotSame($original, $withDl);
        $this->assertNotSame($original, $withCan);
        $this->assertNull($original->value('k'));
        $this->assertNull($original->err());
    }

    public function testErrReturnsNullWhenNotDone(): void
    {
        $ctx = Context::background()->withValue('foo', 'bar');
        $this->assertNull($ctx->err());
    }
}
