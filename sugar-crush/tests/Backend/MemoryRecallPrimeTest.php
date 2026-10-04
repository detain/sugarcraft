<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Agents\MemoryScope;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Context\TurnContextBlock;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Roadmap 5.3-2 end to end: `EngineBackend::completeAsync()` ranks the turn's
 * memory recall in the PARENT before it forks — the embedding call happens
 * there, once, through the configured `embeddingModel` — and the forked
 * turn's persisted `<turn-context>` row carries the recalled note.
 */
final class MemoryRecallPrimeTest extends TestCase
{
    use HomeSandboxTrait;

    private string $dir;

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            self::markTestSkipped('the parent-side prime is the forked path; without pcntl the blocking fallback runs in-process');
        }

        $this->dir = sys_get_temp_dir() . '/recall_prime_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/memory', 0700, true);
        mkdir($this->dir . '/repo', 0700, true);
        $this->useHomeSandbox($this->dir . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    public function testAConfiguredEmbeddingModelRanksInTheParentAndTheRowCarriesTheNote(): void
    {
        Bootstrap::writeUserConfig(['embeddingModel' => 'embed-x']);
        $store = new MemoryStore($this->dir . '/memory');
        $id = $store->add('Production rollouts happen on Tuesdays.', MemoryScope::Project);
        $store->add('Colour theme is tokyonight.', MemoryScope::Project);
        $provider = self::provider();

        $reply = $this->turn($provider, $store, 'when do we ship?');

        $row = (string) TurnContextBlock::latestIn($reply->turnTranscript);
        self::assertStringContainsString('<memory-recall>', $row, 'only the vector leg can find this note: no shared word');
        self::assertStringContainsString($id, $row);
        self::assertNotEmpty($provider->embeddingCalls, 'the embedding request ran in the parent, where this object lives');
        self::assertSame('embed-x', $provider->embeddingCalls[0]['model']);

        // The second turn embeds only its query: the notes' vectors are cached.
        $provider->embeddingCalls = [];
        $this->turn($provider, $store, 'when is the rollout?');
        self::assertSame([['when is the rollout?']], array_column($provider->embeddingCalls, 'input'));
    }

    public function testWithoutAnEmbeddingModelTheRecallIsKeywordOnlyAndCallsNoEndpoint(): void
    {
        $store = new MemoryStore($this->dir . '/memory');
        $id = $store->add('Deploys go through the staging cluster.', MemoryScope::Project);
        $provider = self::provider();

        $reply = $this->turn($provider, $store, 'deploy to staging please');

        self::assertStringContainsString($id, (string) TurnContextBlock::latestIn($reply->turnTranscript));
        self::assertSame([], $provider->embeddingCalls);
    }

    public function testAFailingEmbeddingModelDegradesToKeywordRecall(): void
    {
        Bootstrap::writeUserConfig(['embeddingModel' => 'embed-x']);
        $store = new MemoryStore($this->dir . '/memory');
        $id = $store->add('Deploys go through the staging cluster.', MemoryScope::Project);
        $provider = self::provider(failEmbeddings: true);
        $log = $this->dir . '/notice.log';
        $previousLog = ini_set('error_log', $log);

        try {
            $reply = $this->turn($provider, $store, 'deploy to staging');
        } finally {
            ini_set('error_log', (string) $previousLog);
        }

        self::assertStringContainsString($id, (string) TurnContextBlock::latestIn($reply->turnTranscript));
        self::assertNotEmpty($provider->embeddingCalls, 'the configured model was tried');
        // Reported, not swallowed — once per process, so another test may
        // already have spent this exact reason; the log is then empty.
        if (is_file($log)) {
            self::assertStringContainsString('ranking memory notes by keyword only', (string) file_get_contents($log));
        }
    }

    // ── harness ─────────────────────────────────────────────────────────

    private function turn(ProviderInterface $provider, MemoryStore $store, string $prompt): Message
    {
        $backend = EngineBackend::new($provider, 'm')
            ->withMemoryStore($store)
            ->withRoot($this->dir . '/repo');

        $reply = $this->drainUntilSettled($backend->completeAsync([Message::user($prompt)]));
        self::assertInstanceOf(Message::class, $reply);

        return $reply;
    }

    private static function provider(bool $failEmbeddings = false): object
    {
        return new class ($failEmbeddings) implements ProviderInterface {
            /** @var list<array{model: string, input: list<string>}> */
            public array $embeddingCalls = [];

            public function __construct(private readonly bool $failEmbeddings)
            {
            }

            public function name(): string
            {
                return 'recall-stub';
            }

            public function supportsStreaming(): bool
            {
                return false;
            }

            public function supportsFunctionCalling(): bool
            {
                return true;
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

            public function costPer1kTokens(string $model, string $direction): ?float
            {
                return 0.0;
            }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                return new CompleteResponse(content: 'answered');
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                yield new CompleteResponse(content: '');
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                $input = \is_array($request->input) ? array_values($request->input) : [$request->input];
                $this->embeddingCalls[] = ['model' => $request->model, 'input' => $input];
                if ($this->failEmbeddings) {
                    throw new \RuntimeException('embedding endpoint down');
                }

                return new EmbeddingsResponse(array_map(
                    static fn (string $text): array => preg_match('/ship|rollout/i', $text) === 1 ? [1.0, 0.0] : [0.0, 1.0],
                    $input,
                ));
            }
        };
    }

    private function drainUntilSettled(PromiseInterface $promise): mixed
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
                $failure = new \RuntimeException('the forked completion never settled within the safety window');
                $loop->stop();
            });
            $loop->run();
            $loop->cancelTimer($watchdog);
        }

        if ($failure !== null) {
            $this->fail('forked turn failed: ' . $failure->getMessage());
        }

        return $value;
    }
}
