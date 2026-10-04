<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context\Pruning\Strategies;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\ContextProjector;
use SugarCraft\Crush\Context\Pruning\PruneEntry;
use SugarCraft\Crush\Context\Pruning\PruneKind;
use SugarCraft\Crush\Context\Pruning\PruneReason;
use SugarCraft\Crush\Context\Pruning\PrunedInputPlaceholder;
use SugarCraft\Crush\Context\Pruning\PruningPolicy;
use SugarCraft\Crush\Context\Pruning\Strategies\SupersededWriteInputStrategy;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Tools\ToolCall;

/** Roadmap 2.3: a Write's content is elided once a later write or whole-file read of the file exists. */
final class SupersededWriteInputStrategyTest extends TestCase
{
    public function testAnOlderWriteOfTheSameFileHasItsContentElided(): void
    {
        $rows = [
            new UserMessage('go'),
            ...self::step('w1', 'Write', ['file_path' => 'a.php', 'content' => str_repeat('v1 ', 2000)]),
            ...self::step('w2', 'Write', ['file_path' => 'b.php', 'content' => str_repeat('b ', 2000)]),
            ...self::step('w3', 'Write', ['file_path' => './a.php', 'content' => str_repeat('v2 ', 2000)]),
        ];

        $delta = SupersededWriteInputStrategy::new()->propose($rows, ContextLedger::new(), PruningPolicy::new());

        $this->assertSame(['w1'], self::ids($delta->prunes));
        $this->assertSame(PruneKind::WriteContent, $delta->prunes[0]->kind);
        $this->assertSame(PruneReason::Superseded, $delta->prunes[0]->reason);
        $this->assertGreaterThan(1000, $delta->prunes[0]->tokens);

        // Projected, the call keeps its id, name and path; the result is untouched.
        $projected = ContextProjector::new()->project($rows, ContextLedger::new()->apply($delta))->messages;
        $call = $projected[1]->toolCalls()[0];
        $this->assertSame('w1', $call->id());
        $this->assertSame(['file_path' => 'a.php', 'content' => PrunedInputPlaceholder::WRITE_CONTENT], $call->arguments());
        $this->assertSame($rows[2]->content(), $projected[2]->content(), 'the write\'s receipt stays');
        $this->assertSame($rows[5], $projected[5], 'the newest write goes out as it was');
    }

    public function testAWholeFileReadSupersedesButARangedReadOrAFailedWriteDoesNot(): void
    {
        $rows = [
            new UserMessage('go'),
            ...self::step('w1', 'Write', ['file_path' => 'a.php', 'content' => str_repeat('a ', 2000)]),
            ...self::step('w2', 'Write', ['file_path' => 'b.php', 'content' => str_repeat('b ', 2000)]),
            ...self::step('w3', 'Write', ['file_path' => 'c.php', 'content' => str_repeat('c ', 2000)]),
            ...self::step('r1', 'Read', ['file_path' => 'a.php']),
            ...self::step('r2', 'Read', ['file_path' => 'b.php', 'offset' => 1, 'limit' => 2]),
            ...self::step('w4', 'Write', ['file_path' => 'c.php', 'content' => 'x'], error: true),
        ];

        $this->assertSame(['w1'], self::ids(SupersededWriteInputStrategy::new()->propose($rows, ContextLedger::new(), PruningPolicy::new())->prunes));
    }

    /** @param array<string, mixed> $args @return list<Message> */
    private static function step(string $id, string $tool, array $args, bool $error = false): array
    {
        return [
            new AssistantMessage('', [new ToolCall($id, $tool, $args)]),
            new ToolResultMessage($id, "result of {$id}", $error),
        ];
    }

    /** @param list<PruneEntry> $entries @return list<string> */
    private static function ids(array $entries): array
    {
        return array_map(static fn (PruneEntry $e): string => $e->toolCallId, $entries);
    }
}
