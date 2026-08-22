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
     * them their code does not make. {@see shippedNudgeBudgets()} reads the
     * divisor out of the `intdiv($this-><cap>, <n>)` in each tool's own source,
     * so a sixth capped tool joining the seam is covered without being named,
     * and a tool that stops spending a share drops out.
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
     */
    public function testEveryShippedNudgeBudgetClearsTheTrackerCeiling(): void
    {
        $shipped = $this->shippedNudgeBudgets();

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

        foreach ($this->shippedNudgeBudgets() as $tool => $spend) {
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
     * What each Bootstrap-built tool will really spend on a nudge, keyed by
     * class short name.
     *
     * The cap comes off the CONSTRUCTED INSTANCE — a Bootstrap that started
     * passing an explicit cap and a tool whose default constant dropped are the
     * same regression from here — and the divisor comes out of the tool's own
     * source, so neither is restated. A tool whose cap is <= 0 passes `null`
     * for the budget, which means "no caller cap, the class ceiling stands", and
     * is modelled as an unbounded budget rather than as zero.
     *
     * @return array<string, array{cap: int, divisor: int, budget: int}>
     */
    private function shippedNudgeBudgets(): array
    {
        $spend = [];

        foreach (Bootstrap::tools($this->tempDir . '/repo') as $tool) {
            $class = new \ReflectionClass($tool);
            if (!$class->hasProperty('skillNudge') || $this->nudgeOf($tool) === null) {
                continue;
            }

            $file = $class->getFileName();
            if ($file === false) {
                continue;
            }

            // The budget argument of the tool's own forPath()/forPaths() call.
            // `[^;]*?` keeps the match inside one statement, so a later,
            // unrelated intdiv() cannot be read as this call's divisor.
            $matched = preg_match(
                '/skillNudge\?->forPaths?\([^;]*?intdiv\(\$this->(\w+),\s*(\d+)\)/s',
                (string) file_get_contents($file),
                $captured,
            );
            if ($matched !== 1) {
                continue;
            }

            $capProperty = new \ReflectionProperty($tool, $captured[1]);
            $capProperty->setAccessible(true);
            $cap = $capProperty->getValue($tool);
            self::assertIsInt($cap, $class->getShortName() . '::$' . $captured[1] . ' is not an int cap');

            $divisor = (int) $captured[2];
            self::assertGreaterThan(0, $divisor);

            $spend[$class->getShortName()] = [
                'cap' => $cap,
                'divisor' => $divisor,
                'budget' => $cap > 0 ? intdiv($cap, $divisor) : PHP_INT_MAX,
            ];
        }

        ksort($spend);

        return $spend;
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
