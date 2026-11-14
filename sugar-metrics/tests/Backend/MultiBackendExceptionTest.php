<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Tests\Backend;

use SugarCraft\Metrics\Backend\MultiBackendException;
use PHPUnit\Framework\TestCase;

final class MultiBackendExceptionTest extends TestCase
{
    public function testErrorsReturnsTheCollectedThrowables(): void
    {
        $a = new \RuntimeException('a');
        $b = new \LogicException('b');
        $e = new MultiBackendException([$a, $b]);
        $this->assertSame([$a, $b], $e->errors());
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

    /**
     * sugar-metrics-020: the Util::displaySafe sweep of child messages is
     * the only behaviour of this class beyond list storage, and every prior
     * test fed it clean strings. A hostile third-party backend interpolating
     * raw control bytes into its message must not steer the host TUI when
     * the aggregate is printed verbatim — while errors() still hands back
     * the ORIGINAL Throwables (the sweep happens at aggregation, not at
     * storage, so programmatic inspection stays lossless).
     */
    public function testHostileChildMessagesAreSweptAtAggregationNotAtStorage(): void
    {
        $hostile = new \RuntimeException("udp failed\x1b[2J\n/home/metrics\x07\x7f");
        $e = new MultiBackendException([$hostile]);
        $msg = $e->getMessage();
        // CSI clears, bell and DEL are erased; the newline (a legal display
        // byte, see Util::displaySafe) survives — the swept form is exact:
        $this->assertSame(
            'MultiBackend: 1 child backend(s) failed. Errors: udp failed' . "\n" . '/home/metrics',
            $msg,
        );
        $this->assertStringNotContainsString("\x1b", $msg);
        $this->assertStringNotContainsString("\x07", $msg);
        $this->assertStringNotContainsString("\x7f", $msg);
        // Sweep-at-aggregation: the stored Throwable is byte-for-byte intact.
        $this->assertSame([$hostile], $e->errors());
        $this->assertSame("udp failed\x1b[2J\n/home/metrics\x07\x7f", $e->errors()[0]->getMessage());
    }
}
