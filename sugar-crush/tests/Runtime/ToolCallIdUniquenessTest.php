<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Step 0.2, end to end through the engine loop: a text-fallback parser that
 * numbers its calls from zero on every response (`dsml_call_0` twice in one
 * turn) no longer hands the model two different calls under one id. The id
 * the history carries, the id on the ToolStarted/ToolFinished events and the
 * id on the ToolResultMessage are the same rewritten id, and a real server
 * id is kept.
 */
final class ToolCallIdUniquenessTest extends TestCase
{
    /** @return array<string, array{bool}> */
    public static function paths(): array
    {
        return ['batch' => [false], 'streaming' => [true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('paths')]
    public function testEveryCallOfATurnCarriesOneUniqueIdEverywhere(bool $streaming): void
    {
        $provider = self::scripted($streaming, [
            [new ToolCall('dsml_call_0', 'Probe', ['n' => 1]), new ToolCall('dsml_call_1', 'Probe', ['n' => 2])],
            [new ToolCall('dsml_call_0', 'Probe', ['n' => 3]), new ToolCall('call_real_1', 'Probe', ['n' => 4])],
            [new ToolCall('call_real_1', 'Probe', ['n' => 5])],
        ]);

        $events = [];
        EngineBackend::new($provider, 'm')
            ->withTools([self::probe()])
            ->withoutHooks()
            ->complete([Message::user('go')], null, static function (object $event) use (&$events): void {
                $events[] = $event;
            });

        // The last request carries the whole turn's history.
        $history = $provider->requests[array_key_last($provider->requests)]->messages;
        $callIds = [];
        $resultIds = [];
        foreach ($history as $message) {
            if ($message instanceof AssistantMessage) {
                foreach ($message->toolCalls() ?? [] as $call) {
                    $callIds[] = $call->id();
                }
            } elseif ($message instanceof ToolResultMessage) {
                $resultIds[] = $message->toolCallId();
            }
        }

        $this->assertCount(5, $callIds);
        $this->assertSame($callIds, array_values(array_unique($callIds)), 'no two calls of one turn share an id');
        $this->assertSame($callIds, $resultIds, 'each result answers its own call, in order');
        $this->assertSame('call_real_1', $callIds[3], 'a first-seen real server id is kept');
        foreach ([0, 1, 2, 4] as $rewritten) {
            $this->assertMatchesRegularExpression('/^tc_[0-9a-f]{8}_\d+$/', $callIds[$rewritten]);
        }

        $started = array_map(static fn(ToolStarted $e): string => $e->toolCallId, array_values(array_filter($events, static fn(object $e): bool => $e instanceof ToolStarted)));
        $finished = array_map(static fn(ToolFinished $e): string => $e->toolCallId, array_values(array_filter($events, static fn(object $e): bool => $e instanceof ToolFinished)));
        $this->assertSame($callIds, $started);
        $this->assertSame($callIds, $finished);
    }

    private static function probe(): Tool
    {
        return new class implements Tool {
            public function name(): string { return 'Probe'; }
            public function description(): string { return 'answers its argument'; }
            public function inputSchema(): array { return ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']]]; }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: '', content: 'n=' . ($args['n'] ?? '?'));
            }
        };
    }

    /**
     * @param list<list<ToolCall>> $steps
     */
    private static function scripted(bool $streaming, array $steps): ProviderInterface
    {
        return new class ($streaming, $steps) implements ProviderInterface {
            /** @var list<CompleteRequest> */
            public array $requests = [];

            private int $index = 0;

            /** @param list<list<ToolCall>> $steps */
            public function __construct(private bool $streaming, private array $steps) {}

            public function name(): string { return 'test'; }
            public function supportsStreaming(): bool { return $this->streaming; }
            public function supportsFunctionCalling(): bool { return true; }
            public function supportsVision(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 100000; }
            public function costPer1kTokens(string $m, string $d): float { return 0.0; }

            public function complete(CompleteRequest $r): CompleteResponse
            {
                $this->requests[] = $r;
                $step = $this->steps[$this->index++] ?? [];

                return $step === []
                    ? new CompleteResponse(content: 'done')
                    : new CompleteResponse(content: 'working', toolCalls: $step);
            }

            public function completeStream(CompleteRequest $r): \Generator
            {
                $this->requests[] = $r;
                $step = $this->steps[$this->index++] ?? [];
                yield new CompleteResponse(content: $step === [] ? 'done' : 'working');
                if ($step !== []) {
                    yield new CompleteResponse(content: '', toolCalls: $step);
                }
            }

            public function embeddings(EmbeddingsRequest $r): EmbeddingsResponse { return new EmbeddingsResponse([]); }
        };
    }
}
