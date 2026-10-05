<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context\Pruning;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\Pruning\CompressionBlock;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\PruneAuthor;
use SugarCraft\Crush\Context\Pruning\PruneEntry;
use SugarCraft\Crush\Context\Pruning\PruneKind;
use SugarCraft\Crush\Context\Pruning\PruneReason;
use SugarCraft\Crush\Context\TurnContextBlock;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResult;

/**
 * Roadmap 2.2-2: the ledger as SESSION state — the refs it hands tool results
 * (provisional on every request of a turn, fixed where the turn ends, never
 * reused), its sync against the rows a session still has, and its round trip
 * through the store's JSON.
 */
final class ContextLedgerSyncTest extends TestCase
{
    public function testProvisionalRefsFollowFirstAppearanceAndAreFixedUnchanged(): void
    {
        $turn = [new UserMessage('go'), ...self::step('a'), ...self::step('b'), new ToolResultMessage('a', 'again')];
        $ledger = ContextLedger::new();

        // Roadmap 3.B-4: the prompt is a row with a ref too, in the same order.
        $go = ContextLedger::userRowKey('go', 1);
        $this->assertSame([$go => 1, 'a' => 2, 'b' => 3], $ledger->refsFor($turn), 'one ref per row, in order of first appearance');
        $this->assertSame([$go => 1, 'a' => 2], $ledger->refsFor(\array_slice($turn, 0, 3)), 'appending rows never renumbers the rows before');
        $this->assertSame([], $ledger->refsFor([new UserMessage('go')]), 'the prompt being answered has no ref until something follows it');

        $fixed = $ledger->withRefsAssigned($turn);
        $this->assertSame($ledger->refsFor($turn), $fixed->refs, 'the turn\'s end keeps exactly the refs its requests showed');
        $this->assertSame(4, $fixed->nextRef);
        $this->assertSame(3, $fixed->refOf('b'));
        $this->assertSame('b', $fixed->toolCallIdForRef(3));
        $this->assertNull($fixed->toolCallIdForRef(9));
        $this->assertSame([], $ledger->refs, 'the original is untouched');
        $this->assertSame($fixed, $fixed->withRefsAssigned($turn), 'nothing new to fix is the same ledger');
    }

    public function testARefIsNeverReusedEvenOnceItsRowIsGone(): void
    {
        $ledger = ContextLedger::new()->withRefsAssigned([...self::step('a'), ...self::step('b')]);
        $synced = $ledger->syncAgainst(['b'], []);

        $this->assertSame(['b' => 2], $synced->refs, 'a gone call loses its ref');
        $this->assertSame(['c' => 3], $synced->refsFor(self::step('c')), 'and its number is not handed out again');
    }

    public function testSyncForgetsWhatNamesAGoneRowAndDeactivatesItsBlock(): void
    {
        $ledger = ContextLedger::new()
            ->withPrune(self::entry('kept'))
            ->withPrune(self::entry('gone'))
            ->withDroppedContextRow(ContextLedger::contextRowKey('still here'), 10)
            ->withDroppedContextRow(ContextLedger::contextRowKey('rewound'), 10)
            ->withBlock(new CompressionBlock(1, 'gone', 'summary', 100, 10, PruneAuthor::Harness));

        $synced = $ledger->syncAgainst(['kept'], [ContextLedger::contextRowKey('still here')]);

        $this->assertSame(['kept'], array_keys($synced->prunes));
        $this->assertTrue($synced->dropsContextRow('still here'));
        $this->assertFalse($synced->dropsContextRow('rewound'));
        $this->assertNull($synced->activeBlock(), 'a block whose boundary is gone is inert');
        $this->assertArrayHasKey(1, $synced->blocks, 'deactivated, not removed: its id stays taken');
        $this->assertSame(2, $synced->nextBlockId);
        $this->assertSame($synced, $synced->syncAgainst(['kept'], [ContextLedger::contextRowKey('still here')]), 'a ledger already in sync is returned as it is');
    }

    public function testSyncAgainstStoredHistoryReadsCallsResultsAndTurnContextRows(): void
    {
        $contextRow = TurnContextBlock::FENCE . "\nstate\n</turn-context>";
        $history = [
            Message::user('read it'),
            Message::assistant('')->withToolCalls([new ToolCall('Read', ['file_path' => 'a.php'], 'called')]),
            Message::assistant('out')->withToolResults([new ToolResult('Read', 'out', null, 'answered')]),
            Message::user($contextRow),
        ];
        $ledger = ContextLedger::new()
            ->withPrune(self::entry('called'))
            ->withPrune(self::entry('answered'))
            ->withPrune(self::entry('compacted away'))
            ->withDroppedContextRow(ContextLedger::contextRowKey($contextRow), 5);

        $synced = $ledger->syncAgainstHistory($history);

        $this->assertSame(['called', 'answered'], array_keys($synced->prunes));
        $this->assertTrue($synced->dropsContextRow($contextRow));
    }

    public function testRefsRoundTripThroughJsonAndACorruptRefIsDropped(): void
    {
        $ledger = ContextLedger::new()->withPrune(self::entry('a'))->withRefsAssigned([...self::step('a'), ...self::step('42')]);

        $this->assertEquals($ledger, ContextLedger::fromArray(json_decode((string) json_encode($ledger->toArray()), true)), 'an all-digit id survives its int key');

        $read = ContextLedger::fromArray(['refs' => ['x' => 3, 'y' => 3, 'z' => 0, 'w' => 'two', '' => 4], 'nextRef' => 2]);
        $this->assertSame(['x' => 3], $read->refs, 'two calls under one ref, a zero, a string and a blank id are all refused');
        $this->assertSame(4, $read->nextRef, 'the next ref is past every one kept');
    }

    /** @return list<\SugarCraft\Crush\Messages\Message> */
    private static function step(string $id): array
    {
        return [
            new AssistantMessage('', [new \SugarCraft\Crush\Tools\ToolCall($id, 'Read', ['file_path' => "{$id}.php"])]),
            new ToolResultMessage($id, "contents of {$id}"),
        ];
    }

    private static function entry(string $id): PruneEntry
    {
        return new PruneEntry($id, PruneKind::Output, PruneReason::Aged, PruneAuthor::Strategy, 100);
    }
}
