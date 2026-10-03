<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * Step 0.13-a: the session-affinity header is WIRED, request-scoped.
 *
 * The chain under test, one link per test: Chat stamps its current session
 * onto the engine per dispatch; the engine puts it on the turn's App, so the
 * hook chain stops receiving `sessionId: ''`; Runtime copies it onto every
 * CompleteRequest; and the two SessionAffinity providers hash the REQUEST's
 * id into the header, ahead of any id they were constructed with.
 */
final class SessionAffinityWiringTest extends TestCase
{
    private const WIRE_BODY = '{"choices":[{"message":{"content":"ok"}}],"usage":{"total_tokens":1}}';

    private const WIRE_STREAM = 'data: {"choices":[{"delta":{"content":"ok"}}]}' . "\n"
        . 'data: {"choices":[{"delta":{},"finish_reason":"stop"}]}' . "\n"
        . 'data: [DONE]' . "\n";

    /** @var list<array<string, mixed>> */
    private array $wire = [];

    public function testTheRequestSessionWinsOverTheConstructorIdOnBothSglangCompletionSites(): void
    {
        $sglang = new SglangProvider('https://gw.example', 'MiniMax-M2.7', null, $this->recordingClient([self::WIRE_BODY, self::WIRE_STREAM, self::WIRE_BODY]), null, null, [], 'ctor-session');

        $sglang->complete($this->requestFor('req-session'));
        iterator_to_array($sglang->completeStream($this->requestFor('req-session')), false);
        $sglang->complete($this->requestFor(null));

        $this->assertSame([hash('sha256', 'req-session')], $this->wire[0]['request']->getHeader('X-SugarCrush-Session'));
        $this->assertSame([hash('sha256', 'req-session')], $this->wire[1]['request']->getHeader('X-SugarCrush-Session'));
        $this->assertSame([hash('sha256', 'ctor-session')], $this->wire[2]['request']->getHeader('X-SugarCrush-Session'), 'a request naming no session falls back to the constructor id');
    }

    public function testTheRequestSessionWinsOnBothCustomCompletionSitesAndNoSessionSendsNoHeader(): void
    {
        $custom = new CustomProvider('custom', 'https://gw.example', 'some-model', null, $this->recordingClient([self::WIRE_BODY, self::WIRE_STREAM, self::WIRE_BODY]), true, true, null);

        $custom->complete($this->requestFor('req-session'));
        iterator_to_array($custom->completeStream($this->requestFor('req-session')), false);
        $custom->complete($this->requestFor(''));

        $this->assertSame([hash('sha256', 'req-session')], $this->wire[0]['request']->getHeader('X-SugarCrush-Session'));
        $this->assertSame([hash('sha256', 'req-session')], $this->wire[1]['request']->getHeader('X-SugarCrush-Session'));
        $this->assertFalse($this->wire[2]['request']->hasHeader('X-SugarCrush-Session'), 'a blank session is no session');
    }

    public function testTheEngineStampsItsSessionOnEveryRequestAndEveryHookContext(): void
    {
        $provider = $this->sessionRecordingProvider();
        $hookSessions = new \ArrayObject();
        $hooks = new HookManager(new HookRegistry());
        $hooks->register($this->sessionCapturingHook($hookSessions));

        EngineBackend::new($provider, 'm')
            ->withTools([$this->idleTool()])
            ->withHooks($hooks)
            ->withSessionId('sess-7')
            ->complete([Message::user('go')]);

        $this->assertSame(['sess-7', 'sess-7'], $provider->sessions, 'both steps of the turn name the session');
        $this->assertSame(['sess-7'], $hookSessions->getArrayCopy(), 'the engine-path hook chain sees the session, not an empty string');
    }

    public function testAnEngineWithNoSessionKeepsTheOldEmptyShape(): void
    {
        $provider = $this->sessionRecordingProvider();
        $hookSessions = new \ArrayObject();
        $hooks = new HookManager(new HookRegistry());
        $hooks->register($this->sessionCapturingHook($hookSessions));

        EngineBackend::new($provider, 'm')
            ->withTools([$this->idleTool()])
            ->withHooks($hooks)
            ->withSessionId('')
            ->complete([Message::user('go')]);

        $this->assertSame([null, null], $provider->sessions);
        $this->assertSame([''], $hookSessions->getArrayCopy());
    }

