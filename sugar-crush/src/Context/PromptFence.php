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
     * construction), `system-reminder` — not a PromptSection fence at all but
     * SkillPathNudge's trust channel
     * ({@see \SugarCraft\Crush\Skills\SkillPathNudge::HEADER}); it joins the
     * roster because a payload that can forge `<system-reminder>` inside any
     * section forges the platform's own reminder voice, which is the more
     * dangerous of the two attacks the acceptance matrix names — and
     * `user-rules` (Runtime::systemPromptSections() inline construction, the
     * P6.S2 rules-tier fence). It joins on the same argument the maxims note
     * refused: not because inert prose needed it, but because a rule body is
     * foreign bytes like a repo file is, and a body carrying `</user-rules>`
     * would close its own fence early and hand the remainder of the render to
     * the model outside any provenance frame. Widening for it costs zero
     * golden bytes (the fixture rule body carries no fence marker) — and
     * `harness-injected`, the seventh entry and the only one the roster carries
     * with NO emitter: no `PromptSection` reports it and no construction site
     * opens it, so unlike the six above it is not derived from a fence that
     * exists. It is the roster's one pre-registered tag, and it is here because
     * the §9.15 harness-voiced channel is coming and this array — not the
     * section list — is the single head every construction site already agrees
     * on. A tag added before the bytes that need it is free; a tag added after
     * leaves a window in which repository-controlled bytes could forge the
     * harness's own provenance voice, exactly the `system-reminder` case above,
     * which is itself proof that a defang-only tag earns its place with nothing
     * emitting it.
     *
     * WHAT PINS THIS ROSTER — FIVE SITES, not the one this note used to name.
     * Two are whole-roster: the SORTED list in
     * {@see \SugarCraft\Crush\Tests\Context\PromptSectionTest::testTheEscapeRosterIsExactlyTheDerivedFenceTagList()}
     * and the DECLARATION-order map in
     * {@see \SugarCraft\Crush\Tests\BaseSystemPromptTest::testForgedInstructionDocumentCannotForgeFencesOrAuthorityVoice()},
     * whose `assertSame(array_keys($expected), tags())` tripwire reddens any
     * widening until the expectation grows with it. Three are tier forgery
     * guards in the same file —
     * {@see \SugarCraft\Crush\Tests\BaseSystemPromptTest::testAForgedUserRuleBodyCannotEscapeItsOwnFence()},
     * {@see \SugarCraft\Crush\Tests\BaseSystemPromptTest::testAForgedProjectRuleBodyCannotEscapeTheProjectInstructionsFence()}
     * and
     * {@see \SugarCraft\Crush\Tests\BaseSystemPromptTest::testAForgedRootRulesFileCannotEscapeTheProjectInstructionsFence()}
     * — each reading a prompt the real assembler produced, so a tag DROPPED from
     * this array stops matching at a splice and reddens its guard's
     * neutralised-copy counts, but only for the tags that guard actually forges.
     * Each of the three forges its own tier's: the user guard plants
     * `</user-rules>` plus the `<system-reminder>` pair, the project and root
     * guards plant the `<user-rules>` pair, and none of the three forges
     * `harness-injected` — so dropping that tag reddens none of them and is
     * caught instead by the two whole-roster sites above, by the escape-level guard
     * {@see \SugarCraft\Crush\Tests\Context\PromptSectionTest::testEscapeNeutralisesTheHarnessInjectedTagThatNothingEmits()}
     * — which reads the roster through escape() alone and never touches a splice —
     * and by the assembler guard named below. The earlier prose here credited only
     * the user-tier
     * guard and was understated by four. `harness-injected` itself is
     * load-bearing on the roster plus guard evidence only, which is what makes
     * this widening cost zero golden bytes:
     * {@see \SugarCraft\Crush\Tests\BaseSystemPromptTest::testAForgedHarnessInjectedCloserInsideAnInstructionDocumentCannotRender()}
     * is the guard that proves it, and no fixture byte moves for it.
     *
     * @var list<string>
     */
    private const TAGS = [
        'env',
        'project-memory',
        'repo-map',
        'project-instructions',
        'system-reminder',
        'user-rules',
        'harness-injected',
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
