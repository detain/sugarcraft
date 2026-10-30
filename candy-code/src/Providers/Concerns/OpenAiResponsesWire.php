<?php

declare(strict_types=1);

namespace SugarCraft\Code\Providers\Concerns;

use SugarCraft\Code\Attachments\FileMentions;
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
 * C-PRV-2.b extends the arm with the two replay legs the Responses
 * vocabulary uniquely allows: encrypted reasoning items round-trip through
 * `include: ["reasoning.encrypted_content"]` (the chat wire has no place to
 * put them), and `function_call_output` accepts a PARTS LIST, so a tool's
 * image rides its own row instead of the chat arm's smuggled follow-up turn
 * (which {@see AnthropicMessagesWire}'s toolResultImage prose rejects on
 * role-alternation grounds — a list-typed output is not affected by that
 * objection).
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
     * The tag stored on replayable reasoning items inside the shared
     * `CompleteResponse::$thinkingBlocks` list. Anthropic's signed blocks
     * keep their wire types verbatim; this arm's items are spelled here so
     * neither wire mistakes the other's blocks — {@see
     * AnthropicMessagesWire} filters them out of its replay by name.
     */
    private const REASONING_BLOCK_TYPE = 'openai_reasoning_item';

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
            $body['reasoning'] = [
                'effort' => self::openAiReasoningEffort($request->reasoningEffort),
                // C-PRV-2.b: ask for the public summary alongside the
                // effort — the batch arm already folds `summary_text` parts
                // into the ticker's reasoning channel, but without this
                // field the endpoint never sends them.
                'summary' => 'auto',
            ];
            // C-PRV-2.b: the opaque encrypted blob is what makes a reasoning
            // item REPLAYABLE (the item reference contract — a follow-up
            // request that returns the item keeps the model's chain of
            // thought across tool turns). Gated with the effort, never on
            // its own: a session that never asks for reasoning keeps its
            // exact pre-C-PRV-2.b bytes, and the prompt-cache prefix of
            // every request in a reasoning session stays byte-stable
            // because this list is a fixed literal, not per-turn state.
            $body['include'] = ['reasoning.encrypted_content'];
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
                $output = $msg->content();
                // C-PRV-2.b: this wire's `output` is a parts list, so the
                // pixels ride the tool row itself — the chat arm's string
                // restriction (and its follow-up-turn workaround) does not
                // apply here. C-TOOL-2.g: the PDF leg joins them the same
                // way — `input_file` IS this API's pasted-document part, so
                // the Read-tool's carried bytes reach the model on a
                // document-capable turn. Media order matches the Anthropic
                // arm's blocks leg (caption, then image, then document);
                // no media keeps the plain-string spelling byte-identical
                // to the pre-C-PRV-2.b arm.
                $image = $this->responsesToolResultImage($msg);
                $document = $this->responsesToolResultDocument($msg);
                if ($image !== null || $document !== null) {
                    $output = [];
                    if ($msg->content() !== '') {
                        $output[] = ['type' => 'input_text', 'text' => $msg->content()];
                    }

                    if ($image !== null) {
                        $output[] = ['type' => 'input_image', 'image_url' => $image];
                    }

                    if ($document !== null) {
                        $output[] = $document;
                    }
                }

                $items[] = [
                    'type' => 'function_call_output',
                    'call_id' => $msg->toolCallId(),
                    'output' => $output,
                ];

                continue;
            }

            if ($msg instanceof AssistantMessage) {
                // C-PRV-2.b: encrypted reasoning items replay FIRST — the
                // generation order the endpoint produced was reasoning,
                // then calls, then (after outputs) the message, and this
                // arm's own tagged blocks are the only ones it re-sends;
                // another provider family's blocks in the same transcript
                // (a hand-merged or future-shared store) stay skipped.
                //
                // FIX-1 (review 1, M1): the replay rides the SAME door the
                // request body gates `reasoning`/`include` with — Anthropic
                // replays thinking only while its live budget hook is on
                // ({@see AnthropicMessagesWire} "only while the budget hook
                // is on"); this arm's live equivalent is shapesEffortFor()
                // on the REQUEST's model, because the Responses surface
                // only names `reasoning` items for the reasoning families
                // that accept them. A mid-session switch onto a text-only
                // model (the store carries items the earlier model
                // collected) re-sent the encrypted items unconditionally —
                // an endpoint 400 on every follow-up turn wedges the
                // transcript. Text-only arm => items stay in the store,
                // off the wire; a later switch back replays them again.
                if ($this->shapesEffortFor($request->model)) {
                    foreach ($msg->thinkingBlocks() as $block) {
                        if (!is_array($block)) {
                            continue;
                        }

                        $replay = self::responsesReasoningReplay($block);
                        if ($replay !== null) {
                            $items[] = $replay;
                        }
                    }
                }

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
     * The one rule for a stored block earning replay on this arm: OUR tag,
     * and both identity fields (id + encrypted_content) present as
     * non-empty strings. A half-item cannot be referenced by the endpoint,
     * and the shape may have been persisted by an older build or hand-edited
     * into the store — the re-validation is the same hostile-blob hygiene the
     * Anthropic arm's signature law applies to its blocks, applied sideways.
     * The summary parts ride only in re-validated wire shape: display text
     * has no chain-of-custody duty, so a malformed part is dropped rather
     * than replayed.
     *
     * @param array<string, mixed> $block
     *
     * @return ?array<string, mixed>
     */
    private static function responsesReasoningReplay(array $block): ?array
    {
        if (($block['type'] ?? null) !== self::REASONING_BLOCK_TYPE) {
            return null;
        }

        $id = $block['id'] ?? null;
        $encrypted = $block['encrypted_content'] ?? null;
        if (!is_string($id) || $id === '' || !is_string($encrypted) || $encrypted === '') {
            return null;
        }

        $item = ['type' => 'reasoning', 'id' => $id, 'encrypted_content' => $encrypted];

        $summary = self::responsesSummaryParts($block['summary'] ?? null);
        if ($summary !== []) {
            $item['summary'] = $summary;
        }

        return $item;
    }

    /**
     * One reasoning wire item (batch output or stream `item.done`) → the
     * tagged stored block, or null when it cannot replay. The collect-side
     * twin of {@see responsesReasoningReplay()}: BOTH require id +
     * encrypted_content, so a block that entered the store can always leave
     * it as the item it came from.
     *
     * @param array<string, mixed> $item
     *
     * @return ?array<string, mixed>
     */
    private static function responsesReasoningBlock(array $item): ?array
    {
        $id = $item['id'] ?? null;
        $encrypted = $item['encrypted_content'] ?? null;
        if (!is_string($id) || $id === '' || !is_string($encrypted) || $encrypted === '') {
            return null;
        }

        $block = [
            'type' => self::REASONING_BLOCK_TYPE,
            'id' => $id,
            'encrypted_content' => $encrypted,
        ];

        $summary = self::responsesSummaryParts($item['summary'] ?? null);
        if ($summary !== []) {
            $block['summary'] = $summary;
        }

        return $block;
    }

    /**
     * Re-validate a hostile-or-benign summary payload down to the wire's
     * own `{type: summary_text, text: string}` parts (anything else in the
     * list is not text this code may persist or replay).
     *
     * @return array<array<string, string>>
     */
    private static function responsesSummaryParts(mixed $parts): array
    {
        if (!is_array($parts)) {
            return [];
        }

        $summary = [];
        foreach ($parts as $part) {
            if (is_array($part) && ($part['type'] ?? null) === 'summary_text'
                && is_string($part['text'] ?? null)) {
                $summary[] = ['type' => 'summary_text', 'text' => $part['text']];
            }
        }

        return $summary;
    }

    /**
     * The using class answers for itself (SHL-3 T2): images ride the tool
     * row only for a vision-capable model. Both OpenAI arms' host already
     * implements it from {@see \SugarCraft\Code\Providers\ProviderInterface};
     * the declaration exists so this trait's call is defined, exactly as
     * {@see AnthropicMessagesWire} declares the same hook for its own arm.
     */
    abstract public function supportsVision(): bool;

    /**
     * Byte budget for a tool-row image, named through the host: PHP forbids
     * direct access to another trait's constant, so the using class answers
     * with the shared ceiling. One budget then covers images on both wires —
     * {@see \SugarCraft\Code\Providers\Concerns\AnthropicMessagesWire::TOOL_RESULT_IMAGE_MAX_BYTES}
     * is the source of truth (it shipped first, SHL-3), and a cross-wire
     * image that fit on one row fits on the other.
     */
    abstract protected function responsesToolResultImageBudget(): int;

    /**
     * Byte budget for a tool-row document, named through the host for the same
     * trait-constant reason as {@see responsesToolResultImageBudget()}: one
     * ceiling covers documents on both wires — {@see
     * \SugarCraft\Code\Providers\Concerns\AnthropicMessagesWire::TOOL_RESULT_DOCUMENT_MAX_BYTES}
     * is the source of truth (C-TOOL-2 shipped it first), and a PDF that fit
     * on one row fits on the other.
     */
    abstract protected function responsesToolResultDocumentBudget(): int;

    /**
     * C-TOOL-2.g: whether the configured model reads a pasted PDF on its own
     * tool row, named through the host. This is deliberately NOT
     * {@see supportsVision()}: the C-TOOL-2.e document gate law says a
     * provider block's vision override buys images (an `image_url` part any
     * chat model tolerates structurally), it does not teach a non-vision
     * deployment to read PDFs — the same refusal this host's
     * supportsDocuments() applies to the chat `file` part, and the reason
     * that method cannot be consulted here: it is arm-gated to the chat
     * spelling of user attachments, and this leg lives on the other arm. The
     * host answers with the vision TABLE for the configured model.
     */
    abstract protected function responsesDocumentModelGate(): bool;

    /**
     * C-PRV-2.b: the pixels a tool carried, as a data URL for this row's
     * `input_image` part. The gate ladder mirrors the Anthropic arm's
     * toolResultImage by DECISION, not by copy-paste habit: every rung is
     * the same judgement about the same untrusted bytes (error rows are
     * text, the model must see, the budget must hold, and the header is
     * re-sniffed so forged payload stays caption text instead of a 400).
     * The size budget comes from {@see responsesToolResultImageBudget()} so
     * one constant budgets images on both wires.
     */
    private function responsesToolResultImage(ToolResultMessage $msg): ?string
    {
        if ($msg->isError() || !$msg->hasImage() || !$this->supportsVision()) {
            return null;
        }

        $bytes = (string) $msg->imageBytes();
        if ($bytes === '' || strlen($bytes) > $this->responsesToolResultImageBudget()) {
            return null;
        }

        $mediaType = FileMentions::sniffImage($bytes);
        if ($mediaType === null) {
            return null;
        }

        // The one shared data-URL spelling ({@see AttachmentEncoding::dataUrl()})
        // — same format image bytes wear on this wire no matter which turn
        // they entered the transcript from.
        return AttachmentEncoding::dataUrl($mediaType, $bytes);
    }

    /**
     * C-TOOL-2.g: the PDF a tool carried, as this row's `input_file` part.
     * The gate ladder mirrors the Anthropic arm's toolResultDocument rung for
     * rung by DECISION — error rows stay text, the model must be able to read
     * the bytes, the shared budget must hold, and the `%PDF-` head is
     * re-sniffed so forged payload stays caption text instead of a 400.
     *
     * Two disclosed shape differences. The capability rung asks
     * {@see responsesDocumentModelGate()}, the host's document-gate law
     * restated for this arm, not the image leg's supportsVision() and not the
     * provider's supportsDocuments() (arm-gated to the chat user-attachment
     * spelling — consulting it here would leave this leg permanently dark,
     * and flipping it would silently reroute the user flow the C-TOOL-2.e
     * ruling keeps declining). And the builder returns the finished part —
     * name hygiene lives in the shared encoder — where the image ladder
     * returns the bare data URL.
     */
    private function responsesToolResultDocument(ToolResultMessage $msg): ?array
    {
        if ($msg->isError() || !$msg->hasDocument() || !$this->responsesDocumentModelGate()) {
            return null;
        }

        $bytes = (string) $msg->documentBytes();
        if ($bytes === '' || strlen($bytes) > $this->responsesToolResultDocumentBudget()) {
            return null;
        }

        $mediaType = FileMentions::sniffDocument($bytes);
        if ($mediaType === null) {
            return null;
        }

        return AttachmentEncoding::responsesInputFile($mediaType, $bytes, (string) $msg->documentName());
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
     *   `response.reasoning_summary_text.delta` → reasoning-summary delta (C-PRV-2.b)
     *   `response.output_item.added/done`       → function_call bookends, and the
     *                                             reasoning item.done carries the
     *                                             encrypted replay block (C-PRV-2.b)
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

                case 'response.reasoning_summary_text.delta':
                    // C-PRV-2.b: the public summary streams on the ticker's
                    // reasoning channel (same carrier the batch fold and the
                    // chat arm's `reasoning_content` use) — display text,
                    // protocol state is NOT smuggled in deltas.
                    $delta = is_string($event['delta'] ?? null) ? $event['delta'] : '';
                    if ($delta !== '') {
                        yield new CompleteResponse(content: '', reasoning: $delta);
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

                    // C-PRV-2.b: a completed reasoning item is the
                    // protocol-state carrier for this stream — collected
                    // with the same both-fields rule as the batch arm and
                    // handed up on its own frame (Runtime folds the list
                    // per-frame before the usage carrier lands, the same
                    // mid-stream carrier the batch return relies on).
                    if (($item['type'] ?? null) === 'reasoning') {
                        $block = self::responsesReasoningBlock($item);
                        if ($block !== null) {
                            yield new CompleteResponse(
                                content: '',
                                thinkingBlocks: [$block],
                            );
                        }

                        break;
                    }

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
                    // output_item.added non-call types, the reasoning
                    // part-boundary and non-summary reasoning deltas,
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
        $thinkingBlocks = [];

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
                    $summary = self::responsesSummaryParts($item['summary'] ?? null);
                    $summaryTexts = array_column($summary, 'text');

                    if ($summaryTexts !== []) {
                        $reasoning = ($reasoning === null ? '' : $reasoning . "\n") . implode("\n", $summaryTexts);
                    }

                    // C-PRV-2.b: the same item is protocol state when it
                    // carries its encrypted blob — collected beside the
                    // display fold so the next turn can reference the model's
                    // own reasoning, not just its summary. No blob, no block:
                    // an unreplayable half-item is not stored (Anthropic's
                    // signature law, restated for this wire).
                    $block = self::responsesReasoningBlock($item);
                    if ($block !== null) {
                        $thinkingBlocks[] = $block;
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
            thinkingBlocks: $thinkingBlocks,
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
     * (the `openai` and `azure` schema rows both require `apiKey`).
     * C-PRV-2.c: an Azure provider (`apiKeyHeader` set) authenticates
     * with the `api-key` header instead — Azure OpenAI accepts that
     * scheme on its OpenAI-compatible endpoint and never sees an
     * `Authorization` header from us, mirroring how the factory builds
     * the chat arm without `withApiKey`.
     *
     * @return array<string, string>
     */
    private function responsesHeaders(): array
    {
        $headers = [];

        if ($this->apiKeyHeader !== null) {
            $headers['api-key'] = $this->apiKeyHeader;
        } elseif (is_string($this->modelsApiKey ?? null) && $this->modelsApiKey !== '') {
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
