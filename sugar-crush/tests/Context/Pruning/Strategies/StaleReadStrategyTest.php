<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context\Pruning\Strategies;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\PruneEntry;
use SugarCraft\Crush\Context\Pruning\PruneKind;
use SugarCraft\Crush\Context\Pruning\PruneReason;
use SugarCraft\Crush\Context\Pruning\PruningPolicy;
use SugarCraft\Crush\Context\Pruning\Strategies\StaleReadStrategy;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Tools\ToolCall;

/** Roadmap 2.3: a Read older than a successful Edit/Write of the same file is pruned, outside the protected window. */
final class StaleReadStrategyTest extends TestCase
{
    public function testAReadOlderThanASuccessfulEditOfTheFileIsPruned(): void
    {
        $rows = [
            new UserMessage('first'),
            ...self::step('r1', 'Read', ['file_path' => 'src/A.php']),
            ...self::step('r2', 'Read', ['file_path' => 'src/B.php']),
            ...self::step('e1', 'Edit', ['file_path' => 'src/A.php', 'old_string' => 'x', 'new_string' => 'y']),
            ...self::step('e2', 'Write', ['file_path' => 'src/B.php', 'content' => 'z'], error: true),
            new UserMessage('second'),
            new UserMessage('third'),
        ];

        $delta = StaleReadStrategy::new()->propose($rows, ContextLedger::new(), PruningPolicy::new());

        $this->assertSame(['r1'], self::ids($delta->prunes), 'a failed write left B.php as it was read');
        $this->assertSame(PruneKind::Output, $delta->prunes[0]->kind);
        $this->assertSame(PruneReason::Stale, $delta->prunes[0]->reason);
    }

    public function testAReadAfterTheEditIsCurrentAndKept(): void
    {
        $rows = [
            new UserMessage('first'),
            ...self::step('e1', 'Write', ['file_path' => 'a.php', 'content' => 'v1']),
            ...self::step('r1', 'Read', ['file_path' => 'a.php']),
            new UserMessage('second'),
            new UserMessage('third'),
        ];

        $this->assertSame([], self::ids(StaleReadStrategy::new()->propose($rows, ContextLedger::new(), PruningPolicy::new())->prunes));
    }

    public function testTheLastUserTurnsAreProtected(): void
    {
        $rows = [
            new UserMessage('first'),
            ...self::step('r1', 'Read', ['file_path' => 'a.php']),
            ...self::step('e1', 'Edit', ['file_path' => 'a.php', 'old_string' => 'x', 'new_string' => 'y']),
            new UserMessage('second'),
        ];

        $this->assertSame([], self::ids(StaleReadStrategy::new()->propose($rows, ContextLedger::new(), PruningPolicy::new())->prunes), 'mid-task, the model edits from the read it just made');
        $this->assertSame(['r1'], self::ids(StaleReadStrategy::new()->propose($rows, ContextLedger::new(), PruningPolicy::new()->withProtectUserTurns(0))->prunes));
    }

    /** @param array<string, mixed> $args @return list<Message> */
    private static function step(string $id, string $tool, array $args, bool $error = false): array
    {
        return [
            new AssistantMessage('', [new ToolCall($id, $tool, $args)]),
            new ToolResultMessage($id, str_repeat("output of {$id} ", 200), $error),
        ];
    }

    /** @param list<PruneEntry> $entries @return list<string> */
    private static function ids(array $entries): array
    {
        return array_map(static fn (PruneEntry $e): string => $e->toolCallId, $entries);
    }
}
