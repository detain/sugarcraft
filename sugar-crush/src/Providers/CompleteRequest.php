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
 * Sampling has no config surface in sugar-crush yet, and no step in the
 * current plan claims one - the pre-existing `temperature`/`maxTokens` fields
 * are in exactly the same position, left unset by every in-tree construction
 * site. Until some step wires them to config, all of these stay
 * caller-supplied knobs that only reach a request when something constructs
 * this DTO with them directly.
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
    ) {}
}
