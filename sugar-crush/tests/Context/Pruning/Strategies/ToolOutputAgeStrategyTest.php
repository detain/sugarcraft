<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context\Pruning\Strategies;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\Pruning\CompressionBlock;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\PruneAuthor;
use SugarCraft\Crush\Context\Pruning\PruneEntry;
use SugarCraft\Crush\Context\Pruning\PruneKind;
use SugarCraft\Crush\Context\Pruning\PruneReason;
use SugarCraft\Crush\Context\Pruning\PruningPolicy;
use SugarCraft\Crush\Context\Pruning\Strategies\ToolOutputAgeStrategy;
use SugarCraft\Crush\Context\TurnContextBlock;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Roadmap 2.2-1, the age rule: the last two user turns and the newest 40k
 * tokens of older tool output stay; older output is pruned only when that
 * frees 20k; Task and Skill output never.
 */
final class ToolOutputAgeStrategyTest extends TestCase
{
    /** 60,000 characters of prose: 15,000 estimated tokens. */
    private const BIG = 15_000;

    public function testOutputOlderThanTheProtectedWindowIsProposed(): void
    {
        $rows = [
            new UserMessage('first'),
            ...self::step('a1', 'Read', ['file_path' => 'a1.php']),
            ...self::step('a2', 'Read', ['file_path' => 'a2.php']),
            ...self::step('a3', 'Grep', ['pattern' => 'TODO']),
            ...self::step('a4', 'Read', ['file_path' => 'a4.php']),
            ...self::step('a5', 'Read', ['file_path' => 'a5.php']),
            new AssistantMessage('done'),
            new UserMessage('second'),
            ...self::step('b1', 'Read', ['file_path' => 'b1.php']),
            new AssistantMessage('done'),
            new UserMessage('third'),
            ...self::step('c1', 'Read', ['file_path' => 'c1.php']),
        ];

        $delta = ToolOutputAgeStrategy::new()->propose($rows, ContextLedger::new(), PruningPolicy::new());

        // b1 and c1 sit in the last two user turns. Behind them a5 and a4 are
        // the newest 30k; a3 tips the count past 40k, so a3, a2, a1 go.
        $this->assertSame(['a1', 'a2', 'a3'], array_map(static fn (PruneEntry $e): string => $e->toolCallId, $delta->prunes));
        foreach ($delta->prunes as $entry) {
            $this->assertSame(PruneKind::Output, $entry->kind);
            $this->assertSame(PruneReason::Aged, $entry->reason);
            $this->assertSame(PruneAuthor::Strategy, $entry->by);
            $this->assertGreaterThan(self::BIG - 100, $entry->tokens);
        }
        $this->assertGreaterThanOrEqual(PruningPolicy::MIN_FREED_TOKENS, $delta->freedTokens());
    }

    public function testNothingIsProposedBelowTheFloor(): void
    {
        $rows = [
            new UserMessage('first'),
            ...self::step('a1', 'Read', ['file_path' => 'a1.php']),
            ...self::step('a2', 'Read', ['file_path' => 'a2.php']),
            ...self::step('a3', 'Read', ['file_path' => 'a3.php']),
            new UserMessage('second'),
            new UserMessage('third'),
        ];

        // a3 + a2 are 30k (kept); a1's 15k would be freed — under 20k.
        $this->assertTrue(ToolOutputAgeStrategy::new()->propose($rows, ContextLedger::new(), PruningPolicy::new())->isEmpty());
        $this->assertCount(1, ToolOutputAgeStrategy::new()->propose($rows, ContextLedger::new(), PruningPolicy::new()->withMinFreedTokens(10_000))->prunes);
    }

    public function testTheLastTwoUserTurnsAreKeptHoweverLarge(): void
    {
        $rows = [new UserMessage('previous')];
        for ($i = 1; $i <= 6; $i++) {
            array_push($rows, ...self::step("p{$i}", 'Read', ['file_path' => "p{$i}.php"]));
        }
        $rows[] = new UserMessage('now');
        for ($i = 1; $i <= 6; $i++) {
            array_push($rows, ...self::step("n{$i}", 'Read', ['file_path' => "n{$i}.php"]));
        }

        $this->assertTrue(ToolOutputAgeStrategy::new()->propose($rows, ContextLedger::new(), PruningPolicy::new())->isEmpty());
    }

