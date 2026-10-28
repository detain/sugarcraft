<?php

declare(strict_types=1);

namespace SugarCraft\Code\Providers\Concerns;

use SugarCraft\Code\Messages\AssistantMessage;
use SugarCraft\Code\Messages\SystemMessage;
use SugarCraft\Code\Messages\ToolResultMessage;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Providers\AttachmentEncoding;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\SseData;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolCall;

/**
 * The OpenAI Responses wire (`POST {base}/responses`) as the sibling arm of
 * the chat-completions SDK path — C-PRV-2.a.
 *
 * WHY THIS IS A TRAIT AND NOT A SECOND PROVIDER: the arm shares every
 * billing, pricing, cache-bucket and effort-shaping seam the chat arm
 * already owns ({@see \SugarCraft\Code\Providers\OpenAIProvider::parseUsage()},
 * {@see \SugarCraft\Code\Providers\OpenAIProvider::calculateCost()},
 * {@see \SugarCraft\Code\Providers\OpenAIProvider::shapesEffortFor()}). A
 * second provider class would fork that arithmetic a second time — the
 * same reason {@see \SugarCraft\Code\Providers\Runtime}'s usage fold rides
 * the one `Usage` type. The config block's `api` key picks the arm at
 * construction; both live in one object.
 *
 * TRANSPORT: raw Guzzle through {@see HttpClientDefaults} (connect-bound,
 * stream-bounded, and emphatically NO total request timeout — E646), the
 * same shape SglangProvider/CustomProvider ship, NOT the openai-php typed
 * client: the SDK's `Responses` surface did not exist when this repo's
 * pinned client version was cut, and the chat arm's DTO caveat (its
 * `CreateResponseMessage` silently drops `reasoning_content`) is exactly
 * the class of seam a raw JSON wire avoids — here the `reasoning` summary
 * items are read whole.
 *
 * MEASURED VS NOT: the request body, the `store:false` law (a candy-code
 * session never server-stores its transcript), the flat tool spelling,
 * the usage fold and every SSE event shape below are pinned by
 * `tests/Providers/OpenAiResponsesWireTest.php` against recorded fixtures.
 * None of it has been checked against a live `/v1/responses` endpoint
 * (no key in this environment); the event vocabulary follows OpenAI's
 * published Responses-streaming names.
 *
 * @see https://platform.openai.com/docs/api-guide/responses The wire this mirrors.
 */
trait OpenAiResponsesWire
{
    use HttpClientDefaults;

    /**
     * The one request-body builder for both arms (batch and stream share
     * it byte-for-byte, mirroring the chat arm's `buildParams` law).
     *
     * Deviations from the chat arm, each deliberate:
     *  - `temperature` rides ONLY when the request carries one explicitly.
     *    The chat arm's historical `?? 0.7` is a pinned legacy behaviour
     *    its tests freeze; a brand-new wire must not inherit a silent
     *    sampling override the operator never asked for — the Responses
     *    default applies untouched otherwise.
     *  - reasoning lives in `reasoning.effort`, gated by the SAME
     *    {@see \SugarCraft\Code\Providers\OpenAIProvider::shapesEffortFor()}
     *    predicate the chat arm gates `reasoning_effort` with (C-PRV-1.a),
     *    through the same `max`/`xhigh` → `high` round-down.
     *  - `max_output_tokens` is the Responses spelling of the chat arm's
     *    `max_tokens`/`max_completion_tokens` pair — one name, both
     *    families, no 400 trap (ISS-005 cannot recur here).
     *
     * @return array<string, mixed>
     */
    private function responsesBody(CompleteRequest $request): array
    {
        $body = [
            'model' => $request->model,
            'input' => $this->responsesInput($request),
            // Sessions are replayed from our own store on every step; a
            // server-side stored response is a privacy leak and a
            // duplicate bill of the same prompt. The chat arm never sets
            // `store` because chat completions never stored by default;
            // the Responses wire DOES unless this line ships.
            'store' => false,
            'max_output_tokens' => $request->maxTokens ?? 4096,
        ];

        if ($request->systemPrompt !== null) {
            $body['instructions'] = $request->systemPrompt;
        }

        if ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }

        if ($request->tools !== null) {
            $body['tools'] = $this->responsesTools($request->tools);
        }

