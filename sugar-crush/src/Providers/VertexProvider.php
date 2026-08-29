<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Providers\Concerns\HttpClientDefaults;
use SugarCraft\Crush\Providers\Concerns\ToolSchema;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Google Vertex AI provider.
 *
 * WHY `rawPredict` AND NOT `predict` FOR CLAUDE
 * ---------------------------------------------
 * Vertex serves two entirely different request protocols under one service:
 *
 *   - Google's own publisher models (`publishers/google/models/*`) take
 *     `:predict` / `:streamPredict` with the Google-shaped envelope
 *     (`{"instances": [...], "parameters": {...}}`).
 *   - Anthropic publisher models (`publishers/anthropic/models/claude-*`) are
 *     NOT wrapped at all. They take `:rawPredict` / `:streamRawPredict` and
 *     the body is the native Anthropic Messages API document -
 *     `anthropic_version`, `messages`, `max_tokens`, `system`, `tools` - with
 *     the model named only in the URL path, never in the body.
 *
 * This class previously sent the Google envelope to `:predict` for every
 * model while templating a `publishers/anthropic` endpoint, i.e. the one
 * combination Vertex does not serve. The failure is NOT a missing route:
 * the vendored REST config binds `:predict` to the wildcard resource pattern
 * `projects/{p}/locations/{l}/publishers/{publisher}/models/{model}` for EVERY
 * publisher (`prediction_service_rest_client_config.php`, `Predict`), so
 * `publishers/anthropic/models/claude-*:predict` resolves fine. What fails is
 * the document: an Anthropic MaaS model does not accept the `instances`
 * wrapper - it only reads the native Messages API body, which `:predict`
 * cannot carry. Hence `:rawPredict`, whose body is passed to the publisher
 * verbatim. Every completion failed before reaching the model.
 *
 * NOT SERVED HERE: Vertex's other MaaS publishers
 * ------------------------------------------------
 * Vertex also fronts `publishers/mistralai`, `publishers/meta` and
 * `publishers/ai21`, which likewise answer on `:rawPredict`. This class does
 * not route them - {@see publisherFor()} is anthropic-or-google, so
 * `mistral-large@2411`, `llama-3.1-405b-instruct-maas` and `jamba-1.5-large`
 * all template `publishers/google` + `:predict` and fail at the server.
 *
 * A publisher lookup table alone would NOT fix that, which is why one is
 * deliberately not added: those models take a THIRD request document (an
 * OpenAI-shaped `{model, messages}` body that, unlike Anthropic's, requires
 * the model id IN the body). Routing them to the right publisher while still
 * sending `instances` - or sending them the Anthropic body, which omits
 * `model` on purpose - would trade a diagnosable 404 for a confusing 400.
 * Supporting them is a feature (a third body shaper), not a routing tweak.
 *
 * Two further bugs went with it, both in the default network seam:
 *
 *   - The client was built as `new PredictionServiceClient(['projectId' => …])`.
 *     `projectId` is not one of the keys `Google\ApiCore\Options\ClientOptions
 *     ::fromArray()` reads, so it was silently dropped - and with no
 *     `apiEndpoint` set the client stayed pointed at the GLOBAL
 *     `aiplatform.googleapis.com`, which does not serve regional publisher
 *     predictions. The endpoint must be `{location}-aiplatform.googleapis.com`.
 *   - `completeStream()` was a stub that yielded one empty chunk, so the
 *     streaming path silently produced an empty answer.
 *
 * The legacy Google `predict` path is kept intact for non-Anthropic models
 * rather than removed - it is simply no longer used for Claude.
 */
