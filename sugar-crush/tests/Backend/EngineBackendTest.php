<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * @see EngineBackend
 */
final class EngineBackendTest extends TestCase
{
    public function testIsABackend(): void
    {
        $this->assertInstanceOf(Backend::class, EngineBackend::new(new EchoProvider(), 'echo'));
    }

    public function testCompleteEchoesThroughTheEngine(): void
    {
        $backend = EngineBackend::new(new EchoProvider(), 'echo');

        $reply = $backend->complete([Message::user('hello world')]);

        $this->assertInstanceOf(Message::class, $reply);
        $this->assertStringContainsString('> hello world', $reply->content);
    }

    public function testCompleteAsyncResolvesToReply(): void
    {
        $backend = EngineBackend::new(new EchoProvider(), 'echo');

        $promise = $backend->completeAsync([Message::user('ping')]);
        $this->assertInstanceOf(PromiseInterface::class, $promise);

        // completeAsync() forks the real work off-process (see its docblock)
        // and only settles once the parent's ReactPHP loop observes the
        // child's result over a socket - genuinely async, so the promise is
        // NOT resolved synchronously right after then() the way every other
        // Backend's fake-async completeAsync() is. Pump the loop until it
        // settles instead of asserting immediately.
        $resolved = $this->awaitPromise($promise);

        $this->assertInstanceOf(Message::class, $resolved);
        $this->assertStringContainsString('> ping', $resolved->content);
    }

    public function testCompleteAsyncDoesNotBlockTheEventLoop(): void
    {
        // The whole point of the fork-based rewrite: while a completion is
        // in flight, the caller's event loop must keep ticking (spinner
        // animation, keystroke handling) instead of freezing until the
        // provider call returns. Prove it by counting periodic-timer fires
        // that happen concurrently with an in-flight completeAsync() call.
        $provider = new class implements ProviderInterface {
            public function name(): string { return 'slow'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return false; }
            public function supportsVision(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 1000; }
            public function costPer1kTokens(string $m, string $d): float { return 0.0; }
            public function complete(CompleteRequest $r): CompleteResponse
            {
                usleep(150_000);

                return new CompleteResponse(content: 'slow reply');
            }
            public function completeStream(CompleteRequest $r): \Generator { yield new CompleteResponse(content: ''); }
            public function embeddings(EmbeddingsRequest $r): EmbeddingsResponse { return new EmbeddingsResponse([]); }
        };

        $backend = EngineBackend::new($provider, 'slow');
        $loop = \React\EventLoop\Loop::get();

        $ticks = 0;
        $timer = $loop->addPeriodicTimer(0.02, static function () use (&$ticks): void {
            $ticks++;
        });

        $resolved = $this->awaitPromise($backend->completeAsync([Message::user('go')]));

        $loop->cancelTimer($timer);

        $this->assertInstanceOf(Message::class, $resolved);
        $this->assertSame('slow reply', $resolved->content);
        $this->assertGreaterThan(
            0,
            $ticks,
            'periodic timer never fired while completeAsync() was in flight - the event loop was blocked',
        );
    }

    /**
     * Pump the real ReactPHP loop until $promise settles, returning its
     * resolved value (or rethrowing its rejection). Bounded so a genuine
     * regression (the promise never settling) fails the test instead of
     * hanging the suite forever.
     */
    private function awaitPromise(PromiseInterface $promise): mixed
    {
        // A single run()/stop() pair, exactly like Program::run()'s own
        // one-shot $this->loop->run() - NOT a repeated add-short-timer-then-
        // run() polling dance. That looks equivalent but isn't: re-entering
        // run() over and over via a freshly re-added timer raced against a
        // forked child's real (curl/HTTPS) I/O and could miss the socket's
        // readability edge between iterations, hanging the test. A single
        // long-lived run(), stopped only once by whichever settles first
        // (the promise's own callback or the safety timer), matches how the
        // real Program drives this and is what completeAsync() is verified
        // against.
        $loop = \React\EventLoop\Loop::get();
        $settled = false;
        $value = null;
        $error = null;

        $promise->then(
            function ($v) use (&$settled, &$value, $loop): void { $settled = true; $value = $v; $loop->stop(); },
            function (\Throwable $e) use (&$settled, &$error, $loop): void { $settled = true; $error = $e; $loop->stop(); },
        );

        $safety = $loop->addTimer(10.0, static function () use ($loop): void { $loop->stop(); });
        $loop->run();
        $loop->cancelTimer($safety);

        if (!$settled) {
            $this->fail('Promise did not settle within the test timeout');
        }

        if ($error !== null) {
            throw $error;
        }

        return $value;
    }

    public function testAgenticLoopExecutesToolThenAnswers(): void
    {
        $provider = $this->toolThenAnswerProvider();
        $backend = EngineBackend::new($provider, 'tc')->withTools([$this->clockTool()]);

        $reply = $backend->complete([Message::user('what time is it?')]);

        // Two provider round-trips: one that requested the tool, one that
        // answered after seeing the tool result.
        $this->assertSame(2, $provider->calls);
        $this->assertStringContainsString('NOON', $reply->content);
    }