        if ($request->reasoningEffort !== null && $this->shapesEffortFor($request->model)) {
            $body['reasoning'] = ['effort' => self::openAiReasoningEffort($request->reasoningEffort)];
        }

        return $body;
    }

    /**
     * Transcript → Responses `input` items. The chat arm's single
     * `messages` array forks here into three item types: a plain
     * conversation turn, a call the assistant made, and an output we are
     * handing back for it — the shape that lets `function_call` records
     * replay without the chat wire's `tool_call_id` indirection.
     *
     * @return array<array<string, mixed>>
     */
    private function responsesInput(CompleteRequest $request): array
    {
        $items = [];

        foreach ($request->messages as $msg) {
            if ($msg instanceof ToolResultMessage) {
                $items[] = [
                    'type' => 'function_call_output',
                    'call_id' => $msg->toolCallId(),
                    'output' => $msg->content(),
                ];

                continue;
            }

            if ($msg instanceof AssistantMessage) {
                // The call RECORDS come first: a turn that thought, then
                // called, then (after outputs) answered must replay in
                // generation order, and the Responses wire wants
                // function_call items as siblings of the message, not
                // fields inside it.
                foreach ($msg->toolCalls() ?? [] as $call) {
                    $items[] = [
                        'type' => 'function_call',
                        'call_id' => $call->id(),
                        'name' => $call->name(),
                        // Replay verbatim what the model wrote (Audit A23's
                        // law, same rawArguments carrier as the chat arm);
                        // a call built in-process without a raw string
                        // re-encodes its array.
                        'arguments' => $call->rawArguments()
                            ?? json_encode($call->arguments(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ];
                }

                if ($msg->content() !== '') {
                    $items[] = [
                        'type' => 'message',
                        'role' => 'assistant',
                        'content' => [[
                            'type' => 'output_text',
                            'text' => $msg->content(),
                        ]],
                    ];
                }

                continue;
            }

            // Mirrors formatMessages' arms exactly: openAiContent() is typed
            // to UserMessage, mid-transcript system notes ride role system,
            // and anything unrecognised falls to the chat arm's `user` +
            // plain content() default.
            $content = match (true) {
                $msg instanceof UserMessage => AttachmentEncoding::openAiContent($msg),
                $msg instanceof SystemMessage => $msg->content(),
                default => $msg->content(),
            };

            $items[] = [
                'type' => 'message',
                'role' => $msg instanceof SystemMessage ? 'system' : 'user',
                'content' => $this->responsesContentParts($content),
            ];
        }

        return $items;
    }

    /**
     * Chat-shape content (a string, or `text`/`image_url` parts from
     * {@see AttachmentEncoding::openAiContent()}) → Responses input parts.
     * The Responses wire names them `input_text`/`input_image`; guessing
     * that mapping wrong is a 400, so the conversion is one function and
     * the fixture pins it.
     *
     * @param string|array<array<string, mixed>> $content
     *
     * @return array<array<string, mixed>>
     */
    private function responsesContentParts(string|array $content): array
    {
        if (is_string($content)) {
            return [['type' => 'input_text', 'text' => $content]];
        }

        $parts = [];
        foreach ($content as $part) {
            if (($part['type'] ?? null) === 'image_url') {
                $parts[] = [
                    'type' => 'input_image',
                    'image_url' => $part['image_url']['url'] ?? '',
                ];

                continue;
            }

            $parts[] = ['type' => 'input_text', 'text' => (string) ($part['text'] ?? '')];
        }

        return $parts;
    }

    /**
     * Tools FLAT: the Responses wire moved the function name/description/
     * parameters out of a nested `function` object onto the tool itself.
     * Reusing the chat arm's {@see
     * \SugarCraft\Code\Providers\OpenAIProvider::formatTools()} here would
     * send the nested spelling and 400 — the schema normalisation still
     * runs through the same `normalizeToolSchema()` seam.
     *
     * @param array<Tool> $tools
     *
     * @return array<array<string, mixed>>
     */
    private function responsesTools(array $tools): array
    {
        return array_map(function (Tool $tool): array {
            return [
                'type' => 'function',
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $this->normalizeToolSchema($tool->inputSchema()),
            ];
        }, $tools);
    }

    /**
     * One batch `POST {base}/responses`.
     */
    private function responsesComplete(CompleteRequest $request): CompleteResponse
    {
        try {
            $response = $this->responsesClient()->post(
                $this->responsesEndpoint(),
                ['json' => $this->responsesBody($request)]
                    + ['headers' => $this->responsesHeaders()]
                    + self::heartbeatOptions($request->onHeartbeat)
            );
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            throw self::responsesFailure($e);
        }

        $decoded = json_decode((string) $response->getBody()->getContents(), true);

        return $this->parseResponsesBody(is_array($decoded) ? $decoded : [], $request->model);
    }

    /**
     * The streaming arm: same body, `stream:true`, and the documented
     * Responses SSE vocabulary —
     *   `response.output_text.delta`            → content delta
     *   `response.output_item.added/done`       → function_call bookends
     *   `response.function_call_arguments.delta`/`.done` → argument reassembly
     *   `response.completed`                    → the usage carrier frame
     *
     * Locals, never properties (the owner class is readonly): the argument
     * reassembly buffer lives exactly one generator call, the same law the
     * chat arm's `$toolCallBuffer` records.
     *
     * @return \Generator<int, CompleteResponse>
     */
    private function responsesCompleteStream(CompleteRequest $request): \Generator
    {
        $body = $this->responsesBody($request);
        $body['stream'] = true;

        try {
            $response = $this->responsesClient()->post(
                $this->responsesEndpoint(),
                ['json' => $body, 'stream' => true]
                    + ['headers' => $this->responsesHeaders()]
                    + self::heartbeatOptions($request->onHeartbeat)
            );
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            throw self::responsesFailure($e);
        }

        if ($request->onHeartbeat !== null) {
            try {
                ($request->onHeartbeat)();
            } catch (\Throwable) {
                // Fail-soft by contract - see CompleteRequest::$onHeartbeat.
            }
        }

        // item_id => ['call_id'=>..., 'name'=>..., 'arguments'=>string]
        $pendingCalls = [];
        $usageFrame = null;
        $incomplete = false;

        $stream = $response->getBody();
        $buffer = '';
        $eventName = null;

        foreach (self::sseLines($stream, $buffer) as $line) {
            if ($line === '') {
                $eventName = null;

                continue;
            }

            if (str_starts_with($line, 'event:')) {
                $eventName = trim(substr($line, strlen('event:')));

                continue;
            }

            $payload = SseData::value($line);
            if ($payload === null || $payload === '[DONE]') {
                continue;
            }

            $event = json_decode($payload, true);
            if (!is_array($event)) {
                continue;
            }

            $type = $eventName ?? (is_string($event['type'] ?? null) ? $event['type'] : '');

            switch ($type) {
                case 'response.output_text.delta':
                    $delta = is_string($event['delta'] ?? null) ? $event['delta'] : '';
                    if ($delta !== '') {
                        yield new CompleteResponse(content: $delta);
                    }

                    break;

                case 'response.output_item.added':
                    $item = is_array($event['item'] ?? null) ? $event['item'] : [];
                    if (($item['type'] ?? null) === 'function_call') {
                        $pendingCalls[(string) ($item['id'] ?? '')] = [
                            'call_id' => (string) ($item['call_id'] ?? ''),
                            'name' => (string) ($item['name'] ?? ''),
                            'arguments' => (string) ($item['arguments'] ?? ''),
                        ];
                    }

                    break;

                case 'response.function_call_arguments.delta':
                    $key = (string) ($event['item_id'] ?? '');
                    if (isset($pendingCalls[$key])) {
                        $pendingCalls[$key]['arguments'] .= is_string($event['delta'] ?? null) ? $event['delta'] : '';
                    }

                    break;

                case 'response.function_call_arguments.done':
                    $key = (string) ($event['item_id'] ?? '');
                    if (isset($pendingCalls[$key]) && is_string($event['arguments'] ?? null)) {
                        $pendingCalls[$key]['arguments'] = $event['arguments'];
                    }

                    break;

                case 'response.output_item.done':
                    $item = is_array($event['item'] ?? null) ? $event['item'] : [];
                    if (($item['type'] ?? null) !== 'function_call') {
                        break;
                    }

                    $key = (string) ($item['id'] ?? '');
                    // The done frame's own `arguments` wins over whatever
                    // was reassembled; a stream that sent no arguments.done
                    // still lands the full string here.
                    $raw = is_string($item['arguments'] ?? null)
                        ? $item['arguments']
                        : ($pendingCalls[$key]['arguments'] ?? '');
                    $callId = is_string($item['call_id'] ?? null) && $item['call_id'] !== ''
                        ? $item['call_id']
                        : ($pendingCalls[$key]['call_id'] ?? '');
                    $name = is_string($item['name'] ?? null) && $item['name'] !== ''
                        ? $item['name']
                        : ($pendingCalls[$key]['name'] ?? '');
                    unset($pendingCalls[$key]);

                    yield new CompleteResponse(
                        content: '',
                        toolCalls: [self::responsesToolCall($callId, $name, $raw)],
                    );

                    break;

                case 'response.incomplete':
                    $incomplete = true;
                    // no break: an incomplete frame carries the same
                    // response document, usage included.
                case 'response.completed':
                    $doc = is_array($event['response'] ?? null) ? $event['response'] : $event;
                    if (is_array($doc['usage'] ?? null)) {
                        $usageFrame = $this->parseUsage(
                            self::foldResponsesUsage($doc['usage']),
                            $request->model
                        );
                    }

                    break;

                case 'response.failed':
                    $error = is_array($event['response']['error'] ?? null) ? $event['response']['error'] : [];
                    throw new \RuntimeException(
                        'OpenAI responses stream failed: '
                        . (is_string($error['message'] ?? null) ? $error['message'] : 'the endpoint reported a failure')
                    );

                case 'error':
                    $error = is_array($event['error'] ?? null) ? $event['error'] : [];
                    throw new \RuntimeException(
                        'OpenAI responses stream error: '
                        . (is_string($error['message'] ?? null) ? $error['message'] : 'the endpoint reported an error')
                    );

                default:
                    // Every other documented event (created/queued/
                    // output_item.added non-call types, reasoning deltas,
                    // and whatever vocabulary OpenAI adds next) carries no
                    // engine-visible payload here; unknown-delta-tolerant
                    // by the same law as the chat arm's chunk parser.
                    break;
            }
        }

        // Calls that never saw their item.done frame were cut short by the
        // stream's end — flush decode-or-drop, the chat arm's audit 15a A4
        // rule, BEFORE the usage carrier so the bill stays last.
        if ($pendingCalls !== []) {
            $calls = [];
            foreach ($pendingCalls as $call) {
                $calls[] = self::responsesToolCall($call['call_id'], $call['name'], $call['arguments']);
            }

            yield new CompleteResponse(
                content: '',
                toolCalls: $calls,
                truncated: $incomplete,
            );
        }

        if ($usageFrame !== null) {
            yield new CompleteResponse(
                content: '',
                tokensUsed: $usageFrame->totalTokens,
                costUsd: $usageFrame->costUsd,
                usage: $usageFrame,
                truncated: $incomplete,
            );
        }

        // E707 flag law, review round 1: the truncated signal must reach
        // the engine even when the `.incomplete` frame carried no usable
        // response document (no usage carrier) and no call was pending to
        // flush it. Without this leg that shape truncates SILENTLY — the
        // flag rode only the usage and flush frames above. A flag-only
        // terminal frame, the shape Sglang's arm ships for a payload-less
        // length stop.
        if ($incomplete && $usageFrame === null && $pendingCalls === []) {
            yield new CompleteResponse(content: '', truncated: true);
        }
    }

    /**
     * Batch-arm decode of one full response document.
     */
    private function parseResponsesBody(array $doc, ?string $pricingModel): CompleteResponse
    {
        $content = '';
        $reasoning = null;
        $toolCalls = null;

        foreach (is_array($doc['output'] ?? null) ? $doc['output'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            switch ($item['type'] ?? null) {
                case 'message':
                    foreach (is_array($item['content'] ?? null) ? $item['content'] : [] as $part) {
                        if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                            $content .= $part['text'];
                        }
                    }

                    break;

                case 'reasoning':
                    // The Responses wire carries thinking as summary_text
                    // parts (public reasoning summaries); the chat arm's
                    // DTO dropped `reasoning_content` whole — this is the
                    // seam the raw JSON arm exists to close.
                    $summary = [];
                    foreach (is_array($item['summary'] ?? null) ? $item['summary'] : [] as $part) {
                        if (is_array($part) && ($part['type'] ?? null) === 'summary_text'
                            && is_string($part['text'] ?? null)) {
                            $summary[] = $part['text'];
                        }
                    }

                    if ($summary !== []) {
                        $reasoning = ($reasoning === null ? '' : $reasoning . "\n") . implode("\n", $summary);
                    }

                    break;

                case 'function_call':
                    $toolCalls ??= [];
                    $toolCalls[] = self::responsesToolCall(
                        (string) ($item['call_id'] ?? ''),
                        (string) ($item['name'] ?? ''),
                        is_string($item['arguments'] ?? null) ? $item['arguments'] : '',
                    );

                    break;

                default:
                    break;
            }
        }

        $status = is_string($doc['status'] ?? null) ? $doc['status'] : '';

        // E707's law on this wire's spelling: the Responses endpoint says
        // `status: incomplete` where the chat wire says `finish_reason:
        // length` — one name for "the reply was cut off before it
        // finished", whatever `incomplete_details.reason` spelled the
        // ceiling. A `failed` batch document still decodes to what it
        // carried (empty content) — Runtime's error prose comes from the
        // HTTP layer, which never raised here.
        $truncated = $status === 'incomplete';

        $usageDoc = is_array($doc['usage'] ?? null) ? $doc['usage'] : [];
        $usage = $this->parseUsage(self::foldResponsesUsage($usageDoc), $pricingModel);

        return new CompleteResponse(
            content: $content,
            reasoning: $reasoning,
            toolCalls: $toolCalls,
            tokensUsed: $usage->totalTokens,
            costUsd: $usage->costUsd,
            usage: $usage,
            truncated: $truncated,
        );
    }

    /**
     * One Responses function call (batch item or reassembled stream item) →
     * the shared ToolCall decode-or-drop doctrine: a scalar payload decodes
     * to `[]` beside an `argumentsError` that Runtime reports, the raw
     * string rides for verbatim replay (A11/A23, identical to the chat
     * arm's {@see
     * \SugarCraft\Code\Providers\OpenAIProvider::parseResponse()}).
     */
    private static function responsesToolCall(string $callId, string $name, string $raw): ToolCall
    {
        $decoded = $raw === '' ? [] : json_decode($raw, true);
        $isArrayPayload = is_array($decoded);
        $error = ToolCall::argumentsErrorFor($raw !== '' ? $raw : null);

        return ToolCall::fromArray([
            'id' => $callId,
            'name' => $name,
            // The chat arm's A11 doctrine verbatim: a payload decoding to
            // a scalar is refused by Runtime via argumentsError, never run
            // as a silently-empty `[]`... and never crashes mid-turn.
            'arguments' => $isArrayPayload ? $decoded : [],
            'argumentsError' => $error,
            // rawArguments rides ONLY when the replay stays byte-exact to
            // what the model wrote (A23) — a scalar payload re-encodes to
            // different bytes on the next turn, and a rejected argument
            // string has no replay duty: the error prose carries it. Same
            // net effect as the chat arm, where scalar `arguments` never
            // reach a rawArguments-bearing constructor path.
            'rawArguments' => $isArrayPayload ? $raw : null,
        ]);
    }

    /**
     * The Responses usage document spelled in the chat wire's names, so
     * one {@see
     * \SugarCraft\Code\Providers\OpenAIProvider::parseUsage()} computes
     * buckets, cached-discount pricing and the `unpricedModel` disclosure
     * for both arms (the two price paths are one path by construction —
     * the exact audit-fix the chat arm's stream billing carries too).
     *
     * `input_tokens` INCLUDES the cached prefix on this wire just as
     * `prompt_tokens` does there (OpenAI bills the cached part inside the
     * prompt count on both endpoints), and
     * `input_tokens_details.cached_tokens` is its counterpart of
     * `prompt_tokens_details.cached_tokens`. There is still no
     * cache-CREATION field on OpenAI's prompt caching — the null that
     * `parseUsage()` records stands.
     *
     * @param array<string, mixed> $usage
     *
     * @return array<string, mixed>
     */
    private static function foldResponsesUsage(array $usage): array
    {
        $folded = [
            'prompt_tokens' => $usage['input_tokens'] ?? null,
            'completion_tokens' => $usage['output_tokens'] ?? null,
            'total_tokens' => $usage['total_tokens'] ?? null,
        ];

        if (is_array($usage['input_tokens_details'] ?? null)) {
            $folded['prompt_tokens_details'] = [
                'cached_tokens' => $usage['input_tokens_details']['cached_tokens'] ?? null,
            ];
        }

        return $folded;
    }

    /**
     * Headers for both arms: the key and organization the factory resolved
     * (config `apiKey`/`organization`), never a second credential read.
     * Keyless against a configured custom base still rides — the same
     * self-hosted-proxy posture {@see
     * \SugarCraft\Code\Providers\OpenAIProvider}'s `askableCatalogBase()`
     * documents; against the public API root the factory always has a key
     * (the `openai` schema row requires `apiKey`).
     *
     * @return array<string, string>
     */
    private function responsesHeaders(): array
    {
        $headers = [];

        if (is_string($this->modelsApiKey ?? null) && $this->modelsApiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->modelsApiKey;
        }

        if (is_string($this->organization ?? null) && $this->organization !== '') {
            $headers['OpenAI-Organization'] = $this->organization;
        }

        return $headers;
    }

    /**
     * The full endpoint URL. The responses arm speaks to the SAME base the
     * chat client was built against (config `baseUrl` / OPENAI_BASE_URL or
     * the SDK default the factory threads as `modelsBaseUrl`); a hand-built
     * provider that left it null falls SILENTLY to that SDK default root —
     * pinned by testNullBaseUrlFallsToTheSdkDefaultRoot. The arm is only
     * reachable at all by config `api`, which the factory always builds
     * with a base, so the silent default never guesses a key-less route.
     */
    private function responsesEndpoint(): string
    {
        $base = is_string($this->modelsBaseUrl ?? null) && trim($this->modelsBaseUrl) !== ''
            ? trim($this->modelsBaseUrl)
            : null;

        // The factory hands SDK_DEFAULT_BASE when the operator set no
        // baseUrl; a hand-built provider with a null base gets the same
        // default rather than a refusal — this arm is only reachable at
        // all by config `api`, which the factory always builds with a base.
        $base ??= self::SDK_DEFAULT_BASE;

        return rtrim($base, '/') . '/responses';
    }

    /**
     * The HTTP client for the raw arm: the injected test seam when set,
     * else the house-bounded Guzzle (connect-bound only, stream-bounded;
     * E646's no-total-timeout law lives in {@see HttpClientDefaults} and
     * is pinned again here by source scan). Built fresh per call —
     * the owner class is readonly, and a `Client` is stateless enough to
     * rebuild where a hung-pool reuse would not be.
     */
    private function responsesClient(): \GuzzleHttp\Client
    {
        return $this->responsesClient ?? self::guzzleClient();
    }

    /**
     * Read an SSE body line by line without loading it whole: the chat arm
     * hands this job to openai-php's streamed responses; the raw arm does
     * it the way SglangProvider's pump does — incremental reads, line
     * boundaries held across reads in `$buffer` passed by reference.
     *
     * @param \Psr\Http\Message\StreamInterface $stream
     *
     * @return \Generator<int, string>
     */
    private static function sseLines(\Psr\Http\Message\StreamInterface $stream, string &$buffer): \Generator
    {
        while (!$stream->eof()) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                continue;
            }

            $buffer .= $chunk;

            // Emit whole lines only; the tail of a partial event waits for
            // the next read.
            while (($nl = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $nl), "\r");
                $buffer = substr($buffer, $nl + 1);
                yield $line;
            }
        }

        if ($buffer !== '') {
            yield rtrim($buffer, "\r");
            $buffer = '';
        }
    }

    /**
     * Transport prose for the raw arm, mirroring the Sglang wrap exactly
     * in shape (server's own `error.message` when the body carries one,
     * code 0, previous chain intact for TransientFailure's walk).
     */
    private static function responsesFailure(\GuzzleHttp\Exception\GuzzleException $e): \RuntimeException
    {
        return new \RuntimeException(
            'OpenAI responses request failed: '
            . (\SugarCraft\Code\Providers\SglangProvider::errorBodyMessage($e) ?? $e->getMessage()),
            0,
            $e
        );
    }
}
