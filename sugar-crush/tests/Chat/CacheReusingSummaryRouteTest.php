<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CacheReusingSummaryBackend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Backend\SummarisesWithCache;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Context\ContextCompactor;
use SugarCraft\Crush\HistoryCompactedMsg;
use SugarCraft\Crush\Host\CompactionService;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Usage;

/**
 * Roadmap 2.4-2: `/compact` and the 85% tier summarise by sending the
 * conversation's OWN request — the same system prompt, tools and history a
 * turn sends — plus one final "do not call tools" instruction, so the request
 * reuses the provider's cached prefix. The tools are advertised and never run.
 */
final class CacheReusingSummaryRouteTest extends TestCase
{
    private string $sandbox;

    private string|false $originalHome;

    private mixed $originalServerHome = null;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-cachesum-' . bin2hex(random_bytes(6));
        mkdir($this->sandbox . '/home', 0o700, true);
        mkdir($this->sandbox . '/repo', 0o700, true);
        $this->originalHome = getenv('HOME');
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        putenv('HOME=' . $this->sandbox . '/home');
        $_SERVER['HOME'] = $this->sandbox . '/home';
    }

    protected function tearDown(): void
    {
        $this->originalHome === false ? putenv('HOME') : putenv('HOME=' . $this->originalHome);
        if ($this->originalServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->originalServerHome;
        }
        exec('rm -rf ' . escapeshellarg($this->sandbox) . ' 2>&1');
    }

    /** A switched engine takes the summaries with it, keeping the launch's summary model. */
    public function testABackendSwitchRebuildsTheCacheReusingSummaryOnTheNewEngine(): void
    {
        $launch = EngineBackend::new(new ScriptedProvider([]), 'launch-model')->withSummaryModel('cheap-model');
        $toolless = new EchoBackend();
        $chat = new Chat(backend: $launch, summaryBackend: CacheReusingSummaryBackend::new($toolless, $launch));

        $switched = $chat->withBackend(EngineBackend::new(new ScriptedProvider([]), 'other-model'));

        $summary = (new \ReflectionProperty(Chat::class, 'summaryBackend'))->getValue($switched);
        self::assertInstanceOf(CacheReusingSummaryBackend::class, $summary);
        $engine = $summary->engine();
        self::assertInstanceOf(EngineBackend::class, $engine);
        self::assertSame('other-model', $engine->model(), 'summaries follow the switched engine');
        self::assertSame('cheap-model', $engine->summaryModel(), 'the launch-time summary model is carried');
        self::assertSame($toolless, $summary->toolless());

        $echo = $chat->withBackend(new EchoBackend());
        self::assertSame(
            (new \ReflectionProperty(Chat::class, 'summaryBackend'))->getValue($chat),
            (new \ReflectionProperty(Chat::class, 'summaryBackend'))->getValue($echo),
            'a backend that cannot reuse the cache keeps the summary backend it had',
        );
    }

    public function testCompactSendsTheConversationItselfPlusAnIndexInstruction(): void
    {
        $seen = new \ArrayObject();
        $chat = new Chat(
            history: self::history(),
            inputBuf: '/compact',
            backend: new EchoBackend(),
            compactorConfig: CompactorConfig::new()->withRecentPreserveCount(2),
            summaryBackend: self::summariser($seen, self::records(4)),
        );

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNotNull($cmd);
        $landed = $this->resolve($cmd);

        $this->assertSame('summarise', $seen['via'], 'the cache-reusing route, never a plain completion');
        $this->assertSame(
            array_map(static fn (Message $m): string => $m->content, self::history()),
            array_map(static fn (Message $m): string => $m->content, Message::agentVisible($seen['history'])),
            'the conversation as the next turn would send it — no re-rendered copy',
        );

        $instruction = $seen['instruction'];
        $this->assertStringStartsWith(CompactionService::CACHE_SUMMARY_PREAMBLE, $instruction);
        $this->assertStringContainsString('Do not call any tools', $instruction);
        $this->assertStringContainsString("### Exchange 4\nUser: question 4", $instruction);
        $this->assertStringNotContainsString('detail detail', $instruction, 'an index, not the exchanges again');

        $this->assertInstanceOf(HistoryCompactedMsg::class, $landed);
        [$after] = $next->update($landed);
        $joined = implode("\n", array_map(static fn (Message $m): string => $m->content, Message::agentVisible($after->history)));
        $this->assertStringContainsString('recorded 3', $joined, 'the records land as before');
        $this->assertStringNotContainsString('[exchanged information]', $joined);
    }

    public function testTheParkedPromptIsNotPartOfWhatIsSummarised(): void
    {
        $seen = new \ArrayObject();
        $probe = [...self::history(), Message::user('the parked prompt')];

        $request = CompactionService::new()->buildSummarizationRequest(
            self::summariser($seen, self::records(4)),
            new ContextCompactor(CompactorConfig::new()->withRecentPreserveCount(2)),
            $probe,
            'the parked prompt',
        );
        $this->assertNotNull($request);
        $msg = $this->settle(($request['promise'])());

        $this->assertInstanceOf(HistoryCompactedMsg::class, $msg);
        $this->assertSame('the parked prompt', $msg->parkedSubmission);
        $contents = array_map(static fn (Message $m): string => $m->content, $seen['history']);
        $this->assertNotContains('the parked prompt', $contents, 'it has not been sent yet; it goes out after the landing');
        $this->assertSame(count(self::history()), count($contents));
    }

    public function testAPlainBackendKeepsTheToollessRequest(): void
    {
        $seen = new \ArrayObject();
        $plain = new class ($seen) implements Backend {
            public function __construct(private readonly \ArrayObject $seen)
            {
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                throw new \LogicException('not used');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                $this->seen['history'] = $history;

                return \React\Promise\resolve(Message::assistant('1.'));
            }
        };

        $request = CompactionService::new()->buildSummarizationRequest(
            $plain,
            new ContextCompactor(CompactorConfig::new()->withRecentPreserveCount(2)),
            self::history(),
            null,
        );
        $this->assertNotNull($request);
        $this->settle(($request['promise'])());

        $this->assertSame(CompactionService::COMPACT_SUMMARY_PROMPT, $seen['history'][0]->content);
    }

    /**
     * The engine half: the summary request is a turn's request — the same
     * system prompt and tool schemas — with the history and one instruction
     * row, and a call the reply asks for is dropped, never run.
     */
    public function testTheEngineSendsTheTurnsPrefixAndRunsNoTool(): void
    {
        $tool = self::probe();
        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'plain answer'),
            static fn (CompleteRequest $r): CompleteResponse => new CompleteResponse(
                content: 'THE SUMMARY',
                toolCalls: [new ToolCall('x1', 'probe', [])],
                usage: Usage::new(500, 0.0, 400, 20, 0),
            ),
        ]);
        $engine = EngineBackend::new($provider, 'm')->withoutHooks()->withRoot($this->sandbox . '/repo')->withTools([$tool]);
        $history = [Message::user('first question'), Message::assistant('first answer')];

        $engine->complete([...$history, Message::user('next')]);
        $reply = (new \ReflectionMethod($engine, 'summaryReply'))->invoke($engine, $history, 'SUMMARISE NOW');

        [$turn, $summary] = $provider->requests;
        $this->assertSame($turn->systemPrompt, $summary->systemPrompt, 'the same system prompt');
        $this->assertSame($turn->tools, $summary->tools, 'the same tools stay advertised');
        $this->assertSame('m', $summary->model, 'the conversation\'s own model by default');
        $last = $summary->messages[array_key_last($summary->messages)];
        $this->assertInstanceOf(UserMessage::class, $last);
        $this->assertSame('SUMMARISE NOW', $last->content());
        $this->assertSame('first question', $summary->messages[0]->content());

        $this->assertSame(0, $tool->runs, 'the call the reply asked for never ran');
        $this->assertSame('THE SUMMARY', $reply->content);
        $this->assertSame([], $reply->toolResults);
        $this->assertSame(400, $reply->usage?->inputTokens);
    }

    public function testANamedSummaryModelMovesOnlyTheSummary(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'S')]);
        $engine = EngineBackend::new($provider, 'm')->withoutHooks()->withRoot($this->sandbox . '/repo')
            ->withSummaryModel('small');

        (new \ReflectionMethod($engine, 'summaryReply'))->invoke($engine, [Message::user('q')], 'go');

        $this->assertSame('small', $provider->requests[0]->model);
        $this->assertSame('m', $engine->model());
        $this->assertNull($engine->withSummaryModel('  ')->summaryModel(), 'blank is the conversation\'s own');
    }

    /** The forked path: the reply and its usage cross the frame; no tool runs. */
    public function testSummariseAsyncSettlesFromTheForkedChild(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('summariseAsync() runs in-process without pcntl');
        }

        $marker = $this->sandbox . '/ran';
        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'FORKED SUMMARY', toolCalls: [new ToolCall('x1', 'touch', [])], usage: Usage::new(300, 0.0, 250, 9, 0)),
        ]);
        $engine = EngineBackend::new($provider, 'm')->withoutHooks()->withRoot($this->sandbox . '/repo')
            ->withTools([self::toucher($marker)]);

        $reply = $this->settle($engine->summariseAsync([Message::user('q'), Message::assistant('a')], 'summarise'));

        $this->assertInstanceOf(Message::class, $reply);
        $this->assertSame('FORKED SUMMARY', $reply->content);
        $this->assertSame(250, $reply->usage?->inputTokens);
        $this->assertFileDoesNotExist($marker, 'the advertised tool never ran in the child either');
    }

    public function testACancelledTokenRejectsBeforeAnyRequest(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'never')]);
        $token = new CancellationToken();
        $token->cancel();

        $failure = null;
        EngineBackend::new($provider, 'm')->summariseAsync([Message::user('q')], 'go', $token)
            ->then(null, static function (\Throwable $e) use (&$failure): void {
                $failure = $e;
            });

        $this->assertInstanceOf(\RuntimeException::class, $failure);
        $this->assertSame([], $provider->requests);
    }

    public function testTheAdapterRoutesEachJobToItsHalf(): void
    {
        $seen = new \ArrayObject();
        $engine = self::summariser($seen, 'S');
        $toolless = new EchoBackend();
        $adapter = CacheReusingSummaryBackend::new($toolless, $engine);

        $this->assertSame($toolless, $adapter->toolless());
        $this->assertSame($engine, $adapter->engine());
        $this->settle($adapter->summariseAsync([Message::user('q')], 'go'));
        $this->assertSame('summarise', $seen['via']);
        $this->assertInstanceOf(Message::class, $adapter->complete([Message::user('echo me')]));
        $this->assertSame('summarise', $seen['via'], 'a plain completion never reaches the engine half');
    }

    /** @return list<Message> */
    private static function history(int $pairs = 6): array
    {
        $out = [];
        for ($i = 1; $i <= $pairs; $i++) {
            $out[] = Message::user("question {$i}");
            $out[] = Message::assistant("answer {$i} " . str_repeat('detail ', 60));
        }

        return $out;
    }

    private static function records(int $count): string
    {
        $lines = [];
        for ($n = 1; $n <= $count; $n++) {
            $lines[] = "{$n}.\nasked: recorded {$n}";
        }

        return implode("\n", $lines);
    }

    private static function summariser(\ArrayObject $seen, string $reply): Backend&SummarisesWithCache
    {
        return new class ($seen, $reply) implements Backend, SummarisesWithCache {
            public function __construct(private readonly \ArrayObject $seen, private readonly string $reply)
            {
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                $this->seen['via'] = 'complete';

                return Message::assistant($this->reply);
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                $this->seen['via'] = 'complete';

                return \React\Promise\resolve(Message::assistant($this->reply));
            }

            public function summariseAsync(array $history, string $instruction, ?CancellationToken $cancellation = null): PromiseInterface
            {
                $this->seen['via'] = 'summarise';
                $this->seen['history'] = $history;
                $this->seen['instruction'] = $instruction;

                return \React\Promise\resolve(Message::assistant($this->reply));
            }
        };
    }

    /** @return object{runs:int}&Tool */
    private static function probe(): Tool
    {
        return new class () implements Tool {
            public int $runs = 0;

            public function name(): string
            {
                return 'probe';
            }

            public function description(): string
            {
                return 'Probes.';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                $this->runs++;

                return new ToolResult(toolCallId: '', content: 'probed');
            }
        };
    }

    private static function toucher(string $marker): Tool
    {
        return new class ($marker) implements Tool {
            public function __construct(private readonly string $marker)
            {
            }

            public function name(): string
            {
                return 'touch';
            }

            public function description(): string
            {
                return 'Touches a file.';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                touch($this->marker);

                return new ToolResult(toolCallId: '', content: 'touched');
            }
        };
    }

    private function resolve(\Closure $cmd): mixed
    {
        $asyncCmd = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $asyncCmd);

        return $this->settle($asyncCmd->promise);
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
                $failure = new \RuntimeException('never settled');
                $loop->stop();
            });
            $loop->run();
            $loop->cancelTimer($watchdog);
        }
        if ($failure !== null) {
            $this->fail('the promise failed: ' . $failure->getMessage());
        }

        return $value;
    }
}
