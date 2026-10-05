<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\Pruning\CompressionBlock;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\ContextProjector;
use SugarCraft\Crush\Context\Pruning\LedgerDelta;
use SugarCraft\Crush\Context\Pruning\NudgePolicy;
use SugarCraft\Crush\Context\Pruning\PruneAuthor;
use SugarCraft\Crush\Context\Pruning\PruningMode;
use SugarCraft\Crush\Context\Pruning\RefTag;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Tools\BuiltIn\Compress;
use SugarCraft\Crush\Tools\MutatesContextLedger;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Roadmap 3.B-4 (DCP §13.2 F): the model's `Compress` replaces a closed range
 * of the conversation — from one ref to another, prompts and steps alike —
 * with its own summary, as a range block in the turn's ledger the projection
 * shows in the range's place. Nested blocks become `(bN)` placeholders; a
 * summary that saves nothing, or a nest that expands past 16K, is refused;
 * `Task` / `Skill` outputs ride along verbatim; and the tool is manual by
 * default — it runs only with the turn's `/compress` allowance.
 */
final class CompressToolTest extends TestCase
{
    private ContextLedger $ledger;

    /** @var list<LedgerDelta> */
    private array $applied = [];

    private int $budget = 1;

    protected function setUp(): void
    {
        $this->ledger = ContextLedger::new()->withDefaultMode(PruningMode::Auto);
    }

    public function testItIsALedgerToolTheCatalogDiscovers(): void
    {
        $this->assertInstanceOf(MutatesContextLedger::class, Compress::new());
        $this->assertSame('Compress', Compress::new()->name());
        $this->assertSame(['topic', 'ranges'], Compress::new()->inputSchema()['required']);
    }

    public function testWithoutItsLedgerOrAllowanceEveryCallIsRefused(): void
    {
        $this->assertStringContainsString('not available in this run', Compress::new()->execute(self::args('r1', 'r4'))->content());

        $unallowed = Compress::new()->withLedger(fn (): array => [$this->ledger, self::rows()], fn (LedgerDelta $d): ContextLedger => $this->ledger->apply($d));
        $result = $unallowed->execute(self::args('r1', 'r4'));
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('only when the person asks for it with /compress', $result->content());
    }

    public function testARangeBecomesOneSummaryRowAndTheProtectedOutputRidesAlong(): void
    {
        $result = $this->bound()->execute(self::args('r1', 'r4', 'Explored auth: a.php holds login(), b.php holds logout().'));

        $this->assertFalse($result->isError(), $result->content());
        $this->assertMatchesRegularExpression('/^Compressed 6 rows \(~[\d.]+K? tokens\) into block b1 \(~\d+ tokens\)\.$/', $result->content());
        $this->assertCount(1, $this->applied);
        $block = $this->ledger->block(1);
        $this->assertNotNull($block);
        $this->assertTrue($block->isRange());
        $this->assertSame(PruneAuthor::Model, $block->by);
        $this->assertSame(ContextLedger::userRowKey('explore auth', 1), $block->fromKey, 'starts at the prompt it named');
        $this->assertSame(ContextLedger::stepKey('c2'), $block->toKey, 'ends at the end of the step whose result it named');
        $this->assertSame(0, $this->budget, 'the allowance is spent');

        $projected = ContextProjector::new()->withRefTags()->project(self::rows(), $this->ledger)->messages;
        $this->assertCount(6, $projected, 'rows 0-5 became one row');
        $summary = $projected[0];
        $this->assertTrue(CompressionBlock::isSummaryRow($summary));
        $this->assertStringStartsWith('[Compressed section b1: "Auth exploration" — replaces r1…r4]', $summary->content());
        $this->assertStringContainsString('a.php holds login()', $summary->content());
        $this->assertStringContainsString("### Task (r3)\nThe sub-agent report", $summary->content(), 'a Task report is kept verbatim');
        $this->assertStringNotContainsString(self::big('a'), $summary->content());
        $this->assertSame(RefTag::appendTo('now fix it', 5), $projected[2]->content(), 'the rows after keep their refs');
    }

    public function testASecondCallOnTheSameTriggerIsRefused(): void
    {
        $tool = $this->bound();
        $this->assertFalse($tool->execute(self::args('r1', 'r4'))->isError());

        $again = $tool->execute(self::args('r5', 'r6'));
        $this->assertTrue($again->isError());
        $this->assertCount(1, $this->applied);
    }

    public function testARefusedCallLeavesTheAllowanceForTheRetry(): void
    {
        $tool = $this->bound();
        $this->assertTrue($tool->execute(self::args('r4', 'r1'))->isError());
        $this->assertSame(1, $this->budget);
        $this->assertFalse($tool->execute(self::args('r1', 'r4'))->isError());
    }

