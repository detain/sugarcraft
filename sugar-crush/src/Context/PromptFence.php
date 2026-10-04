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
 *
 * TWO NEUTRALISATIONS, ONE REWRITE (audit 15d-10). Besides the roster tags,
 * the same `<` → `&lt;` rewrite defangs the openers of pipe-delimited
 * chat-template control tokens ({@see self::CONTROL_TOKEN_PIPES}). The roster
 * guards the fences a model READS; the control tokens guard the role
 * boundaries a tokenizer ENCODES — a different layer, but the same forgery
 * (foreign bytes posing as the harness's own structure) through the same
 * splice, so it rides the one authority every construction site already calls.
 *
 * UNICODE TAG CHARACTERS ARE DELETED, NOT ESCAPED (step 0.14-a). The Tags block
 * U+E0000-U+E007F renders as nothing in every terminal and editor a reviewer
 * uses, yet tokenizers encode it and models read it as ASCII ("ASCII
 * smuggling"): a README can carry an invisible instruction that no human sees
 * in review. Unlike a fence tag there is no information to preserve - the
 * characters have no legitimate use in a prompt payload outside emoji flag
 * sequences, whose loss degrades to a plain black flag - so this block is the
 * one exception to "rewrite, never remove". The strip runs BEFORE the `<`
 * rewrite, so a tag character spliced inside a fence name (`<` U+E0001 `env>`)
 * cannot hide the name from it. It stays byte-oriented like the rewrite: the
 * block is the four-byte UTF-8 range F3 A0 80 80 .. F3 A0 81 BF, matched as
 * bytes, so an invalid-UTF-8 payload is still stripped rather than failed.
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
     * `prior-summary`, the seventh entry and the first the roster takes from a
     * fence that opens OUTSIDE the assembled system prompt:
     * {@see \SugarCraft\Crush\Chat::renderPriorSummariesForSummary()} wraps it in a
     * summariser request, around summary rows read back out of the wire history, whose
     * user half the heuristic fold writes raw. So the bytes between those tags are
     * foreign by exactly the route a repo file is, and a row carrying
     * `</prior-summary>` ends its own block early and hands the rest of the block to
     * the model in the instruction's own voice. It joins at zero golden bytes for the
     * same structural reason as `system-reminder` — nothing on the launch path renders
     * a prior block — and at the cost of re-ruling the verbatim-carry promise the rows
     * travel under, which is a promise about WORDING and survives an escape that
     * changes BYTES (the frame is the FF1 note on
     * {@see \SugarCraft\Crush\Chat::COMPACT_SUMMARY_PROMPT}) — and
     * `harness-injected`, the eighth entry and the only one the roster carries
     * with NO emitter: no `PromptSection` reports it and no construction site
     * opens it, so unlike the seven above it is not derived from a fence that
     * exists. It is the roster's one pre-registered tag, and it is here because
     * the §9.15 harness-voiced channel is coming and this array — not the
     * section list — is the single head every construction site already agrees
     * on. A tag added before the bytes that need it is free; a tag added after
     * leaves a window in which repository-controlled bytes could forge the
     * harness's own provenance voice, exactly the `system-reminder` case above,
     * which is itself proof that a defang-only tag earns its place with nothing
     * emitting it. The ninth entry, `available-skills`, is the fence
     * Runtime::systemPromptSections() builds inline around the level-1 skill
     * listing (audit 15d-02): every name and description in it is text from
     * whoever shipped the skill — a cloned checkout's `.claude/skills` among
     * them, with no trust gate — so a description spelling `</available-skills>`
     * must arrive inert, or it would end its own fence and hand the rest of the
     * line to the model outside the provenance preamble. It joins at zero golden
     * bytes: the golden fixture's one skill is enabled, so its listing is empty.
     *
     * WHAT PINS THIS ROSTER — EIGHT SITES, counted from the tests that read it and not
     * from the note that used to name one. Two are whole-roster: the SORTED list in
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
     * and by the assembler guard below, which is the seventh and the one that proves
     * `harness-injected` is load-bearing on the roster plus guard evidence only:
     * {@see \SugarCraft\Crush\Tests\BaseSystemPromptTest::testAForgedHarnessInjectedCloserInsideAnInstructionDocumentCannotRender()}.
     * No fixture byte moves for it, and that is what made that widening cost zero
     * golden bytes. The eighth is the guard for `prior-summary` at the splice that
     * emits it: the whole-roster map above already forges the tag in both polarities,
     * and
     * {@see \SugarCraft\Crush\Tests\Chat\CompactModelSummaryTest::testAForgedPriorSummaryCloserTravelsIntoTheNextRequestDefanged()}
     * is the assembler-level one — it reads the request the real `/compact` route
     * builds, so a `prior-summary` dropped from this array reappears undefanged
     * inside the carried block and reddens there. The earlier prose here credited
     * only the user-tier guard and was understated by seven.
     *
     * The last two fences open OUTSIDE the system prompt (step 1.A-1/1.A-2):
     * `turn-context` is the volatile per-step state row
     * ({@see \SugarCraft\Crush\Context\TurnContextBlock}) and `system-notice`
     * the user-role row a history notice travels as
     * ({@see \SugarCraft\Crush\Providers\SglangProvider::systemNoticeContent()}).
     * Both are harness voice in the request the model reads, so a repository
     * byte inside the system prompt must not be able to spell them either; each
     * class still neutralises its own name in its own payload as well.
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
        'prior-summary',
        'harness-injected',
        'available-skills',
        'turn-context',
        'system-notice',
    ];

    /**
     * The pipe glyphs that, directly after `<` (or `</`), open a chat-template
     * control token: ASCII `|` for the ChatML / Qwen / Llama-3 / Phi families
     * (`<|im_start|>`, `<|im_end|>`, `<|endoftext|>`, `<|eot_id|>`) and the
     * fullwidth U+FF5C `｜` DeepSeek spells its specials with
     * (`<｜end▁of▁sentence｜>`, `<｜User｜>`, `<｜Assistant｜>`, `</｜DSML｜…>`).
     *
     * WHY: a serving stack's HF tokenizer encodes a special-token literal that
     * appears in message text as the REAL control id unless the deploy turns
     * on split_special_tokens, so an AGENTS.md carrying
     * `<｜end▁of▁sentence｜><｜User｜>` could end the system turn and open a
     * forged user turn below the fence layer, where no tag escape reaches.
     * Special tokens match only as whole strings, so rewriting the leading `<`
     * breaks every one of them while keeping the rest of the bytes as captured.
     *
     * WHY EVERY `<|` AND NOT A TOKEN LIST: the vocabularies are open-ended and
     * per-model (and the deploy's model changes), so a list of known names
     * would be stale on the next swap. The cost is that ordinary text such as
     * Haskell's `<|>` also arrives as `&lt;|>` — inert, information-preserving,
     * and rare in prompt payloads. Families whose specials carry no pipe
     * (Gemma's `<start_of_turn>`, Mistral's `[INST]`) are NOT covered.
     *
     * The fullwidth glyph is matched as its three UTF-8 bytes; the pattern
     * stays byte-oriented (no `/u`) for the reason the class doc gives, and the
     * rewrite touches only the ASCII `<`, so no multibyte sequence is split.
     *
     * @var list<string>
     */
    private const CONTROL_TOKEN_PIPES = ['|', "\u{FF5C}"];

    /**
     * The Unicode Tags block U+E0000-U+E007F as a byte pattern (no `/u`, for
     * the reason the class doc gives): every code point in the block encodes
     * as F3 A0 (80|81) xx.
     */
    private const UNICODE_TAG_BYTES = '~\xF3\xA0[\x80\x81][\x80-\xBF]~';

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
     * $payload with every Unicode tag character (U+E0000-U+E007F) deleted, and
     * the number deleted in $removed.
     *
     * The first half of {@see escape()}, exposed on its own for the bytes that
     * reach the model WITHOUT a fence: a tool result is the model's input as
     * surely as the system prompt is, and an invisible instruction smuggled in
     * a fetched page or an MCP server's reply is the same attack there.
     * {@see \SugarCraft\Crush\Runtime} strips it at its one tool-result seam.
     * Only this block — fence-tag escaping would rewrite legitimate `<env>`
     * text a tool printed.
     */
    public static function stripUnicodeTags(string $payload, ?int &$removed = null): string
    {
        $removed = 0;

        // The str_contains() pre-check (the block's shared lead bytes) keeps
        // the common clean payload off the regex engine entirely.
        if (!str_contains($payload, "\xF3\xA0")) {
            return $payload;
        }

        $stripped = preg_replace(self::UNICODE_TAG_BYTES, '', $payload, -1, $count);
        if ($stripped === null) {
            throw new \RuntimeException(
                'PromptFence::escape(): PCRE failure (' . preg_last_error_msg() . ') while stripping Unicode tag characters',
            );
        }
        $removed = $count;

        return $stripped;
    }

    /**
     * Neutralise every fence tag and chat-template control-token opener inside
     * $payload by rewriting its leading `<` to `&lt;`.
     *
     * A roster tag is `<` or `</`, a roster name in any case, then whitespace
     * (space, tab, newline, CR, VT, FF), `/`, `>` or the end of the payload. So
     * `</env>`, `<env >`, `<env/>`, `</ENV/>` lose their `<`, and so do the
     * attribute-bearing spellings a reader model treats as the same channel —
     * `<system-reminder priority="high">`, `<user-rules\tid=1>`, an attribute
     * list broken across lines — and the UNTERMINATED opener
     * `<system-reminder foo="x"` with no `>` at all: a model reads that as the
     * channel opening just as readily, and lenient tag readers recover a
     * missing `>`, so the escape does not wait for one. Left byte-intact:
     * `<envx>` / `<env-x>` (a different name), `< env>` (invalid tag syntax no
     * fence reader tokenises), and every `<` not followed by a roster name.
     * Separately, `<` or `</` directly followed by a
     * {@see self::CONTROL_TOKEN_PIPES} glyph (`<|im_start|>`,
     * `<｜User｜>`) is rewritten the same way.
     *
     * Before any of that, every Unicode tag character (U+E0000-U+E007F) is
     * deleted outright - see the class doc for why this block alone is
     * removed rather than rewritten.
     *
     * The guarantee that matters downstream: after this call the payload holds
     * no Unicode tag character, and no `<` in it is followed by `/`?, a roster
     * name and one of those terminators, nor by `/`? and a control-token pipe
     * — the replacement contains no `<` and no tag character, so running it
     * again changes nothing.
     */
    public static function escape(string $payload): string
    {
        /** @var non-empty-string|null $pattern */
        static $pattern = null;
        $pattern ??= '~<(?=/?(?:(?:' . implode('|', self::TAGS) . ')(?:[\s/>]|\z)|'
            . implode('|', array_map(static fn(string $pipe): string => preg_quote($pipe, '~'), self::CONTROL_TOKEN_PIPES))
            . '))~i';

        $payload = self::stripUnicodeTags($payload);

        // Only the `<` itself is consumed (the rest is lookahead), so the
        // rewrite cannot reach past it and adjacent tags each match on their own.
        $escaped = preg_replace($pattern, '&lt;', $payload);

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
