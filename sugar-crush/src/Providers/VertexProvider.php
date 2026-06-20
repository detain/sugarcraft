<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;

final readonly class VertexProvider implements ProviderInterface
{
    /**
     * The "predictor" is the single network seam. It receives the fully
     * templated publisher endpoint plus the request instances and returns the
     * decoded prediction struct of the first prediction.
     *
     * Google's PredictionServiceClient is `final` (and SDK/credential bound), so
     * it cannot be mocked directly. Routing the actual transport through this
     * closure lets every other concern — endpoint templating, message
     * formatting, response parsing, pricing, capability flags and error
     * handling — be exercised with a fake predictor and real assertions, while
     * keeping only the irreducible gRPC call behind a default seam.
     *
     * @var \Closure(string $endpoint, array<int, array<string, mixed>> $instances): array<string, mixed>
     */
    private \Closure $predictor;

    /**
     * @param (callable(string, array<int, array<string, mixed>>): array<string, mixed>)|null $predictor
     *        Network seam; when null a real Vertex AI prediction call is wired in.
     */
    public function __construct(
        private string $projectId,
        private string $location,
        private string $defaultModel,
        ?callable $predictor = null,
    ) {
        $this->predictor = $predictor !== null
            ? \Closure::fromCallable($predictor)
            : self::defaultPredictor($projectId);
    }

    /**
     * @param (callable(string, array<int, array<string, mixed>>): array<string, mixed>)|null $predictor
     */
    public static function create(
        string $projectId,
        string $location = 'us-central1',
        string $model = 'claude-3-sonnet@20240229',
        ?callable $predictor = null,
    ): self {
        return new self($projectId, $location, $model, $predictor);
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
        $endpoint = $this->endpointFor($request->model);
        $instances = [
            [
                'messages' => $this->formatMessages($request->messages),
                'temperature' => $request->temperature ?? 0.7,
                'max_tokens' => $request->maxTokens ?? 4096,
            ],
        ];

        try {
            $data = ($this->predictor)($endpoint, $instances);

            return $this->parseResponse($data);
        } catch (\Throwable $e) {
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
     * Builds the Vertex AI publisher-model endpoint for a given model id.
     */
    public function endpointFor(string $model): string
    {
        return "projects/{$this->projectId}/locations/{$this->location}/publishers/anthropic/models/{$model}";
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

    /**
     * @param array<string, mixed> $data
     */
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

    /**
     * Default network seam: performs a real Vertex AI prediction call.
     *
     * Lazily constructs the SDK client on first use so unit tests that inject
     * their own predictor never touch the Google client (which is `final` and
     * needs credentials). The decoded struct of the first prediction is
     * returned as a plain array for {@see parseResponse()}.
     *
     * @return \Closure(string, array<int, array<string, mixed>>): array<string, mixed>
     */
    private static function defaultPredictor(string $projectId): \Closure
    {
        return static function (string $endpoint, array $instances) use ($projectId): array {
            $clientClass = 'Google\\Cloud\\AIPlatform\\V1\\Client\\PredictionServiceClient';
            $requestClass = 'Google\\Cloud\\AIPlatform\\V1\\PredictRequest';

            if (!class_exists($clientClass) || !class_exists($requestClass)) {
                throw new \RuntimeException(
                    'Vertex AI prediction requires google/cloud-ai-platform; inject a predictor for offline use.'
                );
            }

            /** @var object $client */
            $client = new $clientClass(['projectId' => $projectId]);
            /** @var object $req */
            $req = (new $requestClass())
                ->setEndpoint($endpoint)
                ->setInstances(self::toProtobufValues($instances));

            $response = $client->predict($req);
            $predictions = $response->getPredictions();

            $first = null;
            foreach ($predictions as $prediction) {
                $first = $prediction;
                break;
            }

            if ($first === null) {
                return [];
            }

            // Protobuf Value -> associative array.
            return json_decode($first->serializeToJsonString(), true) ?? [];
        };
    }

    /**
     * Wraps each instance map in a protobuf Value so it satisfies the
     * PredictRequest instances field. Kept tiny and isolated so the array-shape
     * building stays testable without the SDK.
     *
     * @param array<int, array<string, mixed>> $instances
     * @return array<int, object>
     */
    private static function toProtobufValues(array $instances): array
    {
        $valueClass = 'Google\\Protobuf\\Value';

        return array_map(static function (array $instance) use ($valueClass): object {
            /** @var object $value */
            $value = new $valueClass();
            $value->mergeFromJsonString((string) json_encode($instance));

            return $value;
        }, $instances);
    }
}
