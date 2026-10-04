<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context\Pruning\Strategies;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\PruneAuthor;
use SugarCraft\Crush\Context\Pruning\PruneEntry;
use SugarCraft\Crush\Context\Pruning\PruneKind;
use SugarCraft\Crush\Context\Pruning\PruneReason;
use SugarCraft\Crush\Context\Pruning\PruningPolicy;
use SugarCraft\Crush\Context\Pruning\Strategies\DuplicateCallStrategy;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Tools\ToolCall;

/** Roadmap 2.3: the newest of identical calls wins; a whole-file Read supersedes every older read of the file. */
final class DuplicateCallStrategyTest extends TestCase
{
    public function testAnIdenticalNewerCallPrunesTheOlderOutput(): void
    {
        $rows = [
            new UserMessage('go'),
            ...self::step('r1', 'Read', ['file_path' => 'src/A.php', 'description' => 'first look']),
            ...self::step('g1', 'Grep', ['pattern' => 'TODO']),
            ...self::step('r2', 'Read', ['description' => 'look again', 'file_path' => './src//A.php']),
        ];

        $delta = DuplicateCallStrategy::new()->propose($rows, ContextLedger::new(), PruningPolicy::new());

        $this->assertSame(['r1'], self::ids($delta->prunes), 'description and spelling never split a key; the newest stays');
        $this->assertSame(PruneKind::Output, $delta->prunes[0]->kind);
        $this->assertSame(PruneReason::Duplicate, $delta->prunes[0]->reason);
        $this->assertSame(PruneAuthor::Strategy, $delta->prunes[0]->by);
        $this->assertGreaterThan(0, $delta->prunes[0]->tokens);
    }

    public function testAWholeFileReadSupersedesOlderRangedReadsButNotTheOtherWayRound(): void
    {
        $rows = [
            new UserMessage('go'),
            ...self::step('w0', 'Read', ['file_path' => 'b.php']),
            ...self::step('p1', 'Read', ['file_path' => 'a.php', 'offset' => 1, 'limit' => 50]),
            ...self::step('p2', 'Read', ['file_path' => 'a.php', 'offset' => 51, 'limit' => 50]),
            ...self::step('w1', 'Read', ['file_path' => 'a.php']),
            ...self::step('p3', 'Read', ['file_path' => 'b.php', 'offset' => 10, 'limit' => 5]),
        ];

        $delta = DuplicateCallStrategy::new()->propose($rows, ContextLedger::new(), PruningPolicy::new());

        $this->assertSame(['p1', 'p2'], self::ids($delta->prunes), 'a newer ranged read of b.php supersedes nothing');
    }

    public function testAFailedNewerCallSupersedesNothingAndProtectedToolsAreKept(): void
    {
        $rows = [
            new UserMessage('go'),
            ...self::step('r1', 'Read', ['file_path' => 'a.php']),
            ...self::step('r2', 'Read', ['file_path' => 'a.php'], error: true),
            ...self::step('t1', 'Task', ['prompt' => 'same']),
            ...self::step('t2', 'Task', ['prompt' => 'same']),
        ];

        $this->assertSame([], self::ids(DuplicateCallStrategy::new()->propose($rows, ContextLedger::new(), PruningPolicy::new())->prunes));
    }

    public function testAnAlreadyPrunedOrReusedIdIsNotProposed(): void
    {
        $rows = [
            new UserMessage('go'),
            ...self::step('r1', 'Bash', ['command' => 'git status']),
            ...self::step('dup', 'Bash', ['command' => 'git status']),
            ...self::step('dup', 'Bash', ['command' => 'git status']),
            ...self::step('r9', 'Bash', ['command' => 'git status']),
        ];
        $ledger = ContextLedger::new()->withPrune(new PruneEntry('r1', PruneKind::Output, PruneReason::Aged, PruneAuthor::Strategy, 1));

        $this->assertSame([], self::ids(DuplicateCallStrategy::new()->propose($rows, $ledger, PruningPolicy::new())->prunes));
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
