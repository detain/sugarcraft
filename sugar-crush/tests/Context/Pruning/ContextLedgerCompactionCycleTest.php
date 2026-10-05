<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context\Pruning;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\Pruning\CompressionBlock;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\PruneAuthor;
use SugarCraft\Crush\Host\CompactionService;
use SugarCraft\Crush\Message;

/**
 * Roadmap 2.11: the compaction cycle the pre-compaction memory flush is
 * counted against — kept on the session's ledger, so it spans turns and every
 * route that compacts (an in-turn step summary, `/compact`, the 85% tier).
 */
final class ContextLedgerCompactionCycleTest extends TestCase
{
    public function testAFlushIsOwedOncePerCycleAndAStepSummaryStartsTheNext(): void
    {
        $ledger = ContextLedger::new();
        $this->assertTrue($ledger->memoryFlushDue(), 'a session that never flushed owes the flush');

        $flushed = $ledger->withMemoryFlushed();
        $this->assertFalse($flushed->memoryFlushDue());
        $this->assertSame($flushed, $flushed->withMemoryFlushed(), 'a second claim in the same cycle changes nothing');
        $this->assertTrue($ledger->memoryFlushDue(), 'the original is untouched');

        $summarised = $flushed->withBlock(new CompressionBlock(1, 'c1', 'summary', 100, 10, PruneAuthor::Harness));
        $this->assertSame(1, $summarised->compactionCycle);
        $this->assertTrue($summarised->memoryFlushDue(), 'a landed step summary starts a new cycle');
        $this->assertSame($summarised, $summarised->withBlock(new CompressionBlock(1, 'c1', 'summary', 100, 10, PruneAuthor::Harness)), 'the same block again is no new compaction');
    }

    public function testTheModelsOwnRangeCompressIsNotACompaction(): void
    {
        $flushed = ContextLedger::new()->withMemoryFlushed();
        $ranged = $flushed->withBlock(CompressionBlock::range(1, 'topic', 's:a', 's:b', 1, 2, 'summary', 100, 10));

        $this->assertSame(0, $ranged->compactionCycle);
        $this->assertFalse($ranged->memoryFlushDue());
    }

    public function testAHostCompactionsBoundaryRowStartsANewCycleOnSync(): void
    {
        $before = [Message::user('q1'), Message::assistant('a1')];
        $after = [...$before, Message::notice(CompactionService::COMPACTION_BOUNDARY), Message::user('q2')];

        $flushed = ContextLedger::new()->syncAgainstHistory($before)->withMemoryFlushed();
        $this->assertFalse($flushed->memoryFlushDue());
        $this->assertSame($flushed, $flushed->syncAgainstHistory($before), 'no new boundary, the same ledger');

        $landed = $flushed->syncAgainstHistory($after);
        $this->assertSame(1, $landed->compactionCycle);
        $this->assertSame(1, $landed->compactionBoundaries);
        $this->assertTrue($landed->memoryFlushDue(), 'the landed `/compact` starts a new cycle');
        $this->assertSame($landed, $landed->syncAgainstHistory($after), 'the same boundary is counted once');

        // A /rewind past the boundary moves the mark, never the cycle back,
        // so the next compaction is a new cycle again.
        $rewound = $landed->withMemoryFlushed()->syncAgainstHistory($before);
        $this->assertSame(1, $rewound->compactionCycle);
        $this->assertSame(0, $rewound->compactionBoundaries);
        $this->assertFalse($rewound->memoryFlushDue());
        $again = $rewound->syncAgainstHistory($after);
        $this->assertSame(2, $again->compactionCycle);
        $this->assertTrue($again->memoryFlushDue());

        $quoted = [...$before, Message::user(CompactionService::COMPACTION_BOUNDARY)];
        $this->assertSame(0, ContextLedger::new()->syncAgainstHistory($quoted)->compactionCycle, 'a row quoting the sentence is no boundary');
    }

    public function testTheCycleSurvivesTheStoreAndAnOlderLedgerKeepsItsShape(): void
    {
        $this->assertSame(
            ['prunes', 'droppedContextRows', 'blocks', 'nextBlockId', 'refs', 'nextRef', 'mode'],
            array_keys(ContextLedger::new()->toArray()),
            'a ledger that never compacted or flushed is stored as before',
        );

        $ledger = ContextLedger::new()
            ->syncAgainstHistory([Message::notice(CompactionService::COMPACTION_BOUNDARY)])
            ->withMemoryFlushed();
        $read = ContextLedger::fromArray(json_decode((string) json_encode($ledger->toArray()), true));

        $this->assertSame(1, $read->compactionCycle);
        $this->assertSame(1, $read->compactionBoundaries);
        $this->assertFalse($read->memoryFlushDue(), 'a resumed session does not flush the same cycle again');

        $odd = ContextLedger::fromArray(['compactionCycle' => 2, 'memoryFlushedCycle' => 5, 'compactionBoundaries' => -1]);
        $this->assertSame(2, $odd->compactionCycle);
        $this->assertSame(0, $odd->compactionBoundaries, 'an unreadable count starts again');
        $this->assertTrue($odd->memoryFlushDue(), 'a flush mark past the cycle is not trusted');
    }
}
