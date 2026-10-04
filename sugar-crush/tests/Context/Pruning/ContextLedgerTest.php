<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context\Pruning;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\LedgerDelta;
use SugarCraft\Crush\Context\Pruning\PruneAuthor;
use SugarCraft\Crush\Context\Pruning\PruneEntry;
use SugarCraft\Crush\Context\Pruning\PruneKind;
use SugarCraft\Crush\Context\Pruning\PruneReason;

/**
 * Roadmap 2.2-1: the ledger of what has been taken out of the model's view —
 * immutable, idempotent under a replayed delta, and lenient when read back.
 */
final class ContextLedgerTest extends TestCase
{
    public function testANewLedgerIsEmptyAndWithersNeverMutate(): void
    {
        $ledger = ContextLedger::new();
        $this->assertTrue($ledger->isEmpty());

        $pruned = $ledger->withPrune(self::entry('c1'));
        $this->assertTrue($ledger->isEmpty(), 'the original is untouched');
        $this->assertTrue($pruned->isPruned('c1'));
        $this->assertFalse($pruned->isPruned('c2'));
        $this->assertSame(500, $pruned->prune('c1')?->tokens);

        $dropped = $pruned->withDroppedContextRow(ContextLedger::contextRowKey('row'), 40);
        $this->assertFalse($pruned->dropsContextRow('row'));
        $this->assertTrue($dropped->dropsContextRow('row'));
        $this->assertFalse($dropped->dropsContextRow('other row'));
    }

    public function testTheFirstEntryForACallWins(): void
    {
        $ledger = ContextLedger::new()->withPrune(self::entry('c1', 500))->withPrune(self::entry('c1', 9));

        $this->assertSame(500, $ledger->prune('c1')?->tokens);
    }

    public function testApplyingTheSameDeltaTwiceChangesNothing(): void
    {
        $delta = LedgerDelta::new()
            ->withPrune(self::entry('c1'))
            ->withPrune(self::entry('c2'))
            ->withDroppedContextRow(ContextLedger::contextRowKey('old'), 120);

        $once = ContextLedger::new()->apply($delta);
        $twice = $once->apply($delta);

        $this->assertEquals($once, $twice);
        $this->assertSame(['c1', 'c2'], array_keys($once->prunes));
        $this->assertSame(500 + 500 + 120, $delta->freedTokens());
    }

    public function testItRoundTripsThroughAnArray(): void
    {
        $ledger = ContextLedger::new()
            ->withPrune(self::entry('c1'))
            ->withDroppedContextRow(ContextLedger::contextRowKey('old'), 120);

        $this->assertEquals($ledger, ContextLedger::fromArray(json_decode((string) json_encode($ledger->toArray()), true)));
    }

    public function testReadingBackSkipsWhatItCannotReadRatherThanLosingEverything(): void
    {
        $ledger = ContextLedger::fromArray([
            'prunes' => [
                self::entry('good')->toArray(),
                ['toolCallId' => 'bad-kind', 'kind' => 'shredded', 'reason' => 'aged', 'by' => 'strategy', 'tokens' => 1],
                ['toolCallId' => '', 'kind' => 'output', 'reason' => 'aged', 'by' => 'strategy'],
                'not an entry',
            ],
            'droppedContextRows' => ['abc' => 10, 'def' => 'ten', 7 => 3],
        ]);

        $this->assertSame(['good'], array_keys($ledger->prunes));
        $this->assertSame(['abc' => 10], $ledger->droppedContextRows);
        $this->assertTrue(ContextLedger::fromArray('garbage')->isEmpty());
        $this->assertTrue(ContextLedger::fromArray(null)->isEmpty());
    }

    private static function entry(string $id, int $tokens = 500): PruneEntry
    {
        return new PruneEntry($id, PruneKind::Output, PruneReason::Aged, PruneAuthor::Strategy, $tokens);
    }
}
