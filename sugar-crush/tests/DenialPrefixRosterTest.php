<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Agents\TaskBlockedException;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Permissions\DenialKind;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\ToolResult as ChatToolResult;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * THE THREE WAYS A CALL IS STOPPED ARE THREE STRINGS, AND THE ROSTER THAT
 * CLASSIFIES THEM LIVES IN A DIFFERENT CLASS (E210, E211).
 *
 * {@see Runtime::gate()} is the engine-path producer of a denial reason and
 * {@see DenialKind} is the roster two surfaces read to decide
 * whether a tool result is a REFUSAL (a call that never ran, drawn struck
 * through by {@see \SugarCraft\Crush\Renderer::renderToolResults()} and listed
 * in a `--output-format json` document's `refusals` array by
 * {@see \SugarCraft\Crush\Cli\NonInteractive::refusalFrom()}) or an ordinary
 * tool ERROR (a call that ran and failed, which the model is expected to act
 * on). The producer READS that roster now — {@see Runtime::gate()} holds a
 * {@see DenialKind} and renders it once — which is the end of a three-round
 * arc this doc-block records rather than erases.
 *
 * THE ROSTER MOVED, AND HALF THE REASON THIS FILE EXISTS WENT WITH IT (E239).
 * WHAT THIS DOC-BLOCK SAID: that the roster is `Chat::DENIED_ERROR_PREFIXES`,
 * and that `Runtime` cannot read it because doing so would autoload `Chat` on
 * the first gated tool call of every run, including the `-p` path that exists
 * to avoid building one. WHAT IS TRUE NOW: the roster is {@see DenialKind}, a
 * dependency-free enum in `src/Permissions/`, and that autoload objection no
 * longer applies to it — `Chat::DENIED_ERROR_PREFIXES` is a projection of the
 * enum, and {@see \SugarCraft\Crush\Cli\NonInteractive::refusalFrom()}
 * already classifies against the enum instead (MEASURED, PHP 8.3.6: that path
 * no longer loads `Chat` at all).
 *
 * AND THEN THE COPY WENT TOO (E246), WHICH THIS DOC-BLOCK HAD PRESCRIBED A
 * DELETION FOR AND THE PRESCRIPTION WAS WRONG. WHAT IT SAID: that
 * `Runtime`'s three `DENIAL_*` constants are still three string literals, so
 * until they are re-pointed at the enum's cases "this file is the only thing
 * making that copy loud", and "the day they are, the membership test below
 * becomes a tautology and should be deleted with them — not before". WHAT IS
 * TRUE NOW: they are re-pointed, and
 * {@see self::testEveryDenialPrefixRuntimeProducesIsOnChatsRoster()} is NOT a
 * tautology. MEASURED on PHP 8.3.6 at round 49: substituting
 * `public const DENIAL_HOOK = 'Nope:';` leaves
 * {@see self::testTheOnlyDenialShapedLiteralsInSrcAreTheLeafsAndOneEarnedException()}
 * GREEN — the walk matches a denial-shaped literal and `Nope:` carries no
 * denial term, so the widest guard in this file cannot see it — while the
 * membership test KILLS it. The two guards cover different holes: the walk
 * catches a second spelling that LOOKS like a denial, and the membership test
 * catches a constant that has stopped being one.
 *
 * AND ONE HOLE THEY NO LONGER COVER BETWEEN THEM, WHICH IS E246's OWN COST.
 * Recorded here rather than left to a backlog line, because a reader of the
 * paragraph above would otherwise take "not a tautology" for "nothing was
 * lost". The membership test used to compare a LITERAL prefix against a
 * roster derived from {@see DenialKind}, so it also caught a respelling of a
 * CASE; now both sides derive from the enum and move together. MEASURED on
 * PHP 8.3.6 at round 49, both halves: substituting
 * `case Hook = 'Blocked:';` leaves this entire file GREEN at HEAD (12 tests,
 * 81 assertions), and FAILS the membership test at its `assertContains` when
 * `Runtime`'s three constants are put back in their pre-E246 literal form.
 * {@see self::testTheRostersBackingValuesAreTheThreePublishedPrefixes()} is
 * the replacement, and it is deliberately spelled out rather than derived.
 *
 * WHY THE PRESCRIPTION STILL
 * EARNS ITS PLACE: it is the reason this paragraph checked before deleting,
 * and a reviewer reading only the first half would have taken it.
 *
 * WHAT IS LEFT WHEN A PRODUCER DOES NOT READ ITS ROSTER is a copy that can
 * drift, and the drift is silent in the worst direction: a prefix
 * `Runtime` invents that the roster does not carry renders a BLOCKED call as a
 * failed one on both surfaces. That is the failure this file was built for and
 * the reason it survives the copy's removal: `DENIAL_*` is `public const` on a
 * class an embedder reads, so re-literalising one is a one-line edit.
 *
 * It is deliberately not in `tests/Cli` or `tests/Hooks`: the contract spans
 * `Runtime` (producer), `Chat` (roster + classifier) and `NonInteractive`
 * (second consumer), and filing it under any one of the three would put it
 * where a reader of the other two will not look.
 */
final class DenialPrefixRosterTest extends TestCase
{
    /**
     * THE FRAME: a capitalised word run of at most four words ending in `:`,
     * anywhere in the literal that is not mid-word.
     *
     * Four because `Permission required:` is two and the longest invented
     * spelling worth worrying about (`Tool call rejected by policy:`) is four;
     * an unbounded run would swallow the first colon of an entire sentence.
     *
     * IT USED TO BE ANCHORED WITH `^`, AND THAT ANCHOR WAS A HOLE THE WIDTH OF
     * THIS TREE'S OWN PRODUCERS (round 49). WHAT IT SAID: that a denial
     * spelling is what a literal OPENS with, mirroring
     * {@see DenialKind::classify()}'s `str_starts_with`. WHAT IS TRUE NOW: the
     * two are different questions. `classify()` is handed a FINISHED reason,
     * which does open with its prefix; this scanner is handed a SOURCE
     * literal, and a producer is free to decorate one — `src/Chat.php`'s
     * refusal note was written `"_Permission denied: {$name} was not run._"`,
     * whose interpolated run therefore begins `_`. MEASURED on PHP 8.3.6 by
     * re-introducing that exact line and running this file: under `^` the
     * guard stayed GREEN on `'src/Chat.php' => 0`, i.e. it could not see the
     * very producer E236 removed. A leading space did the same. The lookbehind
     * costs nothing that was being caught: re-run over the whole of `src/`,
     * both alphabets name the SAME three files
     * ({@see self::testTheOnlyDenialShapedLiteralsInSrcAreTheLeafsAndOneEarnedException()}
     * names the two that are left after E246 took the third), and no row moved.
     *
     * `(?<![A-Za-z])` and not a bare unanchored match: without it `Refused:`
     * inside `PermissionRefused:` would count, which is one identifier and not
     * two spellings.
     */
    private const DENIAL_SHAPE = '/(?<![A-Za-z])[A-Z][A-Za-z]*(?: [A-Za-z]+){0,3}:/';

    /**
     * THE VOCABULARY: at least one of these words must appear inside the
     * frame. This is what separates `Blocked:` (a denial nobody put on the
     * roster) from `Tool not found:` (an ordinary error), which the frame
     * alone cannot do because they are the same shape.
     *
     * `not` is only ever matched as part of a two-word phrase, deliberately:
     * bare `not` is what makes `Tool not found:` a false positive.
     *
     * FOUR WORDS ADDED AT ROUND 49, AND THE REASON IS THAT A VOCABULARY IS
     * WRITTEN TO MATCH THE CASES ALREADY KNOWN. `declined`, `prohibited`,
     * `vetoed` and `barred` are ordinary English for refusing a thing and none
     * of them was here; MEASURED on PHP 8.3.6 by carrying each as a dead
     * `private const` in `src/Permissions/ToolRefusal.php`, every one SURVIVED
     * the whole of this file. Adding them cost nothing measurable: re-scanned
     * over `src/` with the wider list, the whole-tree map
     * ({@see self::testTheOnlyDenialShapedLiteralsInSrcAreTheLeafsAndOneEarnedException()})
     * names the same two files and the same four literals. The list is still
     * a list, so this paragraph is an admission as much as a fix — the next
     * invented verb that is not on it is invisible in exactly this way, and
     * {@see self::ROSTER_CASE_VARIANTS_ARE_CAUGHT_BY} covers only the one
     * sub-case that can be closed mechanically.
     */
    private const DENIAL_TERMS = '/\b(?:den(?:y|ied|ial)|refus(?:e|ed|al)|block(?:ed)?|reject(?:ed)?'
        . '|declin(?:e|ed)|prohibited|vetoed|barred'
        . '|forbidden|disallowed|unauthori[sz]ed|required|not (?:allowed|permitted|granted))\b/i';

