<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Context\CompactionSource;
use SugarCraft\Code\Host\CompactionService;

final class CompactionSourceTest extends TestCase
{
    public function testModelSourceIsNotAFallback(): void
    {
        $source = CompactionSource::model();

        $this->assertTrue($source->model);
        $this->assertNull($source->reason);
    }

    public function testHeuristicSourceCarriesItsReason(): void
    {
        $source = CompactionSource::heuristic(CompactionSource::REASON_SPEND_CAP);

        $this->assertFalse($source->model);
        $this->assertSame(CompactionSource::REASON_SPEND_CAP, $source->reason);
    }

    public function testAHeuristicWithoutAKnownReasonIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CompactionSource::heuristic('because');
    }

    /**
     * The boundary notice without a source is the text every caller before #7
     * got; a source only appends to it, so nothing that reads the old sentence
     * changes.
     */
    public function testTheBoundaryNoticeOnlyGainsASuffixWhenASourceIsGiven(): void
    {
        $bare = CompactionService::contextCompactedMessage(30, 12, 40, 5000, 100000);
        $withModel = CompactionService::contextCompactedMessage(30, 12, 40, 5000, 100000, CompactionSource::model());
        $withHeuristic = CompactionService::contextCompactedMessage(
            30,
            12,
            40,
            5000,
            100000,
            CompactionSource::heuristic(CompactionSource::REASON_MODEL_FAILED),
        );

        $this->assertStringStartsWith($bare->content, $withModel->content);
        $this->assertStringStartsWith($bare->content, $withHeuristic->content);
        $this->assertGreaterThan(strlen($bare->content), strlen($withModel->content));
        $this->assertStringStartsWith($bare->content, $withHeuristic->content);
    }
}
