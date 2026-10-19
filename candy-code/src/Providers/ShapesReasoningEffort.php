<?php

declare(strict_types=1);

namespace SugarCraft\Code\Providers;

/**
 * PRV-2 (§6.5): a provider that READS {@see CompleteRequest::$reasoningEffort}
 * and turns it into a real wire control — a `reasoning_effort` string
 * (OpenAI, SGLang), a thinking-token budget ({@see EffortBudget}) on the
 * Anthropic Messages arms or the derived Gemini `thinkingConfig`.
 *
 * The marker exists for one refusal: `/effort` asks the live backend for
 * it, and a provider that does not implement this is told so loudly
 * instead of storing a tier no wire will ever read. That asymmetry IS
 * the contract — the banned failure mode of PRV-2 is a setting that is
 * set but ignored, so the command surface and the transport surface must
 * agree on exactly this list, and the roster test pins the list to the
 * implementations.
 *
 * Deliberately NOT shapers: `bedrock` (its Converse transport carries no
 * thinking or effort field anywhere in this codebase today — mapping
 * would be forking wire work, §6.5 says refuse loudly instead),
 * `custom` (an operator-defined endpoint: the app cannot know which of
 * its extensions reads the field, so `/effort` refuses rather than
 * implying a guarantee), `claude-code` (the tier belongs to the CLI it
 * shells out to, not to this wire), and the offline providers.
 *
 * This is a SugarCraft architecture interface, not a port: no
 * `Mirrors charmbracelet/...` citation attaches to it.
 */
interface ShapesReasoningEffort
{
}
