<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
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
 * on). The producer does not READ that roster and deliberately does not — see
 * {@see Runtime::DENIAL_HOOK}'s doc-block for the measured reason.
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
 * no longer loads `Chat` at all). WHY THIS FILE STILL EARNS ITS PLACE:
 * `src/Runtime.php` was owned by a different concurrent lane when the leaf
 * landed, so `Runtime`'s three `DENIAL_*` constants are STILL three string
 * literals and still a copy. Until they are re-pointed at the enum's cases,
 * this file is the only thing making that copy loud. The day they are, the
 * membership test below becomes a tautology and should be deleted with them —
 * not before.
 *
 * WHAT IS LEFT WHEN A PRODUCER DOES NOT READ ITS ROSTER is a copy that can
 * drift, and the drift is silent in the worst direction: a prefix
 * `Runtime` invents that the roster does not carry renders a BLOCKED call as a
 * failed one on both surfaces. This file is the coupling, made loud.
 *
 * It is deliberately not in `tests/Cli` or `tests/Hooks`: the contract spans
 * `Runtime` (producer), `Chat` (roster + classifier) and `NonInteractive`
 * (second consumer), and filing it under any one of the three would put it
 * where a reader of the other two will not look.
 */
final class DenialPrefixRosterTest extends TestCase
{
    /**
     * THE FRAME: a capitalised word run of at most four words ending in `:`.
     *
     * Four because `Permission required:` is two and the longest invented
     * spelling worth worrying about (`Tool call rejected by policy:`) is four;
     * an unbounded run would swallow the first colon of an entire sentence.
     */
    private const DENIAL_SHAPE = '/^[A-Z][A-Za-z]*(?: [A-Za-z]+){0,3}:/';

    /**
     * THE VOCABULARY: at least one of these words must appear inside the
     * frame. This is what separates `Blocked:` (a denial nobody put on the
     * roster) from `Tool not found:` (an ordinary error), which the frame
     * alone cannot do because they are the same shape.
     *
     * `not` is only ever matched as part of a two-word phrase, deliberately:
     * bare `not` is what makes `Tool not found:` a false positive.
     */
    private const DENIAL_TERMS = '/\b(?:den(?:y|ied|ial)|refus(?:e|ed|al)|block(?:ed)?|reject(?:ed)?'
        . '|forbidden|disallowed|unauthori[sz]ed|required|not (?:allowed|permitted|granted))\b/i';

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
     * and the interpolated three are seen, so the scan over that file is now
     * exactly the six real spellings. WHY THE PARAGRAPH STILL EARNS ITS PLACE:
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

    // ── one spelling, in one file ────────────────────────────────────────

    /**
     * THE FILES IN THE DENIAL FAMILY THAT MUST SPELL NOTHING, and the one that
     * must spell everything.
     *
     * Named files rather than a walk of `src/`, and that is a measurement
     * rather than timidity. Running {@see denialLiteralsIn()} over the whole
     * of `src/` on PHP 8.3.6 returns a fourth file —
     * `src/Agents/TaskBlockedException.php`, whose `'Task creation blocked: '`
     * is an exception message and matches the vocabulary's `block(ed)?` term.
     * It is not a tool-result prefix and is not on any roster, so a whole-tree
     * scan would red this guard on a string that is entirely correct. The
     * value here is per-file and not a total, so a lane adding a file to
     * `src/` cannot move it (E-rule 18).
     *
     * @var array<string, int>
     */
    private const FAMILY_SPELLINGS = [
        // The leaf. Three cases, three backing values, and nothing else.
        'src/Permissions/DenialKind.php' => 3,
        // The TUI model. Was three hand-rolled producers plus a roster of
        // three literals; is now zero (E236/E239).
        'src/Chat.php' => 0,
        // The two classifiers. Both were already zero before E239 and this
        // pins that they stay that way: a consumer that spells a prefix is a
        // consumer that has stopped agreeing with the roster.
        'src/Renderer.php' => 0,
        'src/Cli/NonInteractive.php' => 0,
        'src/Cli/HeadlessPermissionPrompt.php' => 0,
    ];

    /**
     * EVERY DENIAL PREFIX IN THE FAMILY IS SPELLED IN `DenialKind` AND NOWHERE
     * ELSE (E239).
     *
     * THE POSITIVE ROW IS THE CONTROL, AND IT IS NOT A SEPARATE FIXTURE. Four
     * of the five expectations below are ZERO, and a zero is what a dead
     * scanner returns too — the shape that let round 48 ship a comment fixture
     * proving nothing. The `DenialKind.php` row runs through the identical
     * path (same `file_get_contents`, same {@see denialLiteralsIn()}) and
     * expects THREE, so a scanner that stopped matching, a path that stopped
     * resolving, or a token kind that stopped being read all red this test on
     * that row before the four zeros can lie.
     *
     * The positive row asserts the VALUES and not merely the count, because a
     * scanner returning three of the wrong strings would satisfy a count.
     */
    public function testTheLeafIsTheOnlyFileInTheFamilyThatSpellsADenialPrefix(): void
    {
        foreach (self::FAMILY_SPELLINGS as $relative => $expected) {
            $found = self::denialLiteralsIn(self::sourceOf($relative));

            self::assertCount(
                $expected,
                $found,
                "{$relative} spells " . \count($found) . ' denial-shaped literal(s) and should spell '
                . "{$expected}: " . var_export($found, true) . '. Every prefix belongs to a '
                . 'DenialKind case; a second spelling is a second definition, and the one that drifts '
                . 'renders a BLOCKED call as an ordinary tool ERROR on both surfaces',
            );
        }

        self::assertSame(
            DenialKind::prefixes(),
            self::denialLiteralsIn(self::sourceOf('src/Permissions/DenialKind.php')),
            'the three literals in the leaf are no longer the three case values the leaf exposes, so the '
            . 'zero expectations above were checked by a scanner that is looking at the wrong thing',
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
     * {@see self::FAMILY_SPELLINGS} rows expect.
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
            if (preg_match(self::DENIAL_SHAPE, $value, $m) === 1
                && preg_match(self::DENIAL_TERMS, $m[0]) === 1) {
                $out[] = $value;
            }
        }

        return $out;
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
