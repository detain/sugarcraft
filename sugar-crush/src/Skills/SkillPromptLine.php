<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Skills;

use SugarCraft\Crush\Context\PromptFence;
use SugarCraft\Crush\Context\Utf8Scrub;

/**
 * The one authority that turns a skill's repository-supplied name and
 * description into a `- name: description` line the prompt may carry.
 *
 * A skill's `description` is frontmatter written by whoever shipped the skill,
 * and its name is the directory the SKILL.md sits in. Both arrive from
 * `.claude/skills`, `.opencode/skills` and `.sugar-crush/skills` inside a
 * cloned checkout with no trust gate, so both are arbitrary repository text.
 * Before this class, {@see SkillMatcher::listForPrompt()} and
 * {@see SkillPathNudge} interpolated them verbatim: a block-scalar
 * `description: |` holding `</project-instructions>` and a
 * `<system-reminder>…</system-reminder>` pair landed in the system prompt as
 * harness text, outside every fence (audit 15d-02). Every other repo-to-prompt
 * route — instruction documents, rules, memory, the repo map — already went
 * through {@see PromptFence::escape()}; skills were the one that never reached
 * it.
 *
 * THREE STEPS, IN THIS ORDER, AND ONE PLACE THAT DOES THEM so the listing and
 * the path nudge cannot drift apart:
 *
 *  1. Collapse every whitespace run, newlines included, to one space. A
 *     listing entry is ONE line by contract; a description that can start a
 *     new line can start a line that reads as the harness's own.
 *  2. {@see PromptFence::escape()}, so no roster opener or closer survives.
 *  3. Clip to the caller's byte budget, UTF-8-safe, with a marker.
 */
final class SkillPromptLine
{
    /**
     * Ends a line that did not fit its budget.
     *
     * A clipped description that says nothing about being clipped reads as the
     * skill's whole trigger phrase, and the model then decides the skill does
     * not apply on half a sentence.
     */
    public const CLIP_MARKER = ' ... [clipped]';

    /**
     * The most bytes ONE line of the system-prompt skill listing may occupy.
     *
     * Wider than {@see SkillPathNudge}'s 300-byte entry on purpose: the listing
     * is the ONLY place an un-path-scoped skill is ever named, so clipping a
     * shipped trigger phrase there costs the skill its auto-invocation, whereas
     * the nudge is a pointer spent inside a tool result's own cap. 1,024 is the
     * Agent Skills spec's ceiling on `description`, so a description that obeys
     * the spec loses at most its tail to the `- name: ` prefix, and the three
     * shipped built-ins over 300 bytes (the longest is 347 MEASURED on this
     * tree) render whole. What this bounds is the hostile or careless case: a
     * multi-kilobyte description entering every request.
     */
    public const LISTING_MAX_BYTES = 1024;

    /**
     * Opens every `## Skill:` heading the prompt or a tool result carries.
     *
     * ONE spelling for both channels on purpose: the Skill tool's result header
     * and an enabled skill's spliced body section are the same announcement —
     * "these bytes are skill <X>" — and they used to be minted independently,
     * which is how the enabled-body path kept showing a bundle's uuid path long
     * after the listing and the tool stopped showing it (skills QA, M5).
     */
    public const HEADING_PREFIX = '## Skill: ';

    /**
     * Opens the heading's companion line naming the directory the SKILL.md was
     * read from, so a body's `scripts/…` and `docs/…` references resolve.
     */
    public const BASE_DIR_PREFIX = '> Base directory for this skill: ';

    private function __construct()
    {
    }

    /**
     * The heading line for $skill: the display name, never the registry key,
     * terminated by the blank line that separates it from whatever follows.
     *
     * {@see Skill::displayName()} is what the model reads in the listing and
     * what {@see \SugarCraft\Crush\Tools\BuiltIn\SkillTool} resolves back to a
     * key, so the section a body lands under announces itself by the same name
     * the skill is called by. A name without a '/' is identical to its key, so
     * every flat skill — every built-in and every directly-installed one —
     * renders byte-identically to before.
     */
    public static function heading(Skill $skill): string
    {
        return self::HEADING_PREFIX . self::field($skill->displayName()) . "\n\n";
    }

