<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Exception\AwsException;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Providers\Concerns\HttpClientDefaults;
use SugarCraft\Crush\Usage;

/**
 * Amazon Bedrock provider, speaking the Converse API.
 *
 * WHY THE *RUNTIME* CLIENT AND NOT `Aws\Bedrock\BedrockClient`
 * -----------------------------------------------------------
 * The AWS SDK splits Bedrock across two entirely separate services with two
 * separate API models:
 *
 *   - `bedrock` (2023-04-20, `Aws\Bedrock\BedrockClient`) is the CONTROL
 *     plane - guardrails, evaluation jobs, model customisation, provisioned
 *     throughput. It has no inference operations at all.
 *   - `bedrock-runtime` (2023-09-30, `Aws\BedrockRuntime\BedrockRuntimeClient`)
 *     is the DATA plane, and is the only one that defines `Converse`,
 *     `ConverseStream`, `InvokeModel` and `InvokeModelWithResponseStream`.
 *
 * This class was previously handed a `BedrockClient`, so `converse()` fell
 * through `AwsClient::__call()` into `getCommand()`, which threw
 * `InvalidArgumentException: Operation not found: Converse` - and, because
 * that is not an `AwsException`, it slipped straight past the `catch` below
 * and out of the provider unwrapped. Every Bedrock completion failed, always,
 * before a single byte reached AWS.
 */
