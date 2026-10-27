<?php

declare(strict_types=1);

namespace SugarCraft\Code\Context;

use SugarCraft\Code\Lang;

/**
 * The Claude Code `/context` attribution vocabulary (roadmap P-CTX.a).
 *
 * A prompt layer answers to two questions at once: WHERE it sits in the
 * assembled prompt (the per-layer rows {@see ContextBreakdown::$sections}
 * prints) and WHO to bill for it — the harness's own voice, the repository's
 * instructions, the memory notes, the MCP bridges. `/context` shows both:
 * the layers first (byte truth), then the same tokens attributed by category
 * (billing truth). This enum is the one mapping between the two, so the
 * report, the wire (a later step) and any future panel agree on which layer
 * belongs to which category — and an unknown layer lands in System prompt,
 * the honest remainder, rather than vanishing from the sum.
 *
 * The case values double as the lang key tails
 * (`cmd.context.category.<value>`), so adding a category cannot silently
 * skip its translation: {@see label()} asks for the key by value and the
 * suite pins every case's key into `lang/en.php`.
 */
enum ContextCategory: string
{
    /** Harness voice: base identity, maxims, tool doctrine (roster excluded), plan reminders, `<env>`, and every unknown layer. */
    case SystemPrompt = 'system-prompt';

    /** The built-in tools' schemas — everything not named as an MCP surface. */
    case SystemTools = 'system-tools';

    /** The `mcp__<server>__*` bridges and the `mcp_list`/`mcp_call` dispatchers. */
    case McpTools = 'mcp-tools';

    /** The `<available-agents>` roster the Task guidance splices (CustomAgents is why it is NOT System prompt). */
    case CustomAgents = 'custom-agents';

    /** The instruction documents and user-tier rules (`<project-instructions>`, `<user-rules>`). */
    case MemoryFiles = 'memory-files';

    /** The project memory notes (`<project-memory>`). */
    case ProjectMemory = 'project-memory';

    /** The repository map and its symbol refinement (`<repo-map>`, `<symbol-map>`). */
    case RepositoryMap = 'repo-map';

    /** Enabled skill bodies and the `<available-skills>` listing. */
    case Skills = 'skills';

    /** The conversation the model is sent, net of what the ledger prunes. */
    case Messages = 'messages';

    /** The room between where background compaction fires and the window ends. */
    case AutocompactBuffer = 'autocompact-buffer';

    /** What is left of the window (roadmap: never negative — an over-budget session shows 0). */
    case FreeSpace = 'free-space';

    /**
     * Which category a prompt layer bills to.
     *
     * $label is the row label {@see \SugarCraft\Code\Runtime::promptSectionSizes()}
     * derives (the fence trimmed of its brackets, or one of the unfenced
     * specials `base`, `maxims`, `skills`, `tool-guidance`). Anything not
     * listed — including future fences and the injected `harness-injected` /
     * `prior-summary` framings — falls to System prompt, so the category sum
     * always accounts for every measured byte.
     */
    public static function forSectionLabel(string $label): self
    {
        return match ($label) {
            'project-instructions', 'user-rules' => self::MemoryFiles,
            'project-memory' => self::ProjectMemory,
            'repo-map', 'symbol-map' => self::RepositoryMap,
            'skills', 'available-skills' => self::Skills,
            default => self::SystemPrompt,
        };
    }

    /** The user-facing name of this category. */
    public function label(): string
    {
        // Interpolated, not concatenated: the LangParity literal census reads
        // single-quoted prefixes as complete keys, and `cmd.context.category.`
        // is not one. The suite pins the real key set instead — see
        // ContextCategoriesTest::testEveryCategoryHasItsLabelKey().
        return Lang::t("cmd.context.category.{$this->value}");
    }
}
