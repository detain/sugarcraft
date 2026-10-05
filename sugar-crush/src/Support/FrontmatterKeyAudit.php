<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

use SugarCraft\Crush\Tui\Components\PaneLabel;

/**
 * Which frontmatter keys a markdown-plus-YAML file declares that sugar-crush
 * reads and then does nothing with — said once, at launch, instead of never.
 *
 * WHY IT EXISTS (Part II #37, step X-37a). Four formats carry frontmatter —
 * agent presets, skills, slash commands and rules — and every one of their
 * readers types the keys it knows and walks past the rest. Two kinds of key
 * fell through that silently:
 *
 *  - an INERT key: parsed, typed, carried on the value object, and consumed by
 *    nothing on a live path. `context: fork` on a skill was documented in the
 *    README as running the skill in an isolated sub-agent; no production code
 *    dispatches it. A preset's `isolation:` rides onto the roster row and
 *    `Task` still runs in the session's checkout.
 *  - an UNKNOWN key: a typo (`permisionMode`, `keyword`, `enable: false`) or
 *    another tool's field. The typo is the dangerous one — a rule written
 *    `enable: false` stays enabled — and nothing said so.
 *
 * Neither refuses the file. Imported `.claude`/`.opencode` files routinely
 * carry keys this port does not act on (`allowed-tools` is common), and a
 * file that loads with a key ignored is still the file its author wanted
 * loaded. What changes is that the launch says so, in ONE aggregated row per
 * format ({@see notice()}), with a did-you-mean when an unknown key is a near
 * miss of a known one ({@see suggestion()}).
 *
 * THE {@see INERT} MAP IS THE SINGLE SOURCE. The documentation's "Inert" rows
 * (`docs/SKILLS.md`'s field table, `docs/COMMANDS.md`'s frontmatter table,
 * `docs/AGENTS_AUTHORING.md`'s field table) are pinned to it by
 * `InertFrontmatterDocumentationDriftTest`, so the step that finally honours
 * a field (as 4.1 did for a preset's `model`/`effort`/`permissionMode`) deletes its
 * entry here and the docs go red until they stop calling it inert.
 */
final class FrontmatterKeyAudit
{
    public const AGENT = 'agent preset';
    public const SKILL = 'skill';
    public const COMMAND = 'command';
    public const RULE = 'rule';

    /**
     * Every key each format's reader recognises, inert ones included.
     *
     * Skill keys `name`, `license`, `compatibility` and `metadata` are the
     * agentskills.io spec's descriptive fields: a skill's name is its
     * directory, and the rest describe the file rather than ask for
     * behaviour, so declaring them is never a mistake worth a notice.
     */
    public const KNOWN = [
        self::AGENT => [
            'name', 'description', 'tools', 'disallowedTools', 'model', 'permissionMode', 'maxTurns',
            'skills', 'mcpServers', 'memory', 'background', 'effort', 'isolation', 'color', 'initialPrompt',
        ],
        self::SKILL => [
            'name', 'description', 'license', 'compatibility', 'metadata', 'user-invocable',
            'disable-model-invocation', 'allowed-tools', 'disallowed-tools', 'model', 'effort', 'context',
            'paths', 'requires', 'os',
        ],
        self::COMMAND => ['description', 'argument-hint', 'model', 'subtask'],
        self::RULE => ['name', 'description', 'enabled', 'models', 'keywords', 'paths'],
    ];

    /**
     * Keys each format parses and no live path acts on, mapped to the values
     * that are no-ops anyway (lower-cased). A declared key whose value is one
     * of those is not reported: `model: inherit` and `context: thread` ask for
     * exactly what happens.
     *
     * - agent preset: `Task` honours `model`, `effort` and (narrow-only)
     *   `permissionMode` since roadmap 4.1, and `background` since 4.3-2
     *   ({@see \SugarCraft\Crush\Tools\BuiltIn\TaskTool}); `memory`,
     *   `isolation` and `color` are carried onto the roster row and read by
     *   nothing.
     * - skill: no tool-scoping code reads `allowed-tools`/`disallowed-tools`;
     *   `model` is read only by `App::dispatchSkill()`, which has no
     *   production caller; `effort` is read by nothing; `context: fork` has no
     *   fork executor.
     * - command: `Chat` expands a file command's template into the current
     *   conversation on the current model; nothing reads `model` or `subtask`.
     *
     * @var array<string, array<string, list<string>>>
     */
    public const INERT = [
        self::AGENT => [
            'memory' => [],
            'isolation' => ['none'],
            'color' => [],
        ],
        self::SKILL => [
            'allowed-tools' => [],
            'disallowed-tools' => [],
            'model' => [],
            'effort' => [],
            'context' => ['thread'],
        ],
        self::COMMAND => [
            'model' => [],
            'subtask' => ['false'],
        ],
        self::RULE => [],
    ];

    /**
     * One launch row per format. `%1$d %2$s` is "3 skills", `%3$s` the verb's
     * agreement, `%4$s` the `; `-joined groups.
     */
    public const NOTICE_FORMAT = '%d %s declare%s frontmatter sugar-crush ignores: %s';

    /** Names quoted per group before the rest are counted. */
    public const NAMES_PER_GROUP = 3;

    /** Characters one key, value or name may contribute to a row. */
    private const MAX_TOKEN_CHARS = 48;

    private const FRONTMATTER_PATTERN = '/^---\s*\n(.*?)\n---\s*\n/s';

