<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Context\Pruning\PrunedOutputPlaceholder;
use SugarCraft\Crush\Context\TurnContextBlock;
use SugarCraft\Crush\Events\StepStarted;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Roadmap 2.2-1: a step whose request is over its budget is pruned BEFORE it
 * is sent — older tool output becomes placeholders, superseded
 * `<turn-context>` rows go — and the turn's own rows are left as they were.
 */
final class InTurnPruneTest extends TestCase
{
    public function testAnOverBudgetRequestIsSentPruned(): void
    {
        // 100k window, no output ceiling: the step budget is 80k. Six old
        // 15k results put the first request near 90k.
        $provider = new ScriptedProvider([new CompleteResponse(content: 'done')], contextWindow: 100_000);
        $events = [];

        $turn = EngineBackend::new($provider, 'm')->completeTranscript(
            self::history(6),
            onStep: static function (object $event) use (&$events): void {
                if ($event instanceof StepStarted) {
                    $events[] = $event;
                }
            },
        );

        $this->assertCount(1, $provider->requests, 'the refused build made no provider call');
        $sent = self::results($provider->requests[0]->messages);
        foreach (['old1', 'old2', 'old3', 'old4'] as $id) {
            $this->assertSame(PrunedOutputPlaceholder::for('Read', ['file_path' => "{$id}.php"]), $sent[$id], "{$id} is older than the newest 40k");
        }
        $this->assertSame(self::bigOutput(), $sent['old5']);
        $this->assertSame(self::bigOutput(), $sent['old6']);

        // The step persisted its own `<turn-context>` row on top of the two
        // the history carried, so both of those are superseded now and go in
        // the same batch; the newest stays.
        $wire = implode("\n", array_map(static fn (Message $m): string => $m->content(), $provider->requests[0]->messages));
        $this->assertStringNotContainsString('state one', $wire, 'a superseded turn-context row goes in the same batch');
        $this->assertStringNotContainsString('state two', $wire);
        $contextRows = array_filter($provider->requests[0]->messages, static fn (Message $m): bool => TurnContextBlock::isTurnContext($m));
        $this->assertCount(1, $contextRows, 'the newest state is still sent');

        $this->assertCount(1, $events, 'one StepStarted, for the request actually sent');
        $this->assertFalse($events[0]->pressure?->isOverBudget(), 'it reports the pruned request');

        $kept = self::results($turn->transcript);
        $this->assertSame(self::bigOutput(), $kept['old1'], 'the turn\'s rows are never rewritten');
        $this->assertSame('done', $turn->reply->content);
    }

    public function testThePruneRunsWithoutAnObserver(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'done')], contextWindow: 100_000);

        EngineBackend::new($provider, 'm')->completeTranscript(self::history(6));

        $this->assertCount(1, $provider->requests);
        $this->assertSame(PrunedOutputPlaceholder::for('Read', ['file_path' => 'old1.php']), self::results($provider->requests[0]->messages)['old1']);
    }

    public function testARequestUnderBudgetIsSentUntouched(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'done')], contextWindow: 1_000_000);

        EngineBackend::new($provider, 'm')->completeTranscript(self::history(6));

        $this->assertSame(self::bigOutput(), self::results($provider->requests[0]->messages)['old1']);
    }

    public function testAPruneThatWouldFreeTooLittleIsNotMade(): void
    {
        // Three old results: the newest two are the protected 30k and the
        // third would free 15k, under the 20k floor. Over budget all the same
        // (40k window: 32k budget), so the request is sent as it stands.
        $provider = new ScriptedProvider([new CompleteResponse(content: 'done')], contextWindow: 40_000);
        $events = [];

        EngineBackend::new($provider, 'm')->completeTranscript(
            self::history(3),
            onStep: static function (object $event) use (&$events): void {
                if ($event instanceof StepStarted) {
                    $events[] = $event;
                }
            },
        );

        $this->assertCount(1, $provider->requests);
        $this->assertSame(self::bigOutput(), self::results($provider->requests[0]->messages)['old1']);
        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->pressure?->isOverBudget(), 'the figure says so; nothing could relieve it');
    }

    /**
     * Two earlier turns: the first read $reads files, the second only talked;
     * then the new prompt.
     *
     * @return list<Message>
     */
    private static function history(int $reads): array
    {
        $rows = [new UserMessage('read the project')];
        for ($i = 1; $i <= $reads; $i++) {
            $rows[] = new AssistantMessage('', [new ToolCall("old{$i}", 'Read', ['file_path' => "old{$i}.php"])]);
            $rows[] = new ToolResultMessage("old{$i}", self::bigOutput());
            if ($i === 1) {
                $rows[] = new UserMessage(TurnContextBlock::FENCE . "\nstate one\n</turn-context>");
            }
        }
        $rows[] = new UserMessage(TurnContextBlock::FENCE . "\nstate two\n</turn-context>");
        $rows[] = new AssistantMessage('read them all');
        $rows[] = new UserMessage('what did you find?');
        $rows[] = new AssistantMessage('plenty');
        $rows[] = new UserMessage('summarise it');

        return $rows;
    }

    /** 15,000 estimated tokens. */
    private static function bigOutput(): string
    {
        return str_repeat('lorem ipsum ', 5_000);
    }

    /**
     * @param list<mixed> $messages
     * @return array<string, string> tool-call id => content
     */
    private static function results(array $messages): array
    {
        $results = [];
        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage) {
                $results[$message->toolCallId()] = $message->content();
            }
        }

        return $results;
    }
}
