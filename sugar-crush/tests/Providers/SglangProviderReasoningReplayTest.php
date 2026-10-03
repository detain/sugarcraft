<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Step 0.1: an assistant tool-call row replays its `reasoning_content` on
 * the wire for the families whose chat templates read it back (DeepSeek-V4,
 * Qwen3.8), and nowhere else.
 *
 * Runtime already folds the step's reasoning into the AssistantMessage it
 * appends to history; before this step the provider's wire mapping dropped
 * it, so the next step of the same turn re-rendered that row without its
 * thinking - worse reasoning, and a broken RadixAttention prefix at that row.
 * Every assertion is on the REQUEST body the provider emits.
 */
final class SglangProviderReasoningReplayTest extends TestCase
{
    private const OK_BODY = '{"choices":[{"message":{"content":"ok"}}],"usage":{"total_tokens":1}}';

    /** @var list<array<string, mixed>> */
    private array $history = [];

    private function provider(string $model): SglangProvider
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], self::OK_BODY)]));
        $stack->push(Middleware::history($this->history));

        return new SglangProvider(
            'https://api.example.com',
            $model,
            null,
            new Client(['base_uri' => 'https://api.example.com/', 'handler' => $stack]),
        );
    }

    /**
     * Sends a mid-turn history (user, assistant tool-call step with
     * reasoning, tool result) and returns the assistant wire row.
     *
     * @return array<string, mixed>
     */
    private function sentAssistantRow(string $model, AssistantMessage $assistant): array
    {
        $this->provider($model)->complete(new CompleteRequest(
            model: $model,
            messages: [
                new UserMessage('What is the weather in Tokyo?'),
                $assistant,
                new ToolResultMessage('call_1', 'sunny'),
            ],
        ));

        $sent = json_decode((string) $this->history[0]['request']->getBody(), true);
        $rows = array_values(array_filter(
            $sent['messages'],
            static fn(array $row): bool => $row['role'] === 'assistant',
        ));
        $this->assertCount(1, $rows);

        return $rows[0];
    }

    private static function toolStep(?string $reasoning): AssistantMessage
    {
        return new AssistantMessage(
            'Checking.',
            [new ToolCall('call_1', 'get_weather', ['city' => 'Tokyo'])],
            $reasoning,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function replayingFamilies(): iterable
    {
        yield 'deepseek-v4' => ['deepseek-ai/DeepSeek-V4-Flash-0731'];
        yield 'qwen3.8' => ['Qwen/Qwen3.8-Flash-Next'];
    }

    #[DataProvider('replayingFamilies')]
    public function testToolCallRowReplaysReasoningForReplayingFamilies(string $model): void
    {
        $row = $this->sentAssistantRow($model, self::toolStep('Need the weather tool for Tokyo.'));

        $this->assertSame('Need the weather tool for Tokyo.', $row['reasoning_content'] ?? null);
        $this->assertSame('Checking.', $row['content']);
        $this->assertSame('call_1', $row['tool_calls'][0]['id']);
    }

    #[DataProvider('replayingFamilies')]
    public function testFinalAnswerRowWithoutToolCallsCarriesNoReasoning(string $model): void
    {
        $row = $this->sentAssistantRow($model, new AssistantMessage('It is sunny.', null, 'I already know.'));

        $this->assertArrayNotHasKey('reasoning_content', $row);
    }

    #[DataProvider('replayingFamilies')]
    public function testEmptyOrMissingReasoningAddsNoKey(string $model): void
    {
        $this->assertArrayNotHasKey('reasoning_content', $this->sentAssistantRow($model, self::toolStep(null)));
        $this->assertArrayNotHasKey('reasoning_content', $this->sentAssistantRow($model, self::toolStep('')));
    }

    public function testOtherFamiliesKeepThePreReplayWireByteForByte(): void
    {
        $row = $this->sentAssistantRow('MiniMax-M2.7', self::toolStep('Need the weather tool for Tokyo.'));

        $this->assertSame(['role', 'content', 'tool_calls'], array_keys($row));
    }
}
