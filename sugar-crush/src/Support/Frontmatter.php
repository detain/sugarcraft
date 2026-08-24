<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the YAML frontmatter block of a markdown file the way the agent-tool
 * ecosystem actually writes it.
 *
 * WHY THIS EXISTS. Every frontmatter block sugar-crush reads -- SKILL.md,
 * `.claude/agents/*.md`, `.opencode/agent/*.md`, slash-command `*.md`, Claude
 * Code's own memory entries -- is written by SOMEBODY ELSE'S TOOL, for
 * somebody else's tool, and only incidentally read by us. The dominant shape
 * in that corpus is a one-line `description:` written as a plain (unquoted)
 * scalar of English prose, and English prose contains colons:
 *
 *     description: Scaffolds a new port end-to-end: creates composer.json, ...
 *
 * That is a YAML syntax error -- `Yaml::parse()` raises "A colon cannot be
 * used in an unquoted mapping value" -- and yet Claude Code loads the file
 * without complaint, so authors keep writing it and have no reason to stop.
 * A strict parse therefore does not reject bad files; it rejects NORMAL files.
 * Six of them on the machine this class was written on, two of which live
 * outside this repository entirely, so "go quote your description" is not
 * advice their owner can act on -- see {@see \SugarCraft\Crush\Skills\SkillLoader}'s
 * skip recorder, which had already reasoned its way to that conclusion and
 * only ever fixed the reporting, never the parse.
 *
 * WHAT IT DOES NOT DO. This is not a lenient YAML dialect. Strict parsing is
 * tried first and, when it succeeds, its result is returned untouched --
 * lists, nested maps, block scalars, quoted strings and `true`/`123`/`null`
 * typing all keep exactly the semantics Symfony gives them. Only a
 * ParseException triggers the repair pass, only top-level `key: value` lines
 * whose value YAML ITSELF rejects are rewritten, and if the rewritten block
 * still will not parse the ORIGINAL exception is rethrown, so a genuinely
 * malformed file fails exactly as before rather than degrading to silently
 * empty metadata.
 */
final class Frontmatter
{
    /**
     * A top-level `key: value` line. The key charset is deliberately narrow:
     * a key containing anything weirder is a key whose colon we cannot safely
     * locate, so we decline to touch that line at all rather than guess where
     * the split belongs.
     */
    private const KEY_VALUE_LINE = '/^([A-Za-z0-9_][A-Za-z0-9_.\-]*):[ \t]+(.*\S)[ \t]*$/';

    /** `|`, `>`, and their chomping (`+`/`-`) and explicit-indent (digit) indicators. */
    private const BLOCK_SCALAR_INTRODUCER = '/^[|>][0-9]*[+-]?$/';

    /**
     * Parse a frontmatter block, repairing unquotable plain scalars if -- and
     * only if -- strict YAML has already refused it.
     *
     * The return value is whatever `Yaml::parse()` would return, unchanged:
     * an array for a mapping, `null` for an empty or comment-only block, a
     * scalar for a bare value. Call sites already branch on that shape and
     * normalising it here would have silently changed several of them.
     *
     * @throws ParseException when the block is malformed for a reason the
     *         repair pass does not address. The exception rethrown is the one
     *         STRICT parsing raised, not one from the repaired text: the
     *         original names the defect the author actually has to fix, while
     *         a repaired-text error would point at a line number in a
     *         document that exists only inside this method.
     */
    public static function parse(string $block): mixed
    {
        try {
            return Yaml::parse($block);
        } catch (ParseException $strictFailure) {
            $repaired = self::quoteUnparsableScalars($block);

            if ($repaired === null) {
                throw $strictFailure;
            }

            try {
                return Yaml::parse($repaired);
            } catch (ParseException) {
                throw $strictFailure;
            }
        }
    }