    /**
     * The base-directory line for $skill, or '' when the skill has no on-disk
     * source.
     *
     * A manifest skill (registered from a composer.json-style array, never
     * written to a file) has an empty `sourcePath`, and `dirname('')` is `'.'`
     * — a confident lie about the current directory. Silence is the honest
     * rendering, and the caller's own text follows immediately either way.
     */
    public static function baseDirLine(Skill $skill): string
    {
        return $skill->sourcePath === ''
            ? ''
            : self::BASE_DIR_PREFIX . self::field(dirname($skill->sourcePath)) . "\n\n";
    }

    /**
     * One `- name: description` line: single-line, fence-escaped, and never
     * longer than $maxBytes.
     *
     * $withOrigin opens the line with the skill's provenance badge —
     * `- [project] name: description`, `- [user, foreign: claude] …` — from
     * {@see SkillOrigin::badge()} (audit 15d-02, fix step 3). The system-prompt
     * listing asks for it, and its fence preamble tells the model what the
     * bracket means; {@see SkillPathNudge} does not, because its
     * `<system-reminder>` header carries no such explanation and a bare bracket
     * in front of a name is one the model could mistake for part of it. The
     * badge leads the line so the byte clip, which cuts the tail, can never
     * remove it, and its bytes are enum constants, so no repository text
     * reaches it.
     *
     * @throws \InvalidArgumentException when $maxBytes cannot hold the marker,
     *         since such a budget could only ever return a marker with no line.
     */
    public static function render(Skill $skill, int $maxBytes, bool $withOrigin = false): string
    {
        if ($maxBytes <= strlen(self::CLIP_MARKER)) {
            throw new \InvalidArgumentException(sprintf(
                'SkillPromptLine::render(): a %d-byte budget cannot hold the %d-byte clip marker',
                $maxBytes,
                strlen(self::CLIP_MARKER),
            ));
        }

        // The DISPLAY name, not the registry key: a bundle skill's uuid path is
        // identity the model must use to call it, but noise on the line it
        // decides by — see Skill::displayName(). {@see SkillTool} resolves the
        // leaf back to the key.
        $badge = $withOrigin ? '[' . $skill->origin->badge($skill->source) . '] ' : '';
        $line = '- ' . $badge . self::field($skill->displayName()) . ': ' . self::field($skill->description);
        if (strlen($line) <= $maxBytes) {
            return $line;
        }

        // mb_strcut and not substr, for the reason
        // {@see \SugarCraft\Crush\Tools\Concerns\TruncatesOutput::clipInstructions()}
        // gives: a plain byte cut lands inside a UTF-8 sequence and puts invalid
        // bytes in front of the model. Clipping AFTER the escape cannot revive a
        // tag: a cut can shorten `&lt;/env>` but never turn it back into `<`.
        return mb_strcut($line, 0, $maxBytes - strlen(self::CLIP_MARKER), 'UTF-8')
            . self::CLIP_MARKER;
    }

    /**
     * One repository-supplied value made safe to sit inside a prompt line:
     * collapsed to a single line, then fence-escaped.
     */
    public static function field(string $text): string
    {
        // Scrubbed here as well as at load: a skill's NAME is its directory
        // name, which no loader rewrites (it is the skill's identity), and a
        // directory name is arbitrary bytes (audit 15d-08).
        return PromptFence::escape(self::oneLine(Utf8Scrub::clean($text)));
    }

    /**
     * Every whitespace run — including NEL, LINE SEPARATOR and PARAGRAPH
     * SEPARATOR, which a model renders as line breaks — becomes one space.
     */
    public static function oneLine(string $text): string
    {
        // /u refuses a subject that is not valid UTF-8 and returns null; the
        // ASCII fallback still removes every byte that can break a line, so an
        // invalidly encoded description is collapsed, not passed through raw.
        $collapsed = preg_replace('/[\s\x{85}\x{2028}\x{2029}]+/u', ' ', $text)
            ?? preg_replace('/[\t\n\v\f\r ]+/', ' ', $text)
            ?? '';

        return trim($collapsed);
    }
}
