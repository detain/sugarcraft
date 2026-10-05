<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Backend\PendingAsk;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Events\PermissionAsked;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\ApprovalVerdict;
use SugarCraft\Crush\Permissions\AskOrigin;
use SugarCraft\Crush\Permissions\PermissionReply;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolEventPumpMsg;
use SugarCraft\Crush\Tools\ActivitySink;
use SugarCraft\Crush\Tools\ExemptFromParallelDeadline;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\RelaysPermissionAsks;
use SugarCraft\Crush\Tools\StreamsActivity;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall as EngineToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap P-E2: a delegated run's permission question says whose it is — in
 * the parent's modal, inline in that run's Agent View, and in the row the
 * answer leaves behind. The origin is named where it is known (the turn
 * child's reap loop, reading a parallel member's ask relay) and rides the
 * `ask` frame; a lone Task's question is recognised in the parent.
 */
final class AgentViewShowsChildAskTest extends TestCase
{
    use \SugarCraft\Crush\Tests\Support\ReapsForkedChildrenTrait;

    private const GENERATION = 3;
    private const COLS = 120;
    private const ROWS = 60;

    protected function setUp(): void
    {
        Renderer::setAgentView(null);
        Renderer::scanner()->clear();
    }

    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();
        Renderer::setAgentView(null);
        Renderer::scanner()->clear();
    }

    public function testTheOriginNamedAroundAQuestionRidesTheAskFrame(): void
    {
        $call = new EngineToolCall('inner_1', 'Bash', ['command' => 'rm -rf build']);
        $ask = HookResult::ask('Bash needs approval')->withAskedBy([PermissionGateHook::NAME]);

        $plain = PendingAsk::describe($call, $ask, 'default');
        $this->assertArrayNotHasKey('origin', $plain, 'the turn\'s own question names no run');

        $frame = AskOrigin::during(
            new AskOrigin('run-2', 'explore', 'call_1'),
            static fn (): array => PendingAsk::describe($call, $ask, 'default'),
        );
        $this->assertNull(AskOrigin::current(), 'the origin is named only while the question is put');
        $this->assertSame(['agentId' => 'run-2', 'agentName' => 'explore', 'parentCallId' => 'call_1'], $frame['origin']);
        $this->assertSame($plain['askId'], $frame['askId'], 'who asked does not change which question it is');

        $decoded = PendingAsk::fromFrame(\unserialize(\serialize($frame), ['allowed_classes' => false]), static function (): void {
        });
        $this->assertNotNull($decoded);
        $this->assertSame('run-2', $decoded->origin?->agentId);
        $this->assertSame('explore', $decoded->origin?->agentName);
        $this->assertNull(PendingAsk::fromFrame($plain, static function (): void {
        })?->origin);
        $this->assertNull(AskOrigin::fromArray(['agentId' => 7]), 'an unreadable origin is no origin');
    }

    public function testTheReapLoopNamesTheMemberWhoseRunAsked(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('Concurrent tool dispatch requires ext-pcntl.');
        }
        $origins = [];
        $approver = static function (EngineToolCall $call) use (&$origins): ApprovalVerdict {
            $origin = AskOrigin::current();
            $origins[$call->id()] = $origin?->toArray();

            return ApprovalVerdict::once();
        };
        $emitter = static function (SubAgentActivity $beat): void {
        };

        $provider = $this->createMock(ProviderInterface::class);
        $runtime = new Runtime($provider, new HookManager(new HookRegistry()), null, true);
        $a = self::member('task_a', 'call_a', $emitter);
        $b = self::member('task_b', 'call_b', $emitter);
        $app = App::new($provider, 'gpt-4')->withTools([$a, $b]);
        $results = array_values(iterator_to_array((new \ReflectionMethod($runtime, 'executeToolCalls'))->invoke(
            $runtime,
            [new EngineToolCall('call_a', 'task_a', []), new EngineToolCall('call_b', 'task_b', [])],
            $app,
            static function (): void {},
            $approver,
            null,
        )));

        $this->assertSame('task_a: once', $results[0]->content());
        $this->assertSame(['agentId' => 'task_a-run', 'agentName' => 'task_a', 'parentCallId' => 'call_a'], $origins['inner_task_a'] ?? null);
        $this->assertSame(['agentId' => 'task_b-run', 'agentName' => 'task_b', 'parentCallId' => 'call_b'], $origins['inner_task_b'] ?? null);
        $this->assertNull(AskOrigin::current());
    }

    public function testTheModalNamesTheSubAgentThatAsked(): void
    {
        [$chat, $inbox] = $this->chat();
        $ask = self::ask('inner_1', new AskOrigin('run-2', 'explore', 'call_1'));
        $inbox[] = [self::GENERATION, new PermissionAsked($ask)];

        [$asking] = $chat->update(new ToolEventPumpMsg());

        $prompt = $asking->pendingPermission();
        $this->assertNotNull($prompt);
        $this->assertSame($ask, $prompt->pendingAsk, 'the same handle answers it');
        $this->assertSame("Asked by sub-agent explore (look at the session layer)\nBash needs approval", $prompt->prompt);
        $this->assertStringContainsString('Asked by sub-agent explore', self::plain($asking));
    }

    public function testALoneTasksQuestionIsPutDownToTheOneRunGoingAndNeverGuessedBetweenTwo(): void
    {
        // A lone Task asks through the turn's own channel: no origin on the
        // frame, and a call the main turn never made.
        [$chat, $inbox] = $this->chat(runs: ['run-1']);
        $inbox[] = [self::GENERATION, new PermissionAsked(self::ask('inner_1'))];
        [$asking] = $chat->update(new ToolEventPumpMsg());
        $this->assertStringStartsWith('Asked by sub-agent explore (map the login flow)', $asking->pendingPermission()?->prompt ?? '');

        [$chat, $inbox] = $this->chat();
        $inbox[] = [self::GENERATION, new PermissionAsked(self::ask('inner_1'))];
        [$asking] = $chat->update(new ToolEventPumpMsg());
        $this->assertSame('Bash needs approval', $asking->pendingPermission()?->prompt, 'two runs going: not guessed');

        [$chat, $inbox] = $this->chat(runs: ['run-1']);
        $inbox[] = [self::GENERATION, new PermissionAsked(self::ask('call_1'))];
        [$asking] = $chat->update(new ToolEventPumpMsg());
        $this->assertSame('Bash needs approval', $asking->pendingPermission()?->prompt, 'the main turn\'s own question is its own');
    }

    public function testTheQuestionShowsInlineInThatRunsAgentViewOnly(): void
    {
        [$chat, $inbox] = $this->chat();
        $inbox[] = [self::GENERATION, new PermissionAsked(self::ask('inner_1', new AskOrigin('run-2', 'explore', 'call_1')))];
        [$asking] = $chat->update(new ToolEventPumpMsg());
        [$app] = App::new($this->createMock(ProviderInterface::class), 'm')
            ->withChat($asking)
            ->update(new WindowSizeMsg(self::COLS, self::ROWS));

        $inView = self::plain($app->openAgentView('run-2'));
        $this->assertStringContainsString('⏳ waiting on you: Bash(command: "rm -rf build") — answer it', $inView);

        $this->assertStringNotContainsString('⏳ waiting on you', self::plain($app->openAgentView('run-1')), 'not in a sibling\'s view');
        $this->assertStringNotContainsString('⏳ waiting on you', self::plain($app), 'nor in the parent transcript (the modal is there)');
    }

    public function testAnsweringLeavesAUiOnlyRowNamingTheRun(): void
    {
        [$chat, $inbox] = $this->chat();
        $ask = self::ask('inner_1', new AskOrigin('run-2', 'explore', 'call_1'));
        $inbox[] = [self::GENERATION, new PermissionAsked($ask)];
        [$asking] = $chat->update(new ToolEventPumpMsg());

        [$answered] = $asking->update(new KeyMsg(KeyType::Char, 'y'));

        $this->assertSame(PermissionReply::Once, $ask->resolution()?->reply);
        $this->assertNull($answered->pendingPermission());
        $last = $answered->history[\count($answered->history) - 1];
        $this->assertTrue($last->uiOnly, 'the parent model is never shown it');
        $this->assertSame('sub-agent explore asked to run Bash: allowed once', $last->content);

        [$chat, $inbox] = $this->chat();
        $inbox[] = [self::GENERATION, new PermissionAsked(self::ask('inner_1', new AskOrigin('run-2', 'explore', 'call_1')))];
        [$asking] = $chat->update(new ToolEventPumpMsg());
        [$refused] = $asking->update(new KeyMsg(KeyType::Escape));
        $this->assertSame('sub-agent explore asked to run Bash: refused', $refused->history[\count($refused->history) - 1]->content);
    }

    public function testTheMainTurnsOwnAnswerLeavesNoRow(): void
    {
        [$chat, $inbox] = $this->chat();
        $inbox[] = [self::GENERATION, new PermissionAsked(self::ask('call_1'))];
        [$asking] = $chat->update(new ToolEventPumpMsg());
        $before = \count($asking->history);

        [$answered] = $asking->update(new KeyMsg(KeyType::Char, 'y'));

        $this->assertCount($before, $answered->history);
    }

    /**
     * An in-flight parent whose one Task call (`call_1`) runs two delegated
     * runs, run-1 and run-2, each of the `explore` agent.
     *
     * @param list<string> $runs
     *
     * @return array{0: Chat, 1: \ArrayObject}
     */
    private function chat(array $runs = ['run-1', 'run-2']): array
    {
        $inbox = new \ArrayObject();
        $chat = (new Chat(
            history: [Message::user('audit everything'), Message::toolRunning(new ToolCall('Task', [], 'call_1'))],
            backend: new EchoBackend(),
            inFlight: true,
            generation: self::GENERATION,
            liveToolEvents: $inbox,
        ))->withSize(self::COLS, self::ROWS);

        $tasks = ['run-1' => 'map the login flow', 'run-2' => 'look at the session layer'];
        foreach ($runs as $id) {
            $chat->agentLive()->apply(new SubAgentActivity(
                SubAgentActivity::OP_STARTED,
                $id,
                'explore',
                $tasks[$id],
                1,
                '',
                parentCallId: 'call_1',
                description: $tasks[$id],
            ));
        }

        return [$chat, $inbox];
    }

    private static function ask(string $toolCallId, ?AskOrigin $origin = null): PendingAsk
    {
        return new PendingAsk(
            PendingAsk::askId($toolCallId, 'Bash', ['command' => 'rm -rf build']),
            $toolCallId,
            'Bash',
            ['command' => 'rm -rf build'],
            'Bash needs approval',
            'gate',
            'default',
            [PermissionReply::Once->value, PermissionReply::Reject->value],
            [],
            static function (): void {
            },
            $origin,
        );
    }

    private static function plain(Chat|App $model): string
    {
        $view = $model instanceof App ? $model->view() : Renderer::render($model);
        $text = \is_string($view) ? $view : $view->body;

        return (string) preg_replace('/\x{E000}[^\x{E001}]*\x{E001}|[\x{E000}-\x{F8FF}]/u', '', Ansi::strip($text));
    }

    /**
     * A parallel Task member stand-in: it reports its run started under its
     * call, then asks one gate-only question and reports the verdict.
     */
    private static function member(string $name, string $callId, \Closure $emitter): Tool
    {
        $inherited = static fn (): ApprovalVerdict => ApprovalVerdict::reject('inherited');

        return new class ($name, $callId, $emitter, $inherited) implements Tool, ParallelSafe, ExemptFromParallelDeadline, RelaysPermissionAsks, StreamsActivity {
            public function __construct(
                private string $name,
                private string $callId,
                private \Closure $emitter,
                private \Closure $approver,
            ) {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'asks before it writes';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                ($this->emitter)(new SubAgentActivity(
                    SubAgentActivity::OP_STARTED,
                    $this->name . '-run',
                    $this->name,
                    'task',
                    1,
                    '',
                    parentCallId: $this->callId,
                ));
                $verdict = ($this->approver)(
                    new EngineToolCall('inner_' . $this->name, 'Write', ['path' => 'x.txt']),
                    HookResult::ask('Write x.txt?')->withAskedBy([PermissionGateHook::NAME]),
                );

                return new ToolResult(toolCallId: $this->callId, content: $this->name . ': ' . ($verdict->reply?->value ?? 'unanswered'));
            }

            public function isParallelSafe(): bool
            {
                return true;
            }

            public function withPermissionApprover(\Closure $approver): Tool
            {
                return new self($this->name, $this->callId, $this->emitter, $approver);
            }

            public function subAgentEmitter(): ?\Closure
            {
                return $this->emitter;
            }

            public function queuedActivity(EngineToolCall $call, array $args): ?SubAgentActivity
            {
                return null;
            }

            public function withActivitySink(ActivitySink $sink): Tool
            {
                return new self($this->name, $this->callId, static function (SubAgentActivity $beat) use ($sink): void {
                    $sink->emit($beat);
                }, $this->approver);
            }
        };
    }
}
