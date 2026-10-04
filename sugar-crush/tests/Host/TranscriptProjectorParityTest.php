<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\BackendToolEventsMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Host\SessionEvent;
use SugarCraft\Crush\Host\TranscriptProjector;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolEventPumpMsg;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Tools\ToolResult as EngineToolResult;

/**
 * O-2f: the projection of a turn's tool events onto transcript rows left
 * {@see Chat} for {@see TranscriptProjector} — the running placeholder, the
 * newest-first replace-by-id, the finished-row shape — and Chat kept only the
 * folding. The behaviour through Chat stays pinned by the suites that drive
 * its two folds ({@see \SugarCraft\Crush\Tests\ChatTest},
 * {@see \SugarCraft\Crush\Tests\Chat\MidTurnSpendCapTranscriptTest},
 * {@see \SugarCraft\Crush\Tests\Chat\AgentLiveLinesTest},
 * {@see \SugarCraft\Crush\Tests\Chat\CancelledToolPlaceholderTest}); these
 * cases pin that what Chat commits on EITHER fold is the projector's answer,
 * so a host with no screen writes exactly the rows the TUI writes.
 */
final class TranscriptProjectorParityTest extends TestCase
{
    private const GENERATION = 3;

    /**
     * A matched pair, a call still running when the queue ends, and a result
     * whose placeholder never existed (appended, never dropped).
     *
     * @return list<ToolStarted|ToolFinished>
     */
    private static function turnEvents(): array
    {
        return [
            new ToolStarted('c1', 'Bash', ['command' => 'ls', 'description' => 'List files']),
            new ToolFinished('c1', 'Bash', new EngineToolResult('c1', "a.txt\nb.txt", durationMs: 12)),
            new ToolStarted('c2', 'Read', ['path' => 'a.txt']),
            new ToolFinished('c9', 'Grep', new EngineToolResult('c9', 'no matches', isError: true)),
        ];
    }

    /** @return list<Message> */
    private static function base(): array
    {
        return [Message::user('look around')];
    }

    public function testTheSettledQueueFoldWritesTheProjectorsRows(): void
    {
        $chat = new Chat(history: self::base(), backend: new EchoBackend());
        $reply = Message::assistant('done');

        $current = $chat;
        $next = new BackendToolEventsMsg(self::turnEvents(), $reply);
        for ($i = 0; $i < 16 && $next instanceof BackendToolEventsMsg; $i++) {
            [$current, $cmd] = $current->update($next);
            $next = $cmd === null ? null : $cmd();
        }

        self::assertSame(
            self::shapes(self::headless()),
            self::shapes($current->history),
            'the settled-queue fold must commit exactly the rows the projector builds',
        );
    }

    public function testTheLivePumpFoldWritesTheProjectorsRows(): void
    {
        $inbox = new \ArrayObject();
        $chat = new Chat(
            history: self::base(),
            backend: new EchoBackend(),
            inFlight: true,
            generation: self::GENERATION,
            liveToolEvents: $inbox,
        );
        foreach (self::turnEvents() as $event) {
            $inbox[] = [self::GENERATION, $event];
        }

        for ($i = 0; $i < 16 && \count($inbox) > 0; $i++) {
            [$chat] = $chat->update(new ToolEventPumpMsg());
        }

        self::assertCount(0, $inbox);
        self::assertSame(
            self::shapes(self::headless()),
            self::shapes($chat->history),
            'the live-pump fold must commit exactly the rows the projector builds',
        );
    }

    /**
     * Audit 15b-02 through the projector: ids repeat across a session, so the
     * finish resolves the NEWEST placeholder with its id, and carries that
     * placeholder's description, arguments and thought onto the finished row.
     */
    public function testAFinishReplacesTheNewestPlaceholderAndKeepsWhatRan(): void
    {
        $projector = TranscriptProjector::new();
        $history = $projector->started([], new ToolStarted('dsml_call_0', 'Bash', ['command' => 'pwd']));
        $history[] = Message::assistant('between turns');
        $history = $projector->started($history, new ToolStarted('dsml_call_0', 'Bash', ['command' => 'ls']), 'I should list');

        [$after, $row, $replaced] = $projector->finish(
            $history,
            new ToolFinished('dsml_call_0', 'Bash', new EngineToolResult('dsml_call_0', 'a.txt')),
        );

        self::assertSame('dsml_call_0', $after[0]->pendingToolCallId, 'the older turn\'s row is untouched');
        self::assertSame($row, $after[2]);
        self::assertSame($history[2], $replaced);
        self::assertNull($row->pendingToolCallId);
        self::assertSame('I should list', $row->reasoning);
        self::assertSame(['command' => 'ls'], $row->toolResults[0]->arguments);
        self::assertSame($history[2]->content, $row->toolResults[0]->description);
    }

    /** Chat's finished-row builder IS the projector's, for both pipelines. */
    public function testChatsResultRowIsTheProjectorsResultRow(): void
    {
        $ok = ToolResult::ok('Bash', 'fine', 'c1');
        $failed = ToolResult::error('Bash', 'boom', 'c2');
        $build = new \ReflectionMethod(Chat::class, 'toolResultMessage');

        foreach ([[$ok, null], [$failed, 'why']] as [$result, $reasoning]) {
            self::assertSame(
                self::shape(TranscriptProjector::resultRow($result, $reasoning)),
                self::shape($build->invoke(null, $result, $reasoning)),
            );
        }
        self::assertSame('Tool error: boom', TranscriptProjector::resultRow($failed)->content);
    }

    /** A placeholder starts unnamed, so the event names it only once saved; the call id pairs it. */
    public function testTheToolEventsNameTheirRowsAndPairByCallId(): void
    {
        $projector = TranscriptProjector::new();
        $started = new ToolStarted('c1', 'Bash', ['command' => 'ls']);
        $finished = new ToolFinished('c1', 'Bash', new EngineToolResult('c1', str_repeat('x', TranscriptProjector::MAX_EVENT_CONTENT_BYTES + 10)));
        [, $row] = $projector->finish($projector->started([], $started), $finished);

        $start = $projector->toolStartedEvent($started, null, 's', 't_1');
        $finish = $projector->toolFinishedEvent($finished, $row, ['m_s_4', 4], ['m_s_3', 3], 's', 't_1');

        self::assertSame(SessionEvent::TOOL_STARTED, $start->type);
        self::assertTrue($start->isDurable());
        self::assertArrayNotHasKey('messageId', $start->data);
        self::assertSame('c1', $start->data['toolCallId']);
        self::assertSame('c1', $finish->data['toolCallId']);
        self::assertSame(['m_s_4', 4, 'm_s_3'], [$finish->data['messageId'], $finish->data['ref'], $finish->data['replaces']]);
        self::assertTrue($finish->data['truncated']);
        self::assertSame(TranscriptProjector::MAX_EVENT_CONTENT_BYTES, \strlen($finish->data['content']));
        self::assertSame(['turnId' => 't_1', ...$finish->data], $finish->payload());
    }

    /** @return list<Message> */
    private static function headless(): array
    {
        $projector = TranscriptProjector::new();
        $history = self::base();
        foreach (self::turnEvents() as $event) {
            $history = $projector->apply($history, $event);
        }

        return $history;
    }

    /**
     * @param list<Message> $rows
     * @return list<array<string, mixed>>
     */
    private static function shapes(array $rows): array
    {
        return array_map(self::shape(...), array_values($rows));
    }

    /** @return array<string, mixed> */
    private static function shape(Message $row): array
    {
        $data = json_decode(json_encode($row, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        unset($data['createdAt']);

        return $data;
    }
}
