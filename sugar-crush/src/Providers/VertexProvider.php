<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

use Google\Cloud\AIPlatform\V1\PredictionServiceClient;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;

final readonly class VertexProvider implements ProviderInterface
{
    public function __construct(
        private string $projectId,
        private string $location,
        private string $defaultModel,
        private PredictionServiceClient $client,
    ) {}

    public static function create(
        string $projectId,
        string $location = 'us-central1',
        string $model = 'claude-3-sonnet@20240229',
    ): self {
        $client = new PredictionServiceClient([
            'projectId' => $projectId,
        ]);

        return new self($projectId, $location, $model, $client);
    }

    public function name(): string
    {
        return 'vertex';
    }

    public function supportsStreaming(): bool
    {
        return false;
    }

    public function supportsFunctionCalling(): bool
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
        return 200_000;
    }

    public function costPer1kTokens(string $model, string $direction): float
    {
        // Vertex pricing varies by model and region - return 0 as placeholder
        return 0.0;
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        $params = [
            'endpoint' => "projects/{$this->projectId}/locations/{$this->location}/publishers/anthropic/models/{$request->model}",
            'instances' => [
                [
                    'messages' => $this->formatMessages($request->messages),
                    'temperature' => $request->temperature ?? 0.7,
                    'max_tokens' => $request->maxTokens ?? 4096,
                ],
            ],
        ];

        try {
            $response = $this->client->predict($params);
            $predictions = $response->getPredictions();
            $data = $predictions[0]->getStruct() ?? [];

            return $this->parseResponse($data);
        } catch (\Exception $e) {
            return new CompleteResponse(
                content: '',
                isError: true,
                errorMessage: $e->getMessage(),
            );
        }
    }

    /**
     * @return \Generator<int, CompleteResponse>
     */
    public function completeStream(CompleteRequest $request): \Generator
    {
        // Vertex streaming implementation placeholder
        // Streaming not yet fully implemented for Vertex AI
        yield new CompleteResponse(
            content: '',
            reasoning: null,
            toolCalls: null,
            tokensUsed: 0,
            costUsd: 0.0
        );
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        return new EmbeddingsResponse(embeddings: []);
    }

    /**
     * @param array<Message> $messages
     * @return array<array{role: string, content: string}>
     */
    private function formatMessages(array $messages): array
    {
        return array_map(function (Message $msg) {
            return [
                'role' => match (true) {
                    $msg instanceof UserMessage => 'user',
                    $msg instanceof AssistantMessage => 'assistant',
                    default => 'user',
                },
                'content' => $msg->content(),
            ];
        }, $messages);
    }

    private function parseResponse(array $data): CompleteResponse
    {
        return new CompleteResponse(
            content: $data['content'] ?? $data['text'] ?? '',
            reasoning: $data['reasoning'] ?? null,
            toolCalls: null,
            tokensUsed: 0,
            costUsd: 0.0,
        );
    }
}