    public function testTurnContextRowsAreNotUserTurns(): void
    {
        $rows = [new UserMessage('first')];
        for ($i = 1; $i <= 6; $i++) {
            array_push($rows, ...self::step("a{$i}", 'Read', ['file_path' => "a{$i}.php"]));
            $rows[] = new UserMessage(TurnContextBlock::FENCE . "\nstep {$i}\n</turn-context>");
        }
        $rows[] = new UserMessage('second');

        // One real turn behind the newest prompt is not two: nothing is old enough.
        $this->assertTrue(ToolOutputAgeStrategy::new()->propose($rows, ContextLedger::new(), PruningPolicy::new())->isEmpty());
    }

    public function testAStepSummaryRowIsNotAUserTurn(): void
    {
        $rows = [(new CompressionBlock(1, 'a1', 'earlier work', 50_000, 10, PruneAuthor::Harness))->summaryRow()];
        for ($i = 1; $i <= 6; $i++) {
            array_push($rows, ...self::step("a{$i}", 'Read', ['file_path' => "a{$i}.php"]));
        }
        $rows[] = new UserMessage('second');

        $this->assertTrue(ToolOutputAgeStrategy::new()->propose($rows, ContextLedger::new(), PruningPolicy::new())->isEmpty());
    }

    public function testTaskAndSkillOutputIsNeverPrunedNorCounted(): void
    {
        $rows = [
            new UserMessage('first'),
            ...self::step('t1', 'Task', ['description' => 'explore']),
            ...self::step('s1', 'Skill', ['name' => 'php']),
            ...self::step('r1', 'Read', ['file_path' => 'r1.php']),
            ...self::step('r2', 'Read', ['file_path' => 'r2.php']),
            ...self::step('r3', 'Read', ['file_path' => 'r3.php']),
            ...self::step('t2', 'Task', ['description' => 'verify']),
            new UserMessage('second'),
            new UserMessage('third'),
        ];

        $policy = PruningPolicy::new()->withMinFreedTokens(10_000);
        $ids = array_map(static fn (PruneEntry $e): string => $e->toolCallId, ToolOutputAgeStrategy::new()->propose($rows, ContextLedger::new(), $policy)->prunes);

        // t2 is not counted: r3 + r2 are the protected 30k, so r1 goes.
        $this->assertSame(['r1'], $ids);
    }

    public function testPrunedAndAmbiguousResultsAreSkipped(): void
    {
        $rows = [
            new UserMessage('first'),
            ...self::step('a1', 'Read', ['file_path' => 'a1.php']),
            ...self::step('dup', 'Read', ['file_path' => 'a2.php']),
            ...self::step('dup', 'Read', ['file_path' => 'a3.php']),
            ...self::step('a4', 'Read', ['file_path' => 'a4.php']),
            ...self::step('a5', 'Read', ['file_path' => 'a5.php']),
            ...self::step('a6', 'Read', ['file_path' => 'a6.php']),
            new UserMessage('second'),
            new UserMessage('third'),
        ];
        $ledger = ContextLedger::new()->withPrune(new PruneEntry('a1', PruneKind::Output, PruneReason::Aged, PruneAuthor::Strategy, 1));

        $policy = PruningPolicy::new()->withMinFreedTokens(10_000);
        $ids = array_map(static fn (PruneEntry $e): string => $e->toolCallId, ToolOutputAgeStrategy::new()->propose($rows, $ledger, $policy)->prunes);

        // a6, a5 kept (30k); a4 tips past 40k; a1 is already pruned and the
        // two `dup` results share one id, so one ledger key would hit both.
        $this->assertSame(['a4'], $ids);
    }

    public function testSelectKeepsRowsTheCallerMustKeepButCountsThem(): void
    {
        $rows = [
            ['userTurn' => true],
            ['key' => 'old', 'tool' => 'Read', 'tokens' => 30_000, 'placeholderTokens' => 20],
            ['key' => 'kept', 'tool' => 'Read', 'tokens' => 45_000, 'placeholderTokens' => 20, 'keep' => true],
            ['userTurn' => true],
            ['userTurn' => true],
        ];

        $this->assertSame(['old' => 29_980], ToolOutputAgeStrategy::select($rows, PruningPolicy::new()));
    }

    /**
     * @param array<string, string> $arguments
     * @return list<Message>
     */
    private static function step(string $id, string $tool, array $arguments): array
    {
        return [
            new AssistantMessage('', [new ToolCall($id, $tool, $arguments)]),
            new ToolResultMessage($id, str_repeat('lorem ipsum ', self::BIG / 3)),
        ];
    }
}