    public function testBoundariesAreValidated(): void
    {
        $this->assertStringContainsString('comes after', $this->bound()->execute(self::args('r4', 'r1'))->content());
        $this->assertStringContainsString('not a ref in your context', $this->bound()->execute(self::args('r1', 'r99'))->content());
        $this->assertStringContainsString('not a compressed section', $this->bound()->execute(self::args('b7', 'r4'))->content());
        $this->assertStringContainsString('topic must be', $this->bound()->execute(['topic' => ' ', 'ranges' => [['from' => 'r1', 'to' => 'r2', 'summary' => 's']]])->content());
        $this->assertStringContainsString('ranges must be', $this->bound()->execute(['topic' => 't', 'ranges' => []])->content());

        $overlap = $this->bound()->execute(['topic' => 't', 'ranges' => [
            ['from' => 'r1', 'to' => 'r3', 'summary' => 'one'],
            ['from' => 'r4', 'to' => 'r5', 'summary' => 'two'],
        ]]);
        $this->assertStringContainsString('overlaps another range', $overlap->content(), 'r3 ends the step r4 is in');
        $this->assertSame([], $this->applied);
    }

    public function testTheNewestPromptIsNeverCompressed(): void
    {
        $rows = [...self::rows(), new AssistantMessage('', [new ToolCall('c9', 'Read', ['file_path' => 'd.php'])]), new ToolResultMessage('c9', self::big('d'))];
        $tool = $this->bound($rows);

        $result = $tool->execute(self::args('r1', 'r8'));
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('newest user message', $result->content());
    }

    public function testASummaryThatSavesLittleIsRefused(): void
    {
        $result = $this->bound()->execute(self::args('r5', 'r6', str_repeat('padding the summary out ', 2000)));

        $this->assertTrue($result->isError());
        $this->assertMatchesRegularExpression('/Summary \(~[\d.]+K? tokens\) is not much smaller than the content it replaces \(~[\d.]+K? tokens\)/', $result->content());
    }

    public function testACoveredBlockBecomesAPlaceholderTheProjectionExpands(): void
    {
        $this->ledger = $this->ledger->withBlock(CompressionBlock::range(1, 'Auth', ContextLedger::userRowKey('explore auth', 1), ContextLedger::stepKey('c2'), 1, 4, 'INNER SUMMARY', 900, 5));
        $this->ledger = $this->ledger->withRefsAssigned(\array_slice(self::rows(), 0, -1));

        $result = $this->bound()->execute(self::args('b1', 'r6', 'Explored then fixed.'));
        $this->assertFalse($result->isError(), $result->content());
        $this->assertStringContainsString('covering b1', $result->content());

        $outer = $this->ledger->block(2);
        $this->assertSame([1], $outer?->consumedBlockIds);
        $this->assertStringEndsWith('(b1)', (string) $outer?->summary, 'the missing placeholder is appended');
        $this->assertFalse($this->ledger->block(1)?->active, 'the inner block is consumed');

        $projected = ContextProjector::new()->withRefTags()->project(self::rows(), $this->ledger)->messages;
        $this->assertCount(2, $projected, 'everything but the trigger is one block');
        $this->assertStringContainsString("Explored then fixed.\n\nINNER SUMMARY", $projected[0]->content(), 'the placeholder expands to the inner summary');
        $this->assertStringContainsString('### Task', $projected[0]->content());
    }

    public function testPlaceholdersAreValidated(): void
    {
        $this->ledger = $this->ledger->withBlock(CompressionBlock::range(1, 'Auth', ContextLedger::userRowKey('explore auth', 1), ContextLedger::stepKey('c2'), 1, 4, 'INNER', 900, 5));

        $this->assertStringContainsString('(b9) is not a block inside this range', $this->bound()->execute(self::args('b1', 'r6', 'x (b9)'))->content());
        $this->assertStringContainsString('(b1) appears 2 times', $this->bound()->execute(self::args('b1', 'r6', '(b1) and (b1)'))->content());
        $this->assertStringContainsString('cuts block b1', $this->bound()->execute(self::args('r3', 'r6'))->content());
    }

    public function testANestThatWouldExpandPastTheCapIsRefused(): void
    {
        $this->ledger = $this->ledger->withBlock(CompressionBlock::range(1, 'Auth', ContextLedger::userRowKey('explore auth', 1), ContextLedger::stepKey('c2'), 1, 4, str_repeat('word ', 20_000), 900, 5));

        $result = $this->bound()->execute(self::args('b1', 'r6', 'and then fixed'));

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('past the 16K nesting cap; leave the earlier block b1 standalone', $result->content());
    }