    public function testChatStampsItsCurrentSessionOnEachDispatchAndFollowsASwitch(): void
    {
        $shared = EngineBackend::new($this->sessionRecordingProvider(), 'wired');
        $chat = (new Chat(history: [Message::user('hello'), Message::assistant('hi')], backend: $shared))
            ->withCurrentSessionId('first-session');

        $launched = $this->dispatchedEngine($this->submitDraft($chat, 'go'));
        $this->assertSame('first-session', $this->engineSession($launched));
        $this->assertNull($this->engineSession($shared), 'the shared backend is never stamped — the clone is per dispatch');

        $switched = $this->dispatchedEngine($this->submitDraft($chat->withCurrentSessionId('second-session'), 'again'));
        $this->assertSame('second-session', $this->engineSession($switched));
    }

    public function testAChatWithNoSessionDispatchesTheSharedBackendUntouched(): void
    {
        $shared = EngineBackend::new($this->sessionRecordingProvider(), 'wired');
        $chat = new Chat(history: [Message::user('hello'), Message::assistant('hi')], backend: $shared);

        $this->assertSame($shared, $this->dispatchedEngine($this->submitDraft($chat, 'go')));
    }

    private function requestFor(?string $sessionId): CompleteRequest
    {
        return new CompleteRequest(model: 'm', messages: [new UserMessage('hi')], sessionId: $sessionId);
    }

    /** @param list<string> $bodies */
    private function recordingClient(array $bodies): Client
    {
        $this->wire = [];
        $stack = HandlerStack::create(new MockHandler(array_map(static fn(string $b): Response => new Response(200, [], $b), $bodies)));
        $stack->push(Middleware::history($this->wire));

        return new Client(['base_uri' => 'https://gw.example/', 'handler' => $stack]);
    }

    /** @param \ArrayObject<int, string> $sink */
    private function sessionCapturingHook(\ArrayObject $sink): HookInterface
    {
        return new class ($sink) implements HookInterface {
            /** @param \ArrayObject<int, string> $sink */
            public function __construct(private \ArrayObject $sink) {}

            public function name(): string { return 'session-probe'; }
            public function event(): HookEvent { return HookEvent::PreToolUse; }
            public function matcher(): string { return 'Idle'; }

            public function execute(HookContext $context): HookResult
            {
                $this->sink[] = $context->sessionId;

                return HookResult::allow();
            }
        };
    }

    private function idleTool(): Tool
    {
        return new class implements Tool {
            public function name(): string { return 'Idle'; }
            public function description(): string { return 'does nothing'; }
            public function inputSchema(): array { return ['type' => 'object', 'properties' => []]; }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: '', content: 'idle');
            }
        };
    }

    private function sessionRecordingProvider(): ProviderInterface
    {
        return new class implements ProviderInterface {
            /** @var list<?string> */
            public array $sessions = [];

            public function name(): string { return 'session-recorder'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return true; }
            public function supportsVision(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 100_000; }
            public function costPer1kTokens(string $model, string $direction): float { return 0.0; }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                $this->sessions[] = $request->sessionId;

                return count($this->sessions) === 1
                    ? new CompleteResponse(content: 'working', toolCalls: [new ToolCall('call_1', 'Idle', [])])
                    : new CompleteResponse(content: 'done');
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                yield new CompleteResponse(content: '');
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                return new EmbeddingsResponse([]);
            }
        };
    }

    /** @return \Closure the command the submit produced */
    private function submitDraft(Chat $chat, string $draft): \Closure
    {
        foreach (mb_str_split($draft) as $char) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $char));
        }
        [, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceOf(\Closure::class, $cmd, 'fixture: the submit must dispatch a turn');

        return $cmd;
    }

    /**
     * Walk the command's captured closures (breadth-first, reflection only —
     * nothing is invoked, so no fork and no provider call) to the
     * EngineBackend the dispatch was built around.
     */
    private function dispatchedEngine(\Closure $cmd): EngineBackend
    {
        $queue = [$cmd];
        while ($queue !== []) {
            $closure = array_shift($queue);
            foreach ((new \ReflectionFunction($closure))->getStaticVariables() as $name => $captured) {
                if ($name === 'backend' && $captured instanceof EngineBackend) {
                    return $captured;
                }
                if ($captured instanceof \Closure) {
                    $queue[] = $captured;
                } elseif (is_array($captured)) {
                    foreach ($captured as $item) {
                        if ($item instanceof \Closure) {
                            $queue[] = $item;
                        }
                    }
                }
            }
        }

        $this->fail('no EngineBackend was captured by the dispatched command');
    }

    private function engineSession(EngineBackend $engine): ?string
    {
        return (new \ReflectionProperty(EngineBackend::class, 'sessionId'))->getValue($engine);
    }
}
