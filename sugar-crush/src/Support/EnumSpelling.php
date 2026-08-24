<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

/**
 * Resolves a frontmatter scalar onto a backed enum case across the two
 * spellings the agent-tool ecosystem uses for the same value.
 *
 * WHY THIS EXISTS. sugar-crush's own enums are kebab-case
 * (`accept-edits`, `bypass-permissions`, `dont-ask`); Claude Code writes the
 * same values camelCase (`acceptEdits`, `bypassPermissions`). Both spellings
 * turn up in preset files on one machine, because the files come from both
 * tools and get copied between them. A lookup that understands only one of
 * them does not report the other as unknown -- it silently substitutes a
 * default, and a preset that asked to auto-accept edits quietly stops doing so
 * with no message anywhere.
 *
 * That was measurable in the shipped tree: `.sugar-crush/agents/coder.md`,
 * a preset tracked in this repository, declares `permissionMode: acceptEdits`
 * and was resolving to {@see \SugarCraft\Crush\Permissions\PermissionMode::Default}.
 * {@see \SugarCraft\Crush\Agents\ForeignAgentPresetRegistry} had already argued
 * its way to the retry below and implemented it for FOREIGN presets; the
 * native reader next door kept a hand-written `match` and did not. This class
 * is that one implementation, so the two readers cannot drift again.
 *
 * The enum's kebab values stay the only source of truth -- no camelCase case
 * is added to any enum -- and an unrecognised value still resolves to null so
 * each caller keeps applying its own documented default.
 */
final class EnumSpelling
{
    /**
     * Case-insensitive lookup, retried with camelCase folded to kebab-case.
     *
     * The plain lowercase lookup goes first so a value that is already kebab,
     * or that has no case boundary at all (`xhigh`), never reaches the split.
     *
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T|null null for a non-scalar, absent, or genuinely unrecognised
     *         value -- deliberately NOT a default, because only the caller
     *         knows which default its field documents.
     */
    public static function resolve(string $enum, mixed $value): ?\BackedEnum
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $raw = (string) $value;

        return $enum::tryFrom(strtolower($raw))
            ?? $enum::tryFrom(strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $raw)));
    }
}
