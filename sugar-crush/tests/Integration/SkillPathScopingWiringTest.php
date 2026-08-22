<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillPathNudge;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Glob;
use SugarCraft\Crush\Tools\BuiltIn\Read;

/**
 * crush_feat.md section 7 E4: `paths:` auto-scoping has to reach the live
 * tool-touch path, not sit as static frontmatter. Against the old code
 * `Bootstrap::tools()` built Read/Edit/Glob with no route to the registry at
 * all, so a project skill scoped to `**\/*.php` never announced itself when
 * the agent opened a PHP file.
 *
 * Drives Bootstrap directly rather than shelling out to bin/sugarcrush, which
 * ends in a blocking Program::run() — same convention as
 * {@see BinSugarcrushWiringTest}.
 */
final class SkillPathScopingWiringTest extends TestCase
{
    /**
     * How much bigger than {@see SkillPathNudge::maxBytes()} the tightest
     * shipped nudge budget must stay.
     *
     * 2.0 and not 1.0: at 1.0 the guard fires the instant the nudge starts
     * being clipped, which is also the instant the only free answer has
     * already been spent. Two means the tracker's ceiling can DOUBLE — twice
     * the entries, or twice the bytes per entry — before anyone has to argue
     * about a model-facing output cap. See
     * {@see testTheShippedCapsClearTheCeilingByTheDecidedMargin()} for the
     * decision and {@see SkillPathNudge}'s `MAX_ENTRIES` for the ranked way
     * out.
     */
    private const REQUIRED_CEILING_MARGIN = 2.0;

    private string $tempDir = '';
    private string $originalHome = '';
    private mixed $originalServerHome;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sugarcrush_skill_paths_' . uniqid('', true);
        mkdir($this->tempDir . '/home', 0o700, true);
        mkdir($this->tempDir . '/repo', 0o755, true);

        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=' . $this->tempDir . '/home');

        // BOTH: Bootstrap reads getenv('HOME'), ForeignSkillDiscovery — now
        // reached from SkillManager::loadAll() — reads $_SERVER['HOME'], so
        // redirecting one leaves the other scanning the developer's own
        // ~/.claude/skills.
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->tempDir . '/home';