    /**
     * The ignored keys $meta declares, as display labels — "`allowed-tools`
     * is not acted on", "`permisionMode` is not an agent preset field (did you
     * mean `permissionMode`?)". Inert labels first, in {@see INERT} order, then
     * unknown keys in file order. Empty when nothing is ignored.
     *
     * @param array<mixed> $meta the parsed frontmatter mapping
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException for a format this class does not know
     */
    public static function inspect(string $format, array $meta): array
    {
        if (!isset(self::KNOWN[$format])) {
            throw new \InvalidArgumentException(sprintf('unknown frontmatter format "%s"', $format));
        }

        $labels = [];
        foreach (self::INERT[$format] as $key => $noOps) {
            if (!array_key_exists($key, $meta) || $meta[$key] === null) {
                continue;
            }

            $value = self::scalarText($meta[$key]);
            if ($value !== null && in_array(strtolower(trim($value)), $noOps, true)) {
                continue;
            }

            // A key with no-op values is only ignored for SOME values, so the
            // value is part of what is being reported (`context: fork`).
            $shown = $noOps !== [] && $value !== null ? $key . ': ' . self::token($value) : $key;
            $labels[] = sprintf('`%s` is not acted on', $shown);
        }

        foreach (array_keys($meta) as $key) {
            $key = (string) $key;
            if (in_array($key, self::KNOWN[$format], true)) {
                continue;
            }

            $guess = self::suggestion($format, $key);
            $labels[] = sprintf('`%s` is not %s %s field', self::token($key), self::article($format), $format)
                . ($guess === null ? '' : sprintf(' (did you mean `%s`?)', $guess));
        }

        return $labels;
    }

    /**
     * The closest known key to an unknown one, or null when nothing is close.
     *
     * Two kinds of near miss: a spelling of the same word (`PermissionMode`,
     * `disallowed_tools`, `allowedTools` for `allowed-tools`) compared with
     * case, `-` and `_` folded away; and a typo within a Levenshtein distance
     * of two (one for keys of four characters or fewer, so `os` does not
     * suggest itself for every two-letter key).
     */
    public static function suggestion(string $format, string $key): ?string
    {
        $fold = static fn (string $s): string => strtolower(str_replace(['-', '_'], '', $s));
        $folded = $fold($key);

        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach (self::KNOWN[$format] ?? [] as $known) {
            $candidate = $fold($known);
            if ($candidate === $folded) {
                return $known;
            }

            $limit = strlen($candidate) <= 4 ? 1 : 2;
            $distance = levenshtein($folded, $candidate);
            if ($distance <= $limit && $distance < $bestDistance) {
                $best = $known;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * The launch row for one format, or null when no file declared anything
     * ignored.
     *
     * Grouped by label so twelve imported skills that all set
     * `allowed-tools` are one clause naming three of them and counting the
     * rest, not twelve sentences.
     *
     * @param array<string, list<string>> $findings name => {@see inspect()} labels
     */
    public static function notice(string $format, array $findings): ?string
    {
        $groups = [];
        $files = 0;
        foreach ($findings as $name => $labels) {
            if ($labels === []) {
                continue;
            }

            ++$files;
            foreach ($labels as $label) {
                $groups[$label][] = self::token((string) $name);
            }
        }

        if ($groups === []) {
            return null;
        }

        $clauses = [];
        foreach ($groups as $label => $names) {
            $shown = array_slice($names, 0, self::NAMES_PER_GROUP);
            $rest = \count($names) - \count($shown);
            $clauses[] = sprintf(
                '%s (%s%s)',
                $label,
                implode(', ', $shown),
                $rest > 0 ? sprintf(' +%d more', $rest) : '',
            );
        }

        return sprintf(
            self::NOTICE_FORMAT,
            $files,
            $format . ($files === 1 ? '' : 's'),
            $files === 1 ? 's' : '',
            implode('; ', $clauses),
        );
    }

    /**
     * The frontmatter mapping at the head of $content, or [] when there is no
     * block or it does not parse into a mapping. The readers that own each
     * format decide whether a broken block refuses the file; this only reads
     * what a file that loaded declared, so it never throws.
     *
     * @return array<mixed>
     */
    public static function metaOf(string $content): array
    {
        if (preg_match(self::FRONTMATTER_PATTERN, $content, $matches) !== 1) {
            return [];
        }

        try {
            $parsed = Frontmatter::parse($matches[1]);
        } catch (\Throwable) {
            return [];
        }

        return is_array($parsed) && ($parsed === [] || !array_is_list($parsed)) ? $parsed : [];
    }

    private static function article(string $noun): string
    {
        return in_array($noun[0] ?? '', ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a';
    }

    /** A scalar value as text, null for a list or map. */
    private static function scalarText(mixed $value): ?string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value), is_int($value), is_float($value) => (string) $value,
            default => null,
        };
    }

    /**
     * Repository text made safe for one launch row: escapes and control bytes
     * gone ({@see PaneLabel::safe()}) and clipped, since a key, value or file
     * name is whatever the file's author wrote.
     */
    private static function token(string $raw): string
    {
        $flat = PaneLabel::safe($raw);

        return mb_strlen($flat, 'UTF-8') > self::MAX_TOKEN_CHARS
            ? mb_substr($flat, 0, self::MAX_TOKEN_CHARS - 1, 'UTF-8') . '…'
            : $flat;
    }
}
