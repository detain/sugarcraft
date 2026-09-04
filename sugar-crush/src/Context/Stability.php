<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

/**
 * How often a {@see PromptSection}'s rendered bytes change over a session.
 *
 * DESIGN SOURCE: prompt_expand.md §9.4 and §10 seam 2, which sketch this enum
 * beside the PromptSection interface as the classification the memoized
 * snapshots generalise into. It is a SugarCraft architecture type, not a port
 * — charmbracelet/crush has no Stability symbol to mirror, so the repo's
 * "Mirrors charmbracelet/<repo>.<Method>" convention does not apply; the
 * honest lineage is that this names and generalises crush's prompt-layer idea
 * of per-layer volatility into a closed alphabet. The three cases are the
 * whole alphabet the compaction and caching steps downstream reason over: a
 * section that is byte-stable for the life of the process, one that is stable
 * for the life of a session snapshot, and one that may differ on every turn.
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
     * Stable for the life of a session. The repo map and project memory are
     * each captured once per {@see \SugarCraft\Crush\Runtime} and memoized
     * there; the instruction documents are re-read on every build but their
     * content is session-stable (cached inside the loader), so all three land
     * in this same non-volatile tier. A note added mid-turn does not
     * retroactively join the prompt of a turn already in flight.
     */
    case PerSession;

    /**
     * May differ from one turn to the next: the volatile `<env>` block, whose
     * git section is polled live on every render, and the skill layers, whose
     * set is fixed for the duration of a turn (it does not change between the
     * steps of a turn) but is re-derived per App, so it can differ across
     * turns.
     */
    case PerTurn;
}
