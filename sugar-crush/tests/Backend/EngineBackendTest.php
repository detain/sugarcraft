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

        $resolved = null;
        $promise->then(static function (Message $m) use (&$resolved): void {
            $resolved = $m;
        });

        $this->assertInstanceOf(Message::class, $resolved);
        $this->assertStringContainsString('> ping', $resolved->content);
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
    }

    // --- helpers -----------------------------------------------------------

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
