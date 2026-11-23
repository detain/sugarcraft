<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\EmbeddingsRequest;
use SugarCraft\Code\Providers\EmbeddingsResponse;
use SugarCraft\Code\Providers\HttpSettingsSnapshot;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Providers\SglangProvider;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolCall;
use SugarCraft\Code\Tools\ToolResult;

/**
 * AUD §7 rank 51: the settings `HttpClientDefaults` reads are snapshotted once
 * per turn, and every HTTP read of the turn answers from that snapshot.
 *
 * Outside a turn the read stays live (a client built at launch or on a
 * provider switch sees the settings as they are now); inside one, a save made
 * mid-turn applies from the next turn, which is what the stream keys'
 * documented `next turn` apply mode says.
 */
final class HttpSettingsSnapshotTest extends TestCase
{
    use HomeSandboxTrait;

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/sc_http_snapshot_' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->dir . '/home');
        HttpSettingsSnapshot::resetForTesting();
    }

    protected function tearDown(): void
    {
        HttpSettingsSnapshot::resetForTesting();
        $this->restoreHomeSandbox();
        self::removeTree($this->dir);
        parent::tearDown();
    }

    public function testOutsideATurnTheSettingsAreReadLive(): void
    {
        Bootstrap::writeUserConfig(['streamIdleTimeoutSeconds' => 900]);
        self::assertFalse(HttpSettingsSnapshot::active());
        self::assertSame(900, HttpSettingsSnapshot::config()['streamIdleTimeoutSeconds'] ?? null);

        Bootstrap::writeUserConfig(['streamIdleTimeoutSeconds' => 600]);
        self::assertSame(600, HttpSettingsSnapshot::config()['streamIdleTimeoutSeconds'] ?? null);
    }

    public function testInsideATurnEveryStreamingRequestReadsTheTurnsSnapshot(): void
    {
        $client = self::clientOf(SglangProvider::openAiCompatible('https://sglang.invalid/v1'));
        $streaming = ['stream' => true, 'candy_connect_preflight' => false];

        Bootstrap::writeUserConfig(['streamIdleTimeoutSeconds' => 900, 'streamHeaderTimeoutSeconds' => 60]);
        $token = HttpSettingsSnapshot::beginTurn(Bootstrap::readUserConfig());

        // Saved mid-turn: the turn keeps what it started with.
        Bootstrap::writeUserConfig(['streamIdleTimeoutSeconds' => 600, 'streamHeaderTimeoutSeconds' => 30]);
        $first = self::captureHandlerOptions($client, $streaming);
        $second = self::captureHandlerOptions($client, $streaming);
        HttpSettingsSnapshot::endTurn($token);

        self::assertSame([900.0, 60.0], [$first['read_timeout'], $first['timeout']]);
        self::assertSame([900.0, 60.0], [$second['read_timeout'], $second['timeout']]);

        // The next request outside the turn sees the save.
        $after = self::captureHandlerOptions($client, $streaming);
        self::assertSame([600.0, 30.0], [$after['read_timeout'], $after['timeout']]);
    }

    public function testTurnsThatEndOutOfOrderLeaveNoSnapshotBehind(): void
    {
        $outer = HttpSettingsSnapshot::beginTurn(['k' => 'outer']);
        $inner = HttpSettingsSnapshot::beginTurn(['k' => 'inner']);
        self::assertSame('inner', HttpSettingsSnapshot::config()['k'] ?? null, 'the newest turn answers');

        HttpSettingsSnapshot::endTurn($outer);
        self::assertSame('inner', HttpSettingsSnapshot::config()['k'] ?? null);
        HttpSettingsSnapshot::endTurn($inner);

        self::assertFalse(HttpSettingsSnapshot::active(), 'no snapshot outlives its turn');
        self::assertSame(2, HttpSettingsSnapshot::taken());
    }

    public function testAnEngineTurnThatThrowsLeavesNoSnapshotBehind(): void
    {
        $provider = $this->scriptedProvider([], static function (): void {
            throw new \RuntimeException('provider down');
        });

        try {
            (new EngineBackend($provider, 'scripted'))->withoutHooks()->complete([Message::user('hi')]);
        } catch (\Throwable) {
            // Whether the failure is thrown or folded into the reply, the
            // snapshot must be gone once the turn is.
        }

        self::assertSame(1, HttpSettingsSnapshot::taken(), 'fixture: the turn did start');
        self::assertFalse(HttpSettingsSnapshot::active());
    }

    public function testAMultiStepEngineTurnTakesOneSnapshotAndReadsItForEveryStep(): void
    {
        Bootstrap::writeUserConfig(['streamIdleTimeoutSeconds' => 900]);

        /** @var list<array{active: bool, taken: int, idle: mixed}> $seen */
        $seen = [];
        $provider = $this->scriptedProvider(
            [
                new CompleteResponse(content: 'checking', toolCalls: [new ToolCall('call_1', 'clock', [])]),
                new CompleteResponse(content: 'it is noon'),
            ],
            static function () use (&$seen): void {
                $seen[] = [
                    'active' => HttpSettingsSnapshot::active(),
                    'taken' => HttpSettingsSnapshot::taken(),
                    'idle' => HttpSettingsSnapshot::config()['streamIdleTimeoutSeconds'] ?? null,
                ];
                // A save between the turn's steps must not reach its next step.
                Bootstrap::writeUserConfig(['streamIdleTimeoutSeconds' => 600]);
            },
        );

        $reply = (new EngineBackend($provider, 'scripted'))
            ->withoutHooks()
            ->withTools([$this->clockTool()])
            ->complete([Message::user('what time is it?')]);

        self::assertSame('it is noon', $reply->content, 'fixture: the loop ran two steps');
        self::assertCount(2, $seen);
        self::assertSame([true, true], array_column($seen, 'active'));
        self::assertSame([1, 1], array_column($seen, 'taken'), 'one snapshot for the whole turn, not one per step');
        self::assertSame([900, 900], array_column($seen, 'idle'), 'both steps read the turn-start value');
        self::assertFalse(HttpSettingsSnapshot::active(), 'the snapshot ends with the turn');
        self::assertSame(600, HttpSettingsSnapshot::config()['streamIdleTimeoutSeconds'] ?? null);
    }

    /**
     * @param list<CompleteResponse> $responses
     * @param \Closure(): void $onCall
     */
    private function scriptedProvider(array $responses, \Closure $onCall): ProviderInterface
    {
        return new class ($responses, $onCall) implements ProviderInterface {
            /** @param list<CompleteResponse> $responses */
            public function __construct(
                private array $responses,
                private readonly \Closure $onCall,
            ) {}

            public function name(): string
            {
                return 'snapshot-scripted';
            }

            public function supportsStreaming(): bool
            {
                return false;
            }

            public function supportsFunctionCalling(): bool
            {
                return true;
            }

            public function supportsDocuments(): bool
            {
                return false;
            }

            public function supportsVision(): bool
            {
                return false;
            }

            public function supportsJsonSchema(): bool
            {
                return false;
            }

            public function contextWindow(): int
            {
                return 100000;
            }

            public function costPer1kTokens(string $model, string $direction): float
            {
                return 0.0;
            }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                ($this->onCall)();

                return array_shift($this->responses) ?? new CompleteResponse(content: 'done');
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                yield $this->complete($request);
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                return new EmbeddingsResponse(embeddings: []);
            }
        };
    }

    private function clockTool(): Tool
    {
        return new class implements Tool {
            public function name(): string
            {
                return 'clock';
            }

            public function description(): string
            {
                return 'test tool';
            }

            public function inputSchema(): array
            {
                return [];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: 'call_1', content: 'noon');
            }
        };
    }

    /**
     * @param array<string, mixed> $requestOptions
     * @return array<string, mixed>
     */
    private static function captureHandlerOptions(Client $client, array $requestOptions): array
    {
        $stack = $client->getConfig('handler');
        self::assertInstanceOf(HandlerStack::class, $stack);

        $captured = [];
        $stack->setHandler(static function (RequestInterface $request, array $options) use (&$captured): PromiseInterface {
            $captured = $options;

            return new FulfilledPromise(new Response(200, [], 'ok'));
        });

        $client->request('POST', 'chat/completions', $requestOptions + ['json' => ['probe' => true]]);

        return $captured;
    }

    private static function clientOf(object $provider): Client
    {
        $client = (new \ReflectionProperty($provider, 'httpClient'))->getValue($provider);
        \assert($client instanceof Client);

        return $client;
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
