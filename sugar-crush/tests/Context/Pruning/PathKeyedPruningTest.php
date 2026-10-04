<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context\Pruning;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\Pruning\CanonicalArguments;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\EmergencyPrune;
use SugarCraft\Crush\Context\Pruning\PruneAuthor;
use SugarCraft\Crush\Context\Pruning\PruneEntry;
use SugarCraft\Crush\Context\Pruning\PruneKind;
use SugarCraft\Crush\Context\Pruning\PruneReason;
use SugarCraft\Crush\Context\Pruning\PruningPolicy;
use SugarCraft\Crush\Context\Pruning\Strategies\DuplicateCallStrategy;
use SugarCraft\Crush\Context\Pruning\Strategies\ErroredInputStrategy;
use SugarCraft\Crush\Context\Pruning\Strategies\StaleReadStrategy;
use SugarCraft\Crush\Context\Pruning\Strategies\SupersededWriteInputStrategy;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Roadmap 2.3, the shared parts: the canonical call identity the path-keyed
 * strategies match on, and their registration in the engine's emergency prune.
 */
final class PathKeyedPruningTest extends TestCase
{
    public function testTheKeyIgnoresDescriptionOrderAndNulls(): void
    {
        $a = new ToolCall('1', 'Grep', ['pattern' => 'x', 'path' => 'src', 'description' => 'a', 'glob' => null]);
        $b = new ToolCall('2', 'Grep', ['path' => 'src', 'pattern' => 'x', 'description' => 'b']);
        $c = new ToolCall('3', 'Glob', ['path' => 'src', 'pattern' => 'x']);

        $this->assertSame(CanonicalArguments::key($a), CanonicalArguments::key($b));
        $this->assertNotSame(CanonicalArguments::key($a), CanonicalArguments::key($c), 'another tool is another call');
    }

    public function testPathsAreNormalisedButNeverResolved(): void
    {
        $this->assertSame('src/A.php', CanonicalArguments::path(new ToolCall('1', 'Read', ['file_path' => ' ./src//A.php '])));
        $this->assertSame('/repo/src', CanonicalArguments::path(new ToolCall('1', 'Glob', ['path' => '/repo/./src/'])));
        $this->assertNull(CanonicalArguments::path(new ToolCall('1', 'Bash', ['command' => 'ls'])));
        $this->assertNotSame(CanonicalArguments::normalisedPath('src/A.php'), CanonicalArguments::normalisedPath('/repo/src/A.php'));
        $this->assertTrue(CanonicalArguments::readsWholeFile(new ToolCall('1', 'Read', ['file_path' => 'a'])));
        $this->assertFalse(CanonicalArguments::readsWholeFile(new ToolCall('1', 'Read', ['file_path' => 'a', 'limit' => 5])));
    }

    public function testTheEmergencyPruneRunsThePathKeyedRulesFirst(): void
    {
        $classes = array_map(static fn (object $s): string => $s::class, EmergencyPrune::strategies());

        $this->assertSame(
            [DuplicateCallStrategy::class, StaleReadStrategy::class, SupersededWriteInputStrategy::class, ErroredInputStrategy::class],
            \array_slice($classes, 0, 4),
        );
        $this->assertCount(6, $classes, 'the age rule and the turn-context rule still run');
    }

    public function testACallTwoRulesNameIsPrunedOnceAndCountedOnce(): void
    {
        // Two identical 15k-token reads in the oldest turn: the duplicate rule
        // and the age rule both name r1.
        $big = str_repeat('lorem ipsum ', 5_000);
        $rows = [
            new UserMessage('one'),
            new AssistantMessage('', [new ToolCall('r1', 'Read', ['file_path' => 'a.php'])]),
            new ToolResultMessage('r1', $big),
            new AssistantMessage('', [new ToolCall('r2', 'Read', ['file_path' => 'a.php'])]),
            new ToolResultMessage('r2', $big),
            new UserMessage('two'),
            new UserMessage('three'),
        ];

        $delta = EmergencyPrune::propose($rows, ContextLedger::new(), PruningPolicy::new()->withMinFreedTokens(1)->withProtectTokens(0));

        $this->assertNotNull($delta);
        $ids = array_map(static fn (PruneEntry $e): string => $e->toolCallId, $delta->prunes);
        $this->assertSame($ids, array_values(array_unique($ids)), 'one entry per call');
        $this->assertSame(PruneReason::Duplicate, $delta->prunes[0]->reason, 'the first rule decides');
        $this->assertSame(array_sum(array_map(static fn (PruneEntry $e): int => $e->tokens, $delta->prunes)), $delta->freedTokens());
    }

    public function testAnInputPruneLeavesTheResultAndAnOutputPruneLeavesTheCall(): void
    {
        $rows = [
            new AssistantMessage('', [
                new ToolCall('w1', 'Write', ['file_path' => 'a.php', 'content' => 'long']),
                new ToolCall('r1', 'Read', ['file_path' => 'b.php']),
            ]),
            new ToolResultMessage('w1', 'wrote a.php'),
            new ToolResultMessage('r1', 'contents of b'),
        ];
        $ledger = ContextLedger::new()
            ->withPrune(new PruneEntry('w1', PruneKind::WriteContent, PruneReason::Superseded, PruneAuthor::Strategy, 1))
            ->withPrune(new PruneEntry('r1', PruneKind::Output, PruneReason::Stale, PruneAuthor::Strategy, 1));

        $projected = \SugarCraft\Crush\Context\Pruning\ContextProjector::new()->project($rows, $ledger)->messages;

        $calls = $projected[0]->toolCalls();
        $this->assertNotSame('long', $calls[0]->arguments()['content']);
        $this->assertSame(['file_path' => 'b.php'], $calls[1]->arguments());
        $this->assertSame('wrote a.php', $projected[1]->content());
        $this->assertStringContainsString('output pruned', $projected[2]->content());
        $this->assertSame('long', $rows[0]->toolCalls()[0]->arguments()['content'], 'the rows are never rewritten');
        $this->assertTrue(PruneKind::Input->rewritesInput());
        $this->assertFalse(PruneKind::Output->rewritesInput());
    }
}
