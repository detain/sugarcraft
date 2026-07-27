<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\PtyException;

final class PtyExceptionTest extends TestCase
{
    public function testExtendsRuntimeException(): void
    {
        $e = new PtyException('test');
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    public function testDefaultConstructor(): void
    {
        $e = new PtyException();
        $this->assertSame('', $e->getMessage());
        $this->assertSame(0, $e->getCode());
        $this->assertNull($e->getPrevious());
    }

    public function testConstructorWithMessage(): void
    {
        $e = new PtyException('open failed');
        $this->assertSame('open failed', $e->getMessage());
    }

    public function testConstructorWithCode(): void
    {
        $e = new PtyException('open failed', 42);
        $this->assertSame(42, $e->getCode());
    }

    public function testConstructorWithPrevious(): void
    {
        $prev = new \RuntimeException('root cause');
        $e = new PtyException('open failed', 0, $prev);
        $this->assertSame($prev, $e->getPrevious());
    }

    public function testThrowableInterface(): void
    {
        $e = new PtyException('test');
        $this->assertInstanceOf(\Throwable::class, $e);
    }

    public function testSubclassCanBeCaughtAsPtyException(): void
    {
        $thrown = false;
        try {
            throw new PtyException('test message', 5);
        } catch (PtyException $e) {
            $thrown = true;
            $this->assertSame('test message', $e->getMessage());
            $this->assertSame(5, $e->getCode());
        }
        $this->assertTrue($thrown);
    }

    public function testSubclassCanBeCaughtAsRuntimeException(): void
    {
        $thrown = false;
        try {
            throw new PtyException('test message', 5);
        } catch (\RuntimeException $e) {
            $thrown = true;
            $this->assertSame('test message', $e->getMessage());
        }
        $this->assertTrue($thrown);
    }
}
