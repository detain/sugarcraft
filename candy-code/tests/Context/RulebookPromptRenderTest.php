<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Context;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Context\Rule;
use SugarCraft\Code\Context\RuleLoader;
use SugarCraft\Code\Context\RulePathNudge;
use SugarCraft\Code\Context\RulesState;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Runtime;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * The two live renders that prove a `/rules` toggle changes the PROMPT, not merely
 * the object graph.
 *
 * Requirement 9's shape: build the system prompt twice for the SAME pack directory
 * — once with the pack on, once with it toggled off — and compare. Every assertion
 * below is about bytes that reach the model, so a loader that filters correctly but
 * a splice that ignores the filter (or the reverse) cannot pass.
 *
 * WHY THE GOLDEN DOES NOT MOVE. Nothing here touches
 * `tests/fixtures/prompt/golden-system-prompt.txt`, and nothing here needs it to:
 * the packs live in a synthetic `$HOME` under `sys_get_temp_dir()` that the golden's
 * fixture home never contains, so the committed bytes are produced by an input set
 * this file does not modify. That is also why the proof is two renders rather than a
 * third golden — a fixture would have to be regenerated to carry a toggle, and the
 * toggle is the thing being asserted, not a constant to freeze.
 *
 * Each render gets its OWN {@see Runtime}: the prompt is memoised per-Runtime (a
 * §17.2 invariant), so two calls on one instance would return the same string for
 * opposite states and the comparison would be vacuous.
 */