    /**
     * Rewrite every top-level `key: value` line whose plain value YAML rejects
     * into the same key with a single-quoted value.
     *
     * Returns null when nothing was rewritten, which is the caller's signal
     * that the repair pass has nothing to offer and the strict failure stands.
     *
     * The discriminator for "would YAML reject this value" is YAML itself: the
     * line is re-parsed on its own and only a ParseException earns a rewrite.
     * That is what keeps `paths: [a, b]`, `maxTurns: 12`, `background: true`
     * and `model: sonnet` untouched while catching `description: Does X: and Y`,
     * a leading `[`/`{` that is prose rather than a flow collection, and a
     * `: ` inside backticks. A line YAML ACCEPTS is left alone even where its
     * meaning is surprising -- ` #` still opens a comment -- because rule one
     * is that valid YAML keeps parsing exactly as it does today.
     */
    private static function quoteUnparsableScalars(string $block): ?string
    {
        $lines = explode("\n", $block);
        $changed = false;

        foreach ($lines as $i => $line) {
            // Preserve CRLF byte-for-byte: the block is rejoined from these
            // same strings, so a stripped \r would be a silent edit to a file
            // we were only asked to read.
            $cr = str_ends_with($line, "\r") ? "\r" : '';
            $bare = $cr === '' ? $line : substr($line, 0, -1);

            if (!self::isTopLevelCandidate($bare)) {
                continue;
            }

            if (preg_match(self::KEY_VALUE_LINE, $bare, $m) !== 1) {
                continue;
            }

            [, $key, $value] = $m;

            if (self::valueIsOffLimits($value)) {
                continue;
            }

            // A more-indented next line makes this a multi-line plain scalar
            // (or a mapping whose value opened on the following line);
            // quoting only the first line would break the continuation, so
            // this whole line is out of scope and the strict failure stands.
            if (self::nextLineIsIndented($lines, $i)) {
                continue;
            }

            if (self::parsesStandalone($key, $value)) {
                continue;
            }

            $lines[$i] = $key . ": '" . str_replace("'", "''", $value) . "'" . $cr;
            $changed = true;
        }

        return $changed ? implode("\n", $lines) : null;
    }

    /**
     * Whether a line is a candidate for repair at all: unindented (so it is
     * neither nested content nor a block scalar's body), not blank, not a
     * comment, not a list item, and not a document marker.
     */
    private static function isTopLevelCandidate(string $bare): bool
    {
        if ($bare === '' || ltrim($bare) === '') {
            return false;
        }

        $first = $bare[0];

        if ($first === ' ' || $first === "\t" || $first === '#' || $first === '-') {
            return false;
        }

        return !str_starts_with($bare, '...');
    }

    /**
     * Values the repair pass must not touch: ones that are already well-formed
     * by construction, and one that is broken in a way quoting would paper
     * over rather than fix.
     *
     * Well-formed by construction: quoted strings, block-scalar introducers,
     * and the anchor / alias / tag sigils, whose meaning single quotes would
     * destroy.
     *
     * The one that must stay broken is an UNTERMINATED flow collection --
     * `tools: [a, b` or `paths: {a` -- and the distinction it draws against
     * prose is worth stating, because both fail the same way. A description
     * reading `[beta] Adds a thing` opens a bracket and CLOSES it; the parse
     * fails only on the prose that follows, so the author plainly meant text
     * and quoting recovers exactly what they wrote. A value that opens a
     * bracket and never closes it is an author who meant a collection and
     * truncated it: quoting would turn their broken list into a plausible
     * string and hand it downstream as though nothing had happened, which is
     * precisely the silent-empty this helper refuses elsewhere. That case
     * stays a hard parse error, as {@see \SugarCraft\Crush\Commands\CommandLoader}'s
     * fail-closed contract requires of user-controlled input.
     */
    private static function valueIsOffLimits(string $value): bool
    {
        $first = $value[0];

        if ($first === '"' || $first === "'" || $first === '&' || $first === '*' || $first === '!') {
            return true;
        }

        if ($first === '[' && !str_contains($value, ']')) {
            return true;
        }

        if ($first === '{' && !str_contains($value, '}')) {
            return true;
        }

        return preg_match(self::BLOCK_SCALAR_INTRODUCER, $value) === 1;
    }

    /** @param list<string> $lines */
    private static function nextLineIsIndented(array $lines, int $i): bool
    {
        for ($j = $i + 1, $n = count($lines); $j < $n; $j++) {
            $next = rtrim($lines[$j], "\r");

            if (trim($next) === '') {
                continue;
            }

            return $next[0] === ' ' || $next[0] === "\t";
        }

        return false;
    }

    private static function parsesStandalone(string $key, string $value): bool
    {
        try {
            Yaml::parse($key . ': ' . $value);

            return true;
        } catch (ParseException) {
            return false;
        }
    }
}
