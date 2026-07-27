<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Exception;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Exception\PoolExhaustedException;
use SugarCraft\Pty\PtyException;

final class PoolExhaustedExceptionTest extends TestCase
{
    public function testExtendsPtyException(): void
    {
        $e = new PoolExhaustedException('pool exhausted');
        $this->assertInstanceOf(PtyException::class, $e);
    }

    public function testAtCapacityMessageIncludesMaxSize(): void
    {
        $e = PoolExhaustedException::atCapacity(10);
        $this->assertStringContainsString('10', $e->getMessage());
        $this->assertStringContainsString('exhausted', $e->getMessage());
    }

    public function testAtCapacityMessageIncludesCurrentCount(): void
    {
        $e = PoolExhaustedException::atCapacity(5);
        $this->assertStringContainsString('5 sessions', $e->getMessage());
    }

    public function testAtCapacityZeroMaxSize(): void
    {
        $e = PoolExhaustedException::atCapacity(0);
        $this->assertStringContainsString('0', $e->getMessage());
    }

    public function testAtCapacityLargeMaxSize(): void
    {
        $e = PoolExhaustedException::atCapacity(1000);
        $this->assertStringContainsString('1000', $e->getMessage());
    }

    public function testMessageIsHumanReadable(): void
    {
        $e = PoolExhaustedException::atCapacity(50);
        $this->assertStringContainsString('PtyPool', $e->getMessage());
        $this->assertStringContainsString('release', $e->getMessage());
    }

    public function testCanBeCaughtAsPtyException(): void
    {
        $thrown = false;
        try {
            throw PoolExhaustedException::atCapacity(5);
        } catch (PtyException $e) {
            $thrown = true;
            $this->assertInstanceOf(PoolExhaustedException::class, $e);
        }
        $this->assertTrue($thrown);
    }

    public function testCanBeCaughtAsRuntimeException(): void
    {
        $thrown = false;
        try {
            throw PoolExhaustedException::atCapacity(5);
        } catch (\RuntimeException $e) {
            $thrown = true;
        }
        $this->assertTrue($thrown);
    }
}
