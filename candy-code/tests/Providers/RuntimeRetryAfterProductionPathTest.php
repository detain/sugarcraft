<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\EmbeddingsRequest;
use SugarCraft\Code\Providers\EmbeddingsResponse;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Providers\TransientFailure;
use SugarCraft\Code\Runtime;

/**
 * ISS-074 review fix: the Retry-After machinery is wired into PRODUCTION.
 *
 * The unit pins in {@see TransientFailureTest} proved `backoff()` honours a
 * server instruction once handed one; they could not prove any call site
 * ever hands one. Every site used to call the bare `backoff($attempt)`, so
 * `retryAfterSeconds()` was dead code end-to-end and the documented
 * "rate-limited, retrying in Ns" announcement had no producer. This file
 * drives the exception through the real loops — a fake provider throwing a
 * Guzzle `RequestException` carrying status 429 and a `Retry-After` header,
 * consumed through `Runtime::run()` — and measures that the wall actually
 * waits (a short 2-second instruction, slept for real) and that the notice
 * sink receives exactly the announcement sentence.
 *
 * The 20-second headline case is not slept here: the cap and the honouring
 * are already wall-pinned at 2s, and {@see TransientFailureTest} pins a
 * 7-second read plus the 25s ceiling on the unit side. What the third test
 * censuses instead is that no bare call survived anywhere in `src/` —
 * the shape the reviewer found, caught structurally so a future site
 * cannot silently reintroduce it.
 */
final class RuntimeRetryAfterProductionPathTest extends TestCase
{
    protected function setUp(): void
    {
        // Arm the in-process backend: an unarmed sink DROPS rows, and the
        // announcement this file pins travels through it (house pattern from
        // Diagnostics\RuntimeNoticeSinkTest).
        RuntimeNoticeSink::reset();
        RuntimeNoticeSink::arm(false);
    }

    protected function tearDown(): void
    {
        RuntimeNoticeSink::reset();
    }

    public function testABatchRetryWaitsForTheServerInstructionAndAnnouncesIt(): void
    {
        $provider = $this->rateLimitedThenAnswering(streaming: false);
        $runtime = new Runtime($provider, new HookManager(new HookRegistry()));

        $start = microtime(true);
        $messages = iterator_to_array($runtime->run(App::new($provider, 'gpt-4')), false);
        $wall = microtime(true) - $start;

        self::assertSame(2, $provider->calls, 'the 429 must be retried, not surfaced');
        self::assertGreaterThanOrEqual(1.9, $wall, 'a Retry-After: 2 waited materially less than two seconds — the server instruction was not honoured on the production path');
        self::assertLessThan(10.0, $wall, 'the wait ran far past the instruction');
        self::assertNotEmpty($messages, 'the retried turn must still settle an answer');
        self::assertSame(
            [sprintf(TransientFailure::RATE_LIMIT_NOTICE_FORMAT, 2, 's')],
            RuntimeNoticeSink::drain(),
            'the honoured wait must announce the same number it slept',
        );
    }

    public function testAStreamingRetryWaitsForTheServerInstructionAndAnnouncesIt(): void
    {
        $provider = $this->rateLimitedThenAnswering(streaming: true);
        $runtime = new Runtime($provider, new HookManager(new HookRegistry()));

        $start = microtime(true);
        iterator_to_array($runtime->run(App::new($provider, 'gpt-4')), false);
        $wall = microtime(true) - $start;

        self::assertSame(2, $provider->calls, 'the streamed 429 must be retried, not surfaced');
        self::assertGreaterThanOrEqual(1.9, $wall, 'a streamed Retry-After: 2 was not honoured on the production path');
        self::assertLessThan(10.0, $wall, 'the streamed wait ran far past the instruction');
        self::assertSame(
            [sprintf(TransientFailure::RATE_LIMIT_NOTICE_FORMAT, 2, 's')],
            RuntimeNoticeSink::drain(),
            'the streamed honoured wait must announce the same number it slept',
        );
    }

    /**
     * Structural keystone (the mutation arm): no `src/` retry site may
     * regress to the bare ladder call. The throwable paths pass `failure:`,
     * every path passes a beat where one exists; the exact bare spelling
     * `backoff($attempt);` is what unwired production looked like.
     */
    public function testNoProductionRetrySiteCallsTheBareLadderAnymore(): void
    {
        $sites = [
            \dirname(__DIR__, 2) . '/src/Runtime.php' => ['bare' => 0, 'failure' => 2, 'beat' => 4],
            \dirname(__DIR__, 2) . '/src/Agents/AgentManager.php' => ['bare' => 0, 'failure' => 2, 'beat' => 3],
        ];

        foreach ($sites as $file => $shape) {
            $src = (string) file_get_contents($file);

            self::assertSame(
                $shape['bare'],
                substr_count($src, 'TransientFailure::backoff($attempt);'),
                basename($file) . ' still holds a bare ladder call - Retry-After is dead there again',
            );
            self::assertSame(
                $shape['failure'],
                substr_count($src, 'backoff($attempt, failure:'),
                basename($file) . ' no longer threads the throwable the number of times the loops promise',
            );
            self::assertGreaterThanOrEqual(
                $shape['beat'],
                substr_count($src, 'onBeat:'),
                basename($file) . ' lost beat threading - waits went dark',
            );
        }
    }

    public function testAnnouncingNothingForALadderWaitKeepsTheInboxClean(): void
    {
        // No header, no instruction, no notice: announceRateLimit speaks ONLY
        // when the server actually set the clock backoff is about to obey.
        TransientFailure::announceRateLimit(new RequestException('plain 503', new Request('POST', 'https://example.invalid'), new Response(503)));

        self::assertSame([], RuntimeNoticeSink::drain());
    }

    private function rateLimitedThenAnswering(bool $streaming): object
    {
        return new class ($streaming) implements ProviderInterface {
            public int $calls = 0;

            public function __construct(private bool $streaming)
            {
            }

            public function name(): string
            {
                return 'stub-rate-limited';
            }

            public function supportsStreaming(): bool
            {
                return $this->streaming;
            }

            public function supportsFunctionCalling(): bool
            {
                return false;
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
                return 1000;
            }

            public function costPer1kTokens(string $model, string $direction): float
            {
                return 0.0;
            }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                $this->failFirstCall();

                return new CompleteResponse(content: 'answered');
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                $this->failFirstCall();

                yield new CompleteResponse(content: 'streamed');
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                return new EmbeddingsResponse([]);
            }

            /**
             * First call throws the throttled failure with its instruction;
             * every later call answers. The exception is the real wire shape
             * (Guzzle attaches the PSR-7 response the chain walk reads).
             */
            private function failFirstCall(): void
            {
                if (++$this->calls > 1) {
                    return;
                }

                throw new RequestException(
                    'Too Many Requests',
                    new Request('POST', 'https://example.invalid/v1/chat/completions'),
                    new Response(429, ['Retry-After' => '2']),
                );
            }
        };
    }
}
