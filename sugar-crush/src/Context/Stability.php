<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

/**
 * How often a {@see PromptSection}'s rendered bytes change over a session.
 *
 * Mirrors charmbracelet/crush's prompt-layer stability tiers (prompt_expand.md
 * §9.4, §10 seam 2). The three classes are the whole alphabet the compaction
 * and caching steps downstream reason over: a section that is byte-stable for
 * the life of the process, one that is stable for the life of a session
 * snapshot, and one that may differ on every turn.
 *
 * WHY AN ENUM AND NOT A BOOL OR AN INT. A two-valued "stable or not" cannot
 * tell the per-Runtime memoized snapshots apart from the volatile git block:
 * both are "not static" to a boolean, yet one is safe to cache across steps of
 * the agentic loop and the other is polled live on every render. A free int
 * invites a fourth value that means nothing to the code reading it. Three
 * named cases make the distinction the type itself.
 *
 * THIS STEP DECLARES THE CLASSIFICATION; IT DOES NOT ACT ON IT. Introducing
 * the tiers is P5.S1's job because the assembler's contract is what four later
 * steps build on. Nothing in P5.S1 changes a byte of output by consulting
 * stability — the ordering the golden pins is fixed by construction, not by
 * this enum. The consumers arrive with prompt-cache breakpoints and the
 * per-tier compaction that read it.
 */
enum Stability
{
    /**
     * Byte-identical for the life of the process: the base identity heredoc,
     * which is a fixed string with no input.
     */
    case Static;

    /**
     * Stable for the life of a session snapshot — the memoized blocks captured
     * once per {@see \SugarCraft\Crush\Runtime} (repo map, project
     * instructions, project memory). A note added mid-turn does not
     * retroactively join the prompt of a turn already in flight.
     */
    case PerSession;

    /**
     * May differ on every turn: the volatile `<env>` block, whose git section
     * is polled live on render, and the skill layers, whose set can be
     * re-derived between steps.
     */
    case PerTurn;
}
