<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Providers\ReportsServedModel;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Audit 15b-35: an SGLang provider built on its default id adopts the model
 * the server reports serving, but the served name was discovered inside the
 * forked turn child and died with it, so the parent's labels kept naming the
 * static default. The child now carries the name home on its result frame
 * and the parent's provider — the one every clone of the backend shares —
 * learns it.
 */
final class EngineBackendServedModelTest extends TestCase
{
    use HomeSandboxTrait;

    private const SERVED = 'Qwen/Qwen3.8-Served-By-The-Child';

    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir() . '/sc_eb_served_' . bin2hex(random_bytes(4));
        $this->useHomeSandbox($this->home);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        @rmdir($this->home);
        parent::tearDown();
    }

    public function testTheForkedTurnsDiscoveryReachesTheParentsProvider(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('completeAsync() takes the blocking fallback without pcntl; there is no child to carry the name home');
        }

        $provider = self::discoversOnFirstCall();
        $backend = EngineBackend::new($provider, 'default-id');

        $this->assertNull($backend->servedModel(), 'the parent has not asked the server');

        $reply = $this->awaitForkedTurn($backend->completeAsync([Message::user('go')]));

        $this->assertSame('ok', $reply->content);
        $this->assertSame(self::SERVED, $backend->servedModel(), 'the child\'s discovery must reach the parent');
        $this->assertSame(
            self::SERVED,
            $backend->withMaxSteps(3)->servedModel(),
            'every clone shares the provider, so the hosted Chat\'s copy sees it too',
        );
    }

    public function testAResultFrameNamesTheServedModelToTheProvider(): void
    {
        $provider = self::discoversOnFirstCall();
        $this->settle(EngineBackend::new($provider, 'm'), ['kind' => 'result', 'ok' => true, 'content' => 'x', 'servedModel' => self::SERVED]);

        $this->assertSame(self::SERVED, $provider->servedModel());
    }

    public function testAFailedTurnStillReportsWhatItLearned(): void
    {
        $provider = self::discoversOnFirstCall();
        $rejected = $this->settle(EngineBackend::new($provider, 'm'), ['kind' => 'result', 'ok' => false, 'error' => 'boom', 'servedModel' => self::SERVED]);

        $this->assertInstanceOf(\RuntimeException::class, $rejected);
        $this->assertSame(self::SERVED, $provider->servedModel());
    }

    public function testAFrameWithoutTheKeyOrWithGarbageLearnsNothing(): void
    {
        $provider = self::discoversOnFirstCall();
        $backend = EngineBackend::new($provider, 'm');

        $this->settle($backend, ['kind' => 'result', 'ok' => true, 'content' => 'x']);
        $this->settle($backend, ['kind' => 'result', 'ok' => true, 'content' => 'x', 'servedModel' => ['not', 'a', 'name']]);

        $this->assertNull($provider->servedModel());
    }

    public function testAProviderThatCannotSayAnswersNull(): void
    {
        $this->assertNull(EngineBackend::new(new EchoProvider(), 'echo')->servedModel());
    }

    /**
     * @param array<string, mixed> $frame
     */
    private function settle(EngineBackend $backend, array $frame): mixed
    {
        $deferred = new Deferred();
        (new \ReflectionMethod($backend, 'settleFromResultFrame'))->invoke($backend, $frame, $deferred, null);

        $outcome = null;
        $deferred->promise()->then(
            static function (Message $m) use (&$outcome): void {
                $outcome = $m;
            },
            static function (\Throwable $e) use (&$outcome): void {
                $outcome = $e;
            },
        );

        return $outcome;
    }

    /**
     * Pump the shared loop until the forked turn settles, bounded by one
     * safety timer so a wedged child reds the test instead of hanging it.
     */
    private function awaitForkedTurn(PromiseInterface $promise): Message
    {
        $loop = Loop::get();
        $outcome = null;
        $settle = static function (mixed $v) use (&$outcome, $loop): void {
            $outcome = $v;
            $loop->stop();
        };
        $promise->then($settle, $settle);

        if ($outcome === null) {
            $guard = $loop->addTimer(30.0, static function () use (&$outcome, $loop): void {
                $outcome = new \RuntimeException('the forked turn never settled');
                $loop->stop();
            });
            $loop->run();
            $loop->cancelTimer($guard);
        }

        if ($outcome instanceof \Throwable) {
            $this->fail('forked turn failed: ' . $outcome->getMessage());
        }
        $this->assertInstanceOf(Message::class, $outcome);

        return $outcome;
    }

    /**
     * A provider that learns the served name only when it is CALLED — which,
     * through completeAsync(), happens in the forked child — and is told it
     * otherwise through noteServedModel().
     */
    private static function discoversOnFirstCall(): ProviderInterface&ReportsServedModel
    {
        return new class (self::SERVED) implements ProviderInterface, ReportsServedModel {
            private ?string $discovered = null;

            private ?string $noted = null;

            public function __construct(private readonly string $served) {}

            public function servedModel(): ?string
            {
                return $this->discovered ?? $this->noted;
            }

            public function noteServedModel(string $servedModel): void
            {
                if ($servedModel !== '') {
                    $this->noted = $servedModel;
                }
            }

            public function name(): string
            {
                return 'served-model-double';
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
                return 8192;
            }

            public function costPer1kTokens(string $model, string $direction): float
            {
                return 0.0;
            }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                $this->discovered = $this->served;

                return new CompleteResponse('ok');
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                yield from [];
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                throw new \LogicException('not used');
            }
        };
    }
}
