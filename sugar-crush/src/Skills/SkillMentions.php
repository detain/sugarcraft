<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Skills;

use SugarCraft\Crush\Attachment;
use SugarCraft\Crush\AttachmentType;
use SugarCraft\Crush\Tools\BuiltIn\SkillTool;

/**
 * `$name` in a prompt: that skill's body, for THIS turn only (roadmap 5.14l).
 *
 * `review $security-audit the auth module` sends the prompt as typed with the
 * `security-audit` skill's SKILL.md body attached to it, the way `@file`
 * attaches a file ({@see \SugarCraft\Crush\Attachments\FileMentions}). The
 * standing paths stay what they were: `enabledSkills` puts a body in every
 * turn's system prompt, the Ctrl+S picker enables one for the session, and the
 * model loads one on demand with the `Skill` tool. A mention is the per-turn
 * door between them.
 *
 * THE REGISTRY IS THE GATE, NOT THE SYNTAX. A `$` token is a mention only when
 * {@see SkillRegistry::userInvocable()} answers it — registered, not disabled,
 * and `user-invocable` — so `$HOME`, `$1` and `$PATH` in a pasted shell line
 * are text and nothing else. A name that IS a skill but may not be invoked this
 * way (disabled, or `user-invocable: false`) gets a notice instead of silence,
 * because the user evidently meant it. A `$` inside a `` `code span` `` or a
 * fenced block is never a mention.
 *
 * ON THE WIRE it is a FILE attachment of the skill's `SKILL.md` — its body,
 * frontmatter stripped, read through {@see SkillLoader::loadSkillBody()}, the
 * bounded read the `Skill` tool uses — so it rides the user's row, survives a
 * resume and goes out with the replayed history like any other snapshot. A
 * body that names `$ARGUMENTS` gets the rest of the prompt (the mentions
 * removed) substituted there, as {@see SkillTool::substituteArguments()} does
 * for the tool; one that does not is attached unchanged, because the prompt
 * itself already says what to do.
 *
 * At most {@see MAX_MENTIONS} skills per prompt; later ones get a notice.
 */
final class SkillMentions
{
    /** Most skills one prompt attaches. */
    public const MAX_MENTIONS = 3;

    /** `$` then a skill name, at the start of the prompt or after whitespace. */
    private const MENTION_PATTERN = '/(?<![^\s])\$([A-Za-z0-9][A-Za-z0-9_.:-]*)/u';

    /** Trailing punctuation a sentence puts after a mention. */
    private const TRAILING_PUNCTUATION = '.,;:!?)]}\'"';

    /** Fenced blocks and inline code spans, where a `$` is never a mention. */
    private const CODE_PATTERN = '/```.*?(?:```|\z)|`[^`\n]*`/s';

    private function __construct(
        private readonly SkillRegistry $registry,
        private readonly ?SkillLoader $loader,
    ) {
    }

    /** Mentions resolved against $registry; $loader reads the bodies (a fresh {@see SkillLoader} when null). */
    public static function new(SkillRegistry $registry, ?SkillLoader $loader = null): self
    {
        return new self($registry, $loader);
    }

    /**
     * The distinct names `$`-mentioned in $text, in order, outside code, with
     * trailing sentence punctuation trimmed. Unfiltered: whether each is a
     * skill is {@see resolve()}'s question.
     *
     * @return list<string>
     */
    public static function names(string $text): array
    {
        if (!str_contains($text, '$')) {
            return [];
        }

        $prose = preg_replace(self::CODE_PATTERN, ' ', $text) ?? $text;
        if (preg_match_all(self::MENTION_PATTERN, $prose, $matches) === false) {
            return [];
        }

        $names = [];
        foreach ($matches[1] as $token) {
            $name = rtrim($token, self::TRAILING_PUNCTUATION);
            if ($name !== '') {
                $names[$name] = true;
            }
        }

        return array_map('strval', array_keys($names));
    }

