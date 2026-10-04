<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\PendingAsk;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Events\PermissionAsked;
use SugarCraft\Crush\Events\PermissionResolved;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Host\EventLog;
use SugarCraft\Crush\Host\SessionEvent;
use SugarCraft\Crush\Host\TranscriptStore;
use SugarCraft\Crush\Host\TurnController;
use SugarCraft\Crush\Host\TurnRunner;
use SugarCraft\Crush\Host\WorkspaceContext;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\PermissionReply;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\ToolEventPumpMsg;
use SugarCraft\Crush\Tools\ToolResult as EngineToolResult;

/**
 * O-2f's carried handoff: {@see TurnRunner} is the first writer of the W3
 * {@see EventLog}. A turn's durable story — `turn.started`, each tool's start
 * and finish, the permission question and its answer, `assistant.completed`,
 * `turn.completed` — lands in `session_events` in the order it happened, each
 * transcript row named by the identity its save keeps
 * ({@see TranscriptStore::identify()}); streaming deltas reach a listener and
 * never the log.
 */
final class TurnRunnerEventLogTest extends TestCase
{
    private string $dir;

    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-turn-runner-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->store = new EnhancedSessionStore($this->dir . '/session.db');
        $this->store->createSession('s', 'p', 'm');
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

    /**
     * The settled-queue path (every turn without ext-pcntl): the tool events
     * fold after the backend answered, and `turn.completed` still lands after
     * the last row they wrote, never above it.
     */
    public function testASettledTurnLogsItsStoryInOrderWithTheSavedRowIds(): void
    {
        $backend = self::scripted();
        $chat = $this->chat($backend);

        [$running, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $settled = self::start($cmd);
        ($backend->emit)(new ToolStarted('c1', 'Bash', ['command' => 'ls']));
        ($backend->emit)(new ToolFinished('c1', 'Bash', new EngineToolResult('c1', 'a.txt')));
        ($backend->deferred)()->resolve(Message::assistant('there is one file'));

        $done = self::apply($running, self::settled($settled));
        $done->flushTranscript();

        // O-2g: the prompt's row is announced (`message.created`) ahead of the
        // turn that answers it; the turn's own story follows from seq 2.
        [$created, $events] = self::splitCreated(EventLog::new($this->store)->since('s'));
        self::assertCount(1, $created);
        self::assertSame(1, $created[0]['seq']);
        self::assertSame(
            [SessionEvent::TURN_STARTED, SessionEvent::TOOL_STARTED, SessionEvent::TOOL_FINISHED, SessionEvent::ASSISTANT_COMPLETED, SessionEvent::TURN_COMPLETED],
            array_column($events, 'type'),
        );
        self::assertSame(range(2, 6), array_column($events, 'seq'));
        $turnIds = array_unique(array_map(static fn (array $e): string => $e['payload']['turnId'], $events));
        self::assertCount(1, $turnIds, 'every event names the one turn');
        self::assertSame('end_turn', $events[4]['payload']['stopReason']);

        $saved = [];
        foreach (TranscriptStore::new($this->store)->load('s') as $row) {
            $saved[$row->content] = $row->id;
        }
        self::assertSame($saved['run ls'], $events[0]['payload']['messageId'], 'turn.started names the prompt row');
        self::assertSame($saved['run ls'], $created[0]['payload']['messageId'], 'message.created named the same row first');
        self::assertSame($saved['a.txt'], $events[2]['payload']['messageId'], 'tool.finished names the saved tool row');
        self::assertSame($saved['there is one file'], $events[3]['payload']['messageId'], 'assistant.completed names the saved reply');
        self::assertSame('c1', $events[1]['payload']['toolCallId']);
    }

    /**
     * The live path: events fold as they arrive, a listener hears the
     * durable ones with their seq and the deltas without one, and the log
     * holds the durable ones only — including the permission question and
     * the answer that settled it.
     */
    public function testTheLivePumpLogsDurableEventsAndOnlyBroadcastsDeltas(): void
    {
        $runner = TurnRunner::new();
        $heard = [];
        $runner->listen(static function (SessionEvent $event) use (&$heard): void {
            $heard[] = $event;
        });
        $backend = self::scripted();
        $chat = $this->chat($backend, $runner);

        [$running, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $settled = self::start($cmd);

        ($backend->token)('there ');
        $ask = new PendingAsk(
            PendingAsk::askId('c1', 'Bash', ['command' => 'rm x']),
            'c1',
            'Bash',
            ['command' => 'rm x'],
            'Bash wants to run rm x',
            'gate',
            'default',
            ['once', 'always', 'reject'],
            ['tool' => 'Bash'],
            static function (PermissionResolved $resolved) use ($backend): void {
                ($backend->emit)($resolved);
            },
        );
        ($backend->emit)(new PermissionAsked($ask));
        $running = self::pump($running);
        self::assertNotNull($running->pendingPermission(), 'the question went up');
        $ask->reply(PermissionReply::Once);
        ($backend->emit)(new ToolStarted('c1', 'Bash', ['command' => 'rm x']));
        ($backend->emit)(new ToolFinished('c1', 'Bash', new EngineToolResult('c1', 'removed')));
        $running = self::pump($running);

        ($backend->deferred)()->resolve(Message::assistant('there it is'));
        self::apply($running, self::settled($settled));

        // `message.created` is announced through the runner ahead of the
        // turn (TurnRunner::announce()), so it is logged AND heard, seq 1.
        $logged = array_column(EventLog::new($this->store)->since('s'), 'type');
        self::assertSame([
            SessionEvent::MESSAGE_CREATED,
            SessionEvent::TURN_STARTED,
            SessionEvent::PERMISSION_REQUESTED,
            SessionEvent::PERMISSION_RESOLVED,
            SessionEvent::TOOL_STARTED,
            SessionEvent::TOOL_FINISHED,
            SessionEvent::ASSISTANT_COMPLETED,
            SessionEvent::TURN_COMPLETED,
        ], $logged);

        $delta = array_values(array_filter($heard, static fn (SessionEvent $e): bool => $e->type === SessionEvent::ASSISTANT_DELTA));
        self::assertCount(1, $delta, 'the listener heard the delta');
        self::assertNull($delta[0]->seq, 'an ephemeral event never carries a seq');
        self::assertSame('there ', $delta[0]->data['text']);
        $durable = array_values(array_filter($heard, static fn (SessionEvent $e): bool => $e->isDurable()));
        self::assertSame($logged, array_map(static fn (SessionEvent $e): string => $e->type, $durable));
        self::assertSame(range(1, 8), array_map(static fn (SessionEvent $e): ?int => $e->seq, $durable), 'heard after the log numbered it');
        self::assertSame('once', $durable[3]->data['reply']);
    }

    /** A turn cancelled before it settled records its end and no reply. */
    public function testACancelledTurnRecordsOnlyItsEnd(): void
    {
        $runner = TurnRunner::new();
        $backend = self::scripted();
        $cancellation = new CancellationToken();
        $run = $runner->start(
            backend: $backend,
            history: [Message::user('go')],
            inbox: new \ArrayObject(),
            generation: 1,
            cancellation: $cancellation,
            transcripts: TranscriptStore::new($this->store),
            sessionId: 's',
        );
        $run();
        $cancellation->cancel();
        ($backend->deferred)()->resolve(Message::assistant('too late'));

        $events = EventLog::new($this->store)->since('s');
        self::assertSame([SessionEvent::TURN_STARTED, SessionEvent::TURN_COMPLETED], array_column($events, 'type'));
        self::assertSame('cancelled', $events[1]['payload']['stopReason']);
    }

    /** The log is a second audience: a throwing listener is detached, the turn is not lost. */
    public function testAThrowingListenerNeverCostsTheTurn(): void
    {
        $runner = TurnRunner::new();
        $calls = 0;
        $runner->listen(static function () use (&$calls): void {
            $calls++;

            throw new \RuntimeException('broken socket');
        });
        $backend = self::scripted();
        $chat = $this->chat($backend, $runner);

        [$running, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $settled = self::start($cmd);
        ($backend->deferred)()->resolve(Message::assistant('fine'));
        $done = self::apply($running, self::settled($settled));

        self::assertSame(1, $calls, 'detached after its first throw');
        self::assertFalse($runner->hasListeners());
        self::assertSame('fine', $done->history[\count($done->history) - 1]->content);
        self::assertCount(3, self::splitCreated(EventLog::new($this->store)->since('s'))[1], 'the log is still written');
    }

    /** No store, or no session: nothing is logged and the turn runs as before. */
    public function testWithoutAStoreNothingIsLogged(): void
    {
        $backend = self::scripted();
        $chat = new Chat(inputBuf: 'run ls', backend: $backend);

        [$running, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $settled = self::start($cmd);
        ($backend->deferred)()->resolve(Message::assistant('ok'));
        $done = self::apply($running, self::settled($settled));

        self::assertFalse($done->inFlight);
        self::assertSame([], EventLog::new($this->store)->since('s'));
    }

    private function chat(Backend $backend, ?TurnRunner $runner = null): Chat
    {
        $workspace = WorkspaceContext::new(sessionStore: $this->store);
        if ($runner !== null) {
            $workspace = $workspace->withService(TurnRunner::class, $runner);
        }

        return new Chat(
            inputBuf: 'run ls',
            backend: $backend,
            sessionStore: $this->store,
            currentSessionId: 's',
            workspace: $workspace,
            liveToolEvents: new \ArrayObject(),
            streaming: true,
        );
    }

    /**
     * The log split into the prompt rows' `message.created` events (written by
     * {@see TurnController} at dispatch) and the turn's own story.
     *
     * @param list<array<string, mixed>> $events
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private static function splitCreated(array $events): array
    {
        $created = array_values(array_filter($events, static fn (array $e): bool => $e['type'] === TurnController::MESSAGE_CREATED));
        $rest = array_values(array_filter($events, static fn (array $e): bool => $e['type'] !== TurnController::MESSAGE_CREATED));

        return [$created, $rest];
    }

    /**
     * A backend whose turn the test drives: `emit` / `token` are the
     * callbacks the turn handed it, `deferred` settles it.
     */
    private static function scripted(): Backend
    {
        return new class () implements Backend {
            public ?\Closure $emit = null;

            public ?\Closure $token = null;

            public ?\Closure $deferred = null;

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant('unused');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                $deferred = new Deferred();
                $this->emit = static fn (object $event) => $onEvent === null ? null : $onEvent($event);
                $this->token = static fn (string $text) => $onToken === null ? null : $onToken($text);
                $this->deferred = static fn (): Deferred => $deferred;

                return $deferred->promise();
            }
        };
    }

    /** Run the dispatch's async Cmd; the box receives the Msg its promise settles to. */
    private static function start(?\Closure $cmd): \stdClass
    {
        self::assertNotNull($cmd);
        $box = new \stdClass();
        $box->msg = null;
        $pending = [$cmd];
        while (($next = array_shift($pending)) !== null) {
            $out = $next();
            if ($out instanceof BatchMsg) {
                array_push($pending, ...$out->cmds);
            } elseif ($out instanceof AsyncCmd) {
                $out->promise->then(static function (mixed $msg) use ($box): void {
                    $box->msg = $msg;
                });
            }
        }

        return $box;
    }

    private static function settled(\stdClass $box): Msg
    {
        self::assertInstanceOf(Msg::class, $box->msg, 'the turn never settled');

        return $box->msg;
    }

    private static function pump(Chat $chat): Chat
    {
        for ($i = 0; $i < 32; $i++) {
            [$chat, $cmd] = $chat->update(new ToolEventPumpMsg());
            if ($cmd === null) {
                break;
            }
        }

        return $chat;
    }

    /** Fold a settled turn's Msg the way Program::runCmd() does. */
    private static function apply(Chat $chat, Msg $msg): Chat
    {
        $next = $msg;
        for ($i = 0; $i < 32 && $next !== null; $i++) {
            [$chat, $cmd] = $chat->update($next);
            $next = null;
            if ($cmd !== null) {
                $produced = $cmd();
                if ($produced instanceof Msg && !$produced instanceof AsyncCmd && !$produced instanceof BatchMsg) {
                    $next = $produced;
                }
            }
        }

        return $chat;
    }
}