        $dir = $this->tempDir . '/repo/.sugar-crush/skills/path-scoped-audit';
        mkdir($dir, 0o755, true);
        file_put_contents(
            $dir . '/SKILL.md',
            "---\ndescription: PATH SCOPED MARKER\nuser-invocable: true\n"
            . "disable-model-invocation: false\npaths:\n  - \"*.php\"\n---\n# body\n",
        );
    }

    protected function tearDown(): void
    {
        if ($this->originalHome !== '') {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }

        if ($this->originalServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->originalServerHome;
        }

        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    /**
     * NARROW BY DESIGN, and no longer the only guard. This names three tools;
     * `Bootstrap::tools()` hands the tracker to FIVE (Read, Edit, Glob, Grep,
     * Write). A hand-kept list here is what let `Write`'s nudge wiring ship
     * unguarded. MEASURED at 82b8ee3e, from `sugar-crush/`:
     *
     *   vendor/bin/phpunit tests/Integration/FeatWiringReachabilityTest.php \
     *     tests/Integration/SkillPathScopingWiringTest.php \
     *     tests/Integration/BinSugarcrushWiringTest.php \
     *     tests/Tools/BuiltIn/WriteTest.php \
     *     tests/Tools/BuiltIn/SkillPathScopingTest.php
     *
     * reports `OK (376 tests, 1980 assertions)` untouched, and reports the
     * SAME `OK (376 tests, 1980 assertions)` with `skillNudge:` dropped from
     * `Write` — the mutation moves not one assertion. Its `instructionLoader:`
     * half was NOT unguarded: dropping that one gives `Tests: 376,
     * Assertions: 1980, Failures: 1` at
     * `BinSugarcrushWiringTest::testBootstrapToolsShipsAWriteToolAndTheWholeBuiltInSet()`.
     *
     * The roster-wide assertion now lives in
     * {@see \SugarCraft\Crush\Tests\Integration\BinSugarcrushWiringTest::testEveryLoaderCarryingToolSharesTheOneSkillPathNudge()},
     * which DERIVES its list from the tools that actually declare the
     * property. This test is kept as the focused Read/Edit/Glob case it always
     * was; it is deliberately not widened, because two derivations of the same
     * roster is the duplication that started this.
     */
    public function testBootstrapGivesReadEditAndGlobTheSameNudgeTracker(): void
    {
        $byClass = [];
        foreach (Bootstrap::tools($this->tempDir . '/repo') as $tool) {
            $byClass[$tool::class] = $tool;
        }

        $read = $this->nudgeOf($byClass[Read::class]);
        $edit = $this->nudgeOf($byClass[Edit::class]);
        $glob = $this->nudgeOf($byClass[Glob::class]);

        $this->assertInstanceOf(SkillPathNudge::class, $read);
        $this->assertSame($read, $edit);
        $this->assertSame($read, $glob);
    }

    public function testReadingAProjectPhpFileSurfacesThePathScopedProjectSkill(): void
    {
        $path = $this->tempDir . '/repo/App.php';
        file_put_contents($path, "<?php\n");

        $byClass = [];
        foreach (Bootstrap::tools($this->tempDir . '/repo') as $tool) {
            $byClass[$tool::class] = $tool;
        }

        $content = $byClass[Read::class]->execute(['id' => 'c1', 'file_path' => $path])->content();

        $this->assertStringContainsString('path-scoped-audit: PATH SCOPED MARKER', $content);
        $this->assertStringContainsString('<system-reminder>', $content);
    }

    /**
     * E78 — NOTHING TIED THE SHIPPED TOOL CAPS TO THE PRICE OF A NUDGE, and
     * the caps are the only reason E70's dead band is not live in production.
     *
     * E70: a tool spends a FRACTION of its own output cap on the skill-path
     * nudge, and a fraction too small to buy one entry makes
     * {@see SkillPathNudge::forPaths()} return null and mark nothing — so the
     * model is shown a hit inside a directory a skill claims and is never told
     * the skill exists. Round 41 pinned that at the unit level, one tool at a
     * time, against caps the test itself chose. Whether any of it applies to a
     * real launch depends entirely on the caps `Bootstrap::tools()` actually
     * ships, and no assertion connected the two.
     *
     * WHAT THE BACKLOG SAID, and what re-measuring found: the item records
     * `Bootstrap.php` as CONSTRUCTING Read/Glob/Grep with 1 MB / 65,536 /
     * 65,536. It does not. {@see Bootstrap::tools()} passes `$root`,
     * `instructionLoader:` and `skillNudge:` and NO cap at all — the figures are
     * the tools' own `DEFAULT_MAX_BYTES` / `DEFAULT_MAX_OUTPUT_BYTES` defaults.
     * That matters for where the guard has to look: an assertion written
     * against a literal in `Bootstrap.php` would have had nothing to read, and
     * the value that can actually regress lives in the tool. So the cap is read
     * off the CONSTRUCTED INSTANCE, which is true whichever of the two moves.
     *
     * THE ROSTER IS DERIVED FROM THE CALL, not from a property name and not
     * from a list written here. {@see \SugarCraft\Crush\Tools\BuiltIn\Edit} and
     * {@see \SugarCraft\Crush\Tools\BuiltIn\Write} also hold a `skillNudge` and
     * `Edit` also declares a `maxBytes`, but both hand the tracker a NULL
     * budget — their result is a one-line success message with no cap to spend
     * inside — so a roster keyed off "declares a cap" would make a claim about
     * them their code does not make. {@see nudgeSpendRoster()} reads the
     * divisor out of the `intdiv($this-><cap>, <n>)` in each tool's own source.
     *
     * WHAT THIS PARAGRAPH USED TO CLAIM FOR THAT DERIVATION: "a sixth capped
     * tool joining the seam is covered without being named".
     * WHAT IS TRUE NOW, and what was already true when the sentence was
     * written: it is false in both directions, and round 42's review is what
     * caught it. The derivation was a single regex with `continue` on
     * non-match, so a new tool that spelled its budget any other way was
     * dropped SILENTLY and the hard-coded `assertSame` roster below stayed
     * green with that tool unguarded; MEASURED on PHP 8.3.6 against six
     * plausible spellings, only two matched (the canonical ternary and the same
     * expression inside a `match`) — a hoisted local, a named-argument helper,
     * an `intdiv(self::CAP, …)` on a class constant and a plain `/` division
     * all missed. And a tool that DID match made the roster four entries and
     * red the `assertSame`, so it had to be named after all.
     * WHY THE DERIVATION STILL EARNS ITS PLACE: only the SILENT half was the
     * defect. {@see nudgeSpendRoster()} now fails loudly on any nudge-spending
     * call it cannot read — an unparsable budget is an assertion failure naming
     * the expression, not a `continue` — so the one outcome that cannot happen
     * any more is the green suite with an uncovered tool. Being named in the
     * roster below is then a deliberate, one-line acknowledgement rather than
     * the thing that decides whether the tool is checked at all; the two
     * buckets ({@see testTheToolsThatPassNoNudgeBudgetSpendNothingByDesign()}
     * for the null-budget half) together account for every tool that holds the
     * tracker, so nothing can leave the roster unnoticed either.
     *
     * THE THRESHOLD IS ASKED OF THE TRACKER at runtime rather than written
     * down, which is the discipline round 41 established for exactly these
     * guards — {@see SkillPathNudge::maxBytes()} is priced from the header, the
     * footer, `MAX_ENTRIES`, `MAX_ENTRY_BYTES` and the deferred note, so a
     * change to any of them moves this test with it. MEASURED on this tree, PHP
     * 8.3.6: `maxBytes()` is 2,636, and the shipped budgets are Read
     * 1,048,576/8 = 131,072, Glob 65,536/8 = 8,192, Grep 65,536/8 = 8,192.
     *
     * AND THE BRIEF'S MARGIN WAS WRONG, which is the finding rather than the
     * guard: it records the nudge floor as 166-174 bytes and the shipped
     * eighths as clearing it "by three orders of magnitude", with a reopening
     * threshold "below roughly 1,400 bytes". Round 41 changed
     * `SkillPathNudge`'s pricing after those figures were taken. Against
     * `maxBytes()` the real margin on Glob and Grep is 8,192 / 2,636 = 3.1x —
     * half an order of magnitude, not three — and the cap at which this test
     * reds is 8 x 2,636 = 21,088, fifteen times the figure recorded.
     *
     * AND THE ROUND-43 BRIEF FOR E87 WAS WRONG IN THE OTHER DIRECTION. It
     * states that raising `MAX_ENTRIES` from 8 to 20 "reds the new ceiling
     * guard without anyone touching a cap". The two ceiling figures it quotes
     * both reproduce — 2,636 at 8 and 6,248 at 20 — but 6,248 is comfortably
     * UNDER the 8,192 the tightest shipped budget carries, so this test stays
     * green. MEASURED by mutation at 26bcdb42 on PHP 8.3.6, with only
     * `MAX_ENTRIES` changed: 20 green, 26 green, 27 RED (ceiling 8,355), 40
     * red. The tipping point is 27, and it is now derived rather than
     * remembered — {@see SkillPathNudge::largestEntryCountWithin()} over the
     * tightest shipped budget answers 26.
     *
     * WHAT WAS DECIDED FOR E87, since the brief asked for a choice and not a
     * survey: the ABSOLUTE caps stay, and the required relationship is
     * ASSERTED rather than engineered. Expressing each tool's nudge budget as
     * a multiple of `maxBytes()` would make the relationship structural and
     * would also hand a `Grep` constructed with `maxOutputBytes: 1_000` a
     * 2,636-byte nudge budget inside a 1,000-byte result — E66 inverted. The
     * nudge is spent INSIDE the caller's cap by design, so the margin can only
     * ever be a property of the DEFAULT caps. What DID change is that the
     * eighth is one constant instead of three literals
     * ({@see SkillPathNudge::CALLER_BUDGET_DIVISOR}), and that the margin is
     * now stated out loud with an action attached by
     * {@see testTheShippedCapsClearTheCeilingByTheDecidedMargin()}.
     */
    public function testEveryShippedNudgeBudgetClearsTheTrackerCeiling(): void
    {
        $shipped = $this->nudgeSpendRoster()['spend'];

        self::assertSame(
            ['Glob', 'Grep', 'Read'],
            array_keys($shipped),
            'the set of shipped tools that spend a share of their own cap on the skill nudge has changed; '
            . 'derive the guard again rather than widening this list',
        );

        foreach ($shipped as $tool => $spend) {
            self::assertGreaterThanOrEqual(
                SkillPathNudge::maxBytes(),
                $spend['budget'],
                sprintf(
                    '%s ships a cap of %d and spends a %dth of it on the nudge — %d bytes, under the '
                    . "tracker's own %d-byte ceiling, so a nudge this launch builds can be clipped",
                    $tool,
                    $spend['cap'],
                    $spend['divisor'],
                    $spend['budget'],
                    SkillPathNudge::maxBytes(),
                ),
            );
        }
    }

    /**
     * E87 — the margin over the ceiling, stated as a number with an action
     * attached rather than left as an accident of two unrelated constants.
     *
     * {@see testEveryShippedNudgeBudgetClearsTheTrackerCeiling()} asserts the
     * HARD property: a shipped budget must be able to hold a whole nudge, or
     * the model gets a clipped one. That guard reds at the moment the
     * behaviour breaks, which is the worst moment to find out, because the
     * three ways out of it are ranked and two of them are model-facing
     * changes that need their own measurement (see
     * {@see SkillPathNudge}'s `MAX_ENTRIES` doc-block for the ranked list).
     *
     * THIS ONE REDS ONE STEP EARLIER, ON PURPOSE. The decided requirement is
     * that the tightest shipped budget carries at least {@see
     * REQUIRED_CEILING_MARGIN} times the tracker's ceiling — enough room to
     * roughly DOUBLE `MAX_ENTRIES`, or `MAX_ENTRY_BYTES`, without anyone
     * having to touch a tool's output cap. A guard that only fires once the
     * nudge is already being clipped tells you to make a model-facing change
     * under time pressure; this one tells you while option 1 (come back under
     * the ceiling) is still free.
     *
     * MEASURED on this tree, PHP 8.3.6, and every figure below is re-derived
     * at run time rather than asserted from memory: ceiling 2,636, tightest
     * shipped budget 8,192 (Grep and Glob, 65,536 / 8), actual margin 3.1x,
     * `MAX_ENTRIES` 8 against the 26 that budget carries. The hard guard reds
     * at `MAX_ENTRIES` 27; this one reds at 13.
     *
     * SO IF YOU ARE HERE HAVING REDDED IT: nothing is broken yet. The nudge
     * still fits. You have spent the headroom that existed so the next person
     * would not have to argue about a cap, and the cheap answer is to give
     * some back.
     */
    public function testTheShippedCapsClearTheCeilingByTheDecidedMargin(): void
    {
        $shipped = $this->nudgeSpendRoster()['spend'];
        self::assertNotSame([], $shipped, 'no tool spends a share of its cap on the nudge any more');

        // One dial, not three. A tool that deliberately spends a different
        // share has to say so here, which is the point of the constant.
        foreach ($shipped as $tool => $spend) {
            self::assertSame(
                SkillPathNudge::CALLER_BUDGET_DIVISOR,
                $spend['divisor'],
                $tool . ' spends a share of its cap the other nudge-spending tools do not; if that is '
                . 'deliberate say why here, because the margin below is derived from the tightest of them',
            );
        }

        $tightest = min(array_column($shipped, 'budget'));
        $margin = $tightest / SkillPathNudge::maxBytes();

        self::assertGreaterThanOrEqual(
            self::REQUIRED_CEILING_MARGIN,
            $margin,
            sprintf(
                'the tightest shipped nudge budget is %d bytes against a tracker ceiling of %d — a margin '
                . 'of %.2fx, under the %.1fx this tree decided to keep. Nothing is clipped yet; the hard '
                . 'guard is at 1.0x. The cheap fix is to bring SkillPathNudge::MAX_ENTRIES down to %d or '
                . 'below (it is %d), which is what that budget carries at the decided margin. Raising a '
                . "tool's default output cap or lowering CALLER_BUDGET_DIVISOR are the other two answers "
                . 'and both move what the model sees, so both are their own item.',
                $tightest,
                SkillPathNudge::maxBytes(),
                $margin,
                self::REQUIRED_CEILING_MARGIN,
                SkillPathNudge::largestEntryCountWithin((int) ($tightest / self::REQUIRED_CEILING_MARGIN)),
                $this->configuredMaxEntries(),
            ),
        );

        // AND THE INVERSE IS PINNED AT ITS BOUNDARY, not merely sanity-checked.
        // The message above is only actionable if largestEntryCountWithin()
        // really is the inverse of maxBytes(), and `>= MAX_ENTRIES` is far too
        // loose a window to notice that it is not: dropping the fixed-parts
        // subtraction from it leaves every such comparison green while the
        // number it prints is one too high. The exact round-trip is the
        // property — maxBytes() is by construction the price of exactly
        // MAX_ENTRIES entries, so one byte less must buy exactly one fewer.
        $entries = $this->configuredMaxEntries();

        self::assertSame(
            $entries,
            SkillPathNudge::largestEntryCountWithin(SkillPathNudge::maxBytes()),
            'the tracker ceiling does not buy exactly the entry count it is priced from',
        );
        self::assertSame(
            $entries - 1,
            SkillPathNudge::largestEntryCountWithin(SkillPathNudge::maxBytes() - 1),
            'one byte under the ceiling still buys a full nudge, so largestEntryCountWithin() is not '
            . "maxBytes()'s inverse and the remedy this test prints is off by at least one entry",
        );
    }

    /**
     * `SkillPathNudge::MAX_ENTRIES`, read rather than copied.
     *
     * Private on the tracker on purpose — it is not a knob callers turn — but
     * the failure message above is only actionable if it can name the value
     * the reader has to change.
     */
    private function configuredMaxEntries(): int
    {
        $value = (new \ReflectionClass(SkillPathNudge::class))->getConstant('MAX_ENTRIES');
        self::assertIsInt($value);

        return $value;
    }

    /**
     * The same guard at the threshold that E70 is actually ABOUT, and the two
     * are not the same number.
     *
     * {@see testEveryShippedNudgeBudgetClearsTheTrackerCeiling()} asserts the
     * strong property — the budget can never clip a nudge at all. The DEAD BAND
     * is the weak one: a budget that cannot buy even ONE worst-case entry
     * returns null and marks nothing, so a visible hit goes unannounced. A cap
     * between the two thresholds clips the nudge without silencing it, which is
     * a degradation and not the defect.
     *
     * The floor is DERIVED, by asking a tracker over a worst-case registry for
     * the smallest budget that buys anything — the same technique
     * {@see \SugarCraft\Crush\Tests\Tools\BuiltIn\GrepInstructionWiringTest::smallestNudgeBudget()}
     * uses, widened from that file's own fixture skill to the worst case any
     * user's skills tree can present: a description far past `MAX_ENTRY_BYTES`
     * so the entry is clipped to the per-entry ceiling, and a SECOND matching
     * skill so the deferred-note reserve is part of the price. MEASURED on this
     * tree, PHP 8.3.6: 529 bytes, against a `maxBytes()` of 2,636.
     *
     * Both are asserted because both can regress independently, and the
     * ordering between them is asserted too — if the derived floor ever exceeds
     * the ceiling, one of the two derivations is wrong and neither guard means
     * anything.
     */
    public function testEveryShippedNudgeBudgetClearsTheWorstCaseDeadBandFloor(): void
    {
        $floor = $this->worstCaseNudgeFloor();

        self::assertLessThanOrEqual(
            SkillPathNudge::maxBytes(),
            $floor,
            'the price of one worst-case entry cannot exceed the price of a whole nudge; one of the two '
            . 'derivations no longer describes the tracker',
        );

        foreach ($this->nudgeSpendRoster()['spend'] as $tool => $spend) {
            self::assertGreaterThanOrEqual(
                $floor,
                $spend['budget'],
                sprintf(
                    "%s ships a cap of %d and spends a %dth of it on the nudge — %d bytes, under the %d "
                    . 'bytes one worst-case entry costs, so E70\'s dead band is open on this tool: a hit '
                    . 'the model can see whose skill it is never told about',
                    $tool,
                    $spend['cap'],
                    $spend['divisor'],
                    $spend['budget'],
                    $floor,
                ),
            );
        }
    }

    /**
     * The tools that hold the tracker and deliberately spend NOTHING on it.
     *
     * The complement of the two ceiling guards above, and the reason their
     * three-name roster is a statement rather than an accident. Every tool
     * {@see Bootstrap::tools()} hands a `SkillPathNudge` lands in exactly one
     * of the two buckets {@see nudgeSpendRoster()} returns — a budget it can
     * read, or a call that passes no budget at all — and anything that is
     * neither is an assertion failure inside the derivation itself. So a new
     * nudge-carrying tool cannot join the seam and be counted by no guard: it
     * either reds this list, reds the roster above, or reds the parse.
     *
     * {@see \SugarCraft\Crush\Tools\BuiltIn\Edit} and
     * {@see \SugarCraft\Crush\Tools\BuiltIn\Write} are here because their
     * result is a one-line success message — there is no output cap to spend a
     * fraction of, so `forPath($path)` is called with the budget argument
     * omitted and the tracker's own ceiling stands. `Edit` declares a
     * `maxBytes` all the same, which is exactly why the roster cannot be keyed
     * off "declares a cap".
     */
    public function testTheToolsThatPassNoNudgeBudgetSpendNothingByDesign(): void
    {
        $roster = $this->nudgeSpendRoster();

        self::assertSame(
            ['Edit', 'Write'],
            $roster['unbudgeted'],
            'the set of nudge-carrying tools that pass no budget has changed; a tool that gained an '
            . 'output cap belongs in the ceiling guards above, and one that lost its budget has left them',
        );

        self::assertSame(
            [],
            array_intersect($roster['unbudgeted'], array_keys($roster['spend'])),
            'a tool cannot be in both buckets',
        );
    }

    /**
     * What each Bootstrap-built tool will really spend on a nudge, keyed by
     * class short name, plus the ones that spend nothing.
     *
     * The cap comes off the CONSTRUCTED INSTANCE — a Bootstrap that started
     * passing an explicit cap and a tool whose default constant dropped are the
     * same regression from here — and the divisor comes out of the tool's own
     * source, so neither is restated. A tool whose cap is <= 0 passes `null`
     * for the budget, which means "no caller cap, the class ceiling stands", and
     * is modelled as an unbounded budget rather than as zero.
     *
     * NOTHING IS DROPPED SILENTLY, which is the whole difference between this
     * and the version round 42's review took apart. A tool that holds a
     * non-null tracker gets exactly three outcomes: it calls the tracker with a
     * budget this can read (the `spend` bucket), it calls the tracker with no
     * budget argument at all (`unbudgeted`), or the derivation FAILS naming the
     * expression it could not read. There is deliberately no `continue` for the
     * third case: a tool quietly leaving the roster is precisely how the two
     * ceiling guards above would go on passing while covering less.
     *
     * @return array{spend: array<string, array{cap: int, divisor: int, budget: int}>, unbudgeted: list<string>}
     */
    private function nudgeSpendRoster(): array
    {
        $spend = [];
        $unbudgeted = [];

        foreach (Bootstrap::tools($this->tempDir . '/repo') as $tool) {
            $class = new \ReflectionClass($tool);
            if (!$class->hasProperty('skillNudge') || $this->nudgeOf($tool) === null) {
                continue;
            }

            $short = $class->getShortName();
            $file = $class->getFileName();
            self::assertIsString($file, $short . ' has no source file to read its nudge budget out of');

            $arguments = self::nudgeCallArguments((string) file_get_contents($file));
            self::assertNotNull(
                $arguments,
                $short . ' holds a SkillPathNudge but never calls forPath()/forPaths(): either the '
                . 'tracker is wired to a tool that cannot use it, or the call moved somewhere this '
                . 'derivation cannot see, and either way the guards here have stopped covering it',
            );

            if (count((array) $arguments) < 2) {
                $unbudgeted[] = $short;
                continue;
            }

            $budgetSource = ((array) $arguments)[1];
            // THE DIVISOR IS NOW EITHER A LITERAL OR THE SHARED CONSTANT, and
            // both shapes are read rather than one being blessed. E87 moved
            // Grep, Glob and Read off three literal 8s onto
            // SkillPathNudge::CALLER_BUDGET_DIVISOR — and this derivation
            // rejected all three loudly, which is the roster working. Keeping
            // the literal branch is not dead weight: a tool that deliberately
            // spends a different share writes the number, and the guards below
            // must still cover it.
            self::assertSame(
                1,
                preg_match(
                    '/intdiv\(\$this->(\w+),\s*(?:(\d+)|SkillPathNudge::CALLER_BUDGET_DIVISOR)\s*\)/',
                    $budgetSource,
                    $captured,
                ),
                sprintf(
                    '%s spends a nudge budget written in a shape this derivation cannot read: `%s`. '
                    . 'Leaving it out is not an option — a tool dropped here is a tool the ceiling '
                    . 'guards stop covering while staying green, which is the defect round 42 found. '
                    . 'Teach nudgeSpendRoster() the new shape, or pass no budget argument if the tool '
                    . 'means to spend nothing.',
                    $short,
                    trim(preg_replace('/\s+/', ' ', $budgetSource) ?? $budgetSource),
                ),
            );

            $capProperty = new \ReflectionProperty($tool, $captured[1]);
            $capProperty->setAccessible(true);
            $cap = $capProperty->getValue($tool);
            self::assertIsInt($cap, $short . '::$' . $captured[1] . ' is not an int cap');

            $divisor = ($captured[2] ?? '') !== ''
                ? (int) $captured[2]
                : SkillPathNudge::CALLER_BUDGET_DIVISOR;
            self::assertGreaterThan(0, $divisor);

            $spend[$short] = [
                'cap' => $cap,
                'divisor' => $divisor,
                'budget' => $cap > 0 ? intdiv($cap, $divisor) : PHP_INT_MAX,
            ];
        }

        ksort($spend);
        sort($unbudgeted);

        return ['spend' => $spend, 'unbudgeted' => $unbudgeted];
    }

    /**
     * The argument list of the FIRST `skillNudge?->forPath(…)` /
     * `forPaths(…)` call in $source, as top-level source fragments.
     *
     * A balanced scan rather than a regex over the whole statement, because the
     * regex is what failed: `[^;]*?intdiv\(\$this->(\w+),\s*(\d+)\)` recognised
     * one spelling of the budget and answered "not a spender" to every other,
     * with no way for the caller to tell that apart from "no such call". This
     * answers the narrower question the caller can act on — WHAT the arguments
     * are — and leaves judging them to {@see nudgeSpendRoster()}, which can
     * then fail instead of shrugging.
     *
     * Quoted spans are skipped so a comma inside a string literal does not
     * split an argument. A tool with two nudge calls is read at its first;
     * there is no such tool today and a second one would need this widened.
     *
     * @return list<string>|null null when the tool never calls the tracker at all
     */
    private static function nudgeCallArguments(string $source): ?array
    {
        if (preg_match('/skillNudge\?->forPaths?\(/', $source, $found, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $depth = 1;
        $arguments = [];
        $current = '';
        $quote = '';
        $length = strlen($source);

        for ($i = (int) $found[0][1] + strlen((string) $found[0][0]); $i < $length; $i++) {
            $character = $source[$i];

            if ($quote !== '') {
                $current .= $character;
                if ($character === '\\' && $i + 1 < $length) {
                    $current .= $source[++$i];
                } elseif ($character === $quote) {
                    $quote = '';
                }
                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;
                $current .= $character;
                continue;
            }

            if ($character === '(' || $character === '[') {
                $depth++;
            } elseif ($character === ')' || $character === ']') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }

            if ($depth === 1 && $character === ',') {
                $arguments[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $character;
        }

        if (trim($current) !== '') {
            $arguments[] = trim($current);
        }

        return $arguments;
    }

    /**
     * The smallest budget that buys ONE entry from a worst-case registry.
     *
     * A fresh tracker per probe, because the announce-once mark is one-shot: a
     * reused tracker would answer "already announced" where the question is
     * "could not afford".
     */
    private function worstCaseNudgeFloor(): int
    {
        $registry = new SkillRegistry();
        $registry->register([
            $this->oversizedSkill('worst-case-a'),
            // TWO of them, so the entry is not the last pending one and pays
            // the deferred-note reserve. One skill is the cheap case.
            $this->oversizedSkill('worst-case-b'),
        ]);

        for ($budget = 1; $budget <= SkillPathNudge::maxBytes(); $budget++) {
            if (SkillPathNudge::new($registry)->forPath('/anywhere/probe.php', $budget) !== null) {
                return $budget;
            }
        }

        self::fail('no budget up to the tracker ceiling bought one worst-case entry');
    }

    /**
     * A path-scoped, auto-invocable skill whose description is far past
     * `MAX_ENTRY_BYTES`, so its entry clips to the per-entry ceiling — the most
     * expensive single entry a user's skills tree can produce.
     */
    private function oversizedSkill(string $name): Skill
    {
        return new Skill(
            name: $name,
            description: str_repeat('d', 20000),
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: '',
            context: '',
            paths: ['*.php'],
            content: '',
            sourcePath: '/nonexistent/' . $name . '/SKILL.md',
        );
    }

    private function nudgeOf(object $tool): ?SkillPathNudge
    {
        $property = new \ReflectionProperty($tool, 'skillNudge');
        $property->setAccessible(true);

        return $property->getValue($tool);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
