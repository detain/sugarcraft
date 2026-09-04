<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

/**
 * One ordered layer of the assembled system prompt.
 *
 * Mirrors the `PromptSection` seam in prompt_expand.md §9.4 and §10 seams 1
 * and 2. Before this interface, `Runtime::buildSystemPrompt()` concatenated its
 * layers with hand-placed separators; each layer knew its own shape only at
 * the concatenation site. A section carries that knowledge with itself: the
 * fence it renders inside, how volatile it is, the byte budget it is supposed
 * to fit, and the exact bytes it contributes.
 *
 * WHAT THIS STEP (P5.S1) DOES AND DOES NOT DO. It lands the interface and the
 * ordered-list assembler behind the existing signature, and it holds the
 * golden system prompt BYTE-IDENTICAL — the refactor moves no bytes. Three of
 * these four methods are declared ahead of their consumers on purpose:
 *
 * - `render()` is the only method the P5.S1 assembler reads; the prompt is the
 *   fold of the non-empty renders.
 * - `fence()` names the layer's own opening fence, and is metadata here: the
 *   real blocks already emit their fence tags inside `render()`, so the
 *   assembler neither adds nor enforces them. It exists so the later fence-
 *   escaping step (P5.S3) has one place to ask "escape against which fence?"
 * - `stability()` and `byteBudget()` are the classification and ceiling the
 *   cache-breakpoint and per-tier compaction steps consume. Neither changes
 *   a byte of output at P5.S1: enforcing a budget now would be new behaviour,
 *   and new behaviour is not this refactor.
 *
 * The concrete sections P5.S1 assembles are the ones `Runtime` builds inline;
 * the memoized snapshots migrate onto this interface in P5.S2.
 */
interface PromptSection
{
    /**
     * The opening fence tag this section's body sits inside, e.g. `<env>`,
     * `<repo-map>`, `<project-instructions>`, `<project-memory>`.
     *
     * An empty string means the section has no fence of its own — the base
     * identity heredoc, and the markdown skill layers.
     */
    public function fence(): string;

    /**
     * How often {@see render()}'s bytes change across a session.
     */
    public function stability(): Stability;

    /**
     * The byte ceiling this section is expected to fit.
     *
     * Advisory at P5.S1: the assembler concatenates without enforcing it, so
     * introducing a ceiling cannot silently truncate the golden. The value is
     * a contract for the compaction steps that will read it, not a lever this
     * refactor pulls.
     */
    public function byteBudget(): int;

    /**
     * The exact bytes the section contributes to the assembled prompt.
     *
     * Returns an empty string when the layer is absent, which the assembler
     * folds away — an absent section contributes NOTHING, not an empty fence
     * and not a dangling separator.
     *
     * The body includes its own leading separator where one is due (see
     * {@see \SugarCraft\Crush\Runtime::assemblePrompt()}), so the assembler's
     * rule is only "one `\\n\\n` between adjacent sections, never a second
     * before a body that already carries one."
     */
    public function render(): string;
}
