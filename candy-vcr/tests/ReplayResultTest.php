<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\ReplayResult;

final class ReplayResultTest extends TestCase
{
    public function testSuccessSummary(): void
    {
        $r = new ReplayResult(
            ok: true, diff: '', eventCount: 7,
            inputCount: 1, resizeCount: 1, outputCount: 4, quitCount: 1,
            programQuitCleanly: true,
        );
        $summary = $r->diffSummary();
        $this->assertStringContainsString('replay OK', $summary);
        $this->assertStringContainsString('7 events', $summary);
        $this->assertStringContainsString('1 input', $summary);
        $this->assertStringContainsString('1 resize', $summary);
        $this->assertStringContainsString('4 output', $summary);
    }

    public function testFailureSummary(): void
    {
        $r = new ReplayResult(
            ok: false, diff: 'byte mismatch at 5', eventCount: 3,
            inputCount: 0, resizeCount: 1, outputCount: 2, quitCount: 0,
            programQuitCleanly: false,
        );
        $summary = $r->diffSummary();
        $this->assertStringContainsString('replay FAILED', $summary);
        $this->assertStringContainsString('byte mismatch at 5', $summary);
        $this->assertStringContainsString('unclean', $summary);
    }

    public function testReadonlyProperties(): void
    {
        $r = new ReplayResult(true, '', 0, 0, 0, 0, 0, true);
        $this->assertTrue($r->ok);
        $this->assertSame(0, $r->eventCount);
    }
}