    /**
     * A NOTE, NOT A PATTERN: the frame above requires a CAPITALISED opener, so
     * a lowercase respelling of a roster entry — `permission denied:` — is a
     * shape it cannot express, and that one is sharp:
     * {@see Chat::isDeniedResult()} matches case-sensitively, so a producer
     * writing it lowercase has authored a refusal that renders as an ordinary
     * tool error on both surfaces.
     *
     * WHY NOT SIMPLY WIDEN THE OPENER TO `[A-Za-z]`, which is the obvious fix
     * and was the one prescribed. MEASURED on PHP 8.3.6: it works, and it
     * costs the lookbehind. With the capitalised opener, `(?<![A-Za-z])`
     * changes the verdict on 639-691 of 200,000 random strings built from a
     * 21-token alphabet of this tree's own denial words (four seeds:
     * 49491/20260824/777/1); with an `[A-Za-z]` opener it changes the verdict
     * on ZERO of the same 200,000, four times over, because a frame that may
     * begin with any letter always has a leftmost match at a word start
     * anyway. Widening the frame would leave a live-looking assertion in the
     * pattern doing nothing at all.
     *
     * So the case gap is closed by
     * {@see self::isCaseVariantOfARosterPrefix()} instead, which is narrower
     * and is tied to the actual mechanism: a literal that spells a ROSTER
     * PREFIX in a case the roster does not carry.
     */
    private const ROSTER_CASE_VARIANTS_ARE_CAUGHT_BY = 'isCaseVariantOfARosterPrefix';

    private ProviderInterface $provider;
    private HookRegistry $hookRegistry;
    private Runtime $runtime;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('test-provider');

