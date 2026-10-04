<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Events;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\Pruning\CompressionBlock;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\LedgerDelta;
use SugarCraft\Crush\Context\Pruning\PruneAuthor;
use SugarCraft\Crush\Context\Pruning\PruneEntry;
use SugarCraft\Crush\Context\Pruning\PruneKind;
use SugarCraft\Crush\Context\Pruning\PruneReason;
use SugarCraft\Crush\Events\ContextLedgerChanged;

/**
 * Roadmap 3.B-3: a ledger change survives its frame form — plain arrays, so
 * it can cross a socket the parent reads with `allowed_classes => false` —
 * and a frame it did not write, or one with nothing left to apply, rebuilds
 * to null rather than to a change nobody made.
 */
final class ContextLedgerChangedTest extends TestCase
{
    public function testTheFrameFormRoundTripsAndAppliesAsTheOriginalDoes(): void
    {
        $delta = LedgerDelta::new()
            ->withPrune(new PruneEntry('a', PruneKind::Output, PruneReason::Noise, PruneAuthor::Model, 40))
            ->withPrune(new PruneEntry('b', PruneKind::Distilled, PruneReason::Done, PruneAuthor::Model, 30, 'the gist'))
            ->withDroppedContextRow(ContextLedger::contextRowKey('old state'), 12)
            ->withBlock(new CompressionBlock(1, 'k', 'summary', 900, 50, PruneAuthor::Harness));
        $event = new ContextLedgerChanged($delta, 'call_7');

        $frame = unserialize(serialize($event->toArray()), ['allowed_classes' => false]);
        $back = ContextLedgerChanged::fromArray($frame);

        $this->assertNotNull($back);
        $this->assertSame('call_7', $back->toolCallId);
        $this->assertEquals($delta, $back->delta);
        $this->assertEquals(ContextLedger::new()->apply($delta), ContextLedger::new()->apply($back->delta));
    }

    public function testAFrameItDidNotWriteOrThatCarriesNothingIsNull(): void
    {
        $this->assertNull(ContextLedgerChanged::fromArray('garbage'));
        $this->assertNull(ContextLedgerChanged::fromArray(['toolCallId' => 'x']));
        $this->assertNull(ContextLedgerChanged::fromArray(['delta' => ['prunes' => ['not an entry']]]), 'nothing left to apply');

        $kept = ContextLedgerChanged::fromArray(['delta' => ['prunes' => [
            'not an entry',
            ['toolCallId' => 'd', 'kind' => 'distilled', 'reason' => 'done', 'by' => 'model', 'tokens' => 1],
            (new PruneEntry('a', PruneKind::Output, PruneReason::Done, PruneAuthor::Model, 5))->toArray(),
        ]]]);
        $this->assertNotNull($kept);
        $this->assertSame(['a'], array_map(static fn (PruneEntry $e): string => $e->toolCallId, $kept->delta->prunes), 'a distilled entry without its text is skipped');
        $this->assertSame('', $kept->toolCallId);
    }
}