final class RulebookPromptRenderTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    private string $home = '';

    private string $packsDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir() . '/sugarcrush_rulesrender_' . uniqid('', true);
        $this->home = $this->sandbox . '/home';
        $this->packsDir = $this->home . '/.candy-code/rulebooks';
        mkdir($this->packsDir, 0o700, true);
        $this->useHomeSandbox($this->home, create: false);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        exec('rm -rf ' . escapeshellarg($this->sandbox));

        parent::tearDown();
    }

    /**
     * The centre of the step: the same pack on disk, rendered with and without the
     * session toggle, produces DIFFERENT bytes, and the difference is exactly that
     * pack's fence.
     *
     * The byte counts are asserted rather than only "not equal", because a delta of
     * one character would also be not-equal and would mean the fence geometry had
     * collapsed into something else.
     */
    public function testTheSamePackRendersIntoThePromptWhenOnAndOutOfItWhenToggledOff(): void
    {
        file_put_contents($this->packsDir . '/poetry.md', "---\nname: Poetry\n---\nALWAYS REPLY IN HAIKU.\n");
        $on = $this->render(RulesState::new());

        $off = RulesState::new();
        $off->toggle('poetry');
        $renderOff = $this->render($off);

        self::assertStringContainsString('ALWAYS REPLY IN HAIKU.', $on, 'the pack body is in the ON prompt');
        self::assertStringNotContainsString('ALWAYS REPLY IN HAIKU.', $renderOff, 'and absent from the OFF prompt');
        self::assertNotSame($on, $renderOff, 'the two renders differ at all');

        self::assertSame(1, substr_count($on, '<user-rules>'), 'exactly one fence for exactly one enabled pack');
        self::assertSame(1, substr_count($on, $this->preamble()), 'and exactly one authority preamble with it');
        self::assertSame(0, substr_count($renderOff, '<user-rules>'), 'a toggled-off pack leaves no fence behind');
        self::assertSame(0, substr_count($renderOff, $this->preamble()));

        // The toggle removes a WHOLE framed block, so the delta is the fence, the
        // preamble, the body and their newlines - not a stray byte that would also
        // satisfy assertNotSame(). Derived, not hand-copied, so it cannot rot.
        self::assertGreaterThan(
            strlen($this->preamble()),
            strlen($on) - strlen($renderOff),
            'the delta is at least the size of the framed block, so the geometry survived',
        );
    }

    /**
     * Two enabled packs, two fences — each rule is framed on its own, so the
     * "exactly once per enabled pack" of the done-when is a count of TWO here. A
     * splice that wrapped the tier in one fence would red the first assertion; one
     * that emitted a fence per tier regardless of pack count would red the second.
     */
    public function testEveryEnabledPackGetsItsOwnFenceAndPreamble(): void
    {
        file_put_contents($this->packsDir . '/one.md', "FIRST-PACK-BODY\n");
        file_put_contents($this->packsDir . '/two.md', "SECOND-PACK-BODY\n");
        $state = RulesState::new();

        self::assertSame(2, substr_count($this->render($state), '<user-rules>'));

        $state->toggle('two');
        $oneOff = $this->render($state);

        self::assertSame(1, substr_count($oneOff, '<user-rules>'), 'one enabled pack, one fence');
        self::assertSame(1, substr_count($oneOff, $this->preamble()));
        self::assertStringContainsString('FIRST-PACK-BODY', $oneOff);
        self::assertStringNotContainsString('SECOND-PACK-BODY', $oneOff);
    }

    /**
     * A `rules/` pack toggles through the same command and the same set as a
     * `rulebooks/` pack — the two user directories are one tier behind one fence,
     * which is what requirement 2's no-new-tier ruling has to mean in the bytes.
     */
    public function testAPackInTheRulesDirectoryTogglesThroughTheSameRenderPath(): void
    {
        $rules = $this->home . '/.candy-code/rules';
        mkdir($rules, 0o700, true);
        file_put_contents($rules . '/standing.md', "STANDING-RULE-BODY\n");
        file_put_contents($this->packsDir . '/book.md', "RULEBOOK-BODY\n");

        $both = $this->render(RulesState::new());
        self::assertSame(2, substr_count($both, '<user-rules>'));

        $state = RulesState::new(['standing']);
        $oneOff = $this->render($state);

        self::assertStringNotContainsString('STANDING-RULE-BODY', $oneOff);
        self::assertStringContainsString('RULEBOOK-BODY', $oneOff);
        self::assertSame(1, substr_count($oneOff, '<user-rules>'));
    }

    /**
     * The tier boundary in the prompt: a project file is framed by the project
     * fence, and toggling a name that only the repository uses subtracts nothing
     * from the rendered bytes.
     *
     * This is the render-side half of the scope pinned in
     * `RulesCommandTest::testAProjectRuleIsNotToggleableAndSaysSoAsAnError()` — the
     * command refuses the name, and here the prompt proves the same thing at the
     * byte level rather than only in a message.
     */
    public function testASessionToggleNeverSubtractsAProjectAuthoredRuleFromThePrompt(): void
    {
        $project = $this->sandbox . '/repo/.candy-code/rules';
        mkdir($project, 0o755, true);
        file_put_contents($project . '/terse.md', "PROJECT-TERSE-BODY\n");
        file_put_contents($this->packsDir . '/terse.md', "USER-TERSE-BODY\n");

        $state = RulesState::new();
        self::assertStringContainsString('PROJECT-TERSE-BODY', $this->render($state, $this->sandbox . '/repo'));

        $state->toggle('terse');
        $toggled = $this->render($state, $this->sandbox . '/repo');

        self::assertStringContainsString('PROJECT-TERSE-BODY', $toggled, 'the repository layer is untouched by the session set');
        self::assertStringNotContainsString('USER-TERSE-BODY', $toggled, 'the identically named user pack is the one that left');
    }

    /**
     * A `null` state on the App renders EXACTLY the bytes it rendered before this
     * step existed — the negative control for the whole change, and the reason every
     * existing prompt test is expected to be unmoved.
     */
    public function testAnAppWithNoRulesStateRendersTheUnfilteredPrompt(): void
    {
        file_put_contents($this->packsDir . '/poetry.md', "ALWAYS REPLY IN HAIKU.\n");

        $state = RulesState::new(['poetry']);
        $filtered = $this->render($state);

        $app = App::new($this->provider(), 'gpt-4')->withRoot($this->sandbox . '/repo');
        self::assertNull($app->rulesState, 'this App carries no toggle set at all');
        $unfiltered = $this->renderPrompt($app);

        self::assertStringContainsString('ALWAYS REPLY IN HAIKU.', $unfiltered);
        self::assertStringNotContainsString('ALWAYS REPLY IN HAIKU.', $filtered);
    }

    /**
     * The loader and the prompt must agree about which packs are on, because
     * `RulesCommand` builds its listing from the loader rather than from a second
     * reading of the prompt. Asserted against the real splice rather than argued in
     * a doc-block: a pack the loader returns but the splice drops (an empty body, a
     * non-user tier) must not appear as a toggleable `on` row.
     */
    public function testAPackWithNoBodyIsListedButContributesNoFence(): void
    {
        file_put_contents($this->packsDir . '/blank.md', "---\nname: Blank\n---\n\n");

        $loaderPacks = (new RuleLoader($this->sandbox . '/repo'))->loadUserRulebooks();
        self::assertCount(1, $loaderPacks, 'the loader hands the empty pack to the listing');
        self::assertSame('', trim($loaderPacks[0]->body));

        $rendered = $this->render(RulesState::new());
        self::assertSame(0, substr_count($rendered, '<user-rules>'), 'and the splice frames nothing for it');
    }

    // -- FU5: the standing-splice byte budget ---------------------------------

    /**
     * The ceiling is a ratified number, not a dial: FU5 fixed it at what ONE
     * max-size rule file may spend (65,536 framed bytes, the same figure
     * {@see RuleLoader}'s per-file cap carries), and this pin is what makes a
     * silent widening red rather than review-only.
     */
    public function testTheStandingCeilingIsTheRatifiedValueNotAWidenedOne(): void
    {
        $ref = new ReflectionClass(Runtime::class);

        self::assertSame(65536, $ref->getConstant('MAX_STANDING_RULE_BYTES'));
        self::assertSame(2, $ref->getConstant('MAX_STANDING_POINTERS'));
    }

    /**
     * Requirement 7(a): two smalls and one huge — both smalls render whole, and
     * the huge body NEVER appears (not rendered, not clipped); what appears is
     * exactly one pointer line, byte-identical to the shared
     * {@see RulePathNudge::pointer()} grammar the tool-time channel emits, riding
     * inside its own tier's fence. A single deferral names itself, so there is no
     * counted note.
     */
    public function testARuleOverTheStandingBudgetArrivesAsExactlyOnePointerLine(): void
    {
        file_put_contents($this->packsDir . '/one.md', "SMALL-ONE-CANARY\n");
        file_put_contents($this->packsDir . '/two.md', "SMALL-TWO-CANARY\n");
        file_put_contents($this->packsDir . '/zhuge.md', "---\nname: Zhuge\n---\n" . str_repeat('H', 65400));

        $rendered = $this->render(RulesState::new());

        self::assertStringContainsString('SMALL-ONE-CANARY', $rendered, 'both smalls render whole');
        self::assertStringContainsString('SMALL-TWO-CANARY', $rendered);
        self::assertSame(0, substr_count($rendered, 'HHHH'), 'the over-budget body renders in no form at all');

        $pointer = RulePathNudge::pointer($this->loadedRuleByKey('zhuge'));
        self::assertSame(1, substr_count($rendered, $pointer), 'exactly one pointer line, byte-exact from the shared gate');
        self::assertTrue(
            str_contains($rendered, "<user-rules>\n" . $this->preamble() . "\n\n" . $pointer . "\n</user-rules>"),
            'the pointer rides inside its own tier fence with the tier preamble intact',
        );
        self::assertSame(0, substr_count($rendered, 'further standing rule'), 'one deferral needs no counted note');
        self::assertSame(3, substr_count($rendered, '<user-rules>'), 'two whole fences plus one deferral fence');
    }

    /**
     * Requirement 7(b): deferrals beyond the two-line pointer ceiling are COUNTED,
     * never silently gone — the same grammar the nudge channel's
     * counted-not-dropped test pins, on the splice's channel. The third oversized
     * rule contributes no line of its own; it exists in the note's count.
     */
    public function testDeferralsBeyondThePointerCeilingAreCountedNotDropped(): void
    {
        $bodyRoom = $this->standingWholeBudget() - $this->userRuleFramingOverhead();
        // Names sort the way the loader walks them: x1 < x2 < x3 under ksort,
        // which a wordy naming (xone, xtwo, xthree) would not.
        file_put_contents($this->packsDir . '/abig.md', 'BIGBODY-' . str_repeat('b', $bodyRoom - strlen('BIGBODY-') - 4096) . "\n");
        foreach (['x1', 'x2', 'x3'] as $i => $name) {
            $marker = 'XMARC' . $i . '-';
            file_put_contents($this->packsDir . '/' . $name . '.md', $marker . str_repeat('y', 8192 - strlen($marker)) . "\n");
        }

        $rendered = $this->render(RulesState::new());

        self::assertStringContainsString('BIGBODY-', $rendered, 'the whole-rules budget spends itself on the first three fits');
        self::assertSame(0, substr_count($rendered, 'XMARC'), 'and none of the three overs runs or clips');

        $note = sprintf(
            (string) (new ReflectionClass(Runtime::class))->getConstant('STANDING_DEFERRED_NOTE'),
            1,
        );
        $p1 = RulePathNudge::pointer($this->loadedRuleByKey('x1'));
        $p2 = RulePathNudge::pointer($this->loadedRuleByKey('x2'));
        $p3 = RulePathNudge::pointer($this->loadedRuleByKey('x3'));

        self::assertSame(2, substr_count($rendered, 'deferred: budget. Read'), 'the pointer ceiling holds at two lines');
        self::assertTrue(str_contains($rendered, $p1 . "\n" . $p2 . "\n" . $note), 'in loader order, with the overflow counted in the note');
        self::assertSame(0, substr_count($rendered, $p3), 'the third deferral is counted, not pointed at twice over');
        self::assertTrue(str_contains($rendered, '[1 further standing rule(s) deferred'));
    }

    /**
     * Requirement 7(c): the boundary is the LAST BYTE THAT FITS. A rule whose
     * framed section equals the whole-rules budget to the byte renders; one byte
     * more defers to a pointer. This is what turns the budget from an
     * approximately-right number into a measured gate.
     */
    public function testTheStandingBudgetBoundaryRendersTheLastFittingByteAndDefersOneMore(): void
    {
        $exact = $this->standingWholeBudget() - $this->userRuleFramingOverhead();
        file_put_contents($this->packsDir . '/edge.md', 'EDGEMARK' . str_repeat('e', $exact - 8));

        $fits = $this->render(RulesState::new());
        self::assertStringContainsString('EDGEMARK', $fits, 'exactly on budget, it renders whole');
        self::assertSame(0, substr_count($fits, 'deferred: budget'));

        file_put_contents($this->packsDir . '/edge.md', 'EDGEMARK' . str_repeat('e', $exact - 7));

        $over = $this->render(RulesState::new());
        self::assertSame(0, substr_count($over, 'EDGEMARK'), 'one byte past it, the body is gone whole');
        self::assertSame(
            1,
            substr_count($over, RulePathNudge::pointer($this->loadedRuleByKey('edge'))),
            'and the pointer line is what stands in its place',
        );
    }

    /**
     * The end-to-end bound guard fix round 1 asked for, through the real render
     * seam: sum the actual framed bytes of every standing-rule section the prompt
     * carries and assert the ratified ceiling. The plant is the worst case the
     * grammar can build — a whole user pack plus three oversized user packs and
     * three oversized project packs whose names push their pointer lines toward
     * {@see RulePathNudge::maxPointerBytes()}, so BOTH tier fences carry the two
     * lines plus the counted note. The positive controls make the test
     * non-vacuous: if the geometry ever stops being the worst case, the pointer
     * and note counts fail before the ceiling assertion gets to lie.
     */
    public function testTheRenderedStandingSpliceNeverExceedsTheCeiling(): void
    {
        $longName = str_repeat('L', 900);
        file_put_contents($this->packsDir . '/a-small.md', 'SPLICE-SMALL-' . str_repeat('s', 2986) . "\n");
        foreach (['b1', 'b2', 'b3'] as $n) {
            file_put_contents(
                $this->packsDir . '/' . $n . '.md',
                "---\nname: " . $longName . "\n---\n" . 'SPLICE-BIG-' . $n . str_repeat('b', 57984 - strlen('SPLICE-BIG-' . $n)) . "\n",
            );
        }
        $projectDir = $this->sandbox . '/repo/.candy-code/rules';
        mkdir($projectDir, 0o700, true);
        foreach (['p1', 'p2', 'p3'] as $n) {
            file_put_contents(
                $projectDir . '/' . $n . '.md',
                "---\nname: " . $longName . "\n---\n" . 'SPLICE-PRJ-' . $n . str_repeat('p', 57984 - strlen('SPLICE-PRJ-' . $n)) . "\n",
            );
        }

        $prompt = $this->render(RulesState::new());

        self::assertSame(4, substr_count($prompt, 'deferred: budget. Read'), 'two pointer lines in each tier fence');
        self::assertSame(2, substr_count($prompt, 'further standing rule(s) deferred'), 'both fences carry the counted note');
        self::assertSame(1, substr_count($prompt, 'SPLICE-SMALL-'), 'the fitting pack still renders whole');
        self::assertSame(0, substr_count($prompt, 'SPLICE-BIG-'), 'no deferred body runs or clips');

        // Summed with strpos/substr rather than a regex on purpose: a
        // preg pattern literal is glob-shaped, and harvesting string literals
        // from the tree is exactly how GlobDialectDifferentialTest derives its
        // corpus — this test must not move that figure by existing.
        $fences = $this->spliceBlocks($prompt, 'user-rules');
        self::assertCount(2, $fences, 'one whole user fence plus one deferral fence');
        $projectFences = $this->spliceBlocks($prompt, 'project-instructions');
        self::assertCount(1, $projectFences, 'one project deferral fence');

        $total = array_sum(array_map('strlen', array_merge($fences, $projectFences)));
        $ceiling = (new ReflectionClass(Runtime::class))->getConstant('MAX_STANDING_RULE_BYTES');

        self::assertIsInt($ceiling);
        self::assertGreaterThan(8000, $total, 'the plant really did produce the framing this test exists to price');
        self::assertLessThanOrEqual($ceiling, $total, 'the emitted splice, measured end to end, respects the ceiling');
    }

    /**
     * The known-answer pin for the reserve: computed here INDEPENDENTLY of
     * {@see Runtime::standingDeferReserve()} from the same public surface —
     * literal framings, the shared pointer ceiling, the worst-case note — so the
     * budget tests no longer agree with production merely by asking production
     * what it thinks. The integer interior must never ride inside a strlen'd
     * string again: that is exactly how the 759-byte undercount survived the
     * first round's self-referential oracle.
     */
    public function testStandingDeferReserveEqualsAnIndependentlyComputedKnownAnswer(): void
    {
        $rc = new ReflectionClass(Runtime::class);
        $pointers = (int) $rc->getConstant('MAX_STANDING_POINTERS');
        $note = sprintf((string) $rc->getConstant('STANDING_DEFERRED_NOTE'), PHP_INT_MAX);

        $interior = $pointers * RulePathNudge::maxPointerBytes()
            + ($pointers - 1)
            + 1
            + strlen($note);
        $expected = strlen("<user-rules>\n")
            + strlen((string) $rc->getConstant('USER_RULES_AUTHORITY_PREAMBLE'))
            + strlen("\n\n") + $interior + strlen("\n</user-rules>")
            + strlen("<project-instructions>\n")
            + strlen((string) $rc->getConstant('INSTRUCTIONS_AUTHORITY_PREAMBLE'))
            + strlen("\n\n") + $interior + strlen("\n</project-instructions>");

        $reserve = new ReflectionMethod(Runtime::class, 'standingDeferReserve');
        $reserve->setAccessible(true);

        self::assertSame(2147, $interior, 'the worst-case deferral interior is this fixed arithmetic');
        self::assertSame(5045, $expected, 'the ratified reserve: 65,536 ceiling minus this is what whole renders may spend');
        self::assertSame($expected, (int) $reserve->invoke(null), 'production agrees with the independent sum');
    }

    // -- helpers --------------------------------------------------------------

    private function provider(): ProviderInterface
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test-provider');

        return $provider;
    }

    private function render(?RulesState $state, ?string $root = null): string
    {
        return $this->renderPrompt(
            App::new($this->provider(), 'gpt-4')
                ->withRoot($root ?? $this->sandbox . '/repo')
                ->withRulesState($state),
        );
    }

    /**
     * One render per call, on a fresh Runtime — the prompt is memoised per-Runtime
     * (§17.2), so reusing an instance across two states would return one string
     * twice and make every comparison in this file vacuous.
     */
    private function renderPrompt(App $app): string
    {
        $runtime = new Runtime($this->provider(), new HookManager(new HookRegistry()));
        $method = new ReflectionMethod($runtime, 'buildSystemPrompt');
        $method->setAccessible(true);

        return (string) $method->invoke($runtime, $app);
    }

    /**
     * Every whole fence block with the given bare tag in a rendered prompt,
     * delimited by literal byte searches rather than a regex: a
     * pattern literal containing a star and a question mark is
     * glob-shaped, and harvesting string literals from the tree is exactly how
     * GlobDialectDifferentialTest derives its corpus - this file must not move
     * that pinned figure merely by existing.
     */
    private function spliceBlocks(string $prompt, string $tag): array
    {
        $open = "<$tag>\n";
        $close = "\n</$tag>";
        $blocks = [];
        $offset = 0;
        while (($start = strpos($prompt, $open, $offset)) !== false) {
            $end = strpos($prompt, $close, $start);
            self::assertNotFalse($end, 'an unclosed ' . $tag . ' fence in a rendered prompt');
            $blocks[] = substr($prompt, $start, $end + strlen($close) - $start);
            $offset = $end + strlen($close);
        }

        return $blocks;
    }

    private function preamble(): string
    {
        $preamble = (new ReflectionClass(Runtime::class))->getConstant('USER_RULES_AUTHORITY_PREAMBLE');
        self::assertIsString($preamble, 'Runtime::USER_RULES_AUTHORITY_PREAMBLE must exist as a string constant');

        return $preamble;
    }

    /**
     * The framed bytes a whole standing-rule section costs around one user-tier
     * body: opener, preamble, blank line, body, closer. Derived from the same
     * constant the splice reads rather than a copied count, so a preamble reword
     * moves this with it instead of red-ing the boundary fixtures by accident.
     */
    private function userRuleFramingOverhead(): int
    {
        return strlen("<user-rules>\n") + strlen($this->preamble()) + 2 + strlen("\n</user-rules>");
    }

    /**
     * What the two standing loops may spend on WHOLE rule sections: the ratified
     * ceiling minus the reserved worst-case deferral framing, both read from the
     * production class so the boundary fixtures test the real gate and not a
     * re-derivation of it.
     */
    private function standingWholeBudget(): int
    {
        $reserve = new ReflectionMethod(Runtime::class, 'standingDeferReserve');
        $reserve->setAccessible(true);
        $ceiling = (new ReflectionClass(Runtime::class))->getConstant('MAX_STANDING_RULE_BYTES');

        return (int) $ceiling - (int) $reserve->invoke(null);
    }

    /**
     * The loader's own Rule for a pack written into this sandbox — the identity
     * the pointer line is built from, taken from production parsing rather than a
     * hand-made Rule, so frontmatter name fallback and path spelling are exactly
     * what the splice saw.
     */
    private function loadedRuleByKey(string $key): Rule
    {
        foreach ((new RuleLoader($this->sandbox . '/repo'))->load() as $rule) {
            if ($rule->key === $key) {
                return $rule;
            }
        }

        self::fail('the loader no longer emits a rule keyed ' . $key);
    }
}
