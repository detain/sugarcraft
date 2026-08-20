<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

/**
 * Provider-agnostic completion request.
 *
 * The sampling fields below (`topP`/`topK`/`minP`/`repetitionPenalty`/`stop`)
 * and `extraTemplateKwargs` exist because SGLang's `/v1/chat/completions`
 * route accepts a wider sampling surface than plain OpenAI does, and
 * MiniMax-M2.7 in particular needs `repetition_penalty`/`min_p` to stay
 * coherent across the long agentic tool-loop transcripts sugar-crush
 * produces (crush_feat.md §12 D4). Every field is optional and defaults to
 * null so a provider that has no equivalent knob simply drops it.
 *
 * SAMPLING has no config surface in sugar-crush yet, and no step in the
 * current plan claims one - the pre-existing `temperature`/`maxTokens` fields
 * are in exactly the same position, left unset by every in-tree construction
 * site. Until some step wires them to config, all of those stay
 * caller-supplied knobs that only reach a request when something constructs
 * this DTO with them directly.
 *
 * `$reasoningEffort` is the ONE field on this DTO that is NOT in that
 * position, and the distinction is the point: it has a config surface today -
 * the `reasoningEffort` key on an `sglang` provider block, read by
 * {@see ProviderFactory::createSglang()} - so it reaches a live request even
 * though every in-tree construction site leaves this parameter null. Do not
 * read the paragraph above as covering it.
 *
 * NOTE that `temperature` and `topP` are ALSO no longer purely
 * caller-supplied on the SGLang path, for a different reason: leaving them
 * null there now selects a MODEL-DERIVED default rather than the single
 * hardcoded 0.7 it used to ({@see SglangProvider::defaultTemperature()}).
 * That is a provider-side default, not a config surface.
 *
 * Mirrors SGLang's `SamplingParams` naming (docs.sglang.io sampling_params).
 */
final readonly class CompleteRequest
{
    /**
     * @param array<mixed>          $messages
     * @param ?array<mixed>         $tools
     * @param string|array|null     $jsonSchema        JSON-Schema for constrained decoding, as a schema array or a pre-encoded JSON string.
     * @param ?float                $topP              Nucleus-sampling mass.
     * @param ?int                  $topK              Top-k candidate cutoff (SGLang extension; -1 disables server-side).
     * @param ?float                $minP              Minimum token probability relative to the top token (SGLang extension).
     * @param ?float                $repetitionPenalty Multiplicative penalty on already-emitted tokens (SGLang extension).
     * @param string|array|null     $stop              Stop string, or list of stop strings.
     * @param ?array<string, mixed> $extraTemplateKwargs Passthrough into the server-side Jinja chat template, e.g. `['enable_thinking' => true]`.
     * @param string|float|null    $reasoningEffort   How much thinking the server should budget for this request. See below.
     */
    public function __construct(
        public string $model,
        public array $messages,
        public ?array $tools = null,
        public ?string $systemPrompt = null,
        public ?float $temperature = null,
        public ?int $maxTokens = null,
        public string|array|null $jsonSchema = null,
        public ?float $topP = null,
        public ?int $topK = null,
        public ?float $minP = null,
        public ?float $repetitionPenalty = null,
        public string|array|null $stop = null,
        public ?array $extraTemplateKwargs = null,
        /**
         * SGLang's top-level `reasoning_effort` field.
         *
         * A LEVEL NAME or a FLOAT, and both halves of that are the deployed
         * server's own vocabulary rather than any model card's: probed against
         * skynet2 (SGLang, `deepseek-ai/DeepSeek-V4-Flash-0731`) on 2026-08-20,
         * an unknown value is rejected with TWO pydantic errors naming both
         * accepted shapes -
         * `literal['none','minimal','low','medium','high','xhigh','max']` and a
         * `constrained-float`. The DeepSeek-V4-Flash card documents only
         * `low`/`high`/`max`; that is a RECOMMENDATION, and narrowing this
         * field to those three would refuse four values the server serves.
         *
         * Null means "let {@see SglangProvider} decide" - which is not the same
         * as "send nothing". Measured on the same deployment, a request with no
         * `reasoning_effort` at all comes back with `reasoning_content: null`,
         * `reasoning_tokens: 0` and the model's thinking written INLINE into
         * `content` ("Okay, let's break it down carefully..." for a riddle
         * prompt), so an absent effort actively pollutes the assistant text.
         * That is why the provider substitutes a model-derived default rather
         * than passing null through - see
         * {@see SglangProvider::resolveReasoningEffort()}.
         *
         * DOMAIN: `reasoning_effort` is an SGLang `/v1/chat/completions` field.
         * {@see SglangProvider} is the only provider in this repo that reads
         * it; every other provider ignores it, exactly as they ignore
         * `$minP`/`$topK`.
         */
        public string|float|null $reasoningEffort = null,
    ) {}
}
