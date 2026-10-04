<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context\Pruning;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\Pruning\CompressionBlock;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\ContextProjector;
use SugarCraft\Crush\Context\Pruning\LedgerDelta;
use SugarCraft\Crush\Context\Pruning\PruneAuthor;
use SugarCraft\Crush\Context\Pruning\PruneEntry;
use SugarCraft\Crush\Context\Pruning\PruneKind;
use SugarCraft\Crush\Context\Pruning\PruneReason;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Roadmap 2.4-1: a step summary is a ledger block — the projection swaps the
 * conversation's start for one user-role summary row and keeps everything
 * from the named step on, byte for byte.
 */
final class CompressionBlockProjectionTest extends TestCase
{
    public function testTheRowsBeforeTheKeptStepBecomeTheSummaryRow(): void
    {
        $rows = self::rows();
        $ledger = ContextLedger::new()->withBlock(self::block(1, 'c3', 'did one and two'));

        $projected = ContextProjector::new()->project($rows, $ledger)->messages;

        $this->assertCount(3, $projected);
        $this->assertInstanceOf(UserMessage::class, $projected[0], 'a user row, never a system row a provider would hoist');
        $this->assertSame(sprintf(CompressionBlock::HEADER, 1) . "\ndid one and two", $projected[0]->content());
        $this->assertTrue(CompressionBlock::isSummaryRow($projected[0]));
        $this->assertSame($rows[5], $projected[1]);
        $this->assertSame($rows[6], $projected[2]);
    }

    public function testABlockWhoseStepIsGoneIsInert(): void
    {
        $rows = self::rows();

        $this->assertSame($rows, ContextProjector::new()->project($rows, ContextLedger::new()->withBlock(self::block(1, 'gone', 's')))->messages);
    }

    public function testANewerBlockConsumesTheOlderOne(): void
    {
        $ledger = ContextLedger::new()
            ->withBlock(self::block(1, 'c2', 'first'))
            ->withBlock(self::block(2, 'c3', 'first and more'));

        $this->assertFalse($ledger->blocks[1]->active);
        $this->assertSame(2, $ledger->activeBlock()?->id);
        $this->assertSame([1], $ledger->activeBlock()->consumedBlockIds);
        $this->assertSame(3, $ledger->nextBlockId);

        $projected = ContextProjector::new()->project(self::rows(), $ledger)->messages;
        $this->assertStringEndsWith('first and more', $projected[0]->content());
        $this->assertCount(3, $projected);
    }

    public function testPrunesStillApplyToTheKeptTail(): void
    {
        $ledger = ContextLedger::new()
            ->withBlock(self::block(1, 'c2', 'one'))
            ->withPrune(new PruneEntry('c2', PruneKind::Output, PruneReason::Aged, PruneAuthor::Strategy, 10));

        $projected = ContextProjector::new()->project(self::rows(), $ledger)->messages;

        $this->assertSame('[Read b.php — output pruned to save context; re-run the tool if you need it]', $projected[2]->content());
    }

    public function testBlocksSurviveTheRoundTripAndAReplayedDelta(): void
    {
        $delta = LedgerDelta::new()->withBlock(self::block(1, 'c2', 'first'))->withBlock(self::block(2, 'c3', 'second'));
        $once = ContextLedger::new()->apply($delta);

        $this->assertEquals($once, $once->apply($delta), 'a replayed delta changes nothing');
        $this->assertEquals($once, ContextLedger::fromArray(json_decode((string) json_encode($once->toArray()), true)));
        $this->assertSame(2, ContextLedger::fromArray($once->toArray())->activeBlock()?->id, 'the active flag is restored as written');
        $this->assertNull(CompressionBlock::fromArray(['id' => 0, 'keepFromToolCallId' => 'x', 'summary' => 's', 'by' => 'harness']));
        $this->assertFalse(ContextLedger::new()->withBlock(self::block(1, 'c2', 's'))->isEmpty());
    }

    public function testTwoProjectionsAreByteIdentical(): void
    {
        $ledger = ContextLedger::new()->withBlock(self::block(1, 'c3', 'did one and two'));
        $wire = static fn (): string => (string) json_encode(array_map(
            static fn (Message $m): array => $m->toArray(),
            ContextProjector::new()->project(self::rows(), $ledger)->messages,
        ));

        $this->assertSame($wire(), $wire());
    }

    /** @return list<Message> */
    private static function rows(): array
    {
        return [
            new UserMessage('go'),
            new AssistantMessage('', [new ToolCall('c1', 'Read', ['file_path' => 'a.php'])]),
            new ToolResultMessage('c1', 'a'),
            new AssistantMessage('', [new ToolCall('c2', 'Read', ['file_path' => 'b.php'])]),
            new ToolResultMessage('c2', 'b'),
            new AssistantMessage('', [new ToolCall('c3', 'Read', ['file_path' => 'c.php'])]),
            new ToolResultMessage('c3', 'c'),
        ];
    }

    private static function block(int $id, string $keepFrom, string $summary): CompressionBlock
    {
        return new CompressionBlock($id, $keepFrom, $summary, 1_000, 10, PruneAuthor::Harness);
    }
}
