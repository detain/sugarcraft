<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\BackendToolEventsMsg;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Host\TranscriptStore;
use SugarCraft\Crush\Host\TurnRunner;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionMeta;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Todo\TodoItem;
use SugarCraft\Crush\Todo\TodoList;
use SugarCraft\Crush\Todo\TodoReminder;
use SugarCraft\Crush\Todo\TodoStatus;
use SugarCraft\Crush\Tools\BuiltIn\Todo;
use SugarCraft\Crush\Tools\ToolCall as EngineToolCall;
use SugarCraft\Crush\Tools\ToolResult as EngineToolResult;

/**
 * Roadmap 3.C: {@see TurnRunner} keeps the session's todo list. A `Todo` call
 * runs in the turn's child; the runner reads the new list off the call's
 * finished frame, holds it for the dock pane and saves it to the session's
 * metadata row (`SessionMeta::$tasks`) — and at dispatch re-shows it to the
 * model when the history no longer shows it current, persisting the reminder
 * where the model read it.
 */
final class TurnRunnerTodoTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-runner-todo-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        foreach (glob($this->dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            foreach (glob($sub . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($sub);
        }
        @rmdir($this->dir);
    }

    public function testATodoCallInATurnBecomesTheSessionsSavedList(): void
    {
        [$store, $transcripts] = $this->store();
        $store->saveSessionMeta(SessionMeta::new('s', summary: 'kept'));
        $runner = TurnRunner::new();

        $this->runTurn($runner, $transcripts, 's', [self::todoCall('t1', self::list()->toArray()), new CompleteResponse(content: 'planned')]);

        $this->assertTrue($runner->heldTodos('s')?->equals(self::list()), 'held for the pane');
        $meta = $store->getSessionMeta('s');
        $this->assertNotNull($meta);
        $this->assertSame(self::list()->toArray(), $meta->tasks, 'saved into the dormant tasks slot');
        $this->assertSame('kept', $meta->summary, 'the row\'s other fields are kept');
        $this->assertTrue($transcripts->loadTodos('s')?->equals(self::list()));
        $this->assertTrue(TurnRunner::new()->todos($transcripts, 's')->equals(self::list()), 'a fresh runner reads it back');
    }

    public function testWithoutAStoreTheRunnerHoldsEachSessionsList(): void
    {
        $runner = TurnRunner::new();
        $this->assertTrue($runner->todos(null, null)->isEmpty());
        $this->assertNull($runner->heldTodos(null));

        $this->runTurn($runner, null, null, [self::todoCall('t1', self::list()->toArray()), new CompleteResponse(content: 'ok')]);

        $this->assertTrue($runner->todos(null, null)->equals(self::list()));
        $this->assertNull($runner->heldTodos('other'), 'one list per session');
    }

    public function testARefusedTodoCallChangesNothing(): void
    {
        $runner = TurnRunner::new();
        $runner->saveTodos(null, null, self::list());

        $twoActive = [['content' => 'a', 'status' => 'in_progress'], ['content' => 'b', 'status' => 'in_progress']];
        $this->runTurn($runner, null, null, [self::todoCall('t1', $twoActive), new CompleteResponse(content: 'oops')]);

        $this->assertTrue($runner->todos(null, null)->equals(self::list()));
    }

    public function testOnlyAFinishedTodoCallOfATurnThisRunnerStartedIsObserved(): void
    {
        $runner = TurnRunner::new();
        $ok = new EngineToolResult('c1', Todo::UPDATED . "\n\n" . self::list()->render());

        $this->assertNull($runner->observeTodo(new CancellationToken(), new ToolFinished('c1', Todo::NAME, $ok)), 'not a turn it started');
        $this->assertNull($runner->observeTodo(null, new ToolFinished('c1', Todo::NAME, $ok)));
        $this->assertNull($runner->heldTodos(null));
    }

    public function testAStaleListIsReShownAtDispatchAndPersistedWhereTheModelReadIt(): void
    {
        [, $transcripts] = $this->store();
        $runner = TurnRunner::new();
        $runner->saveTodos($transcripts, 's', self::list());

        // What compaction leaves: the call that wrote the list is gone.
        $history = [Message::user('[summary of the work so far]'), Message::user('carry on')];
        $reply = $this->runTurn($runner, $transcripts, 's', [
            static function (CompleteRequest $request): CompleteResponse {
                $wire = serialize($request->messages);
                $at = strpos($wire, TodoReminder::FENCE);

                return new CompleteResponse(content: $at !== false && $at > strpos($wire, 'carry on') ? 'saw the list' : 'no list');
            },
        ], $history);

        $this->assertSame('saw the list', $reply->content, 'the reminder reached the model, after the prompt');
        $first = $reply->turnTranscript[0] ?? null;
        $this->assertInstanceOf(Message::class, $first);
        $this->assertTrue(TodoReminder::isReminder($first), 'the reminder leads the turn transcript');
        $this->assertFalse($first->userVisible);

        [$settled, $final] = Message::settleTurnTranscript($history, $reply);
        $settled[] = $final;
        $this->assertFalse(TodoReminder::due(self::list(), $settled), 'once folded in, the next dispatch sees a current copy');
    }

    public function testACurrentListIsNotRepeated(): void
    {
        $runner = TurnRunner::new();
        $runner->saveTodos(null, null, self::list());
        $history = [
            Message::user('go'),
            Message::assistant('')->withToolResults([\SugarCraft\Crush\ToolResult::ok(Todo::NAME, Todo::UPDATED . "\n\n" . self::list()->render(), 'c1')])->withStepId('s_a_1'),
            Message::user('next'),
        ];

        $reply = $this->runTurn($runner, null, null, [
            static fn (CompleteRequest $request): CompleteResponse => new CompleteResponse(
                content: str_contains(serialize($request->messages), TodoReminder::FENCE) ? 'reminded' : 'quiet',
            ),
        ], $history);

        $this->assertSame('quiet', $reply->content);
        foreach ($reply->turnTranscript as $row) {
            $this->assertFalse(TodoReminder::isReminder($row));
        }
    }

    // ── harness ─────────────────────────────────────────────────────────

    /** @return array{0: EnhancedSessionStore, 1: TranscriptStore} */
    private function store(): array
    {
        $store = new EnhancedSessionStore($this->dir . '/session.db');
        $store->createSession('s', 'p', 'm');

        return [$store, TranscriptStore::new($store)];
    }

    /**
     * @param list<CompleteResponse|\Closure> $script
     * @param list<Message>|null $history
     */
    private function runTurn(TurnRunner $runner, ?TranscriptStore $transcripts, ?string $sessionId, array $script, ?array $history = null): Message
    {
        $provider = new ScriptedProvider($script, contextWindow: 1_000_000);
        $backend = EngineBackend::new($provider, 'm')->withoutHooks()->withRoot($this->dir)->withTools([new Todo()]);

        $thunk = $runner->start(
            $backend,
            $history ?? [Message::user('plan the work')],
            new \ArrayObject(),
            1,
            new CancellationToken(),
            false,
            transcripts: $transcripts,
            sessionId: $sessionId,
        );
        $msg = $this->settle($thunk());

        $this->assertTrue($msg instanceof AssistantMsg || $msg instanceof BackendToolEventsMsg);

        return $msg->message;
    }

    /** @param list<array<string, string>> $todos */
    private static function todoCall(string $id, array $todos): CompleteResponse
    {
        return new CompleteResponse(content: '', toolCalls: [new EngineToolCall($id, Todo::NAME, ['todos' => $todos])]);
    }

    private static function list(): TodoList
    {
        return TodoList::new(
            TodoItem::new('Write the parser', TodoStatus::Completed),
            TodoItem::new('Wire the pane', TodoStatus::InProgress),
            TodoItem::new('Update the docs'),
        );
    }

    private function settle(PromiseInterface $promise): mixed
    {
        $loop = Loop::get();
        $settled = false;
        $value = null;
        $failure = null;
        $promise->then(
            static function ($v) use (&$settled, &$value, $loop): void {
                $settled = true;
                $value = $v;
                $loop->stop();
            },
            static function (\Throwable $e) use (&$settled, &$failure, $loop): void {
                $settled = true;
                $failure = $e;
                $loop->stop();
            },
        );
        if (!$settled) {
            $watchdog = $loop->addTimer(30.0, static function () use ($loop, &$failure): void {
                $failure = new \RuntimeException('the turn never settled within the safety window');
                $loop->stop();
            });
            $loop->run();
            $loop->cancelTimer($watchdog);
        }
        if ($failure !== null) {
            $this->fail('turn failed: ' . $failure->getMessage());
        }

        return $value;
    }
}