final readonly class BedrockProvider implements ProviderInterface
{
    use HttpClientDefaults;

    private const REGION_US = 'us-east-1';
    private const REGION_EU = 'eu-west-1';

    private const DEFAULT_MODEL = 'anthropic.claude-sonnet-4-6';

    /**
     * Fallback ceiling for a streaming turn.
     *
     * Converse requires nothing here, but `ConverseStream` without a
     * `maxTokens` inherits whatever per-model default AWS picks, which for
     * some model families is short enough to truncate an agentic reply
     * mid-tool-call.
     */
    private const DEFAULT_STREAM_MAX_TOKENS = 4096;

    private const DEFAULT_TEMPERATURE = 0.7;

    public function __construct(
        private BedrockRuntimeClient $client,
        private string $region = self::REGION_US,
        private string $defaultModel = self::DEFAULT_MODEL,
    ) {}

    public static function create(string $region = self::REGION_US, ?string $model = null): self
    {
        $region = self::resolveRegion($region);

        // Credentials are deliberately left to the SDK's default provider
        // chain (env -> shared ini/profile -> ECS/EC2 metadata), the same
        // chain every other AWS tool on the box resolves through, so an
        // instance role or `AWS_PROFILE` works with no config here. Passing
        // an explicit `credentials` key would *disable* that chain.
        //
        // The AWS SDK talks to Bedrock over Guzzle too, it just takes its
        // transport options as `http` rather than accepting a client. Same
        // policy, same reason - see HttpClientDefaults. Still no total
        // `timeout`: a Bedrock completion is as long-running as any other.
        $client = new BedrockRuntimeClient([
            'region' => $region,
            'version' => 'latest',
            'http' => ['connect_timeout' => self::connectTimeoutSeconds()],
        ]);

        return new self($client, $region, $model ?? self::DEFAULT_MODEL);
    }

    public function name(): string
    {
        return 'bedrock';
    }

    /**
     * The region this provider's client is bound to.
     *
     * Bedrock model availability is regional - a model id that resolves in
     * `us-east-1` is a `ValidationException` in `eu-west-1` - so the region
     * is part of every failure message this class raises.
     */
    public function region(): string
    {
        return $this->region;
    }

    public function model(): string
    {
        return $this->defaultModel;
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
        $model = $this->modelId($request);

        $params = [
            'modelId' => $model,
            'messages' => $this->formatMessages($this->withoutSystemMessages($request->messages)),
        ];

        $system = $this->systemBlocks($request);
        if ($system !== []) {
            $params['system'] = $system;
        }

        $inference = $this->inferenceConfig($request);
        if ($inference !== []) {
            $params['inferenceConfig'] = $inference;
        }

        try {
            // Converse-shaped params (messages/system/inferenceConfig) require
            // the Converse API on the *runtime* client - see the class
            // docblock. Not the legacy invokeModel body protocol, and not
            // anything on the control-plane client, which has no inference
            // operation to call.
            $result = $this->client->converse($params);
            $data = $result->toArray();

            return $this->parseResponse($data, $model);
        } catch (AwsException $e) {
            throw new \RuntimeException($this->failureMessage('completion', $model, $e), 0, $e);
        }
    }

    /**
     * Streams completion responses as a generator of deltas.
     *
     * Each yielded CompleteResponse contains only the delta/content from that
     * chunk. The caller is responsible for accumulating content across chunks.
     *
     * Only the final `metadata` event carries usage, so every chunk before it
     * reports tokensUsed/costUsd of 0 and the last one reports the turn total
     * with empty content. Callers must therefore accumulate content and read
     * usage independently - which is what `Runtime::runStreaming()` does.
     *
     * @return \Generator<int, CompleteResponse>
     */
    public function completeStream(CompleteRequest $request): \Generator
    {
        $model = $this->modelId($request);

        $params = [
            'modelId' => $model,
            'messages' => $this->formatMessages($this->withoutSystemMessages($request->messages)),
            'inferenceConfig' => $this->inferenceConfig($request) + [
                'maxTokens' => self::DEFAULT_STREAM_MAX_TOKENS,
                'temperature' => self::DEFAULT_TEMPERATURE,
            ],
        ];

        $system = $this->systemBlocks($request);
        if ($system !== []) {
            $params['system'] = $system;
        }

        try {
            // ConverseStream emits an event stream of typed events; each text
            // token arrives as a contentBlockDelta event (not the legacy
            // `completion` field). The SDK's EventParsingIterator hands each
            // one over already shaped as [<eventName> => <payload>].
            $result = $this->client->converseStream($params);
            $stream = $result->get('stream');

            foreach ($stream as $event) {
                yield $this->parseChunk(is_array($event) ? $event : [], $model);
            }
        } catch (AwsException $e) {
            throw new \RuntimeException($this->failureMessage('streaming', $model, $e), 0, $e);
        }
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        // Use Titan or Cohere for embeddings via Bedrock
        return new EmbeddingsResponse(embeddings: []);
    }

    /**
     * `modelId` is a required URI path segment on Converse, so an empty one
     * would be signed into a malformed URL rather than rejected usefully.
     * Falling back to the configured model keeps a caller that only set the
     * provider's model (and left CompleteRequest::$model empty) working.
     */
    private function modelId(CompleteRequest $request): string
    {
        return $request->model !== '' ? $request->model : $this->defaultModel;
    }

    /**
     * Converse rejects an empty `inferenceConfig` structure, and previously a
     * request with a temperature but no maxTokens silently dropped the
     * temperature - the whole block was gated on maxTokens alone.
     *
     * @return array<string, mixed>
     */
    private function inferenceConfig(CompleteRequest $request): array
    {
        $config = [];

        if ($request->maxTokens !== null) {
            $config['maxTokens'] = $request->maxTokens;
        }

        if ($request->temperature !== null) {
            $config['temperature'] = $request->temperature;
        }

        if ($request->topP !== null) {
            $config['topP'] = $request->topP;
        }

        // Converse takes stop sequences here rather than at the top level.
        if (is_string($request->stop) && $request->stop !== '') {
            $config['stopSequences'] = [$request->stop];
        } elseif (is_array($request->stop) && $request->stop !== []) {
            $config['stopSequences'] = array_values($request->stop);
        }

        return $config;
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
                $msg instanceof SystemMessage => 'user', // total over Message types; production hoists SystemMessages to `system` first (see $this->systemBlocks())
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
     * Converse has no per-message `system` role: system text only exists in
     * the request-level `system` block list, and `messages` must alternate
     * user/assistant turns. formatMessages() keeps its total contract over
     * Message types (a SystemMessage maps to user, and tests pin that), so a
     * history SystemMessage would sit on the wire as a same-role neighbour
     * of a real user turn - the consecutive-user shape backlog E19 measured.
     * Production therefore filters SystemMessages out of the message list
     * here and hoists their text into the `system` array instead.
     *
     * @param array<Message> $messages
     * @return array<Message>
     */
    private function withoutSystemMessages(array $messages): array
    {
        return array_values(array_filter(
            $messages,
            static fn (Message $msg): bool => !$msg instanceof SystemMessage,
        ));
    }

    /**
     * Builds the Converse `system` block list: the assembled prompt first,
     * then every history SystemMessage's text in message order. The stream
     * path must build exactly the same list as the complete path, so both
     * call sites share this helper.
     *
     * @return array<int, array{text: string}>
     */
    private function systemBlocks(CompleteRequest $request): array
    {
        $blocks = [];

        if ($request->systemPrompt !== null) {
            $blocks[] = ['text' => $request->systemPrompt];
        }

        foreach ($request->messages as $message) {
            if ($message instanceof SystemMessage) {
                $blocks[] = ['text' => $message->content()];
            }
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function parseResponse(array $data, string $model): CompleteResponse
    {
        $output = $data['output']['message'] ?? [];
        $blocks = is_array($output['content'] ?? null) ? $output['content'] : [];

        $text = '';
        $reasoning = '';

        // A Converse reply is a LIST of content blocks, not one text block:
        // a reasoning-capable model puts `reasoningContent` first, so reading
        // only $content[0]['text'] returns the empty string for exactly the
        // models worth pointing this provider at.
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            if (isset($block['text']) && is_string($block['text'])) {
                $text .= $block['text'];
            }

            $thought = $block['reasoningContent']['reasoningText']['text'] ?? null;
            if (is_string($thought)) {
                $reasoning .= $thought;
            }
        }

        // P4.S2: one parsed Usage is the source of every usage number leaving
        // this method; tokensUsed/costUsd keep their exact prior expressions
        // for legitimate wire values (a negative count clamps to 0 per Usage's
        // doctrine, as in SglangProvider::parseResponse()).
        $usage = $this->parseUsage(is_array($data['usage'] ?? null) ? $data['usage'] : [], $model);

        return new CompleteResponse(
            content: $text,
            reasoning: $reasoning !== '' ? $reasoning : null,
            toolCalls: null,
            tokensUsed: $usage->totalTokens,
            costUsd: $usage->costUsd,
        );
    }

    /**
     * Parses a Converse `TokenUsage` object into the provider-counted token
     * BUCKETS (prompt_plan.md P4.S2).
     *
     * CACHE-FIELD FINDING, from the API definition THIS REPO VENDORS: the
     * `bedrock-runtime` 2023-09-30 model defines shape `TokenUsage` with
     * members `inputTokens`, `outputTokens`, `totalTokens`,
     * `cacheReadInputTokens`, `cacheWriteInputTokens`, `cacheDetails`
     * (MEASURED: php-require of
     * vendor/aws/aws-sdk-php/src/data/bedrock-runtime/2023-09-30/api-2.json.php,
     * and `ConverseStreamMetadataEvent.usage` binds the SAME shape — so the
     * unary reply and the terminal stream metadata carry one usage document,
     * parsed here once). This is the provider with the fullest cache story:
     * BOTH sides of the cache are real wire fields, mapping to
     * `cacheReadTokens` and `cacheCreationTokens` (a cache WRITE is what
     * Anthropic-shape calls cache CREATION; the naming difference is the
     * only difference).
     *
     * `inputTokens` maps straight to Usage's `inputTokens` WITHOUT the
     * cache-read subtraction the OpenAI-family parse applies: Bedrock follows
     * the Anthropic-side convention where `inputTokens` already counts only
     * tokens after the last cache breakpoint, so `total = cacheRead +
     * cacheCreation + input` partitions rather than overlaps. The field NAMES
     * and membership are vendored-verified above; that NON-overlap SEMANTICS
     * is Anthropic/Bedrock published-API documentation, not restated in the
     * shape file — labelled UNVERIFIED-locally here so a future reader
     * re-checks it against a live cached response before trusting
     * `Usage::promptTokens()` on this provider.
     *
     * `totalTokens` exists on the wire but the figure `complete()` has always
     * reported is `inputTokens + outputTokens`, and that expression is kept
     * byte-identical: whether Bedrock's own total counts cache is exactly the
     * unverified semantics above, and silently switching what every turn
     * reports as its billable total is a pricing-visible change outside this
     * step's Goal — REPORTED, not done.
     *
     * `cacheDetails` (the 5-minute/1-hour write split) has no Usage bucket to
     * land in — Usage carries two cache sides, not TTL-shaped ones.
     *
     * @param array<string, mixed> $usage the `usage`/`metadata.usage` document
     *                                    from the decoded response; non-array
     *                                    arrives as `[]` via the call sites,
     *                                    keeping the `?? 0` tolerance the
     *                                    inline parse replaced
     */
    public function parseUsage(array $usage, string $model): Usage
    {
        $inputTokens = self::usageInt($usage['inputTokens'] ?? null) ?? 0;
        $outputTokens = self::usageInt($usage['outputTokens'] ?? null) ?? 0;

        return Usage::new(
            // The exact expression this replaces: the sum of the two sides
            // each defaulted to 0 — see the totalTokens paragraph above.
            $inputTokens + $outputTokens,
            $this->cost($model, $inputTokens, $outputTokens),
            self::usageInt($usage['inputTokens'] ?? null),
            self::usageInt($usage['outputTokens'] ?? null),
            self::usageInt($usage['cacheReadInputTokens'] ?? null),
            self::usageInt($usage['cacheWriteInputTokens'] ?? null),
        );
    }

    /**
     * One usage number as reported: absent OR JSON null stays `null`
     * (unreported — never coerced to a measured zero); anything numeric
     * counts as its int.
     */
    private static function usageInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /**
     * Parses a streaming event into a partial/delta CompleteResponse.
     *
     * This returns only the delta content from this chunk - it does NOT
     * contain accumulated content. The caller must accumulate across chunks.
     *
     * @param array<string, mixed> $data
     */
    private function parseChunk(array $data, string $model): CompleteResponse
    {
        // ConverseStream text tokens arrive as contentBlockDelta events, whose
        // `delta` is a union - text OR reasoningContent OR toolUse.
        $delta = $data['contentBlockDelta']['delta'] ?? [];
        $text = is_string($delta['text'] ?? null) ? $delta['text'] : '';
        $thought = $delta['reasoningContent']['text'] ?? null;

        // Usage lands once, on the terminal metadata event; every earlier
        // event genuinely has none to report. P4.S2: the SAME
        // {@see parseUsage()} reads it here as on the unary path - the
        // vendored API definition binds `ConverseStreamMetadataEvent.usage`
        // to the identical `TokenUsage` shape, so the cache buckets cannot be
        // wired on one arm and missed on the other. An event with no usage
        // parses to an all-unreported Usage whose total is 0 - exactly the
        // zeros this method hardcoded before.
        $usage = $this->parseUsage(
            is_array($data['metadata']['usage'] ?? null) ? $data['metadata']['usage'] : [],
            $model,
        );

        return new CompleteResponse(
            content: $text,
            reasoning: is_string($thought) && $thought !== '' ? $thought : null,
            toolCalls: null,
            tokensUsed: $usage->totalTokens,
            costUsd: $usage->costUsd,
        );
    }

    private function cost(string $model, int $inputTokens, int $outputTokens): float
    {
        return ($inputTokens * $this->costPer1kTokens($model, 'input')
            + $outputTokens * $this->costPer1kTokens($model, 'output')) / 1000;
    }

    /**
     * Bedrock's own errors say nothing about which region or model id was
     * asked for, and the overwhelmingly common failure - a model that is not
     * enabled in this account/region - is unreadable without both.
     */
    private function failureMessage(string $stage, string $model, AwsException $e): string
    {
        return sprintf(
            'Bedrock %s failed for model "%s" in %s: %s',
            $stage,
            $model,
            $this->region,
            $e->getMessage(),
        );
    }

    /**
     * An empty region would make the SDK throw on construction rather than
     * fall back, so honour the same `AWS_REGION`/`AWS_DEFAULT_REGION` pair the
     * SDK and the aws CLI read before giving up on the built-in default.
     */
    private static function resolveRegion(string $region): string
    {
        if ($region !== '') {
            return $region;
        }

        return (getenv('AWS_REGION') ?: getenv('AWS_DEFAULT_REGION')) ?: self::REGION_US;
    }
}
