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
use SugarCraft\Crush\Context\Pruning\Strategies\ErroredInputStrategy;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Tools\ToolCall;

/** Roadmap 2.3: the input of a call that failed N user turns ago is blanked; its error stays. */
final class ErroredInputStrategyTest extends TestCase
{
    public function testAFailedCallOlderThanTheWindowHasItsInputBlanked(): void
    {
        $rows = [
            new UserMessage('one'),
            new AssistantMessage('', [new ToolCall('e1', 'Edit', [
                'file_path' => 'src/A.php',
                'old_string' => str_repeat('old ', 1000),
                'new_string' => str_repeat('new ', 1000),
            ])]),
            new ToolResultMessage('e1', 'Error: old_string not found', true),
            ...self::step('ok', 'Edit', ['file_path' => 'src/A.php', 'old_string' => str_repeat('x', 4000), 'new_string' => 'y']),
            new UserMessage('two'),
            new UserMessage('three'),
            new UserMessage('four'),
            new UserMessage('five'),
        ];

        $delta = ErroredInputStrategy::new()->propose($rows, ContextLedger::new(), PruningPolicy::new());

        $this->assertSame(['e1'], self::ids($delta->prunes), 'a successful call keeps its input');
        $this->assertSame(PruneKind::Input, $delta->prunes[0]->kind);
        $this->assertSame(PruneReason::Errored, $delta->prunes[0]->reason);

        $projected = ContextProjector::new()->project($rows, ContextLedger::new()->apply($delta))->messages;
        $this->assertSame([
            'file_path' => 'src/A.php',
            'old_string' => PrunedInputPlaceholder::FAILED_INPUT,
            'new_string' => PrunedInputPlaceholder::FAILED_INPUT,
        ], $projected[1]->toolCalls()[0]->arguments(), 'the main argument says what was tried');
        $this->assertSame('Error: old_string not found', $projected[2]->content(), 'the error stays');
    }

    public function testAFailureInsideTheWindowIsKept(): void
    {
        $rows = [
            new UserMessage('one'),
            ...self::step('e1', 'Bash', ['command' => 'make', 'description' => str_repeat('why ', 500)], error: true),
            new UserMessage('two'),
            new UserMessage('three'),
            new UserMessage('four'),
        ];

        $this->assertSame([], self::ids(ErroredInputStrategy::new()->propose($rows, ContextLedger::new(), PruningPolicy::new())->prunes));
        $this->assertSame(['e1'], self::ids(ErroredInputStrategy::new(3)->propose($rows, ContextLedger::new(), PruningPolicy::new())->prunes));
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