    public function testAPreCompactRefusalAppliesNothing(): void
    {
        $seen = [];
        $tool = $this->bound()->withCompactionGate(static function (string $trigger, string $focus) use (&$seen): ?string {
            $seen[] = [$trigger, $focus];

            return 'archive first';
        });

        $result = $tool->execute(self::args('r1', 'r4'));

        $this->assertStringContainsString('a PreCompact hook refused this compression (archive first)', $result->content());
        $this->assertSame([['manual', 'Auth exploration']], $seen, 'a /compress turn is a manual compaction');
        $this->assertSame([], $this->applied);
        $this->assertSame(1, $this->budget);
    }

    public function testTheCompressionClearsTheNudgeAnchors(): void
    {
        $this->ledger = $this->ledger->withNudge('c4', NudgePolicy::KIND_TURN);

        $this->bound()->execute(self::args('r1', 'r4'));

        $this->assertSame([], $this->ledger->nudges, 'the cooldown');
    }

    public function testTheTriggerPromptIsWhatTheTurnRecognises(): void
    {
        $prompt = Compress::triggerPrompt('the auth work');
        $this->assertStringStartsWith(Compress::TRIGGER . "\n", $prompt);
        $this->assertStringEndsWith("Focus from the user:\nthe auth work", $prompt);
        $this->assertTrue(Compress::isTriggered(self::rows()));
        $this->assertFalse(Compress::isTriggered([...self::rows(), new UserMessage('thanks')]));
        $this->assertFalse(Compress::isTriggered([new UserMessage('hello')]));
    }

    public function testDecompressAndRecompressFlipABlock(): void
    {
        $this->bound()->execute(self::args('r1', 'r4'));
        $compressed = $this->ledger;

        $open = $compressed->withBlockDecompressed(1);
        $this->assertTrue($open->block(1)?->deactivatedByUser);
        $this->assertCount(11, ContextProjector::new()->project(self::rows(), $open)->messages, 'every row is sent again');

        $closed = $open->withBlockRecompressed(1);
        $this->assertTrue($closed->block(1)?->active);
        $this->assertCount(6, ContextProjector::new()->project(self::rows(), $closed)->messages);
        $this->assertSame($compressed, $compressed->withBlockRecompressed(1), 'only a decompressed block recompresses');

        $round = ContextLedger::fromArray(json_decode((string) json_encode($open->toArray()), true));
        $this->assertTrue($round->block(1)?->deactivatedByUser, 'a decompressed range survives the store');
        $this->assertSame('Auth exploration', $round->block(1)?->topic);
    }

    // ── harness ─────────────────────────────────────────────────────────

    /** @return array{topic: string, ranges: list<array{from: string, to: string, summary: string}>} */
    private static function args(string $from, string $to, string $summary = 'Explored auth.'): array
    {
        return ['topic' => 'Auth exploration', 'ranges' => [['from' => $from, 'to' => $to, 'summary' => $summary]]];
    }

    /** @param list<TypedMessage>|null $rows */
    private function bound(?array $rows = null): Compress
    {
        $rows ??= self::rows();
        $this->budget = 1;
        $tool = Compress::new()
            ->withLedger(
                fn (): array => [$this->ledger, $rows],
                function (LedgerDelta $delta): ContextLedger {
                    $this->applied[] = $delta;

                    return $this->ledger = $this->ledger->apply($delta);
                },
            );
        $this->assertInstanceOf(Compress::class, $tool);

        return $tool->withAllowance(function (bool $spend): bool {
            if ($this->budget <= 0) {
                return false;
            }
            if ($spend) {
                $this->budget--;
            }

            return true;
        });
    }

    private static function big(string $seed): string
    {
        return str_repeat("{$seed}.php source line\n", 400);
    }

    /**
     * r1 prompt · c1 Read (r2) · c2 Task (r3) + c3 Read (r4) · an answer ·
     * r5 prompt · c4 Read (r6) · the `/compress` trigger (newest, no ref).
     *
     * @return list<TypedMessage>
     */
    private static function rows(): array
    {
        return [
            new UserMessage('explore auth'),
            new AssistantMessage('', [new ToolCall('c1', 'Read', ['file_path' => 'a.php'])]),
            new ToolResultMessage('c1', self::big('a')),
            new AssistantMessage('', [new ToolCall('c2', 'Task', ['description' => 'map logout']), new ToolCall('c3', 'Read', ['file_path' => 'b.php'])]),
            new ToolResultMessage('c2', 'The sub-agent report: logout() lives in b.php.'),
            new ToolResultMessage('c3', self::big('b')),
            new AssistantMessage('found it'),
            new UserMessage('now fix it'),
            new AssistantMessage('', [new ToolCall('c4', 'Read', ['file_path' => 'c.php'])]),
            new ToolResultMessage('c4', self::big('c')),
            new UserMessage(Compress::triggerPrompt()),
        ];
    }
}
