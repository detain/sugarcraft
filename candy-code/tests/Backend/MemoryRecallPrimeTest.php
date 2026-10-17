<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Agents\MemoryScope;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Context\TurnContextBlock;
use SugarCraft\Code\Memory\MemoryStore;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\EmbeddingsRequest;
use SugarCraft\Code\Providers\EmbeddingsResponse;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * Roadmap 5.3-2 end to end after ISS-072: the turn's memory recall is ranked
 * in the forked child — or the blocking fallback, or a direct call — through
 * the configured `embeddingModel`, never in the parent of a fork, and the
 * turn's persisted `<turn-context>` row carries the recalled note. A
 * sessionless backend (the dream pass) primes nothing at all: no embedding
 * HTTP on background turns.
 */
final class MemoryRecallPrimeTest extends TestCase
{
    use HomeSandboxTrait;

    private string $dir;

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            self::markTestSkipped('the forked child is the priming path this pins; without pcntl only the fallback would run');
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

    public function testAConfiguredEmbeddingModelRanksInTheChildAndTheRowCarriesTheNote(): void
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
        // The embed ran in the child: the parent's copy of the provider saw
        // nothing (ISS-072), which is the whole point of the move.
        self::assertSame([], $provider->embeddingCalls, 'the parent no longer makes the request');
    }

    public function testASecondRankedTurnStillCarriesTheNoteViaTheCachedVectors(): void
    {
        Bootstrap::writeUserConfig(['embeddingModel' => 'embed-x']);
        $store = new MemoryStore($this->dir . '/memory');
        $id = $store->add('Production rollouts happen on Tuesdays.', MemoryScope::Project);
        $provider = self::provider();

        $this->turn($provider, $store, 'when do we ship?');
        $reply = $this->turn($provider, $store, 'when is the rollout?');

        self::assertStringContainsString($id, (string) TurnContextBlock::latestIn($reply->turnTranscript));
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

        // The attempt, the degradation and the report all happen in the child
        // now (ISS-072): the row still carries the note keyword-ranked, and
        // the fork inherits error_log, so the warn lands in the same file —
        // every fresh child re-arms the once-per-process latch.
        self::assertStringContainsString($id, (string) TurnContextBlock::latestIn($reply->turnTranscript));
        self::assertSame([], $provider->embeddingCalls, 'the parent made no request itself');
        self::assertFileExists($log);
        self::assertStringContainsString('ranking memory notes by keyword only', (string) file_get_contents($log));
    }

    public function testASessionlessBackendPrimesNoEmbeddingsAtAll(): void
    {
        Bootstrap::writeUserConfig(['embeddingModel' => 'embed-x']);
        $store = new MemoryStore($this->dir . '/memory');
        $store->add('Production rollouts happen on Tuesdays.', MemoryScope::Project);
        $provider = self::provider();

        $backend = EngineBackend::new($provider, 'm')->withMemoryStore($store)->withRoot($this->dir . '/repo');
        (new \ReflectionMethod($backend, 'primeMemoryRecallForTurn'))->invoke($backend, [Message::user('when do we ship?')]);

        self::assertSame([], $provider->embeddingCalls, 'no session id means no per-turn embedding HTTP (ISS-072)');
    }

    public function testTheParentOfAForkNeverPrimesTheRanking(): void
    {
        $source = (string) file_get_contents((new \ReflectionClass(EngineBackend::class))->getFileName());
        $lines = explode("\n", $source);
        $body = static function (string $method) use ($lines): string {
            $reflected = new \ReflectionMethod(EngineBackend::class, $method);

            return implode("\n", \array_slice($lines, $reflected->getStartLine() - 1, $reflected->getEndLine() - $reflected->getStartLine() + 1));
        };

        self::assertStringNotContainsString('->primeMemoryRecall', $body('completeAsync'), 'ISS-072: the parent must not make the request');
        self::assertStringContainsString('primeMemoryRecallForTurn($history);', $body('runCompleteInChild'));
        self::assertStringContainsString('primeMemoryRecallForTurn($history);', $body('completeAsyncBlocking'));
        self::assertStringContainsString('$this->sessionId === null', $body('primeMemoryRecallForTurn'), 'the sessionless guard is load-bearing');
    }

    // ── harness ─────────────────────────────────────────────────────────

    private function turn(ProviderInterface $provider, MemoryStore $store, string $prompt): Message
    {
        $backend = EngineBackend::new($provider, 'm')
            ->withMemoryStore($store)
            ->withRoot($this->dir . '/repo')
            ->withSessionId('recall-session');

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
