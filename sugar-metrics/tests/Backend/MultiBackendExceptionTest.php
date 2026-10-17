<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Tests\Backend;

use SugarCraft\Metrics\Backend\MultiBackendException;
use PHPUnit\Framework\TestCase;

final class MultiBackendExceptionTest extends TestCase
{
    public function testGetErrorsReturnsTheCollectedThrowables(): void
    {
        $a = new \RuntimeException('a');
        $b = new \LogicException('b');
        $e = new MultiBackendException([$a, $b]);
        $this->assertSame([$a, $b], $e->getErrors());
    }

    public function testIsARuntimeException(): void
    {
        $this->assertInstanceOf(\RuntimeException::class, new MultiBackendException([]));
    }

    public function testDefaultMessageSummarisesChildFailures(): void
    {
        $e = new MultiBackendException([
            new \RuntimeException('udp send failed'),
            new \RuntimeException('rename failed'),
        ]);
        $this->assertSame(
            'MultiBackend: 2 child backend(s) failed. Errors: udp send failed; rename failed',
            $e->getMessage(),
        );
    }

    public function testCustomMessageOverridesTheDefault(): void
    {
        $e = new MultiBackendException([new \RuntimeException('x')], 'custom summary');
        $this->assertSame('custom summary', $e->getMessage());
    }

    public function testFirstErrorIsChainedAsPrevious(): void
    {
        $first = new \RuntimeException('first');
        $e = new MultiBackendException([$first, new \RuntimeException('second')]);
        $this->assertSame($first, $e->getPrevious());
    }

    public function testEmptyErrorListHasNoPreviousAndZeroCount(): void
    {
        $e = new MultiBackendException([]);
        $this->assertNull($e->getPrevious());
        $this->assertStringContainsString('0 child backend(s) failed', $e->getMessage());
    }
}