    public function testMaxStepsGuardsAgainstRunawayToolLoops(): void
    {
        // A provider that calls a tool forever — the loop must stop at the cap.
        $provider = new class implements ProviderInterface {
            public int $calls = 0;
            public function name(): string { return 'loop'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return true; }
            public function supportsVision(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 1000; }
            public function costPer1kTokens(string $m, string $d): float { return 0.0; }
            public function complete(CompleteRequest $r): CompleteResponse
            {
                $this->calls++;
                return new CompleteResponse(content: "step {$this->calls}", toolCalls: [new ToolCall('c', 'noop', [])]);
            }
            public function completeStream(CompleteRequest $r): \Generator { yield new CompleteResponse(content: ''); }
            public function embeddings(EmbeddingsRequest $r): EmbeddingsResponse { return new EmbeddingsResponse([]); }
        };
        $noop = $this->namedTool('noop', 'done');

        $backend = EngineBackend::new($provider, 'loop')->withTools([$noop])->withMaxSteps(3);

        $reply = $backend->complete([Message::user('go')]);

        $this->assertSame(3, $provider->calls, 'loop must stop at maxSteps');
        $this->assertStringContainsString('step 3', $reply->content);
    }

    public function testWithersReturnNewInstances(): void
    {
        $base = EngineBackend::new(new EchoProvider(), 'echo');

        $this->assertNotSame($base, $base->withTools([$this->clockTool()]));
        $this->assertNotSame($base, $base->withMaxSteps(2));
        $this->assertNotSame($base, $base->withoutHooks());
    }

    /**
     * Safe-by-default: a backend constructed WITHOUT a withHooks() call must
     * still register the built-in hooks, so a dangerous Bash tool call is
     * denied before the tool ever executes.
     */
    public function testDefaultBackendDeniesDangerousBashToolCall(): void
    {
        $spy = $this->bashSpyTool();
        $backend = EngineBackend::new($this->bashThenAnswerProvider(), 'm')
            ->withTools([$spy]);

        $backend->complete([Message::user('clean up')]);

        $this->assertFalse($spy->executed, 'rm -rf must be denied by the safe-by-default hooks');
    }

    /**
     * The withoutHooks() escape hatch removes the safe-by-default guard, so the
     * same dangerous Bash tool call now reaches the tool.
     */
    public function testWithoutHooksRunsToolUnguarded(): void
    {
        $spy = $this->bashSpyTool();
        $backend = EngineBackend::new($this->bashThenAnswerProvider(), 'm')
            ->withTools([$spy])
            ->withoutHooks();

        $backend->complete([Message::user('clean up')]);

        $this->assertTrue($spy->executed, 'withoutHooks() must leave the tool unguarded');
    }

    // --- helpers -----------------------------------------------------------

    /**
     * A provider that requests a dangerous Bash tool call on the first turn,
     * then answers plainly once it sees the (denied) tool result.
     */
    private function bashThenAnswerProvider(): ProviderInterface
    {
        return new class implements ProviderInterface {
            public int $calls = 0;
            public function name(): string { return 'bash-tc'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return true; }
            public function supportsVision(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 1000; }
            public function costPer1kTokens(string $m, string $d): float { return 0.0; }
            public function complete(CompleteRequest $r): CompleteResponse
            {
                $this->calls++;
                return $this->calls === 1
                    ? new CompleteResponse(content: 'cleaning', toolCalls: [new ToolCall('c1', 'Bash', ['command' => 'rm -rf ./build'])])
                    : new CompleteResponse(content: 'done');
            }
            public function completeStream(CompleteRequest $r): \Generator { yield new CompleteResponse(content: ''); }
            public function embeddings(EmbeddingsRequest $r): EmbeddingsResponse { return new EmbeddingsResponse([]); }
        };
    }

    /**
     * A tool named "Bash" that records whether it executed but never runs a
     * real shell — so the assertion is purely about the hook gate.
     */
    private function bashSpyTool(): Tool
    {
        return new class implements Tool {
            public bool $executed = false;
            public function name(): string { return 'Bash'; }
            public function description(): string { return 'spy bash'; }
            public function inputSchema(): array { return []; }
            public function execute(array $args): ToolResult
            {
                $this->executed = true;
                return new ToolResult(toolCallId: '', content: 'ran');
            }
        };
    }


    private function toolThenAnswerProvider(): ProviderInterface
    {
        return new class implements ProviderInterface {
            public int $calls = 0;
            public function name(): string { return 'tc'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return true; }
            public function supportsVision(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 1000; }
            public function costPer1kTokens(string $m, string $d): float { return 0.0; }
            public function complete(CompleteRequest $r): CompleteResponse
            {
                $this->calls++;
                return $this->calls === 1
                    ? new CompleteResponse(content: 'checking', toolCalls: [new ToolCall('c1', 'clock', [])])
                    : new CompleteResponse(content: 'The time is NOON.');
            }
            public function completeStream(CompleteRequest $r): \Generator { yield new CompleteResponse(content: ''); }
            public function embeddings(EmbeddingsRequest $r): EmbeddingsResponse { return new EmbeddingsResponse([]); }
        };
    }

    private function clockTool(): Tool
    {
        return $this->namedTool('clock', 'NOON');
    }

    private function namedTool(string $name, string $result): Tool
    {
        return new class($name, $result) implements Tool {
            public function __construct(private string $toolName, private string $result) {}
            public function name(): string { return $this->toolName; }
            public function description(): string { return 'test tool'; }
            public function inputSchema(): array { return []; }
            public function execute(array $args): ToolResult { return new ToolResult(toolCallId: '', content: $this->result); }
        };
    }
}
