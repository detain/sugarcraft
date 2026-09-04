<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

/**
 * The single fence-escape authority for dynamic bytes entering the system prompt.
 *
 * DESIGN SOURCE: prompt_expand.md §9.4 / plan §16.4 ("escape at the fence
 * boundary — one place, not per call site"). Like {@see PromptSection} and
 * {@see Stability}, this is a SugarCraft architecture type, not a port —
 * charmbracelet/crush has no escape authority to mirror, so the repo's
 * "Mirrors charmbracelet/<repo>.<Method>" convention does not apply.
 *
 * WHY A STATIC CLASS AND NOT AN INTERFACE METHOD OR A TRAIT. The seam doc in
 * {@see PromptSection} declared `fence()` as metadata precisely so this step
 * would have "one place to ask escape against which fence?", but the escape
 * itself cannot live on that interface: an interface carries no implementation,
 * and a trait reaches only classes that `use` it — while the fourth production
 * fence (`<project-instructions>`, see Runtime::systemPromptSections()) is built
 * by inline string construction, not by any PromptSection implementant. A
 * dependency-free static is the only shape every construction site can call,
 * which keeps the roster in exactly one head.
 *
 * WHY THE WHOLE ROSTER EVERYWHERE AND NOT JUST THE HOST FENCE. `fence()` names
 * one tag, but escaping only the enclosing tag would leave a payload free to
 * (a) open a NESTED instance of its own or any other fence — unbalancing the
 * open/close counts a reader model uses to find section bounds — and (b) forge
 * `<system-reminder>`, which is a trust channel, not a section. In the
 * assembled prompt every roster tag is a delimiter regardless of which section
 * body carries the bytes, so every site neutralises every roster tag.
 *
 * WHY `&lt;` AND NOT REMOVAL OR CASE-FOLDING. The escape must be inert for the
 * overwhelming majority of payloads (clean repo bytes contain no fence tags at
 * all, so the golden prompt renders byte-identical — pinned by
 * BaseSystemPromptTest), must not destroy information for the rare ones
 * (deletion silently rewrites commit subjects), and must survive being read by
 * a model that has seen HTML entities a trillion times: `&lt;/env>` is
 * unambiguously data-shaped text where `xlt;/env>` or `< /env>` are
 * corruptions or, worse, alternate spellings a lenient parser might still
 * match. Replacing only the leading `<` keeps every other byte of the payload
 * exactly as captured, and makes a second pass provably inert — there is no
 * raw `<` left at a tag start to re-match.
 *
 * BYTE-ORIENTED ON PURPOSE: the pattern carries no `/u` modifier. Diff bodies,
 * commit subjects and file paths can hold invalid UTF-8, the roster names are
 * ASCII, and a `/u` pattern on invalid input does not degrade — it fails
 * (returns null) and would either drop the payload or throw. The class doc of
 * EnvironmentBlock makes the same argument for its `?` substitution; here the
 * consequence of ignoring it would be an exception inside prompt assembly.
 */
final class PromptFence
{
    /**
     * Every tag that opens a production prompt fence, without the angle
     * brackets, as derived from the code (not from any brief's count):
     * `env` (EnvironmentBlock::fence()), `project-memory`
     * (MemoryBlock::fence()), `repo-map` (RepoMapBlock::fence()),
     * `project-instructions` (Runtime::systemPromptSections() inline
     * construction), and `system-reminder` — the last is not a PromptSection
     * fence at all but SkillPathNudge's trust channel
     * ({@see \SugarCraft\Crush\Skills\SkillPathNudge::HEADER}); it joins the
     * roster because a payload that can forge `<system-reminder>` inside any
     * section forges the platform's own reminder voice, which is the more
     * dangerous of the two attacks the acceptance matrix names.
     *
     * @var list<string>
     */
    private const TAGS = [
        'env',
        'project-memory',
        'repo-map',
        'project-instructions',
        'system-reminder',
    ];

    private function __construct()
    {
    }

    /**
     * The escaped roster, for tests and for any later guard that must know
     * exactly what this authority neutralises.
     *
     * @return list<string>
     */
    public static function tags(): array
    {
        return self::TAGS;
    }

    /**
     * Neutralise every fence tag inside $payload by rewriting its leading `<`.
     *
     * Matches an opening or closing tag of every roster name with optional
     * whitespace and optional self-closing slash before `>` — `</env>`,
     * `<env >`, `<env/>`, `</ENV/>` all lose their `<`; bare `<env` (no
     * closer) and `<envx>` (a different name) and `< env>` (invalid tag
     * syntax, which no fence reader tokenises) are left byte-intact. The
     * guarantee that matters downstream: after this call the payload contains
     * no byte sequence that any of the roster's open or close spellings can
     * match, and running it again changes nothing.
     */
    public static function escape(string $payload): string
    {
        /** @var non-empty-string|null $pattern */
        static $pattern = null;
        $pattern ??= '~</?(?:' . implode('|', self::TAGS) . ')\s*/?>~i';

        $escaped = preg_replace_callback(
            $pattern,
            static fn(array $match): string => '&lt;' . substr($match[0], 1),
            $payload,
        );

        // A null here means the pattern itself failed (PCRE backtrack limit on
        // a pathological payload), not "no match" — failing loud beats
        // shipping an unescaped body to every provider because one `??:`
        // returned null. Prompt assembly is not a place for silent fallbacks.
        if ($escaped === null) {
            throw new \RuntimeException(
                'PromptFence::escape(): PCRE failure (' . preg_last_error_msg() . ') while escaping a prompt payload',
            );
        }

        return $escaped;
    }
}