final readonly class VertexProvider implements ProviderInterface
{
    use ToolSchema;

    /**
     * Pulled in for {@see HttpClientDefaults::connectTimeoutSeconds()} alone -
     * this class builds no Guzzle client of its own (the Google SDK owns the
     * transport), but the connect bound it hands that transport must be the
     * SAME audited, `SUGARCRUSH_CONNECT_TIMEOUT`-overridable number every
     * other provider uses, not a second one invented here. See
     * {@see callOptions()} for where it is applied.
     */
    use HttpClientDefaults;

    /**
     * Vertex carries the Anthropic API version in the body (the header
     * `anthropic-version` is a direct-API-only spelling). This is the value
     * every Anthropic-on-Vertex model expects.
     */
    private const ANTHROPIC_VERSION = 'vertex-2023-10-16';

    public const METHOD_PREDICT = 'predict';
    public const METHOD_RAW_PREDICT = 'rawPredict';
    public const METHOD_STREAM_RAW_PREDICT = 'streamRawPredict';

    private const PUBLISHER_ANTHROPIC = 'anthropic';
    private const PUBLISHER_GOOGLE = 'google';

    /** `max_tokens` is REQUIRED by the Anthropic Messages API - there is no server-side default to fall back to. */
    private const DEFAULT_MAX_TOKENS = 4096;

    private const DEFAULT_TEMPERATURE = 0.7;

    /**
     * The "predictor" is the unary network seam. It receives the fully
     * templated publisher endpoint, the Vertex METHOD to invoke
     * (`rawPredict` for Anthropic publisher models, `predict` for Google's)
     * and the already-shaped request body, and returns the decoded response
     * document.
     *
     * Google's PredictionServiceClient is `final` (and SDK/credential bound),
     * so it cannot be mocked directly. Routing the actual transport through
     * this closure lets every other concern - endpoint templating, method
     * selection, message formatting, response parsing, pricing, capability
     * flags and error handling - be exercised with a fake predictor and real
     * assertions, while keeping only the irreducible RPC behind a default
     * seam.
     *
     * @var \Closure(string $endpoint, string $method, array<string, mixed> $body): array<string, mixed>
     */
    private \Closure $predictor;

    /**
     * The streaming counterpart of {@see $predictor}: same inputs, but it
     * yields already-decoded Anthropic SSE events (`message_start`,
     * `content_block_delta`, `message_delta`, …) one at a time. SSE framing
     * lives in the default implementation so tests can feed event arrays
     * straight in.
     *
     * @var \Closure(string $endpoint, string $method, array<string, mixed> $body): iterable<int, array<string, mixed>>
     */
    private \Closure $streamer;

    /**
     * @param (callable(string, string, array<string, mixed>): array<string, mixed>)|null $predictor
     *        Unary network seam; when null a real Vertex AI call is wired in.
     * @param (callable(string, string, array<string, mixed>): iterable<int, array<string, mixed>>)|null $streamer
     *        Streaming network seam; when null a real `streamRawPredict` call is wired in.
     */
    public function __construct(
        private string $projectId,
        private string $location,
        private string $defaultModel,
        ?callable $predictor = null,
        ?callable $streamer = null,
    ) {
        $this->predictor = $predictor !== null
            ? \Closure::fromCallable($predictor)
            : self::defaultPredictor($location);

        $this->streamer = $streamer !== null
            ? \Closure::fromCallable($streamer)
            : self::defaultStreamer($location);
    }

    /**
     * @param (callable(string, string, array<string, mixed>): array<string, mixed>)|null $predictor
     * @param (callable(string, string, array<string, mixed>): iterable<int, array<string, mixed>>)|null $streamer
     */
    public static function create(
        string $projectId,
        string $location = 'us-central1',
        string $model = 'claude-3-sonnet@20240229',
        ?callable $predictor = null,
        ?callable $streamer = null,
    ): self {
        return new self($projectId, $location, $model, $predictor, $streamer);
    }

    public function name(): string
    {
        return 'vertex';
    }

    public function model(): string
    {
        return $this->defaultModel;
    }

    /**
     * Capability flags carry no model argument, so they answer for the
     * provider's configured default model. A caller that overrides the model
     * per request into the other family gets the other family's protocol
     * (see {@see complete()}) but these flags will not have moved.
     */
    public function supportsStreaming(): bool
    {
        // `streamRawPredict` is bound for Anthropic publisher models. Google's
        // `streamPredict`/`serverStreamingPredict` response envelope is not
        // modelled here, so that family still reports no streaming.
        return $this->isAnthropicModel($this->defaultModel);
    }

    public function supportsFunctionCalling(): bool
    {
        return $this->isAnthropicModel($this->defaultModel);
    }

    public function supportsVision(): bool
    {
        // Claude-on-Vertex accepts image blocks, but no Message in this
        // library carries image bytes into a request, so nothing would ever
        // build one. Stays false until a request-side image block exists.
        return false;
    }

    public function supportsJsonSchema(): bool
    {
        // Anthropic constrains output through tools, not a response schema.
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
        $model = $this->modelId($request);
        $anthropic = $this->isAnthropicModel($model);
        $method = $anthropic ? self::METHOD_RAW_PREDICT : self::METHOD_PREDICT;

        try {
            // Inside the try: endpointFor() and anthropicBody() both reject
            // locally-detectable bad input, and a caller expects those the same
            // way it expects a transport failure - as an error CompleteResponse.
            $endpoint = $this->endpointFor($model);
            $body = $anthropic
                ? $this->anthropicBody($request, stream: false)
                : $this->googleBody($request);

            $data = ($this->predictor)($endpoint, $method, $body);

            return $anthropic
                ? $this->parseAnthropicResponse($data, $model)
                : $this->parseResponse($data);
        } catch (\Throwable $e) {
            return new CompleteResponse(
                content: '',
                isError: true,
                errorMessage: $e->getMessage(),
                // Classified here, where the exception still exists: this
                // provider reports a failure as a response rather than by
                // throwing, so the verdict has to be carried rather than
                // re-derived from the message downstream. Note the catch is
                // \Throwable, so it also sees endpointFor()/anthropicBody()'s
                // own \InvalidArgumentException for locally-bad input -
                // TransientFailure's allow-list classifies that as permanent,
                // which is what stops a malformed request being retried three
                // times. See CompleteResponse::$errorTransient.
                errorTransient: TransientFailure::isTransient($e),
            );
        }
    }

    /**
     * Streams an Anthropic-on-Vertex turn through `:streamRawPredict`.
     *
     * Each yielded CompleteResponse carries only that event's delta; callers
     * accumulate.
     *
     * USAGE LANDS TWICE HERE, and that is NOT the contract
     * {@see BedrockProvider::completeStream()} has: Anthropic-on-Vertex reports
     * input tokens on `message_start` and output tokens on the terminal
     * `message_delta`, so {@see parseAnthropicChunk()} emits two usage-bearing
     * responses per turn — `tokensUsed: $inputTokens` on the first and
     * `tokensUsed: $outputTokens` on the last, each priced on its own side of
     * the rate table. A consumer that read only the final chunk would bill the
     * turn for its output and none of its input; {@see \SugarCraft\Crush\Runtime}
     * therefore SUMS across chunks rather than taking the last, and says so.
     * Bedrock really does land its usage once, on the terminal metadata event.
     *
     * @return \Generator<int, CompleteResponse>
     */
    public function completeStream(CompleteRequest $request): \Generator
    {
        $model = $this->modelId($request);

        if (!$this->isAnthropicModel($model)) {
            // Google publisher models have no rawPredict stream and their
            // streaming envelope is not modelled here. Yielding the unary
            // result once keeps a caller that only ever calls completeStream()
            // working instead of handing it the empty chunk this method used
            // to return for every model.
            yield $this->complete($request);

            return;
        }

        // Accumulates `input_json_delta` fragments for in-flight `tool_use`
        // blocks, keyed by the stream's content-block index. A local rather
        // than a property because this class is `final readonly` - the buffer
        // lives exactly as long as one completeStream() call.
        $toolCallBuffer = [];

        try {
            // Endpoint templating and body shaping are inside the try because
            // both now reject locally-detectable bad input (a model id that is
            // a resource path, an empty transcript). A caller of a generator
            // should see those the same way it sees a transport failure - as an
            // error chunk - not as an exception thrown on first iteration.
            $endpoint = $this->endpointFor($model);
            $body = $this->anthropicBody($request, stream: true);

            foreach (($this->streamer)($endpoint, self::METHOD_STREAM_RAW_PREDICT, $body) as $event) {
                $chunk = $this->parseAnthropicChunk(
                    is_array($event) ? $event : [],
                    $model,
                    $toolCallBuffer,
                );

                if ($chunk !== null) {
                    yield $chunk;
                }
            }
        } catch (\Throwable $e) {
            yield new CompleteResponse(
                content: '',
                isError: true,
                errorMessage: $e->getMessage(),
                // See complete()'s catch. This catch sits OUTSIDE the chunk
                // loop, so it can fire after real deltas have already been
                // yielded - which is precisely the case
                // Runtime::runStreaming() must not blindly retry.
                errorTransient: TransientFailure::isTransient($e),
            );
        }
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        return new EmbeddingsResponse(embeddings: []);
    }

    /**
     * Builds the Vertex AI publisher-model endpoint for a given model id.
     *
     * The publisher segment is derived from the model family: routing a
     * Gemini id under `publishers/anthropic` (which this method used to do
     * unconditionally) is a 404 at Vertex.
     *
     * @throws \InvalidArgumentException when the model id cannot be a single
     *         resource segment. `$model` is interpolated as the LAST path
     *         segment of a resource name, so a value that is itself a path
     *         double-nests silently - a configured
     *         `publishers/anthropic/models/claude-3-5-sonnet`, or a tuned
     *         model's own `projects/…/models/…` resource name, would be
     *         templated into `…/publishers/anthropic/models/projects/…`. An
     *         empty id templates a trailing `…/models/` with no segment at
     *         all. Both are locally detectable, so they are rejected here
     *         rather than shipped to Vertex as a malformed resource name.
     */
    public function endpointFor(string $model): string
    {
        if ($model === '') {
            throw new \InvalidArgumentException(
                'Vertex model id is empty: set a model on the request or on the provider. '
                . 'An empty id would be templated into a resource name ending in "/models/".'
            );
        }

        if (str_contains($model, '/')) {
            throw new \InvalidArgumentException(sprintf(
                'Vertex model id %s is a resource path, not a model id. Pass only the last '
                . 'segment (e.g. "claude-3-5-sonnet-v2@20241022"); this provider templates the '
                . 'project, location and publisher around it.',
                var_export($model, true),
            ));
        }

        return sprintf(
            'projects/%s/locations/%s/publishers/%s/models/%s',
            $this->projectId,
            $this->location,
            $this->publisherFor($model),
            $model,
        );
    }

    /**
     * Anthropic publisher models are the `claude-*` family; Vertex ids append
     * a version suffix (`claude-3-5-sonnet-v2@20241022`), so match on the
     * family name rather than an exact id list that would go stale on every
     * model release.
     */
    public function isAnthropicModel(string $model): bool
    {
        return str_contains(strtolower($model), 'claude');
    }

    private function publisherFor(string $model): string
    {
        return $this->isAnthropicModel($model) ? self::PUBLISHER_ANTHROPIC : self::PUBLISHER_GOOGLE;
    }

    /**
     * The model is a URI path segment on both `predict` and `rawPredict`, so
     * an empty one would be templated into a malformed endpoint rather than
     * rejected usefully. Falling back to the configured model keeps a caller
     * that only set the provider's model working.
     */
    private function modelId(CompleteRequest $request): string
    {
        return $request->model !== '' ? $request->model : $this->defaultModel;
    }

    // -------------------------------------------------------------------------
    // Anthropic (rawPredict / streamRawPredict) request shaping
    // -------------------------------------------------------------------------

    /**
     * The native Anthropic Messages API document, as `:rawPredict` expects it.
     *
     * Deliberately carries NO `model` key: Vertex takes the model from the URL
     * and rejects the body field.
     *
     * @throws \InvalidArgumentException when the transcript yields no turns.
     * @return array<string, mixed>
     */
    private function anthropicBody(CompleteRequest $request, bool $stream): array
    {
        $messages = $this->formatAnthropicMessages($request->messages);

        if ($messages === []) {
            // `messages` must hold at least one turn - an empty list is a
            // server-side 400 with no useful detail. The condition is entirely
            // local (an empty transcript, or one that is nothing but
            // SystemMessages, which systemInstruction() hoists out), so say so
            // here instead of burning a round trip on an opaque error.
            throw new \InvalidArgumentException(
                'Vertex rawPredict: the Anthropic Messages API requires at least one user or '
                . 'assistant turn, but the transcript produced none (it was empty, or held only '
                . 'system messages - which are hoisted into the top-level "system" field).'
            );
        }

        $body = [
            'anthropic_version' => self::ANTHROPIC_VERSION,
            'messages' => $messages,
            'max_tokens' => $request->maxTokens ?? self::DEFAULT_MAX_TOKENS,
            'temperature' => $request->temperature ?? self::DEFAULT_TEMPERATURE,
        ];

        $system = $this->systemInstruction($request);
        if ($system !== null) {
            $body['system'] = $system;
        }

        if ($request->topP !== null) {
            $body['top_p'] = $request->topP;
        }

        if ($request->topK !== null) {
            $body['top_k'] = $request->topK;
        }

        $stopSequences = $this->stopSequences($request);
        if ($stopSequences !== []) {
            $body['stop_sequences'] = $stopSequences;
        }

        if ($request->tools !== null && $request->tools !== []) {
            $body['tools'] = $this->formatAnthropicTools($request->tools);
        }

        if ($stream) {
            // `streamRawPredict` still needs the body to opt into SSE; without
            // it Vertex answers with one buffered document.
            $body['stream'] = true;
        }

        return $body;
    }

    /**
     * The assembled system instruction for EITHER envelope this class builds.
     *
     * Anthropic takes the system prompt as a TOP-LEVEL field - a `system`
     * role inside `messages` is a 400. Google's `instances` envelope has no
     * system role at all and carries the instruction in `instances[0].context`
     * ({@see googleBody()}). Both hoist, both join the same way, so there is
     * ONE joiner: any SystemMessage in the transcript is lifted out here and
     * joined onto the request's own systemPrompt, assembled prompt first, in
     * message order.
     *
     * WAS NAMED `anthropicSystem()` until the Google arm was fixed to
     * transmit at all - the prefix described the only caller it then had, not
     * what it computes.
     *
     * An empty-string systemPrompt and an empty-content SystemMessage each
     * contribute nothing, so a request carrying only those yields `null` and
     * neither envelope grows an empty system field. That guard
     * (`!== null && !== ''`) is the one Sglang, Custom and this class already
     * use; OpenAI and Bedrock check only `!== null`. This method keeps the
     * stricter of the two rather than inventing a third.
     */
    private function systemInstruction(CompleteRequest $request): ?string
    {
        $parts = [];

        if ($request->systemPrompt !== null && $request->systemPrompt !== '') {
            $parts[] = $request->systemPrompt;
        }

        foreach ($request->messages as $msg) {
            if ($msg instanceof SystemMessage && $msg->content() !== '') {
                $parts[] = $msg->content();
            }
        }

        return $parts === [] ? null : implode("\n\n", $parts);
    }

    /**
     * @return array<int, string>
     */
    private function stopSequences(CompleteRequest $request): array
    {
        if (is_string($request->stop)) {
            return $request->stop === '' ? [] : [$request->stop];
        }

        if (is_array($request->stop)) {
            return array_values(array_filter($request->stop, static fn ($s): bool => is_string($s) && $s !== ''));
        }

        return [];
    }

    /**
     * Renders the transcript as Anthropic content-block turns.
     *
     * Two rules the Google `instances` shape never had to honour:
     *   - SystemMessage is dropped here (hoisted by {@see systemInstruction()}).
     *   - Consecutive same-role turns are merged. Anthropic rejects two user
     *     turns in a row, and a tool-calling loop produces exactly that
     *     (assistant tool_use, then one ToolResultMessage per call, all of
     *     which are `user` turns).
     *
     * @param array<Message> $messages
     * @return array<int, array{role: string, content: array<int, array<string, mixed>>}>
     */
    private function formatAnthropicMessages(array $messages): array
    {
        $turns = [];

        foreach ($messages as $msg) {
            if (!$msg instanceof Message || $msg instanceof SystemMessage) {
                continue;
            }

            $blocks = $this->anthropicBlocks($msg);
            if ($blocks === []) {
                // An empty text block is a 400; a turn with nothing in it is
                // simply not a turn.
                continue;
            }

            $role = $msg instanceof AssistantMessage ? 'assistant' : 'user';
            $last = array_key_last($turns);

            if ($last !== null && $turns[$last]['role'] === $role) {
                $turns[$last]['content'] = array_merge($turns[$last]['content'], $blocks);

                continue;
            }

            $turns[] = ['role' => $role, 'content' => $blocks];
        }

        return $turns;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function anthropicBlocks(Message $msg): array
    {
        if ($msg instanceof ToolResultMessage) {
            $block = [
                'type' => 'tool_result',
                'tool_use_id' => $msg->toolCallId(),
                'content' => $msg->content(),
            ];

            if ($msg->isError()) {
                $block['is_error'] = true;
            }

            return [$block];
        }

        $blocks = [];

        if ($msg->content() !== '') {
            $blocks[] = ['type' => 'text', 'text' => $msg->content()];
        }

        // No `thinking` block is emitted here - the other half of the dormant
        // extended-thinking seam documented on parseAnthropicResponse(). It is
        // absent rather than removed: there is no signature to replay yet
        // because no request ever asks for extended thinking.
        if ($msg instanceof AssistantMessage) {
            foreach ($msg->toolCalls() ?? [] as $call) {
                $block = $this->toolUseBlock($call);
                if ($block !== null) {
                    $blocks[] = $block;
                }
            }
        }

        return $blocks;
    }

    /**
     * Assistant tool calls have to be replayed back to Anthropic as `tool_use`
     * blocks or the matching `tool_result` has nothing to attach to.
     *
     * {@see ToolCall} keeps its state behind bare accessors on private
     * properties, so handing the object to `json_encode()` emits `{}` - the
     * same trap {@see Concerns\ToolSchema::formatToolCalls()} documents for
     * the OpenAI shape. Pre-shaped arrays (a transcript replayed from disk,
     * in either this library's or OpenAI's spelling) are translated too.
     *
     * @return ?array<string, mixed>
     */
    private function toolUseBlock(mixed $call): ?array
    {
        if ($call instanceof ToolCall) {
            return $this->toolUseFrom($call->id(), $call->name(), $call->arguments());
        }

        if (!is_array($call)) {
            return null;
        }

        $name = $call['name'] ?? $call['function']['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return null;
        }

        $arguments = $call['arguments'] ?? $call['input'] ?? $call['function']['arguments'] ?? [];
        if (is_string($arguments)) {
            // OpenAI streams `function.arguments` as a JSON string.
            $arguments = json_decode($arguments, true) ?? [];
        }

        return $this->toolUseFrom(
            is_string($call['id'] ?? null) ? $call['id'] : '',
            $name,
            is_array($arguments) ? $arguments : [],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function toolUseFrom(string $id, string $name, array $arguments): array
    {
        return [
            'type' => 'tool_use',
            'id' => $id,
            'name' => $name,
            // PHP cannot tell an empty map from an empty list, and `input: []`
            // is rejected as not-an-object - see Concerns\ToolSchema.
            'input' => $arguments === [] ? new \stdClass() : $arguments,
        ];
    }

    /**
     * Anthropic declares tools flat (`name`/`description`/`input_schema`),
     * not inside OpenAI's `{type: function, function: {...}}` wrapper.
     *
     * @param array<mixed> $tools
     * @return array<int, array<string, mixed>>
     */
    private function formatAnthropicTools(array $tools): array
    {
        $formatted = [];

        foreach ($tools as $tool) {
            if ($tool instanceof Tool) {
                $formatted[] = [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'input_schema' => $this->normalizeToolSchema($tool->inputSchema()),
                ];

                continue;
            }

            // Already-shaped declarations pass through untouched.
            if (is_array($tool)) {
                $formatted[] = $tool;
            }
        }

        return $formatted;
    }

    // -------------------------------------------------------------------------
    // Anthropic response parsing
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    private function parseAnthropicResponse(array $data, string $model): CompleteResponse
    {
        if (isset($data['error'])) {
            $message = $data['error']['message'] ?? 'Vertex rawPredict returned an error';

            return new CompleteResponse(
                content: '',
                isError: true,
                errorMessage: is_string($message) ? $message : 'Vertex rawPredict returned an error',
                // A rawPredict error arrives as a 200 carrying an error object,
                // not as an HTTP status, so this is the only place its
                // transience is visible. See
                // TransientFailure::TRANSIENT_ANTHROPIC_ERROR_TYPES.
                errorTransient: TransientFailure::anthropicErrorIsTransient($data['error']),
            );
        }

        $text = '';
        $reasoning = '';
        $toolCalls = [];

        // An Anthropic reply is a LIST of content blocks, not one text field:
        // a thinking-enabled model puts a `thinking` block first, and a
        // tool-calling turn ends in one `tool_use` block per call.
        //
        // KNOWN-INCOMPLETE SEAM - extended thinking round-trip. A `thinking`
        // block carries a `signature` alongside its text, and when extended
        // thinking is on Anthropic requires the assistant's thinking blocks
        // (signature included) to be replayed verbatim in the next request or
        // it rejects the turn. This method folds thinking text into
        // `reasoning` and drops the signature, and {@see anthropicBlocks()}
        // has no `thinking` case to replay it with - CompleteResponse has
        // nowhere to carry a signature, and AssistantMessage nowhere to store
        // one. Nothing is broken today because {@see anthropicBody()} never
        // sends a `thinking` parameter, so no signed block is ever produced.
        // Whoever enables extended thinking has to close BOTH halves: keep the
        // signature on the way in, and emit the block on the way out.
        foreach ($this->contentBlocks($data) as $block) {
            $type = $block['type'] ?? null;

            if ($type === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            } elseif (($type === 'thinking' || $type === 'redacted_thinking')
                && is_string($block['thinking'] ?? null)) {
                $reasoning .= $block['thinking'];
            } elseif ($type === 'tool_use') {
                $toolCalls[] = ToolCall::fromArray([
                    'id' => is_string($block['id'] ?? null) ? $block['id'] : '',
                    'name' => is_string($block['name'] ?? null) ? $block['name'] : '',
                    'arguments' => is_array($block['input'] ?? null) ? $block['input'] : [],
                ]);
            }
        }

        $inputTokens = (int) ($data['usage']['input_tokens'] ?? 0);
        $outputTokens = (int) ($data['usage']['output_tokens'] ?? 0);

        return new CompleteResponse(
            content: $text,
            reasoning: $reasoning !== '' ? $reasoning : null,
            toolCalls: $toolCalls === [] ? null : $toolCalls,
            tokensUsed: $inputTokens + $outputTokens,
            costUsd: $this->cost($model, $inputTokens, $outputTokens),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function contentBlocks(array $data): array
    {
        $content = $data['content'] ?? [];

        if (!is_array($content)) {
            return [];
        }

        return array_values(array_filter($content, 'is_array'));
    }

    /**
     * Translates one decoded Anthropic SSE event into a delta response, or
     * null when the event carries nothing a caller can use (`ping`,
     * `content_block_start` for a tool call, …).
     *
     * @param array<string, mixed> $event
     * @param array<int, array{id?: string, name?: string, json?: string}> $toolCallBuffer
     */
    private function parseAnthropicChunk(array $event, string $model, array &$toolCallBuffer): ?CompleteResponse
    {
        $type = $event['type'] ?? null;

        // A `tool_use` block announces its id/name up front and then streams
        // its arguments as `input_json_delta` fragments that are only valid
        // JSON once the block closes - so the call has to be buffered until
        // `content_block_stop`, exactly as the OpenAI shape is buffered in
        // CustomProvider::resolveStreamedToolCalls().
        if ($type === 'content_block_start') {
            $block = $event['content_block'] ?? [];

            if (is_array($block) && ($block['type'] ?? null) === 'tool_use') {
                $index = (int) ($event['index'] ?? 0);
                $toolCallBuffer[$index] = [
                    'id' => is_string($block['id'] ?? null) ? $block['id'] : '',
                    'name' => is_string($block['name'] ?? null) ? $block['name'] : '',
                    'json' => '',
                ];
            }

            return null;
        }

        if ($type === 'content_block_delta') {
            $delta = $event['delta'] ?? [];
            $deltaType = is_array($delta) ? ($delta['type'] ?? null) : null;

            if ($deltaType === 'input_json_delta') {
                $index = (int) ($event['index'] ?? 0);
                if (isset($toolCallBuffer[$index])) {
                    $toolCallBuffer[$index]['json'] .= (string) ($delta['partial_json'] ?? '');
                }

                return null;
            }

            $text = is_string($delta['text'] ?? null) ? $delta['text'] : '';
            $thought = is_string($delta['thinking'] ?? null) ? $delta['thinking'] : '';

            if ($text === '' && $thought === '') {
                return null;
            }

            return new CompleteResponse(
                content: $text,
                reasoning: $thought !== '' ? $thought : null,
            );
        }

        if ($type === 'content_block_stop') {
            $index = (int) ($event['index'] ?? 0);
            $buffered = $toolCallBuffer[$index] ?? null;

            if ($buffered === null) {
                return null;
            }

            unset($toolCallBuffer[$index]);

            $json = $buffered['json'];

            // A tool that takes no arguments streams no `input_json_delta` at
            // all, so an EMPTY buffer legitimately means "called with {}".
            // A non-empty buffer that will not decode means the opposite: the
            // fragments were truncated (a dropped chunk, a stream cut short).
            // Those two must not collapse onto the same value. Coercing a
            // failed decode to `[]` - which is what this used to do - hands the
            // agentic loop a well-formed zero-argument call, so a destructive
            // tool runs with its arguments silently missing instead of the turn
            // being reported as broken.
            $arguments = $json === '' ? [] : json_decode($json, true);

            // `json_decode(..., true)` flattens a JSON object and a JSON LIST
            // onto the same PHP array, so an is_array() test alone accepts
            // `[1,2]` as an argument map while the error text below says the
            // buffer has to decode to an object. The two cases that must stay
            // accepted both look like a list to `array_is_list()` - the empty
            // buffer handled above, and an explicit `{}`, which also decodes to
            // `[]` - so the only sound discriminator left is the source text: a
            // document that decoded without error is an object iff it opens
            // with `{`.
            if (!is_array($arguments) || ($json !== '' && !str_starts_with(ltrim($json), '{'))) {
                return new CompleteResponse(
                    content: '',
                    isError: true,
                    errorMessage: sprintf(
                        'Vertex streamRawPredict: tool call %s (%s) streamed argument JSON that does '
                        . 'not decode to an object (%s); the call was truncated, not argumentless. '
                        . 'Buffered fragment: %s',
                        var_export($buffered['name'], true),
                        $buffered['id'] === '' ? 'no id' : $buffered['id'],
                        json_last_error() === JSON_ERROR_NONE
                            // Reaching here WITH an array means it decoded to a
                            // list; get_debug_type() would just say "array".
                            ? 'decoded to ' . (is_array($arguments) ? 'a JSON list' : get_debug_type($arguments))
                            : json_last_error_msg(),
                        var_export(self::truncate($json), true),
                    ),
                );
            }

            return new CompleteResponse(
                content: '',
                toolCalls: [ToolCall::fromArray([
                    'id' => $buffered['id'],
                    'name' => $buffered['name'],
                    'arguments' => $arguments,
                ])],
            );
        }

        // Usage arrives split across the stream: input tokens on
        // `message_start`, output tokens on the terminal `message_delta`.
        if ($type === 'message_start') {
            $inputTokens = (int) ($event['message']['usage']['input_tokens'] ?? 0);

            return $inputTokens === 0
                ? null
                : new CompleteResponse(
                    content: '',
                    tokensUsed: $inputTokens,
                    costUsd: $this->cost($model, $inputTokens, 0),
                );
        }

        if ($type === 'message_delta') {
            $outputTokens = (int) ($event['usage']['output_tokens'] ?? 0);

            return $outputTokens === 0
                ? null
                : new CompleteResponse(
                    content: '',
                    tokensUsed: $outputTokens,
                    costUsd: $this->cost($model, 0, $outputTokens),
                );
        }

        if ($type === 'error') {
            $message = $event['error']['message'] ?? 'Vertex streamRawPredict returned an error';

            return new CompleteResponse(
                content: '',
                isError: true,
                errorMessage: is_string($message) ? $message : 'Vertex streamRawPredict returned an error',
                // THE case this classification exists for: an overloaded
                // Anthropic-on-Vertex backend does not answer 503, it opens a
                // successful 200 SSE stream and puts
                // `{"type":"overloaded_error"}` in an `error` event. Reading
                // only HTTP statuses and exceptions would leave the provider's
                // most common transient failure unretried.
                errorTransient: TransientFailure::anthropicErrorIsTransient($event['error'] ?? null),
            );
        }

        return null;
    }

    /**
     * A truncated tool-call buffer can hold an entire file body, so the
     * fragment quoted back in an error message is bounded - the point is to
     * identify WHICH call broke, not to reproduce it.
     */
    private static function truncate(string $value, int $limit = 120): string
    {
        return strlen($value) <= $limit ? $value : substr($value, 0, $limit) . '…';
    }

    private function cost(string $model, int $inputTokens, int $outputTokens): float
    {
        return ($inputTokens * $this->costPer1kTokens($model, 'input')
            + $outputTokens * $this->costPer1kTokens($model, 'output')) / 1000;
    }

    // -------------------------------------------------------------------------
    // Google publisher models (legacy `predict` path, unchanged in shape)
    // -------------------------------------------------------------------------

    /**
     * The Google-shaped `:predict` envelope. Retained for `publishers/google`
     * models, which really do take `instances`/`parameters`.
     *
     * THE ASSEMBLED SYSTEM PROMPT RIDES `instances[0].context`. This envelope
     * has no per-message `system` role, and {@see formatMessages()} maps every
     * message it does not recognise - SystemMessage included - to `user`, so
     * before this method read {@see systemInstruction()} the whole assembled
     * prompt was dropped outright for every `publishers/google` model, on the
     * unary path AND on {@see completeStream()}, which delegates here. A
     * history SystemMessage fared no better: it reached the model as an
     * ordinary user turn.
     *
     * `context` IS THE STANDING-INSTRUCTION FIELD - OF THE PaLM 2 `chat-bison`
     * ENVELOPE, WHICH IS THE ONE THIS METHOD BUILDS, and naming the model
     * family is the part that used to be missing. The instance shape is
     * `{"content": ..., "context": ..., "examples": [...], "messages":
     * [{"author": ..., "content": ...}]}`. The leading instance-level
     * `content` is NOT the per-message one and was omitted from this summary
     * until a second reader re-derived the struct; it is unused by this
     * method, which sends `context` + `messages`. CORROBORATED at code level
     * by an independent
     * raw-REST Go implementation of this same `:predict` endpoint,
     * uber/go-vertex-ai `types.go` (MEASURED 2026-08-29 from
     * https://raw.githubusercontent.com/uber/go-vertex-ai/main/types.go): its
     * `inputInstances` struct carries the JSON tags `json:"content"`,
     * `json:"context"`, `json:"examples,omitempty"` and `json:"messages"`
     * (RE-MEASURED 2026-08-29 by a third reader: `Content string` is the
     * struct's FIRST field and was missing from this list), its `payload`
     * struct is `{instances, parameters}` - this envelope exactly - and its
     * README names the model: "chat-bison is a large language model ... The
     * PaLM 2 for chat".
     *
     * WHAT THIS PARAGRAPH USED TO SAY, AND WHY THE CITATION MOVED (rule 42).
     * It used to cite "Design chat prompts" at
     * https://cloud.google.com/vertex-ai/generative-ai/docs/chat/chat-prompts
     * and name no model family at all. That page is NO LONGER THE
     * LOAD-BEARING CITATION for anything in this doc-block - the Go
     * implementation above is - and the reason is epistemic, not editorial.
     *
     * WHAT IS AGREED, ACROSS THREE INDEPENDENT READERS. The old URL
     * 301-redirects. All three measured that.
     *
     * WHAT IS UNVERIFIED (16.3). What the destination RENDERS is disputed and
     * this doc-block must not pretend otherwise. A review reported it as "a
     * navigation index with none of the described content"; a re-measurement
     * reported a live 200 article; a third reader (2026-08-29) also got an
     * article body, containing "Design chat prompts", a "Context
     * (recommended)" section describing `context` as what you "use ... to
     * customize the behavior of the chat model", and a
     * "You are Captain Barktholomew ..." example - though as a
     * best-practices TABLE ROW, not as the worked `"context": ...` JSON
     * snippet an earlier revision of this paragraph described. That third
     * reader also measured a SECOND hop the earlier note did not record: the
     * `docs.cloud.google.com/vertex-ai/...` URL itself redirects again, to
     * https://docs.cloud.google.com/gemini-enterprise-agent-platform/models/chat/chat-prompts,
     * which is a plausible mechanism for two fetchers landing on different
     * documents. Two of three readers reproduce the article; one does not.
     * Under 16.3 a claim two readers cannot both reproduce is NOT MEASURED,
     * so the page's current content is recorded here as UNVERIFIED. An
     * earlier revision of this paragraph called the navigation-index report
     * FALSE; that verdict is WITHDRAWN - it was one reader's fetch asserted
     * over another's, which is not a measurement.
     *
     * WHY IT DOES NOT MATTER TO THE CLAIM. Where the readers who did see an
     * article agree, the page describes `context` but shows a Gemini-shaped
     * message example (`"contents": [{"role": ..., "parts": {"text": ...}}]`)
     * and carries neither `chat-bison` nor `PaLM`. So even on the reading
     * MOST favourable to it, that page does not identify the WIRE SHAPE this
     * method sends. The `instances`/`context` envelope is established by the
     * Go struct above, which every reader can re-derive from a single raw
     * file, and nothing in this doc-block now rests on the disputed page.
     * Either way this is DOCUMENTED, not measured on the wire: nothing here
     * has Vertex credentials, so no live call confirms this deployment
     * honours it.
     *
     * OBSERVED, PRE-EXISTING, AND DELIBERATELY NOT FIXED: that same authority
     * spells the message key `author` - its `ChatMessage` struct is
     * `Author string json:"author"` / `Content string json:"content"` -
     * while {@see formatMessages()} emits `role`. That is a SECOND defect in
     * this envelope, older than the system-prompt hoist above it and
     * independent of it. It is left standing because correcting it would
     * change the body pinned by the existing green
     * `VertexProviderTest::testCompleteSelectsPredictAndTheInstancesEnvelopeForGoogleModels`,
     * which the step that wrote this paragraph is not permitted to touch.
     * Recorded so the next reader finds it rather than re-discovers it.
     *
     * A GEMINI MODEL ID ROUTED HERE DOES NOT GET A REQUEST GEMINI WOULD
     * ACCEPT - the same class of gap the class doc-block above already states
     * for `publishers/mistralai`, `publishers/meta` and `publishers/ai21`.
     * `gemini-1.5-pro-002`, the id both Vertex test files pin as "the Google
     * model", is not served by `instances`/`context` at all: Gemini on Vertex
     * answers `:generateContent` / `:streamGenerateContent` and takes its
     * standing instruction in a top-level `systemInstruction` object
     * (MEASURED 2026-08-29, verbatim from
     * https://ai.google.dev/api/generate-content: "systemInstruction object
     * (Content) Optional. Developer set system instruction(s). Currently, text
     * only."; see also
     * https://docs.cloud.google.com/vertex-ai/generative-ai/docs/reference/rest/v1/projects.locations.publishers.models/generateContent).
     * Switching this arm to that endpoint is a different endpoint, a different
     * method and a different request document - a feature decision for the
     * user, not a fix - and is deliberately not taken here.
     *
     * A SYSTEM-MESSAGE-ONLY TRANSCRIPT NOW YIELDS AN EMPTY `messages` LIST,
     * which is a NEW route introduced by the dedup below and is pinned rather
     * than guarded. MEASURED 2026-08-29:
     * `messages: [SystemMessage('only'), SystemMessage('this')]` with
     * `systemPrompt: 'asm'` produces
     * `{"instances":[{"messages":[],"context":"asm\n\nonly\n\nthis"}],"parameters":{...}}`.
     * BEFORE the dedup the same input produced two `role: user` turns carrying
     * the instruction text twice over. The Anthropic arm REJECTS this exact
     * input with a named \InvalidArgumentException (VertexProvider.php:435-446,
     * pinned by
     * `VertexProviderTest::testCompleteRejectsASystemOnlyTranscriptLocally`)
     * because the Messages API requires at least one turn. This arm does not,
     * and that asymmetry is deliberate: the `instances` envelope states no
     * such requirement, and
     * `VertexProviderTest::testGoogleModelsStillAcceptAnEmptyTranscript`
     * records the no-guard position for the empty transcript already. The new
     * system-only route is pinned by
     * `VertexProviderTest::testAGoogleSystemMessageOnlyTranscriptYieldsAnEmptyMessagesList`.
     *
     * HALF OF THE BODY THIS METHOD BUILDS NEVER REACHES THE WIRE. OBSERVED,
     * PRE-EXISTING, AND DELIBERATELY NOT FIXED HERE. The `parameters` map
     * below (`temperature`, `maxOutputTokens`) is DISCARDED:
     * {@see defaultPredictor()}'s non-`rawPredict` branch builds its
     * `PredictRequest` with `->setEndpoint()` and
     * `->setInstances(...)` only and never calls `setParameters()`
     * (VertexProvider.php:1276-1282), so the sampling knobs are dropped
     * before the request is sent. This is a SEPARATE defect from the one this
     * method's `context` hoist fixes, it predates that fix, and repairing it
     * is a different step (1.10: reported, not repaired).
     *
     * THE `context` HOIST IS UNAFFECTED, and that is why this note is a note
     * and not a blocker: `context` lives INSIDE `instances`, and
     * {@see toProtobufValues()} (VertexProvider.php:1554-1565) merges each
     * instance from arbitrary JSON via `mergeFromJsonString()`, so any key
     * added to the instance - `context` included - does survive to the wire.
     * Epistemic status: the `setParameters()` absence is MEASURED by reading
     * the seam; that the deployed service would honour `parameters` if it
     * were sent is UNVERIFIED, since nothing here has Vertex credentials.
     *
     * @return array{instances: array<int, array<string, mixed>>, parameters: array<string, mixed>}
     */
    private function googleBody(CompleteRequest $request): array
    {
        $instance = [
            'messages' => $this->formatMessages($this->withoutSystemMessages($request->messages)),
        ];

        $context = $this->systemInstruction($request);
        if ($context !== null) {
            $instance['context'] = $context;
        }

        return [
            'instances' => [$instance],
            'parameters' => [
                'temperature' => $request->temperature ?? self::DEFAULT_TEMPERATURE,
                'maxOutputTokens' => $request->maxTokens ?? self::DEFAULT_MAX_TOKENS,
            ],
        ];
    }

    /**
     * A SystemMessage hoisted into `context` must not ALSO stay in
     * `messages`, where {@see formatMessages()}'s `default => 'user'` arm
     * would render it a second time as a user turn.
     *
     * Deliberately a second copy of {@see BedrockProvider::withoutSystemMessages()}
     * rather than a shared trait: the only honest home for one copy is a new
     * file under `src/Providers/Concerns/`, and adding a file to `src/` reds
     * four exact-cardinality assertions in BuiltInToolCorpusTest plus a
     * doc-block in RepoMapBlock.php - out of this step's declared scope. The
     * two bodies are byte-identical today, and NOTHING IN THE SUITE WOULD
     * NOTICE IF THEY DRIFTED - nor even if this `{@see}` stopped naming a real
     * method, which is stronger than the weaker claim that used to stand here.
     * That claim was "`DuplicatedTestHelperDriftTest` reads `tests/` only";
     * true, but not the binding limit. MEASURED 2026-08-29: replacing
     * `BedrockProvider::withoutSystemMessages()` above with a method name that
     * does not exist leaves `SymbolCitationDriftTest` GREEN. Reproduce with
     * `vendor/bin/phpunit tests/SymbolCitationDriftTest.php`, mutated and
     * unmutated; the VERDICT is the durable fact and the command is how you
     * re-derive it. (A literal `OK (7 tests, 2924 assertions)` used to stand
     * here. Rule 42, corrected not deleted: that figure was already stale when
     * written - it is the count at `master` and at this branch's first commit,
     * taken before the second commit added citations to the matrix test, and
     * the count at this commit is different again. Per 16.2 a number no test
     * derives rots, so the figure is dropped rather than re-pinned at a value
     * that would rot the same way.) The census stays green either way because
     * it only validates a
     * citation whose target contains `SugarCraft\Crush\Tests\` or whose class
     * short-name ends in `Test` (`SymbolCitationDriftTest.php:343-354`, the
     * `looksLikeATestSymbol()` alphabet) - and every `{@see}` in this
     * paragraph names a PRODUCTION symbol, which that file states is
     * deliberately out of its scope. So the drift guard here is this sentence
     * and a reader, not an instrument.
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
        // `predict` answers with a list of predictions; the seam hands the
        // first one over already unwrapped, but a caller that passes the whole
        // envelope through is tolerated.
        if (isset($data['predictions'][0]) && is_array($data['predictions'][0])) {
            $data = $data['predictions'][0];
        }

        return new CompleteResponse(
            content: $data['content'] ?? $data['text'] ?? '',
            reasoning: $data['reasoning'] ?? null,
            toolCalls: null,
            tokensUsed: 0,
            costUsd: 0.0,
        );
    }

    // -------------------------------------------------------------------------
    // Default network seams
    // -------------------------------------------------------------------------

    /**
     * Default unary seam: performs a real Vertex AI call.
     *
     * Lazily constructs the SDK client on first use so unit tests that inject
     * their own predictor never touch the Google client (which is `final` and
     * needs credentials).
     *
     * `$client` is the one seam THIS closure has, and it exists because the
     * RPC invocations below are the only place {@see callOptions()} is ever
     * handed to the SDK - a test that re-derives the GAX stack by hand proves
     * nothing about whether these call sites still pass it. Nothing in
     * production supplies it; when it is null {@see sdkClient()} builds the
     * real, credential-bound client per call exactly as before.
     *
     * @return \Closure(string, string, array<string, mixed>): array<string, mixed>
     */
    private static function defaultPredictor(string $location, ?object $client = null): \Closure
    {
        return static function (string $endpoint, string $method, array $body) use ($location, $client): array {
            $client ??= self::sdkClient($location);

            if ($method === self::METHOD_RAW_PREDICT) {
                $requestClass = 'Google\\Cloud\\AIPlatform\\V1\\RawPredictRequest';
                self::requireClasses($requestClass);

                /** @var object $req */
                $req = (new $requestClass())
                    ->setEndpoint($endpoint)
                    ->setHttpBody(self::httpBody($body));

                // rawPredict answers with an HttpBody whose data is the native
                // Anthropic response document, verbatim.
                $response = $client->rawPredict($req, self::callOptions());

                return json_decode((string) $response->getData(), true) ?? [];
            }

            $requestClass = 'Google\\Cloud\\AIPlatform\\V1\\PredictRequest';
            self::requireClasses($requestClass);

            /** @var object $req */
            $req = (new $requestClass())
                ->setEndpoint($endpoint)
                ->setInstances(self::toProtobufValues($body['instances'] ?? []));

            $response = $client->predict($req, self::callOptions());

            foreach ($response->getPredictions() as $prediction) {
                // Protobuf Value -> associative array.
                return json_decode($prediction->serializeToJsonString(), true) ?? [];
            }

            return [];
        };
    }

    /**
     * Default streaming seam: `:streamRawPredict`, whose chunks are Anthropic
     * SSE frames. The frames are reassembled here (a single event can straddle
     * two chunks) and yielded already decoded.
     *
     * `$client` is the same call-site test seam {@see defaultPredictor()}
     * documents - and it matters most here, since this is the RPC whose 60s
     * cap would bound a WHOLE STREAM.
     *
     * @return \Closure(string, string, array<string, mixed>): \Generator<int, array<string, mixed>>
     */
    private static function defaultStreamer(string $location, ?object $client = null): \Closure
    {
        return static function (string $endpoint, string $method, array $body) use ($location, $client): \Generator {
            $requestClass = 'Google\\Cloud\\AIPlatform\\V1\\StreamRawPredictRequest';
            self::requireClasses($requestClass);

            $client ??= self::sdkClient($location);

            /** @var object $req */
            $req = (new $requestClass())
                ->setEndpoint($endpoint)
                ->setHttpBody(self::httpBody($body));

            // Only the RPC itself stays in the closure; the framing runs in
            // decodeSseStream(), which takes raw byte chunks and so can be
            // driven offline with real assertions.
            yield from self::decodeSseStream(
                (static function () use ($client, $req): \Generator {
                    foreach ($client->streamRawPredict($req, self::callOptions())->readAll() as $chunk) {
                        yield (string) $chunk->getData();
                    }
                })()
            );
        };
    }

    /**
     * Reassembles a sequence of raw SSE byte chunks into decoded events.
     *
     * A single event can straddle two chunks, so the partial line is carried
     * across in `$buffer`; and the stream is DRAINED at the end, because
     * {@see decodeSseEvents()} only emits on a newline - a server that closes
     * straight after its last `data:` line would otherwise leave that event
     * stranded in the buffer, silently discarded when this generator returns.
     *
     * @param iterable<int, string> $chunks
     * @return \Generator<int, array<string, mixed>>
     */
    private static function decodeSseStream(iterable $chunks): \Generator
    {
        $buffer = '';

        foreach ($chunks as $chunk) {
            yield from self::decodeSseEvents($chunk, $buffer);
        }

        yield from self::flushSseBuffer($buffer);
    }

    /**
     * Splits an Anthropic SSE byte chunk into decoded events, carrying any
     * partial trailing line over in `$buffer` for the next chunk.
     *
     * The `event:` lines are ignored on purpose - the payload repeats the
     * event name in its own `type` field, which is what
     * {@see parseAnthropicChunk()} dispatches on.
     *
     * KNOWN LIMITATION: the SSE spec allows one event to carry several `data:`
     * lines that the client concatenates with "\n". This decoder treats every
     * `data:` line as a complete payload of its own, so such an event would be
     * dropped as undecodable JSON. Anthropic never emits multi-line `data:` -
     * each event is a single JSON document on one line - so nothing today hits
     * it, and folding lines would need an event-boundary state machine
     * (blank-line delimited) rather than the line loop below.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private static function decodeSseEvents(string $chunk, string &$buffer): \Generator
    {
        $buffer .= $chunk;

        while (($newline = strpos($buffer, "\n")) !== false) {
            $line = trim(substr($buffer, 0, $newline));
            $buffer = substr($buffer, $newline + 1);

            if (!str_starts_with($line, 'data:')) {
                continue;
            }

            $payload = trim(substr($line, 5));
            if ($payload === '' || $payload === '[DONE]') {
                continue;
            }

            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                yield $decoded;
            }
        }
    }

    /**
     * End-of-stream drain for {@see decodeSseEvents()}.
     *
     * A well-behaved SSE server terminates its last event with "\n\n", but
     * nothing guarantees it - a connection closed straight after the final
     * `data:` line leaves that event sitting unread in `$buffer`. Terminating
     * the residue and re-entering the same decoder is deliberate: the trailing
     * event then goes through the identical `data:` / `[DONE]` / JSON handling
     * as every other, rather than a second parallel parse.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private static function flushSseBuffer(string &$buffer): \Generator
    {
        yield from self::decodeSseEvents("\n", $buffer);
    }

    /**
     * Per-call options for every PredictionService RPC this class makes.
     *
     * WHY THIS EXISTS: THE SDK SHIPS A 60s TOTAL REQUEST TIMEOUT
     * ----------------------------------------------------------
     * Not configuring a timeout does not mean there isn't one. The GAPIC
     * client config `prediction_service_client_config.json` sets
     * `timeout_millis: 60000` on `Predict`, `RawPredict` AND
     * `StreamRawPredict`; `RetrySettings::load()` folds that into
     * `noRetriesRpcTimeoutMillis`, and `RetryMiddleware::__invoke()` stamps it
     * onto every call as `timeoutMillis` unless the option is already set.
     * `RestTransport::getCallOptions()` then divides it by 1000 into Guzzle's
     * `timeout` - a TOTAL request timeout - and `GrpcTransport` turns it into a
     * call deadline. So the default really is: abort any completion at 60s,
     * and on `streamRawPredict` abort the WHOLE STREAM at 60s, truncating a
     * long agentic turn mid-answer. That is never acceptable for an LLM call;
     * a 40k-token transcript at `max_tokens: 8192` routinely runs past it.
     *
     * WHY THE RETRY SETTINGS AND NOT `timeoutMillis`
     * ----------------------------------------------
     * The obvious lever - passing `['timeoutMillis' => 0]`, since
     * RetryMiddleware's guard is `!isset($options['timeoutMillis'])` - is
     * transport-dependent and dangerous. Under REST, 0 reaches Guzzle as
     * `timeout: 0`, which curl reads as "no timeout": correct. Under gRPC the
     * SAME 0 becomes `timeout: 0` MICROseconds, i.e. a deadline of *now* -
     * every call would fail instantly. And no finite value is defensible
     * either: any cap is a blanket total-request timeout by another name.
     *
     * Zeroing the two RetrySettings timeouts instead makes RetryMiddleware
     * take NEITHER branch, so `timeoutMillis` stays null and no transport ever
     * emits a timeout key at all - Guzzle falls back to its own default of no
     * timeout, gRPC to `Timeval::infFuture()`. Correct on both transports, and
     * it is the absence of a deadline rather than a very large one.
     *
     * BOTH fields have to be zeroed. `RawPredict`/`Predict` carry retry names
     * in the config so they get `initialRpcTimeoutMillis: 0` from
     * `no_retry_params`, but `StreamRawPredict` has none and is built from
     * `RetrySettings::constructDefault()`, whose `initialRpcTimeoutMillis` is
     * 20000. Zeroing only `noRetriesRpcTimeoutMillis` would drop the stream
     * from a 60s cap to a 20s one - worse than leaving it alone.
     *
     * A CONNECT timeout is a different thing and is still set: it bounds only
     * TCP/TLS establishment, which either succeeds in seconds or is not going
     * to. It rides through `transportOptions.restOptions`, which
     * `RestTransport::getCallOptions()` seeds its Guzzle options from, and the
     * value comes from {@see HttpClientDefaults::connectTimeoutSeconds()} so
     * this provider moves with the rest of the library (and with
     * `SUGARCRUSH_CONNECT_TIMEOUT`) instead of pinning its own number. gRPC has
     * no per-call equivalent (it uses channel backoff), so the key is simply
     * inert there.
     *
     * @return array<string, mixed>
     */
    private static function callOptions(): array
    {
        return [
            'retrySettings' => [
                'noRetriesRpcTimeoutMillis' => 0,
                'initialRpcTimeoutMillis' => 0,
            ],
            'transportOptions' => [
                'restOptions' => ['connect_timeout' => self::connectTimeoutSeconds()],
            ],
        ];
    }

    /**
     * Builds the regional PredictionServiceClient.
     *
     * `apiEndpoint` is the load-bearing option: publisher-model predictions
     * are served by `{location}-aiplatform.googleapis.com`, never by the
     * global default the client would otherwise use. `projectId` - which this
     * used to pass - is not a key `ClientOptions::fromArray()` reads at all,
     * so it was dropped on the floor; the project is already carried in the
     * endpoint resource name.
     *
     * Credentials are deliberately left to the SDK's own ADC chain
     * (`GOOGLE_APPLICATION_CREDENTIALS` -> gcloud config -> metadata server),
     * the same chain every other Google tool on the box resolves through.
     *
     * NO TIMEOUT IS SET HERE ON PURPOSE - and that is not the same as there
     * being none. The SDK's own client config imposes a 60s TOTAL request
     * timeout on `Predict`, `RawPredict` and `StreamRawPredict`; it is
     * overridden per call in {@see callOptions()}, which is also where the
     * mechanism and the reasoning live. Nothing about client construction can
     * turn it off, so do not look for it here.
     */
    private static function sdkClient(string $location): object
    {
        $clientClass = 'Google\\Cloud\\AIPlatform\\V1\\Client\\PredictionServiceClient';
        self::requireClasses($clientClass);

        return new $clientClass([
            'apiEndpoint' => sprintf('%s-aiplatform.googleapis.com', $location),
        ]);
    }

    /**
     * The AIPlatform SDK is a soft dependency of the offline test path - the
     * injected seams never touch it - so its absence has to fail loudly here
     * rather than as an "undefined class" deep inside a closure.
     */
    private static function requireClasses(string ...$classes): void
    {
        foreach ($classes as $class) {
            if (!class_exists($class)) {
                throw new \RuntimeException(
                    'Vertex AI prediction requires google/cloud-ai-platform; inject a predictor for offline use.'
                );
            }
        }
    }

    /**
     * Wraps a request document as the `HttpBody` `rawPredict` takes. The
     * content type matters: Vertex routes the body to the publisher verbatim.
     *
     * @param array<string, mixed> $body
     */
    private static function httpBody(array $body): object
    {
        $bodyClass = 'Google\\Api\\HttpBody';
        self::requireClasses($bodyClass);

        /** @var object $httpBody */
        $httpBody = new $bodyClass();
        $httpBody->setContentType('application/json');
        $httpBody->setData((string) json_encode($body));

        return $httpBody;
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
