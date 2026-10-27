<?php

declare(strict_types=1);

namespace SugarCraft\Code\Providers;

/**
 * PRV-2 (§6.5): a provider that READS {@see CompleteRequest::$reasoningEffort}
 * and turns it into a real wire control — a `reasoning_effort` string
 * (OpenAI, SGLang), a thinking-token budget ({@see EffortBudget}) on the
 * Anthropic Messages arms or the derived Gemini `thinkingConfig`.
 *
 * C-PRV-1: the marker became a PREDICATE. "This provider shapes effort"
 * was never the whole truth — OpenAI shapes it for o-series and gpt-5
 * (non-chat) ids only, Anthropic's family only gained extended thinking
 * at claude-3-7, a Bedrock account running llama has no thinking field to
 * set. A boolean on the class let `/effort high` claim success on
 * `gpt-4o` while the wire silently dropped the tier: the set-but-ignored
 * failure PRV-2 itself bans, wearing a per-model mask. `shapesEffortFor()`
 * answers the question the command actually asks — does THIS provider put
 * the tier on the wire for THIS model — and {@see EffortGate::verdict()}
 * is the one place the answer is read.
 *
 * The roster is still the refusal's complement, and the roster test still
 * pins it to the implementations: a provider that implements the marker
 * but whose predicate lies per model is the pre-C-PRV-1 failure again.
 *
 * Deliberately NOT shapers, now only: `custom` (an operator-defined
 * endpoint: the app cannot know which of its extensions reads the field,
 * so `/effort` refuses rather than implying a guarantee) and the offline
 * providers (`echo` draws nothing). The former refusals joined the roster
 * with real wire controls: `bedrock` spends the tier as Converse
 * `additionalModelRequestFields.thinking` on its Claude-family ids (§6.5's
 * "forking wire work" fork, taken), and `claude-code` plus `codex` forward
 * it as the CLI flag/config override their own binaries advertise —
 * `--effort <level>` and `-c model_reasoning_effort=<level>`, each read
 * off the installed CLI rather than guessed.
 *
 * This is a SugarCraft architecture interface, not a port: no
 * `Mirrors charmbracelet/...` citation attaches to it.
 */
interface ShapesReasoningEffort
{
    /**
     * Does this provider send the effort tier on the wire for this model id?
     *
     * The predicate is about the provider's CAPABILITY for the named model,
     * never about whether a tier is currently set: an unset tier is simply
     * never sent by anyone, and that is the `unset` phrase in the status
     * line, not a refusal.
     */
    public function shapesEffortFor(string $model): bool;
}