        $this->hookRegistry = new HookRegistry();
        $this->runtime = new Runtime($this->provider, new HookManager($this->hookRegistry));
    }

    // ── the coupling ─────────────────────────────────────────────────────

    /**
     * EVERY PREFIX `Runtime` PRODUCES IS ON THE ROSTER.
     *
     * Enumerated by reflection rather than listed here, so a fourth
     * `DENIAL_*` constant added later is covered by this test on the day it is
     * added rather than on the day someone remembers to extend the test.
     */
    public function testEveryDenialPrefixRuntimeProducesIsOnChatsRoster(): void
    {
        $produced = self::runtimeDenialPrefixes();

        self::assertNotSame([], $produced, 'no DENIAL_* constants found; this guard is scanning nothing');

        foreach ($produced as $name => $prefix) {
            self::assertContains(
                $prefix,
                Chat::DENIED_ERROR_PREFIXES,
                "Runtime::{$name} is '{$prefix}', which Chat::DENIED_ERROR_PREFIXES does not carry - so a call "
                . 'blocked this way renders as an ordinary tool ERROR in the TUI and is missing from the '
                . '--output-format json refusals array. Add it to the roster or reuse an entry that is on it',
            );
        }
    }

    /**
     * AND THE ROSTER'S BACKING VALUES ARE THESE THREE STRINGS, WRITTEN OUT.
     *
     * THIS IS COVERAGE E246 REMOVED. Before it, `Runtime`'s three `DENIAL_*`
     * constants were string literals, so
     * {@see self::testEveryDenialPrefixRuntimeProducesIsOnChatsRoster()}
     * compared a literal against a derived roster and a respelled case broke
     * the comparison. Making both sides derive is the right fix for drift and
     * it is also what made that comparison self-consistent by construction, so
     * the published STRINGS need a pin of their own. The measurement is in
     * this class's doc-block.
     *
     * SPELLED OUT AND NOT DERIVED, deliberately — the same treatment
     * {@see self::testTheDocumentsKindTokenIsLowercaseAndIsTheThreePublishedWords()}
     * gives the tokens, and for the same reason. Every other assertion in this
     * file reads the prefixes through {@see DenialKind::prefixes()} or through
     * a projection of it, so all of them move together with a respelling.
     * These three are the bytes a finished reason OPENS with, matched
     * case-sensitively by {@see Chat::isDeniedResult()} and by anything
     * out-of-process reading a run's stderr or its `refusals` array. Changing
     * one is a change to what this application publishes and should cost a
     * deliberate edit here.
     *
     * ORDER IS PINNED TOO, with `assertSame`: at least one consumer iterates
     * the roster, and {@see DenialKind::prefixes()} promises `cases()` order.
     */
    public function testTheRostersBackingValuesAreTheThreePublishedPrefixes(): void
    {
        self::assertSame(
            ['Permission denied:', 'Permission required:', 'Hook denied:'],
            DenialKind::prefixes(),
            'a DenialKind case has been respelled. The roster and every consumer of it derive from this '
            . 'enum, so no other assertion in this tree can see the change: these three strings are what '
            . 'a finished denial reason opens with and what an out-of-process reader matches on',
        );
    }

    /**
     * AND THE CLASSIFIER AGREES, which is a different claim from membership:
     * {@see Chat::isDeniedResult()} matches with `str_starts_with` against
     * `$result->error`, so a prefix that is on the roster but is not how the
     * finished reason actually STARTS is still misclassified.
     *
     * The negative case is in the same test on purpose (rule 15): an assertion
     * that three strings are recognised proves nothing about the classifier
     * unless something it must NOT recognise is put through it too.
     *
     * TWO CLASSES ARE CALLED `ToolResult` IN THIS APPLICATION and this test
     * touches both: {@see \SugarCraft\Crush\ToolResult} (aliased
     * `ChatToolResult` here) is the transcript-side value with the nullable
     * `$error` the classifier reads, and {@see \SugarCraft\Crush\Tools\ToolResult}
     * is the engine-side one {@see Runtime::failure()} builds with an
     * `isError` flag and the reason in `content()`. Aliased rather than
     * fully-qualified inline so the difference is visible at every use.
     */
    public function testTheClassifierRecognisesEachPrefixAndStillRefusesAPlainError(): void
    {
        foreach (self::runtimeDenialPrefixes() as $name => $prefix) {
            self::assertTrue(
                Chat::isDeniedResult(ChatToolResult::error('Bash', $prefix . ' something', 'call_1')),
                "Chat::isDeniedResult() does not recognise a result opening with Runtime::{$name}",
            );
        }

        self::assertFalse(
            Chat::isDeniedResult(ChatToolResult::error('Bash', 'No such file or directory', 'call_1')),
            'the classifier calls an ordinary tool failure a refusal; the assertions above prove nothing',
        );
    }

    // ── the three events, driven for real ────────────────────────────────

    /**
     * A HOOK OBJECTING IS `Hook denied:`.
     */
    public function testAChainDenyIsReportedAsAHookDenial(): void
    {
        $this->hookRegistry->register(self::denyHook('rm -rf is not allowed'));

        self::assertStringStartsWith(
            Runtime::DENIAL_HOOK . ' ',
            $this->reasonFor(null),
        );
    }

    /**
     * AN APPROVER ANSWERING NO IS `Permission denied:` — the user's own
     * decision, and the one of the three that is NOT the hook's doing.
     *
     * Before E210 this produced `Hook denied: <the hook's question>`, which
     * named the wrong party and pointed a reader at the hook configuration
     * rather than at the answer they had just typed.
     */
    public function testAnApproverRefusalIsReportedAsAPermissionDenial(): void
    {
        $this->hookRegistry->register(self::askHook('Run it?'));

        $reason = $this->reasonFor(static fn (ToolCall $c, HookResult $a): bool => false);

        self::assertStringStartsWith(Runtime::DENIAL_REFUSED . ' ', $reason);
        self::assertStringContainsString('Run it?', $reason, 'the question the user answered is gone');
    }

    /**
     * AND AN ASK WITH NOBODY ATTACHED IS `Permission required:` — nobody
     * refused it, there was nobody to ask.
     *
     * The wording check is not decoration: this arm's message used to open
     * "Permission required and no approver…" and be prefixed `Hook denied: `,
     * so the finished reason named two different events in one line. The words
     * a reader greps for are still all present.
     */
    public function testAnAskWithNoApproverIsReportedAsPermissionRequired(): void
    {
        $this->hookRegistry->register(self::askHook('Delete production data?'));

        $reason = $this->reasonFor(null);

        self::assertStringStartsWith(Runtime::DENIAL_UNANSWERED . ' ', $reason);
        self::assertStringContainsString('no approver is attached to this run', $reason);
        self::assertStringContainsString('Delete production data?', $reason);
    }

    /**
     * THE THREE ARE MUTUALLY DISTINGUISHABLE, which is the property E210 is
     * actually about and which no one of the three tests above establishes:
     * all three could pass while two prefixes were the same string.
     */
    public function testTheThreeOutcomesDoNotCollapseIntoOneString(): void
    {
        $this->hookRegistry->register(self::denyHook('nope'));
        $hookDeny = $this->reasonFor(null);

        $this->hookRegistry = new HookRegistry();
        $this->runtime = new Runtime($this->provider, new HookManager($this->hookRegistry));
        $this->hookRegistry->register(self::askHook('Run it?'));

        $refused = $this->reasonFor(static fn (ToolCall $c, HookResult $a): bool => false);
        $unanswered = $this->reasonFor(null);

        $prefixes = array_map(
            static fn (string $r): string => explode(':', $r, 2)[0] . ':',
            [$hookDeny, $refused, $unanswered],
        );

        self::assertSame($prefixes, array_unique($prefixes), 'two of the three denials are still one string');
    }

    // ── no second definition of a prefix ─────────────────────────────────

    /**
     * `Runtime` SPELLS A DENIAL PREFIX IN EXACTLY ONE PLACE EACH — the
     * constant — so the coupling the tests above pin cannot be sidestepped by
     * a hand-rolled literal somewhere else in the file.
     *
     * Compared as SETS against the constants themselves rather than against a
     * count: a cardinality written into a test rots the moment the file grows
     * a fourth outcome, and the claim worth making is "no literal that is not a
     * constant's own value", which is a set difference.
     *
     * Token-based, not `strpos`: doc-blocks in this file discuss all three
     * prefixes at length, and a textual scan would report every paragraph as a
     * second definition.
     *
     * BOTH STRING TOKEN KINDS, AND THE SECOND IS THE ONE THAT MATTERS. The
     * first cut of this scanner read `T_CONSTANT_ENCAPSED_STRING` only, and
     * MEASURED on PHP 8.3.6 that would have missed the exact defect it exists
     * to catch: the line it replaced was `"Hook denied: {$hookResult->message}"`,
     * an INTERPOLATED string, whose literal half is `T_ENCAPSED_AND_WHITESPACE`.
     *
     * THE CENSUS THAT CLAIM CITED WAS WRONG IN BOTH HALVES, and it is
     * corrected here rather than removed because the conclusion it supports is
     * right. WHAT IT SAID: the constant-only scan over `src/Chat.php` reported
     * "three hits — the roster's own entries at its three constant lines — and
     * zero for either of that file's two hand-rolled producers". WHAT IS TRUE
     * NOW, re-derived on PHP 8.3.6 by running this class's own
     * {@see self::denialLiteralsIn()} over `src/Chat.php`: that file has THREE
     * hand-rolled interpolated producers, not two —
     * {@see Chat::answerPermission()}, {@see Chat::forkToolCalls()} and
     * {@see Chat::gateToolCall()} — and under the OLD alphabet the
     * constant-only scan reported FOUR, not three: the roster's three entries
     * plus `'Permission mode: %s — from %s'`, a `sprintf` template in the
     * permission-summary line that is not a denial prefix at all. Under the
     * widened alphabet that fourth hit is gone (`mode` is not a denial term)
     * and the interpolated three are seen.
     *
     * THAT PARAGRAPH THEN CLAIMED COMPLETENESS AND THE CLAIM WAS FALSE, which
     * is corrected here because the false half is what hid a live hole for a
     * round. WHAT IT SAID: "the scan over that file is now exactly the six
     * real spellings". WHAT IS TRUE, counted at `db90e768` — the commit that
     * file's pre-E236 state is preserved at — by listing every denial prefix
     * in `src/Chat.php` outside a comment: there were SEVEN, not six. The
     * seventh is {@see Chat::answerPermission()}'s system note,
     * `"_Permission denied: {$name} was not run._"`, whose interpolated run
     * opens with `_` and which the then-`^`-anchored frame could not match.
     * So the scan saw six of seven and the sentence rounded that to "exactly
     * the six real spellings" — the direction that makes an incomplete guard
     * read as an exhaustive one. The frame is unanchored now and the missing
     * shape has fixtures below. WHY THE PARAGRAPH STILL EARNS ITS PLACE:
     * the finding it records — that a constant-only scanner is blind to every
     * producer in this tree — is unchanged and is why the scan reads both
     * token kinds. A guard that cannot see the shape the code is actually
     * written in is a guard whose emptiness means nothing.
     */
    public function testRuntimeSpellsNoDenialPrefixOutsideItsConstants(): void
    {
        $source = self::runtimeSource();

        // KNOWN-POSITIVE FIRST (rule 15). The assertion below is an emptiness
        // claim, and an emptiness claim over a dead scanner is green in a tree
        // where anything at all is wrong. The fixture is assembled from parts
        // so this test file is not itself matched if the scan set widens.
        $literal = "'Hook " . "denied: hand rolled'";
        self::assertSame(
            ['Hook denied: hand rolled'],
            self::denialLiteralsIn("<?php \$x = {$literal};"),
            'the literal scanner can no longer see a denial string it is looking straight at',
        );

        // AND THE INTERPOLATED SHAPE, which is the one the tree is written in
        // and the one the first cut of this scanner could not see at all.
        $interpolated = '"Permission ' . 'denied: {$why}"';
        self::assertSame(
            ['Permission denied: '],
            self::denialLiteralsIn("<?php \$y = {$interpolated};"),
            'the scanner is blind to an interpolated denial string, which is how every producer in this '
            . 'tree spells one',
        );
        self::assertSame(
            [],
            self::denialLiteralsIn('<?php /** Hook denied: only in a comment */ $x = 1;'),
            'the scanner reads comments, so it cannot answer this question at all',
        );

        // AND THE THREE SHAPES THE FIRST ALPHABET COULD NOT EXPRESS. Each was
        // MEASURED as a SURVIVING mutation on PHP 8.3.6 — a dead `private
        // const` in src/Runtime.php carrying it, with this file green — before
        // the vocabulary replaced `/^(Hook|Permission) [a-z]+:/`. An off-roster
        // opener is the worst of the three: Chat::isDeniedResult() compares
        // case-sensitively, so `Blocked:` is a refusal that renders as an
        // ordinary error on both surfaces and nothing else in the tree notices.
        foreach ([
            'a capitalised verb' => 'Permission ' . 'Blocked: nope',
            'a two-word verb' => 'Permission ' . 'not granted: nope',
            'an opener that is on neither roster' => 'Blo' . 'cked: nope',
            'a verb the roster has never used' => 'Tool call ' . 'rejected: nope',
        ] as $why => $shape) {
            self::assertSame(
                [$shape],
                self::denialLiteralsIn('<?php $z = ' . var_export($shape, true) . ';'),
                "the scanner cannot express {$why}, so its emptiness says nothing about that shape",
            );
        }

        // AND THE FOUR SHAPES THE SECOND ALPHABET COULD NOT EXPRESS EITHER,
        // found by the round-49 review widening this scan's own word list
        // (rule 11). Each was MEASURED as a SURVIVING mutation on PHP 8.3.6 —
        // carried as a dead `private const` in src/Permissions/ToolRefusal.php
        // with `--filter DenialPrefixRoster` green — before the vocabulary
        // grew and the case-variant rule landed. Assembled from parts so this
        // file is never matched by its own scan set.
        foreach ([
            'a verb the vocabulary had never heard' => 'Tool call ' . 'declined: nope',
            'a second one' => 'Tool call ' . 'prohibited: nope',
            'a one-word opener that is one of them' => 'Vet' . 'oed: nope',
        ] as $why => $shape) {
            self::assertSame(
                [$shape],
                self::denialLiteralsIn('<?php $v = ' . var_export($shape, true) . ';'),
                "the scanner cannot express {$why}, so its emptiness says nothing about that shape",
            );
        }

        // AND THE SHARP ONE, which is not a vocabulary gap but a CASE gap: a
        // roster entry respelled in lowercase is a refusal that
        // Chat::isDeniedResult() will not recognise, because it compares with
        // str_starts_with. The capitalised frame cannot see it at all, so this
        // row is the only thing that pins
        // self::isCaseVariantOfARosterPrefix() — kill that helper and nothing
        // else in this file goes red.
        $lowercased = 'permis' . 'sion denied: rm -rf';
        self::assertSame(
            [$lowercased],
            self::denialLiteralsIn('<?php $w = ' . var_export($lowercased, true) . ';'),
            'the scanner cannot see a roster prefix respelled in a case the roster does not carry, which '
            . 'is a BLOCKED call rendered as an ordinary tool ERROR on both surfaces',
        );

        // AND ITS NEGATIVE: the correctly-cased prefix must not be reported
        // TWICE, once by the frame and once by the case rule.
        $exact = 'Permis' . 'sion denied: rm -rf';
        self::assertSame(
            [$exact],
            self::denialLiteralsIn('<?php $u = ' . var_export($exact, true) . ';'),
            'a correctly-spelled prefix is now reported twice, so the whole-src map counts one literal as '
            . 'two and reds on a tree that is right',
        );

        // AND THE SHAPES THE `^` ANCHOR COULD NOT EXPRESS (round 49). Each is
        // a DECORATED literal — the frame is present, but something precedes
        // it — which is exactly how this tree's own refusal note was written.
        // MEASURED as a SURVIVING mutation on PHP 8.3.6: src/Chat.php's
        // pre-E236 line re-introduced verbatim left the whole-src map's row
        // for that file absent, so the guard could not see the one producer it
        // is named after. Assembled from parts so this file is
        // never matched by its own scan set.
        foreach ([
            'an underscore-wrapped note, which is how Chat spelled its refusal'
                => ['"_Permission ' . 'denied: {$n} was not run._"', '_Permission denied: '],
            'a leading space'
                => ['" Permission ' . 'required: {$n}"', ' Permission required: '],
            'a frame that follows a sentence'
                => ['"tool stopped. Hook ' . 'denied: {$m}"', 'tool stopped. Hook denied: '],
        ] as $why => [$fixture, $expected]) {
            self::assertSame(
                [$expected],
                self::denialLiteralsIn('<?php $d = ' . $fixture . ';'),
                "the scanner cannot see {$why}, so a producer that decorates its prefix is invisible to "
                . 'the whole-src map in this file',
            );
        }

        // AND THE LOOKBEHIND'S OWN NEGATIVE, which is the cost of widening.
        // The frame must not begin MID-WORD: dropping `(?<![A-Za-z])` makes a
        // bare unanchored match read the tail of a lowercase-led identifier as
        // a spelling. MEASURED on PHP 8.3.6, and stated honestly: over `src/`
        // as it stands the two variants name the same three files and the same
        // seven literals, so this lookbehind is a bound on what the widening
        // can newly match rather than a fix for anything present today. The
        // fixture is here because that is a property of the tree and the
        // lookbehind is a property of the guard.
        //
        // NOT `"XPermission denied:"`, which was this fixture's first form and
        // was wrong: `X` opens a word, so that string is a legitimate
        // capitalised two-word frame and the scanner is right to see it. The
        // discriminating case has to be lowercase-led.
        self::assertSame(
            [],
            self::denialLiteralsIn('<?php $z = "errHook ' . 'denied: nope";'),
            'the scanner matches a frame starting mid-word, so the tail of one identifier now counts as a '
            . 'denial spelling and the widening has bought a false positive',
        );

        // AND THE NEGATIVE THE FRAME ALONE WOULD HAVE GOT WRONG. `Tool not
        // found:` is the same SHAPE as `Blocked:` and is an ordinary tool
        // error; src/Runtime.php spells it twice. Widening on shape alone —
        // the obvious fix, and the one prescribed — reddens this guard on the
        // day it lands. This fixture is what stops that being rediscovered.
        self::assertSame(
            [],
            self::denialLiteralsIn('<?php $z = "Tool ' . 'not found: bash";'),
            'the scanner now calls an ordinary tool error a denial prefix, which reddens this guard against '
            . 'src/Runtime.php on two lines that are entirely correct',
        );

        // AND THE SAME NEGATIVE FOLLOWED BY A REAL ONE, which is the leftmost-
        // frame hole. Judged one frame at a time, this literal is reported;
        // judged only on its FIRST frame it was not, because `Tool not found:`
        // is innocent and came first.
        $twoFrames = 'Tool ' . 'not found: bash. Hook ' . 'denied: nope';
        self::assertSame(
            [$twoFrames],
            self::denialLiteralsIn('<?php $t = ' . var_export($twoFrames, true) . ';'),
            'the scanner judges only the first colon-run in a literal, so a denial prefix behind an '
            . 'innocent one is invisible to the whole-src map',
        );

        $declared = array_values(self::runtimeDenialPrefixes());
        $found = self::denialLiteralsIn($source);

        $extra = array_values(array_filter(
            $found,
            static function (string $literal) use ($declared): bool {
                foreach ($declared as $prefix) {
                    if ($literal === $prefix) {
                        return false;
                    }
                }

                return true;
            },
        ));

        self::assertSame(
            [],
            $extra,
            'src/Runtime.php spells a denial prefix outside its DENIAL_* constants. A second spelling is a '
            . 'second definition, and the one that drifts off Chat::DENIED_ERROR_PREFIXES will do so silently',
        );
    }

    // ── one spelling, in one file, across the whole of src/ ──────────────

    /**
     * THE ONE DENIAL-SHAPED LITERAL IN `src/` THAT IS NOT ON THE ROSTER, and
     * the class it belongs to.
     *
     * Keyed by path and valued by the class whose being a {@see \Throwable}
     * is what EARNS the exclusion — see
     * {@see self::testTheOneOffRosterShapeInSrcIsAThrowableMessageAndNotAFourthKind()},
     * which is where the earning happens. A path listed here with no
     * mechanically checkable reason would be the "a comment saying this one is
     * different" that E247 explicitly rejects.
     *
     * @var array<string, class-string<\Throwable>>
     */
    private const OFF_ROSTER_THROWABLE_SHAPES = [
        'src/Agents/TaskBlockedException.php' => TaskBlockedException::class,
    ];

    /**
     * EVERY DENIAL PREFIX IN `src/` IS SPELLED IN `DenialKind`, WITH ONE
     * EARNED EXCEPTION (E239, E246, E247).
     *
     * IT USED TO BE FIVE NAMED FILES AND THAT WAS THE WRONG SHAPE OF GUARD.
     * WHAT IT SAID: a `FAMILY_SPELLINGS` map of five paths with an expected
     * count each — the leaf at 3 and four consumers at 0 — defended on the
     * grounds that "named files rather than a walk of `src/`" was a
     * measurement rather than timidity, because a whole-tree scan turns up
     * `src/Agents/TaskBlockedException.php` and would red the guard on a
     * string that is entirely correct. WHAT IS TRUE NOW: that is an argument
     * for classifying the one straggler, not for not looking. A per-file map
     * cannot see a SIXTH file — the shape the guard exists to catch is a
     * producer inventing a prefix, and a producer is free to be in a file
     * nobody thought to list. Re-derived on PHP 8.3.6 at round 49: the walk
     * finds hits in TWO files now that E246 took the last copy out of
     * `src/Runtime.php`, and both are named below.
     *
     * WHY THE OLD DOC-BLOCK STILL EARNS ITS PLACE: the measurement in it — that
     * `TaskBlockedException` matches the vocabulary and is not a denial — is
     * the whole content of E247 and is still true. What changed is that it is
     * now earned by a test rather than asserted in prose.
     *
     * THE ASSERTION IS THE WHOLE MAP, not a per-file count, and it carries its
     * own known-positive (rules 15 and 25). Four of the five old rows expected
     * ZERO and a zero is also what a dead scanner returns; a map whose
     * expected value names two files and four exact strings cannot be
     * satisfied by an instrument that has stopped matching, stopped resolving
     * paths or stopped reading a token kind.
     */
    public function testTheOnlyDenialShapedLiteralsInSrcAreTheLeafsAndOneEarnedException(): void
    {
        $expected = [
            'src/Agents/TaskBlockedException.php' => ['Task creation blocked: '],
            'src/Permissions/DenialKind.php' => DenialKind::prefixes(),
        ];

        $actual = self::denialLiteralsAcrossSrc();

        self::assertSame(
            $expected,
            $actual,
            'src/ spells a denial-shaped literal somewhere other than the two files that have earned one. '
            . 'Every tool-result prefix belongs to a DenialKind case; a second spelling is a second '
            . 'definition, and the one that drifts renders a BLOCKED call as an ordinary tool ERROR on '
            . 'both surfaces. If the new hit is not a tool-result prefix at all, it needs the mechanical '
            . 'exclusion OFF_ROSTER_THROWABLE_SHAPES carries, not a row added here',
        );

        // AND THE FIVE FILES THE OLD MAP NAMED, called out by name so their
        // absence from the map above is a stated claim rather than something a
        // reader has to notice. These are the roster's two classifiers and the
        // surfaces that render its answer; a prefix appearing in any of them
        // is a consumer that has stopped agreeing with the roster.
        //
        // THESE ROWS ARE A RESTATEMENT AND NOT INDEPENDENT COVERAGE, which is
        // worth saying plainly: whenever the whole-map assertSame above holds,
        // $expected has exactly two keys and none of these five can be one, so
        // every row below is unconditionally true. They exist so a reader
        // grepping for a consumer's path finds the claim, and they read the
        // map that was already computed -- calling the walk again per row cost
        // five extra 292-file token_get_all passes for nothing.
        foreach ([
            'src/Chat.php',
            'src/Renderer.php',
            'src/Cli/NonInteractive.php',
            'src/Cli/HeadlessPermissionPrompt.php',
            'src/Runtime.php',
        ] as $consumer) {
            self::assertArrayNotHasKey($consumer, $actual, "{$consumer} spells a "
                . 'denial prefix. It is a reader of the roster, not an author of one');
        }
    }

    /**
     * E247's VERDICT: `Task creation blocked:` IS A COINCIDENCE OF WORDING AND
     * NOT A FOURTH DENIAL KIND — and the discriminator is mechanical.
     *
     * THE EVIDENCE, in the order it decides the question.
     *
     * FIRST, WHAT A DenialKind IS. Each case is the text a TOOL RESULT opens
     * with — {@see DenialKind::classify()} is handed `$result->content()` by
     * {@see \SugarCraft\Crush\Permissions\ToolRefusal::fromEvent()} and
     * `$result->error` by {@see Chat::isDeniedResult()}, and both answers
     * decide how ONE TOOL CALL is drawn and reported. A fourth kind would have
     * to be a fourth way a tool call is stopped before it runs.
     *
     * SECOND, WHAT THIS ONE IS. It is the default constructor message of a
     * {@see \Throwable}, raised when a `TaskCreated` hook blocks the
     * insertion of a row into an agent team's task list
     * ({@see \SugarCraft\Crush\Agents\TaskList::addTask()}). It is not a
     * tool call, it does not pass through
     * {@see \SugarCraft\Crush\Runtime::gate()}, and nothing turns it into a
     * `ToolResult`. Asserted rather than asserted-in-prose: the class is a
     * `\Throwable` subclass, and no file in `src/` both names it and names
     * `ToolResult`.
     *
     * THAT SCAN IS NECESSARY AND NOT SUFFICIENT, and saying so costs less than
     * a reader finding out. WHAT THIS PARAGRAPH SAID: that naming both symbols
     * in one file is "the one edit" that would make this message a tool-result
     * opener and so a real fourth kind. WHAT IS TRUE NOW, measured on
     * PHP 8.3.6 at round 49: `src/Chat.php`'s tool callback already catches
     * `\Throwable` and returns `ToolResult::error($name, $e->getMessage(), …)`
     * with the message UNPREFIXED, into the very field
     * {@see Chat::isDeniedResult()} reads — and a generic catch need not name
     * this class to swallow it. So there is a path this scan cannot see. WHY
     * THE SCAN STILL EARNS ITS PLACE: it is the mechanical tripwire for the
     * DELIBERATE wiring, a caller reaching for this class BY NAME in order to
     * turn it into a tool result, which is the edit a person makes when they
     * decide this really is a fourth kind. The generic-catch path is covered
     * by the paragraph below instead, and that cover is asserted: the message
     * classifies as NOT a denial, so even arriving through a `\Throwable`
     * catch it renders as an ordinary tool error. (Also measured at round 49,
     * and not load-bearing here: {@see \SugarCraft\Crush\Agents\TaskList::addTask()},
     * the only thrower, had no caller anywhere in `src/`, so nothing reached
     * the throw from a tool callback at all.)
     *
     * THIRD, WHAT WOULD HAPPEN IF IT DID REACH A CLASSIFIER, because that is
     * the half a "they are just different" comment cannot answer. It would
     * classify as NOT a denial and render as an ordinary tool error — which is
     * the CORRECT treatment, since a blocked task insertion is not a refused
     * tool call and the three remedies the roster distinguishes (change the
     * hook, answer differently, attach an approver) are none of them the
     * remedy for it.
     *
     * THE KNOWN-POSITIVE IS IN THE SAME TEST (rule 15). The third assertion is
     * an emptiness claim over a scanner, so a synthetic source that DOES both
     * things is pushed through the identical helper and must come back named.
     */
    public function testTheOneOffRosterShapeInSrcIsAThrowableMessageAndNotAFourthKind(): void
    {
        foreach (self::OFF_ROSTER_THROWABLE_SHAPES as $relative => $class) {
            self::assertTrue(
                is_subclass_of($class, \Throwable::class),
                "{$class} is excluded from the roster because it is a Throwable, and it is not one",
            );

            // The literal really is in that file and really is off-roster.
            $found = self::denialLiteralsIn(self::sourceOf($relative));
            self::assertNotSame([], $found, "{$relative} no longer spells the shape this exclusion is for, "
                . 'so the exclusion is stale and should be deleted rather than carried');
            foreach ($found as $literal) {
                self::assertNotContains(
                    rtrim($literal),
                    DenialKind::prefixes(),
                    "{$relative} spells a real roster entry; that is not an exception, it is a copy",
                );
                self::assertNull(
                    DenialKind::classify($literal),
                    "{$literal} classifies as a denial, so if it ever reached a tool result it would be "
                    . 'drawn struck through. It is an exception message and must not',
                );
            }

            // AND NOTHING IN src/ TURNS IT INTO A TOOL RESULT. This is the
            // discriminator: the exclusion holds because the string cannot
            // reach a classifier, and the day some caller catches this and
            // returns ToolResult::error($e->getMessage()) is the day it
            // becomes a real fourth kind.
            self::assertSame(
                [],
                self::filesNamingBoth($class, 'ToolResult'),
                "{$class} is now named in a file that also builds a ToolResult, so its message can reach "
                . 'DenialKind::classify() as a tool-result opener. Either it is a fourth denial kind and '
                . 'belongs on the roster, or it needs re-wording off the vocabulary',
            );
        }

        // KNOWN-POSITIVE FOR THE DISCRIMINATOR ITSELF. Without this the
        // emptiness above is also what a scanner that matches nothing returns.
        self::assertSame(
            ['probe.php'],
            self::filesNamingBothIn(
                ['probe.php' => '<?php throw new TaskBlockedException(); return ToolResult::error("x");'],
                TaskBlockedException::class,
                'ToolResult',
            ),
            'the co-occurrence scanner cannot see a file that names both symbols, so its empty answer '
            . 'above says nothing at all',
        );
        self::assertSame(
            [],
            self::filesNamingBothIn(
                ['probe.php' => '<?php throw new TaskBlockedException();'],
                TaskBlockedException::class,
                'ToolResult',
            ),
            'the co-occurrence scanner reports a file naming only ONE of the two symbols, so it would red '
            . 'this guard against every correct tree',
        );

        // AND THE DECLARING FILE IS SKIPPED, through the same matcher: a class
        // that mentions its own name is not a call site. Expected `[]`, so it
        // is the positive fixture above that makes this row mean anything --
        // a matcher that has stopped matching returns `[]` here too.
        self::assertSame(
            [],
            self::filesNamingBothIn(
                ['src/Agents/TaskBlockedException.php' => '<?php class TaskBlockedException {} // ToolResult'],
                TaskBlockedException::class,
                'ToolResult',
            ),
            'the declaring file is no longer skipped, so this guard reds on a tree where nothing has '
            . 'wired the exception to a tool result at all',
        );

        // AND A KNOWN-POSITIVE OVER THE REAL WALK, which is the half the three
        // fixtures above cannot reach (rule 15, one level down). They call
        // filesNamingBothIn() with sources of their own, so they pin the
        // MATCHER and say nothing about the COLLECTION that filesNamingBoth()
        // wraps it in -- phpFilesUnder(), sourceOf() and the path arithmetic.
        // MEASURED on PHP 8.3.6 at round 49: replacing filesNamingBoth()'s
        // body with `return []` left this whole file GREEN, because every
        // assertion that reads it expects an empty answer, and that helper has
        // no other caller in the tree. `TaskList` is the second symbol rather
        // than `ToolResult` precisely because src/Agents/TaskList.php is where
        // the exception is thrown, so a POSITIVE answer over the real walk is
        // available without inventing one.
        self::assertContains(
            'src/Agents/TaskList.php',
            self::filesNamingBoth(TaskBlockedException::class, 'TaskList'),
            'the co-occurrence scan cannot find TaskBlockedException in the file that throws it, so it is '
            . 'not reading src/ at all and its empty answer above is what a dead instrument returns rather '
            . 'than evidence that nothing wires this exception to a tool result',
        );
    }

    /**
     * AND THE PROJECTION HAS NOT DRIFTED FROM THE ENUM.
     *
     * `Chat::DENIED_ERROR_PREFIXES` is declared as three
     * `DenialKind::Case->value` constant expressions rather than three
     * literals, which makes drift impossible by construction TODAY — but it is
     * `public const` on a class an embedder reads, so someone re-spelling it
     * as literals is a one-line edit that nothing else in the tree would
     * notice. Compared with `assertSame`, so ORDER is pinned too: at least one
     * consumer iterates the constant.
     */
    public function testChatsPublicRosterIsStillTheEnumsOwnList(): void
    {
        self::assertSame(DenialKind::prefixes(), Chat::DENIED_ERROR_PREFIXES);
    }

    /**
     * AND THE TOKEN THE `refusals` DOCUMENT CARRIES IS THE ONE THREE-WORD
     * VOCABULARY A CONSUMER OUTSIDE PHP MATCHES ON (E250).
     *
     * PINNED AS LITERALS, WHICH IS DELIBERATE AND IS NOT RULE-18 ROT.
     * {@see DenialKind::token()} is `strtolower($this->name)`, so an
     * assertion written as `DenialKind::Hook->token()` moves with the
     * implementation and pins nothing: MEASURED on PHP 8.3.6 by substituting
     * `return $this->name;` — with only the derived assertions in place that
     * mutation SURVIVED the whole of this file and the whole of
     * {@see \SugarCraft\Crush\Tests\Cli\NonInteractiveRefusalDocumentTest},
     * emitting `Hook` where the documented contract says `hook`. The three strings below are the
     * contract `README.md` publishes for the `kind` field of a `refusals`
     * entry; a fourth denial kind SHOULD red this test, because it is a
     * change to a document other people parse.
     *
     * AND THE PIN LIVES HERE ALONE. The five `kind` assertions in
     * {@see \SugarCraft\Crush\Tests\Cli\NonInteractiveRefusalDocumentTest}
     * are all written `DenialKind::<Case>->token()`, so both sides of each of
     * them still move together — deliberately, since that file's subject is
     * the document's SHAPE rather than its vocabulary. MEASURED on PHP 8.3.6
     * at round 49: `token()` returning `$this->name` produces exactly ONE
     * failure when this file, that file and
     * {@see \SugarCraft\Crush\Tests\Cli\RefusalStderrSurfaceTest} are run
     * together, and it is the first assertion below. Do not read those tests
     * as covering the token.
     *
     * THE SHAPE IS ASSERTED SEPARATELY FROM THE SET, because the two fail
     * differently: the set catches a respelling, and the lowercase-only shape
     * catches the specific regression of emitting the PHP identifier — which
     * is the one that arrives by deleting a function call rather than by
     * editing a string.
     */
    public function testTheDocumentsKindTokenIsLowercaseAndIsTheThreePublishedWords(): void
    {
        $tokens = array_map(static fn (DenialKind $k): string => $k->token(), DenialKind::cases());

        self::assertSame(['refused', 'unanswered', 'hook'], $tokens, 'the `kind` field of a refusals entry '
            . 'is no longer the three tokens README.md publishes, so every consumer matching on it is now '
            . 'matching on nothing');

        foreach ($tokens as $token) {
            self::assertMatchesRegularExpression('/^[a-z]+$/', $token, "'{$token}' is not a lowercase word. "
                . 'A token that is the PHP case identifier makes renaming a case a breaking change to a '
                . 'JSON document');
        }

        self::assertSame($tokens, array_unique($tokens), 'two denial kinds share one token, so a consumer '
            . 'cannot tell them apart at all');
    }

    /**
     * AND `README.md` PUBLISHES THE SAME THREE TOKENS AND SAYS THERE ARE
     * THREE OF THEM.
     *
     * THE GAP THIS CLOSES. The doc-block above calls the three strings "the
     * contract `README.md` publishes", and until round 49 nothing read
     * `README.md` to check — a fourth denial kind would red the token test and
     * leave the published document silently wrong, which is the surface an
     * out-of-process consumer actually writes its parser against. Verified at
     * round 49: no copy of the `refusals` schema exists under `docs/` or
     * `bin/`, so `README.md` is the only published one.
     *
     * THE NUMERAL IS DERIVED FROM THE ENUM, not spelled here, so this is a
     * drift guard rather than a second place the cardinality is written down:
     * add a case and the assertion starts demanding the word `four`.
     *
     * AND THE EXTRACTOR CARRIES A KNOWN-POSITIVE (rule 15). Both assertions
     * below are claims ABOUT a slice of a file, and a slicer that returns the
     * wrong slice — or a matcher that has stopped matching — fails in the
     * direction of silence, so a synthetic markdown fixture goes through the
     * identical helper first.
     */
    public function testTheReadmePublishesTheSameThreeKindTokens(): void
    {
        // KNOWN-POSITIVE FIRST, AND IT HAS TO DISCRIMINATE IN BOTH DIRECTIONS
        // (rule 25). "The extractor found the body" is also what an extractor
        // that returns the WHOLE DOCUMENT answers — and that mutation is the
        // one that matters here, because the two assertions below would then
        // pass on a README whose refusals paragraph had been deleted outright,
        // on the strength of the words `hook` and `refused` appearing
        // somewhere else in a 1,000-line file. So the fixture plants a decoy
        // on each side of the paragraph and the slice must exclude both.
        $fixture = "intro DECOY-HEAD\n\nOne key is **conditional**: FIXTURE-BODY\n\n"
            . "Before this,\ntail DECOY-TAIL\n";
        $sliced = self::refusalsParagraphIn($fixture);
        self::assertStringContainsString(
            'FIXTURE-BODY',
            $sliced,
            'the paragraph extractor cannot find a paragraph it is looking straight at, so its answer for '
            . 'README.md says nothing',
        );
        self::assertStringNotContainsString('DECOY-HEAD', $sliced, 'the extractor returns text from before '
            . 'the paragraph, so its answer is about the whole document and not about the schema');
        self::assertStringNotContainsString('DECOY-TAIL', $sliced, 'the extractor returns text from after '
            . 'the paragraph, so its answer is about the whole document and not about the schema');

        $paragraph = self::refusalsParagraphIn(self::sourceOf('README.md'));

        $numerals = [1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six'];
        $count = \count(DenialKind::cases());
        self::assertArrayHasKey($count, $numerals, 'this guard cannot spell the number of denial kinds');
        self::assertStringContainsString(
            'one of exactly ' . $numerals[$count] . ' tokens',
            $paragraph,
            "README.md no longer tells a consumer there are {$count} kind tokens, and it is the only place "
            . 'the refusals schema is published',
        );

        foreach (DenialKind::cases() as $kind) {
            self::assertStringContainsString(
                '`' . $kind->token() . '`',
                $paragraph,
                "README.md's refusals paragraph does not name the token '{$kind->token()}', so a consumer "
                . 'matching on the documented vocabulary drops every refusal of that kind',
            );
        }
    }

    /**
     * The `refusals` paragraph of a markdown document, or a loud failure.
     *
     * Sliced rather than searched whole (rule 14 in spirit): `hook` and
     * `refused` are ordinary words that appear all over `README.md`, so
     * matching them against the whole file would pass on a document that had
     * deleted the schema entirely.
     */
    private static function refusalsParagraphIn(string $markdown): string
    {
        $open = strpos($markdown, 'One key is **conditional**');
        if ($open === false) {
            throw new \RuntimeException('README.md no longer opens the refusals paragraph the way this '
                . 'guard finds it; the schema may still be published, but this guard cannot answer for it');
        }

        $close = strpos($markdown, 'Before this,', $open);
        if ($close === false) {
            throw new \RuntimeException('README.md no longer closes the refusals paragraph the way this '
                . 'guard finds it; this guard cannot answer for it');
        }

        return substr($markdown, $open, $close - $open);
    }

    /**
     * AND THE CLASSIFIER `Chat` EXPOSES IS THE ENUM'S, for every kind and for
     * a plain error.
     *
     * {@see Chat::isDeniedResult()} now delegates to
     * {@see DenialKind::classify()}. That delegation is worth pinning at the
     * BEHAVIOURAL level rather than by reading the body: the renderer, the
     * headless document and three other test files all call the wrapper, and
     * a wrapper that stopped agreeing with the enum would put the TUI and the
     * `--output-format json` document back on two answers.
     */
    public function testChatsClassifierAgreesWithTheEnumOnEveryKindAndOnAPlainError(): void
    {
        foreach (DenialKind::cases() as $kind) {
            $text = $kind->reason('something');

            self::assertSame($kind, DenialKind::classify($text), "DenialKind::reason() for {$kind->name} "
                . 'produces a string its own classify() does not recognise');
            self::assertTrue(
                Chat::isDeniedResult(ChatToolResult::error('Bash', $text, 'call_1')),
                "Chat::isDeniedResult() disagrees with DenialKind on {$kind->name}",
            );
        }

        self::assertNull(DenialKind::classify('No such file or directory'));
        self::assertFalse(Chat::isDeniedResult(ChatToolResult::error('Bash', 'No such file or directory', 'call_1')));
        self::assertFalse(Chat::isDeniedResult(ChatToolResult::ok('Bash', 'Permission denied: not an error at all', 'call_1')));

        // OPENS WITH, NOT CONTAINS. A `Bash` that RAN and printed the OS's own
        // "Permission denied" is an ordinary failure the model is expected to
        // act on; classifying it as a refusal would strike it through in the
        // TUI and tell a JSON consumer the call never happened. Without this
        // pair, substituting `str_contains` for `str_starts_with` inside
        // DenialKind::classify() is a surviving mutation.
        $midString = 'cat: /root/.ssh/id_rsa: Permission denied: consult your administrator';
        self::assertNull(DenialKind::classify($midString));
        self::assertFalse(Chat::isDeniedResult(ChatToolResult::error('Bash', $midString, 'call_1')));
    }

    // ── harness ──────────────────────────────────────────────────────────

    /**
     * Every `DENIAL_*` constant on {@see Runtime}, as name => value.
     *
     * @return array<string, string>
     */
    private static function runtimeDenialPrefixes(): array
    {
        $out = [];
        foreach ((new \ReflectionClass(Runtime::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'DENIAL_')) {
                // Loud rather than skipped (rule 14): a DENIAL_* that is not a
                // string is something this guard cannot judge, and quietly
                // dropping it leaves a hole shaped exactly like the next
                // defect.
                self::assertIsString($value, "Runtime::{$name} is not a string; this guard cannot judge it");
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /** The raw text of `src/Runtime.php`, or a loud failure. */
    private static function runtimeSource(): string
    {
        return self::sourceOf('src/Runtime.php');
    }

    /**
     * The raw text of one repo-relative source file, or a loud failure.
     *
     * Throws rather than returning `''` (rule 14). An unreadable file is a
     * question this guard cannot answer, and `''` scans to zero literals —
     * indistinguishable from the clean result four of the five
     * {@see self::denialLiteralsAcrossSrc()} expects of every file it walks.
     */
    private static function sourceOf(string $relative): string
    {
        $path = \dirname(__DIR__) . '/' . $relative;
        $source = @file_get_contents($path);
        if ($source === false) {
            throw new \RuntimeException("{$path} could not be read; this guard cannot answer for it");
        }

        return $source;
    }

    /**
     * Every denial-shaped literal in `src/`, as repo-relative path => literals,
     * with files that spell none omitted and the keys sorted.
     *
     * A WALK AND NOT A LIST, which is the difference between "the five files
     * we thought of spell nothing" and "nothing in `src/` spells one". The
     * caller compares the WHOLE map, so a new file is a red rather than a
     * silent addition.
     *
     * @return array<string, list<string>>
     */
    private static function denialLiteralsAcrossSrc(): array
    {
        $root = \dirname(__DIR__);
        $out = [];
        foreach (self::phpFilesUnder($root . '/src') as $path) {
            $found = self::denialLiteralsIn(self::sourceOf(substr($path, \strlen($root) + 1)));
            if ($found !== []) {
                $out[substr($path, \strlen($root) + 1)] = $found;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Every `.php` file under $directory, sorted, or a loud failure.
     *
     * Throws on an unreadable directory rather than yielding nothing
     * (rule 14): an empty walk scans to an empty map, which is exactly what
     * {@see denialLiteralsAcrossSrc()}'s caller would read as "the tree is
     * clean".
     *
     * @return list<string>
     */
    private static function phpFilesUnder(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new \RuntimeException("{$directory} is not a directory; this guard cannot answer for it");
        }

        $files = [];
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($walk as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        if ($files === []) {
            throw new \RuntimeException("{$directory} holds no PHP files; this guard is scanning nothing");
        }

        sort($files);

        return $files;
    }

    /**
     * Repo-relative paths under `src/` whose text names BOTH $class (by its
     * short name) and $symbol.
     *
     * The SHORT name, because that is how a `use`d class is written at every
     * call site; matching the FQN would miss the ordinary case entirely.
     *
     * @param class-string $class
     *
     * @return list<string>
     */
    private static function filesNamingBoth(string $class, string $symbol): array
    {
        $root = \dirname(__DIR__);
        $sources = [];
        foreach (self::phpFilesUnder($root . '/src') as $path) {
            $relative = substr($path, \strlen($root) + 1);
            $sources[$relative] = self::sourceOf($relative);
        }

        return self::filesNamingBothIn($sources, $class, $symbol);
    }

    /**
     * The co-occurrence scan itself, over sources supplied by the caller.
     *
     * Split out from {@see filesNamingBoth()} so the guard's known-positive
     * fixture goes through the IDENTICAL matcher rather than through a second
     * one written to agree with it (rule 15). The declaring file is skipped:
     * `src/Agents/TaskBlockedException.php` names its own class by definition,
     * and a class that mentions itself is not a call site.
     *
     * @param array<string, string> $sources path => text
     * @param class-string $class
     *
     * @return list<string>
     */
    private static function filesNamingBothIn(array $sources, string $class, string $symbol): array
    {
        $short = ($pos = strrpos($class, '\\')) === false ? $class : substr($class, $pos + 1);
        $declaring = 'src/' . str_replace('\\', '/', substr($class, \strlen('SugarCraft\\Crush\\'))) . '.php';

        $out = [];
        foreach ($sources as $relative => $text) {
            if ($relative === $declaring) {
                continue;
            }
            if (str_contains($text, $short) && str_contains($text, $symbol)) {
                $out[] = $relative;
            }
        }

        return $out;
    }

    /**
     * Every string LITERAL in `$source` that opens with a denial-shaped
     * prefix, in source order.
     *
     * The shape rather than the three known values, so a fourth invented
     * spelling is caught by the same scan that catches a duplicate of a known
     * one.
     *
     * TWO TESTS, NOT ONE, AND THE SECOND IS A VOCABULARY. WHAT THIS SAID
     * BEFORE: one regex, `/^(Hook|Permission) [a-z]+:/`, described as
     * shape-based. WHAT IS TRUE NOW: that alphabet could express exactly the
     * three spellings already in the tree and the one example its own
     * doc-block chose. MEASURED on PHP 8.3.6 by inserting a dead `private
     * const` into `src/Runtime.php` and running this file: `'Permission
     * blocked: nope'` was KILLED, while `'Permission Blocked: nope'`
     * (capitalised verb), `'Permission not granted: nope'` (two-word verb) and
     * `'Blocked: nope'` (an opener that is neither `Hook` nor `Permission`)
     * all SURVIVED. An off-roster opener is precisely the defect this scan
     * exists to catch, because `Chat::isDeniedResult()` matches
     * case-sensitively with `str_starts_with`.
     *
     * WHY SHAPE ALONE CANNOT DO IT, and why the prescribed fix was not taken.
     * The obvious widening — any capitalised word run ending in `:` — was
     * MEASURED against this tree first and produces TWO false positives in
     * `src/Runtime.php` (`"Tool not found: "`, twice), which are ordinary
     * tool errors and not denials at all; the guard asserts an empty set, so
     * it would have gone red on the day it landed. `'Tool not found:'` and
     * `'Blocked:'` are the SAME SHAPE, so the discriminator has to be the
     * words. Hence a frame plus a vocabulary: a capitalised run of at most
     * four words ending in `:`, at least one of which is a denial term. The
     * negative fixture in {@see testRuntimeSpellsNoDenialPrefixOutsideItsConstants()}
     * pins that `Tool not found:` stays out.
     *
     * THE WHOLE LITERAL IS RETURNED, not the matched prefix, and that is
     * load-bearing: the caller compares against the constants with `===`, so
     * an interpolated `"Hook denied: {$why}"` yields `'Hook denied: '` with
     * the trailing space and correctly fails to equal `'Hook denied:'`.
     * Returning the trimmed prefix would make the exact hand-rolled shape this
     * guard exists to catch compare equal to the constant and pass.
     *
     * @return list<string>
     */
    private static function denialLiteralsIn(string $source): array
    {
        $out = [];
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === \T_CONSTANT_ENCAPSED_STRING) {
                // A whole literal: strip the one quote character at each end.
                $value = substr($token[1], 1, -1);
            } elseif ($token[0] === \T_ENCAPSED_AND_WHITESPACE) {
                // The literal RUN inside an interpolated string — already
                // unquoted, because the quote belongs to the surrounding
                // `"` token rather than to this one.
                $value = $token[1];
            } else {
                continue;
            }
            if (self::hasADenialFrame($value)) {
                $out[] = $value;

                continue;
            }

            if (self::isCaseVariantOfARosterPrefix($value)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * Whether any frame in $value carries a denial term.
     *
     * EVERY FRAME AND NOT THE LEFTMOST ONE, which was a hole found while
     * widening the vocabulary at round 49. WHAT THE SCAN DID: one
     * `preg_match`, then the vocabulary test against `$m[0]` — the FIRST frame
     * in the literal. WHY THAT WAS WRONG: a literal may carry several, and
     * only the first was ever judged. MEASURED on PHP 8.3.6:
     * `'Tool not found: x. Hook denied: y'` was NOT reported, because the
     * leftmost frame is `Tool not found:` and that one is deliberately
     * innocent — so a producer whose decoration happens to contain an earlier
     * colon-run was invisible to the whole-`src/` map, which is the same class
     * of blindness the `^` anchor had. WHAT IT COST TO FIX: nothing
     * measurable. Re-scanned over `src/` with every frame judged, the map
     * names the same two files and the same four literals.
     *
     * LOUD ON A REGEX FAILURE (rule 14): `preg_match_all` answers `false` on a
     * backtrack limit, and `false` reads as "no frames" — a silent clean bill
     * of health for a literal this guard could not parse.
     */
    private static function hasADenialFrame(string $value): bool
    {
        $frames = preg_match_all(self::DENIAL_SHAPE, $value, $matches);
        if ($frames === false) {
            throw new \RuntimeException('the denial-shape scan failed on a literal, so this guard cannot '
                . 'answer for the file it is in');
        }

        foreach ($matches[0] as $frame) {
            if (preg_match(self::DENIAL_TERMS, $frame) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $value spells a roster prefix in a case the roster does not
     * carry.
     *
     * THE ONE OFF-CASE SHAPE THAT CAN BE CAUGHT WITHOUT GUESSING AT VOCABULARY
     * (round 49). {@see DenialKind::classify()} and
     * {@see Chat::isDeniedResult()} both use `str_starts_with`, which is
     * case-SENSITIVE, so `permission denied: rm` is not a denial to either of
     * them — it renders as an ordinary tool error, which is the exact failure
     * {@see DenialKind}'s own doc-block names. The frame in
     * {@see self::DENIAL_SHAPE} cannot see it, and widening the frame is
     * measured against in {@see self::ROSTER_CASE_VARIANTS_ARE_CAUGHT_BY}'s
     * doc-block.
     *
     * PER OCCURRENCE AND NOT PER LITERAL, deliberately: a literal that quotes
     * the roster correctly once and incorrectly once is exactly the drift this
     * is for, and a whole-literal `str_contains` would clear it on the strength
     * of the correct half.
     *
     * DERIVED FROM {@see DenialKind::prefixes()}, so it cannot list a fourth
     * spelling the roster does not have.
     */
    private static function isCaseVariantOfARosterPrefix(string $value): bool
    {
        foreach (DenialKind::prefixes() as $prefix) {
            $at = 0;
            while (($pos = stripos($value, $prefix, $at)) !== false) {
                if (substr($value, $pos, \strlen($prefix)) !== $prefix) {
                    return true;
                }
                $at = $pos + 1;
            }
        }

        return false;
    }

    /**
     * Gate one tool call through the registered chain and return the reason it
     * was refused.
     *
     * Driven through `executeToolCalls()` rather than `gate()` itself: the
     * reason string only reaches a consumer after
     * {@see Runtime::failure()} has wrapped it in a {@see ToolResult}, and it
     * is that finished text — the one `Chat::isDeniedResult()` and
     * `NonInteractive::refusalFrom()` see — this whole file is about.
     */
    private function reasonFor(?callable $onPermissionRequest): string
    {
        $tool = $this->createMock(Tool::class);
        $tool->method('name')->willReturn('blocked_tool');
        $tool->method('description')->willReturn('a tool that must not run');
        $tool->method('inputSchema')->willReturn([]);
        $tool->method('execute')->willReturnCallback(
            static fn (): ToolResult => self::fail('the gate let a refused call through to the tool'),
        );

        $app = App::new($this->provider, 'gpt-4')->withTools([$tool]);

        $reflection = new \ReflectionMethod($this->runtime, 'executeToolCalls');
        $reflection->setAccessible(true);
        $results = iterator_to_array($reflection->invokeArgs($this->runtime, [
            [new ToolCall('call_1', 'blocked_tool', [])],
            $app,
            null,
            $onPermissionRequest,
        ]));

        self::assertCount(1, $results);
        self::assertTrue($results[0]->isError(), 'the call was not refused at all');

        return $results[0]->content();
    }

    private static function denyHook(string $message): HookInterface
    {
        return new class ($message) implements HookInterface {
            public function __construct(private readonly string $message) {}
            public function name(): string { return 'deny-hook'; }
            public function event(): HookEvent { return HookEvent::PreToolUse; }
            public function matcher(): string { return '.*'; }
            public function execute(HookContext $context): HookResult { return HookResult::deny($this->message); }
        };
    }

    private static function askHook(string $question): HookInterface
    {
        return new class ($question) implements HookInterface {
            public function __construct(private readonly string $question) {}
            public function name(): string { return 'ask-hook'; }
            public function event(): HookEvent { return HookEvent::PreToolUse; }
            public function matcher(): string { return '.*'; }
            public function execute(HookContext $context): HookResult { return HookResult::ask($this->question); }
        };
    }
}