    /**
     * The `$` token the caret sits at the end of, or null when it is not in
     * one — the Tab-completion counterpart of
     * {@see \SugarCraft\Crush\Attachments\FileMentions::tokenAt()}. `start`
     * is the byte offset of the `$`; `partial` is the name typed after it,
     * possibly empty. A `$` inside a `` `code span` `` (an odd number of
     * backticks before it) is not a mention, as in {@see names()}.
     *
     * @return array{start: int, partial: string}|null
     */
    public static function tokenAt(string $buffer, int $caret): ?array
    {
        $caret = max(0, min($caret, \strlen($buffer)));
        $before = substr($buffer, 0, $caret);
        if (!str_contains($before, '$')
            || preg_match('/(?<![^\s])\$([A-Za-z0-9][A-Za-z0-9_.:-]*)?$/u', $before, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        // The token must end at the caret, or the completion would be spliced
        // into the middle of it.
        if ($caret < \strlen($buffer) && !ctype_space($buffer[$caret])) {
            return null;
        }

        $start = $m[0][1];
        if (substr_count(substr($buffer, 0, $start), '`') % 2 === 1) {
            return null;
        }

        return ['start' => $start, 'partial' => (string) ($m[1][0] ?? '')];
    }

    /**
     * Complete a half-typed `$` mention against the skills a user may invoke
     * ({@see SkillRegistry::getUserInvocable()}) the way a shell completes: a
     * unique match comes back whole (`unique`, so the caller may close it
     * with a space), several as their longest common prefix. Null when no
     * name starts with $partial, or the matches add nothing to it.
     *
     * @return array{name: string, unique: bool}|null
     */
    public static function complete(string $partial, SkillRegistry $registry): ?array
    {
        $names = self::completions($partial, $registry);
        if ($names === []) {
            return null;
        }
        if (\count($names) === 1) {
            return ['name' => $names[0], 'unique' => true];
        }

        $common = $names[0];
        foreach ($names as $name) {
            while ($common !== '' && !str_starts_with($name, $common)) {
                $common = substr($common, 0, -1);
            }
        }

        return \strlen($common) > \strlen($partial) ? ['name' => $common, 'unique' => false] : null;
    }

    /**
     * The user-invocable skill names that start with $partial, sorted — what
     * makes a `$` token a completion target at all, so `$HOME` in a shell line
     * leaves Tab alone.
     *
     * @return list<string>
     */
    public static function completions(string $partial, SkillRegistry $registry): array
    {
        $names = [];
        foreach ($registry->getUserInvocable() as $skill) {
            if (str_starts_with($skill->name, $partial)) {
                $names[$skill->name] = true;
            }
        }
        $names = array_map('strval', array_keys($names));
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Every skill `$`-mentioned in $text, snapshotted, plus one notice per
     * mention that names a skill but could not be attached.
     *
     * @return array{attachments: list<Attachment>, notices: list<string>}
     */
    public function resolve(string $text): array
    {
        $attachments = [];
        $notices = [];
        $names = [];
        foreach (self::names($text) as $name) {
            $skill = $this->registry->userInvocable($name);
            if ($skill === null) {
                $reason = match (true) {
                    $this->registry->isDisabled($name) => 'that skill is disabled (disabledSkills)',
                    \in_array($name, array_map('strval', $this->registry->names()), true)
                        => 'that skill is not user-invocable (user-invocable: false)',
                    default => null,
                };
                if ($reason !== null) {
                    $notices[] = sprintf('$%s was not attached: %s.', $name, $reason);
                }

                continue;
            }

            if (\count($attachments) >= self::MAX_MENTIONS) {
                $notices[] = sprintf('$%s was not attached: one prompt attaches at most %d skills.', $name, self::MAX_MENTIONS);

                continue;
            }

            try {
                $body = ($this->loader ?? new SkillLoader())->loadSkillBody($skill->sourcePath);
            } catch (\RuntimeException $e) {
                $notices[] = sprintf('$%s was not attached: %s', $name, $e->getMessage());

                continue;
            }

            $names[] = $name;
            $attachments[] = [$skill->sourcePath, $body];
        }

        if ($attachments === []) {
            return ['attachments' => [], 'notices' => $notices];
        }

        $rest = self::withoutMentions($text, $names);

        return [
            'attachments' => array_map(
                static fn (array $pair): Attachment => new Attachment(
                    $pair[0],
                    AttachmentType::File,
                    str_contains($pair[1], SkillTool::ARGUMENTS_PLACEHOLDER)
                        ? SkillTool::substituteArguments($pair[1], $rest)
                        : $pair[1],
                ),
                $attachments,
            ),
            'notices' => $notices,
        ];
    }

    /**
     * $text with the attached mentions taken out — each with the sentence
     * punctuation after it — and the spacing they leave closed up: what a
     * `$ARGUMENTS` placeholder receives.
     *
     * @param list<string> $names
     */
    private static function withoutMentions(string $text, array $names): string
    {
        foreach ($names as $name) {
            $text = preg_replace(
                '/(?<![^\s])\$' . preg_quote($name, '/') . '[' . preg_quote(self::TRAILING_PUNCTUATION, '/') . ']*(?=\s|\z)/u',
                '',
                $text,
            ) ?? $text;
        }

        return trim(preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text);
    }
}
