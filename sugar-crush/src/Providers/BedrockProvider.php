<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

use Aws\Bedrock\BedrockClient;
use Aws\Exception\AwsException;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;

final readonly class BedrockProvider implements ProviderInterface
{
    private const REGION_US = 'us-east-1';
    private const REGION_EU = 'eu-west-1';

    public function __construct(
        private BedrockClient $client,
        private string $region = self::REGION_US,
        private string $defaultModel = 'anthropic.claude-sonnet-4-6',
    ) {}

    public static function create(string $region = self::REGION_US, ?string $model = null): self
    {
        $client = new BedrockClient([
            'region' => $region,
            'version' => 'latest',
        ]);

        return new self($client, $region, $model ?? 'anthropic.claude-sonnet-4-6');
    }

    public function name(): string
    {
        return 'bedrock';
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    public function supportsFunctionCalling(): bool
    {
        return false; // Depends on model
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
        return match ($this->defaultModel) {
            'anthropic.claude-opus-4-6' => 200_000,
            'anthropic.claude-sonnet-4-6' => 200_000,
            'anthropic.claude-haiku-4-7' => 200_000,
            'meta.llama3-70b-instruct' => 8_192,
            'meta.llama3-8b-instruct' => 8_192,
            default => 8_192,
        };
    }

    public function costPer1kTokens(string $model, string $direction): float
    {
        // Pricing varies by model and region - these are approximations
        return match ($model) {
            'anthropic.claude-opus-4-6' => $direction === 'input' ? 0.015 : 0.075,
            'anthropic.claude-sonnet-4-6' => $direction === 'input' ? 0.003 : 0.015,
            'anthropic.claude-haiku-4-7' => $direction === 'input' ? 0.00025 : 0.00125,
            'meta.llama3-70b-instruct' => $direction === 'input' ? 0.00065 : 0.00275,
            'meta.llama3-8b-instruct' => $direction === 'input' ? 0.00022 : 0.00088,
            default => 0.01,
        };
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        $params = [
            'modelId' => $request->model,
            'messages' => $this->formatMessages($request->messages),
        ];

        if ($request->systemPrompt !== null) {
            $params['system'] = [['text' => $request->systemPrompt]];
        }

        if ($request->maxTokens !== null) {
            $params['inferenceConfig'] = [
                'maxTokens' => $request->maxTokens,
                'temperature' => $request->temperature ?? 0.7,
            ];
        }

        try {
            // Converse-shaped params (messages/system/inferenceConfig) require the
            // Converse API, not the legacy invokeModel body protocol.
            $result = $this->client->converse($params);
            $data = $result->toArray();

            return $this->parseResponse($data);
        } catch (AwsException $e) {
            throw new \RuntimeException('Bedrock completion failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Streams completion responses as a generator of deltas.
     *
     * Each yielded CompleteResponse contains only the delta/content from that chunk.
     * The caller is responsible for accumulating content across chunks.
     *
     * Note: tokensUsed and costUsd will be 0 for all chunks - usage data is only
     * available when the stream completes, not per-chunk.
     *
     * @return \Generator<int, CompleteResponse>
     */
    public function completeStream(CompleteRequest $request): \Generator
    {
        $params = [
            'modelId' => $request->model,
            'messages' => $this->formatMessages($request->messages),
            'inferenceConfig' => [
                'maxTokens' => $request->maxTokens ?? 4096,
                'temperature' => $request->temperature ?? 0.7,
            ],
        ];

        if ($request->systemPrompt !== null) {
            $params['system'] = [['text' => $request->systemPrompt]];
        }

        try {
            // ConverseStream emits an event stream of typed events; each text token
            // arrives as a contentBlockDelta event (not the legacy `completion` field).
            $result = $this->client->converseStream($params);
            $stream = $result->get('stream');

            foreach ($stream as $event) {
                yield $this->parseChunk($event);
            }
        } catch (AwsException $e) {
            throw new \RuntimeException('Bedrock streaming failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        // Use Titan or Cohere for embeddings via Bedrock
        return new EmbeddingsResponse(embeddings: []);
    }

    /**
     * @param array<Message> $messages
     * @return array<array{role: string, content: array<array{text: string}>}>
     */
    private function formatMessages(array $messages): array
    {
        return array_map(function (Message $msg) {
            $role = match (true) {
                $msg instanceof UserMessage => 'user',
                $msg instanceof AssistantMessage => 'assistant',
                $msg instanceof SystemMessage => 'user', // System wrapped as user
                $msg instanceof ToolResultMessage => 'user',
                default => 'user',
            };

            return [
                'role' => $role,
                'content' => [['text' => $msg->content()]],
            ];
        }, $messages);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function parseResponse(array $data): CompleteResponse
    {
        $output = $data['output']['message'] ?? [];
        $content = $output['content'] ?? [];

        $inputTokens = $data['usage']['inputTokens'] ?? 0;
        $outputTokens = $data['usage']['outputTokens'] ?? 0;

        $costUsd = ($inputTokens * $this->costPer1kTokens($this->defaultModel, 'input')
            + $outputTokens * $this->costPer1kTokens($this->defaultModel, 'output')) / 1000;

        return new CompleteResponse(
            content: $content[0]['text'] ?? '',
            reasoning: null,
            toolCalls: null,
            tokensUsed: $inputTokens + $outputTokens,
            costUsd: $costUsd,
        );
    }

    /**
     * Parses a streaming chunk into a partial/delta CompleteResponse.
     *
     * This returns only the delta content from this chunk - it does NOT contain
     * accumulated content. The caller must accumulate content across chunks.
     *
     * Note: tokensUsed and costUsd are always 0 for streaming responses because
     * usage data is only available from the final chunk, not per-chunk.
     *
     * @param array<string, mixed> $data
     */
    private function parseChunk(array $data): CompleteResponse
    {
        // ConverseStream text tokens arrive as contentBlockDelta events.
        $text = $data['contentBlockDelta']['delta']['text'] ?? '';

        return new CompleteResponse(
            content: $text,
            reasoning: null,
            toolCalls: null,
            tokensUsed: 0,
            costUsd: 0.0,
        );
    }
}
