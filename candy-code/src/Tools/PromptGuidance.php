<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools;

/**
 * Opt-in declaration that a {@see Tool} carries session-prompt guidance beyond
 * what its `description()` ships (prompt_expand.md §4.17, §9.9 and the §10
 * seam-9 row; plan P9.S1).
 *
 * `description()` is the provider-wire surface: each backend's tool formatter
 * reads it together with `inputSchema()` and puts both in the per-request tool
 * payload, so its bytes are paid for on every call and pinned by an exact-string
 * test corpus. This interface is the OTHER channel — prose for the SYSTEM
 * PROMPT, assembled per session by
 * {@see \SugarCraft\Code\Runtime::systemPromptSections()} out of `$app->tools`
 * — where a tool can explain how it behaves inside this harness without
 * touching the one paragraph its schema already ships.
 *
 * NOT implementing this interface is the safe default and contributes ZERO
 * prompt bytes: the renderer filters on `instanceof`, so an unknown tool, a
 * test double, or a tool with nothing session-wide to say never enters the
 * layer at all — the same silent non-participation as {@see ParallelSafe} and
 * {@see CarriesSessionState}, whose consumer asks "does this tool opt in?"
 * rather than assuming an answer. Returning `''` means the same thing one
 * level down, at the fragment.
 *
 * The return value is the fragment BODY: plain prose, with no fence and no
 * envelope markup — the layer is deliberately unfenced like the base identity
 * it rides behind, and the fence roster is not widened here — and no trailing
 * newline, because the assembler supplies the separators between layers. The
 * renderer joins fragments in `name()` order, never in registration order: a
 * session's tool set is fixed, so the layer's bytes belong to the Static prefix
 * region and must not depend on how the tool list happened to be assembled.
 *
 * Guidance must be about THIS tool and self-contained. Naming a sibling tool is
 * a dangling reference the moment that sibling is not wired, and the sibling's
 * own contract already travels to the model in the request's tool payload;
 * `ToolPromptGuidanceTest` sweeps every fragment against every other wired
 * tool's name to keep that rule observable rather than aspirational.
 */
interface PromptGuidance
{
    /**
     * Session-prompt prose for this tool — the fragment body only, unframed —
     * or `''` to contribute nothing.
     */
    public function promptGuidance(): string;
}
