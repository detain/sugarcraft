<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

use SugarCraft\Crush\Backend;

/**
 * Opt-in declaration that a {@see Backend} can say what its system prompt is
 * made of, layer by layer — the figures `/context` prints (roadmap 5.6).
 *
 * The status bar's context figure is the HISTORY alone; the system prompt and
 * the tool schemas ride every request too, and on a project with a repo map,
 * rules and a few CLAUDE.md files they are thousands of tokens the user had
 * no way to see. Only a backend that assembles the prompt itself can answer:
 * the sections are built inside {@see \SugarCraft\Crush\Runtime}, which only
 * an engine backend owns. A command backend sends whatever its command sends.
 *
 * A separate capability rather than a method on `Backend`, for the reason
 * {@see ReportsContextWindow} gives: `Backend` is a third-party extension
 * point, and a required method would be a load-time fatal for every
 * implementation outside this repo. Not implementing it is the safe default —
 * `/context` then says the prompt was not measured instead of inventing it.
 */
interface ReportsPromptSections
{
    /**
     * The next request's system prompt per layer, in prompt order — the shape
     * {@see \SugarCraft\Crush\Runtime::promptSectionSizes()} returns. `bytes`
     * sum to the assembled prompt's length; `tokens` are script-weighted
     * estimates ({@see \SugarCraft\Crush\Util\TokenEstimate}), not a
     * tokenizer count.
     *
     * @return list<array{label: string, stability: string, sections: int, bytes: int, tokens: int}>
     */
    public function promptSectionSizes(): array;
}
