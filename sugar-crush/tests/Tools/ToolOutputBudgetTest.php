<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillPathNudge;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Glob;
use SugarCraft\Crush\Tools\BuiltIn\Grep;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\BuiltIn\Write;

/**
 * The output budget has to be spent on the thing that was ASKED FOR.
 *
 * Five tools compose a result out of two things: the answer, and the body of
 * the `CLAUDE.md`/`AGENTS.md` governing the paths the answer names. At
 * 4a4ecb98 the two were never budgeted against each other, and BOTH ways of
 * getting that wrong were live at once.
 *
 * EVERY FIGURE BELOW IS MEASURED ON THE FIXTURE THIS FILE BUILDS — a 9,611-byte
 * `sub/CLAUDE.md`, five matched `sub/needle-file-*.php`, and a 39-byte
 * `sub/target.php` — by running the tools at 4a4ecb98 and again at HEAD. The
 * first version of this docblock quoted a 7,211-byte `CLAUDE.md` and a
 * 637-byte target, and reconciled with NEITHER: the two columns had been
 * measured on two different fixtures and printed as one row.
 *
 *   tool  cap    at 4a4ecb98                 now
 *   Grep  400    10,096 B (25.2x), 2 hits    348 B (0.9x), 2 hits
 *   Glob  200       195 B, 0 of 5 paths      200 B, 0 of 5 paths
 *   Glob  400       387 B, 0 of 5 paths      321 B, 5 of 5 paths
 *   Read  200     9,651 B (48.3x)            141 B (0.7x)
 *   Read  400     9,651 B (24.1x)            141 B (0.4x)
 *
 * Grep appended the bodies AFTER its clip, so they were never inside the
 * budget at all; Glob prepended them BEFORE it, so the budget was spent on
 * rules and the answer was what the truncation marker reported as dropped;
 * Read's cap is a per-file read bound the body sat entirely outside.
 *
 * THE FIXTURES ARE ADVERSARIAL IN THREE DIRECTIONS, NOT TWO. Instruction text
 * an order of magnitude over the cap, a result over the cap — and MANY
 * GOVERNED DIRECTORIES, which is the shape the first cut of this fix missed
 * entirely. One instruction file in one directory cannot show a per-file cost
 * that is paid per file: with the byte share guarded but the count unbounded,
 * this same code returned 129,517 bytes against its 65,536-byte default at 800
 * governed directories and 144,245 at 1,500, and before the share was guarded
 * at all it returned 1,091,833 at 500. Many files per directory does NOT
 * reproduce it; many directories with few files each does.
 *
 * Three properties are asserted, and they were broken in three different
 * places: the returned bytes stay inside the cap, at least one real result
 * survives, and no instruction file is spent from the announce-once ledger
 * without being shown.
 */
final class ToolOutputBudgetTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush_budget_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/sub', 0o777, true);
    }

    protected function tearDown(): void
    {
        self::rmrf($this->dir);
    }

    // =========================================================================
    // The cap bounds what the tool returns
    // =========================================================================

    /**
     * Both halves at once: a `CLAUDE.md` an order of magnitude larger than the
     * cap, over a hit list also larger than the cap.
     */
    public function testGrepStaysInsideItsCapWithAnOversizeInstructionFile(): void
    {
        $this->seedOversizeInstructions();
        $this->seedMatches(60);

        $content = $this->grep(4096);

        self::assertLessThanOrEqual(4096, strlen($content), 'the cap must bound what the tool returns');
        self::assertGreaterThanOrEqual(1, $this->hitCount($content), 'at least one real hit must survive');
    }

    public function testGlobStaysInsideItsCapWithAnOversizeInstructionFile(): void
    {
        $this->seedOversizeInstructions();
        $this->seedMatches(120);

        $content = $this->glob(4096);

        self::assertLessThanOrEqual(4096, strlen($content), 'the cap must bound what the tool returns');
        self::assertGreaterThanOrEqual(1, $this->pathCount($content), 'at least one matched path must survive');
    }

    /**
     * The cap is a real bound at every size, not only at the comfortable one.
     *
     * The floor below which the fixed cost of the two markers no longer fits
     * inside a quarter of the cap is stated in
     * {@see \SugarCraft\Crush\Tools\Concerns\TruncatesOutput::instructionBudget()};
     * these caps are all above it.
     */
    public function testTheCapHoldsAcrossAWideRangeOfSizes(): void
    {
        $this->seedOversizeInstructions();
        $this->seedMatches(60);

        foreach ([1024, 2048, 4096, 16384] as $cap) {
            self::assertLessThanOrEqual($cap, strlen($this->grep($cap)), "Grep at cap $cap");
            self::assertLessThanOrEqual($cap, strlen($this->glob($cap)), "Glob at cap $cap");
        }
    }

    // =========================================================================
    // The answer keeps its floor
    // =========================================================================

    /**
     * The guaranteed floor is what actually fixes the reported bug. The design
     * reserves a QUARTER for the instruction section, so the answer keeps
     * three quarters; the assertion says HALF so the exact length of the
     * truncation marker is not baked into a test that is not about it.
     */
    public function testTheRulesCannotStarveTheAnswer(): void
    {
        $this->seedOversizeInstructions();
        $this->seedMatches(120);

        foreach ([$this->grep(4096), $this->glob(4096)] as $content) {
            self::assertGreaterThanOrEqual(
                2048,
                strlen(self::beforeInstructions($content)),
                'the answer must keep at least half the budget',
            );
        }
    }

    /**
     * The other side of the same split: the rules cannot overrun their quarter.
     *
     * SEEDED WITH MANY GOVERNED DIRECTORIES, and that is the whole difference
     * between this assertion and the one it replaces. That one used a single
     * `sub/CLAUDE.md`, whose section came out at 250 bytes against a 1,024-byte
     * bound — four times the headroom, so it could not have failed however
     * badly the section was bounded. Here the section runs within tens of bytes
     * of its quarter at every cap, so the bound is what is actually holding it:
     * MEASURED at HEAD, 16,358 bytes against 16,384 with 500 governing
     * directories and 16,363 with 1,500 of them.
     */
    public function testTheInstructionSectionCannotOverrunItsQuarter(): void
    {
        $this->seedGovernedDirs(200);

        foreach ([2048, 4096, 16384, 65536] as $cap) {
            foreach (['Grep' => $this->grepAll($cap), 'Glob' => $this->globAll($cap)] as $tool => $content) {
                self::assertLessThanOrEqual(
                    intdiv($cap, 4),
                    strlen($this->rulesSection($content)),
                    "$tool at cap $cap: the section must stay inside its quarter",
                );
            }
        }
    }

    // =========================================================================
    // What the model is told about a clipped rule
    // =========================================================================

    /**
     * A rule read halfway is a rule the model may believe it has followed, so
     * the clipped instruction text carries its own marker, distinct from the
     * one that annotates a clipped RESULT.
     */
    public function testAClippedRuleSaysItIsPartialInItsOwnWords(): void
    {
        $this->seedOversizeInstructions();
        $this->seedMatches(60);

        foreach ([$this->grep(4096), $this->glob(4096)] as $content) {
            self::assertStringContainsString('instructions truncated:', $content);
            self::assertStringContainsString('these project rules are PARTIAL', $content);
        }
    }

    /**
     * A marker naming no subject tells the model that rules exist and not
     * which. The heading survives even when nothing else does.
     */
    public function testTheHeadingOfAClippedRuleSurvives(): void
    {
        $this->seedOversizeInstructions();
        $this->seedMatches(5);

        self::assertStringContainsString('BIG-RULE', $this->grep(800));
        self::assertStringContainsString('BIG-RULE', $this->glob(800));
    }

    /**
     * Below the cap where a quarter can hold a rule at all, NOTHING is
     * surfaced — and nothing is spent, which is what makes that acceptable.
     *
     * This is the resolution of a genuine conflict rather than an oversight.
     * "The section spends at most a quarter" and "every governing file is
     * shown" cannot both hold once the quarter is smaller than one entry, and
     * the first cut of this change resolved it by quietly dropping the cap.
     * The cap wins instead: the paths are simply never examined, so the
     * announce-once ledger is untouched and the very next call with room in
     * its reserve surfaces the rule in full. Asserted here end-to-end, because
     * "nothing was lost" is the only thing that makes silence defensible.
     */
    public function testACapTooSmallForARuleSurfacesNothingAndSpendsNothing(): void
    {
        $this->seedOversizeInstructions();
        $this->seedMatches(5);

        $loader = new InstructionFileLoader($this->dir);
        $content = (new Grep($this->dir, 400, $loader))
            ->execute(['pattern' => 'NEEDLE_TOKEN', 'path' => $this->dir])
            ->content();

        self::assertLessThanOrEqual(400, strlen($content));
        self::assertStringNotContainsString('BIG-RULE', $content);
        self::assertSame([], $loader->emittedPaths(), 'a rule not shown must not be spent');

        // Still there for the next caller with room for it.
        $later = (new Read($this->dir, 8192, null, $loader))
            ->execute(['file_path' => $this->dir . '/sub/needle-file-000.php'])
            ->content();

        self::assertStringContainsString('BIG-RULE', $later);
    }

    // =========================================================================
    // Grep and Glob compose the same way
    // =========================================================================

    /**
     * The two tools answer the same shape of question and used to disagree
     * about where the rules go — one appended, one prepended — which is how
     * one blew through its cap while the other starved its own answer.
     */
    public function testGrepAndGlobPutTheAnswerFirstAndTheRulesLast(): void
    {
        $this->seedOversizeInstructions();
        $this->seedMatches(5);

        foreach ([$this->grep(8192), $this->glob(8192)] as $content) {
            $firstResult = strpos($content, 'needle-file-');
            $label = strpos($content, '... [instructions:');

            self::assertIsInt($firstResult, 'a result must be present');
            self::assertIsInt($label, 'the instruction section must be present');
            self::assertLessThan($label, $firstResult, 'the answer comes before the rules');
        }
    }

    // =========================================================================
    // The reservation costs nothing when it is not used
    // =========================================================================

    /**
     * Announce-once means a governing instruction file is emitted on the FIRST
     * call and never again, so almost every call in a session has no
     * instruction section at all. Reserving a quarter of the cap for those
     * calls would be a permanent 25% cut to every list the agent ever sees;
     * the reservation is therefore taken only when something claims it.
     */
    public function testACallWithNoInstructionsToShowGetsTheWholeCap(): void
    {
        $this->seedMatches(60);

        $withLoader = $this->grep(2048);
        $withoutLoader = (new Grep($this->dir, 2048))
            ->execute(['pattern' => 'NEEDLE_TOKEN', 'path' => $this->dir])
            ->content();

        self::assertSame($withoutLoader, $withLoader, 'an unused reserve must not shorten the answer');

        // And asserted in ABSOLUTE bytes as well as by comparison. The two
        // calls above run the same code, so pinning BOTH of them at the
        // three-quarter floor keeps them identical and passes — MEASURED at
        // HEAD, 2,011 bytes against a floor of 1,805. Only the absolute
        // assertion can tell "the reserve was not taken" from "it was taken
        // from both".
        self::assertGreaterThan(2048 - 242 - 1, strlen($withLoader), 'the whole cap, not the floor');
    }

    /**
     * The same for Glob, which additionally used to walk the instruction
     * loader once per matched file.
     */
    public function testGlobWithNothingToSurfaceMatchesTheUnwiredTool(): void
    {
        $this->seedMatches(60);

        $withoutLoader = (new Glob($this->dir, null, [], null, 2048))
            ->execute(['pattern' => 'sub/*.php', 'path' => $this->dir])
            ->content();

        self::assertSame($withoutLoader, $this->glob(2048));

        // Absolute, for the reason the Grep half is: the two calls run the
        // same code, so clipping BOTH at the three-quarter floor would keep
        // them identical and pass. MEASURED at HEAD, 2,001 bytes against a
        // floor of 1,805.
        self::assertGreaterThan(2048 - 242 - 1, strlen($this->glob(2048)), 'the whole cap, not the floor');
    }

    // =========================================================================
    // The announce-once mark is spent only on what the model receives
    // =========================================================================

    /**
     * {@see InstructionFileLoader::loadForPath()} marks a file emitted at LOAD
     * time, so loading against the full match list and then clipping retires
     * instruction files for the whole session that the model never saw. Glob
     * did exactly that: it loaded one per matched file before truncating.
     *
     * Asserted structurally rather than by count, because which directories
     * survive a clip depends on walk order: every instruction file that was
     * spent must govern a directory that is actually present in the result.
     */
    public function testGlobSpendsAnInstructionFileOnlyOnADirectoryTheModelSees(): void
    {
        for ($d = 0; $d < 6; $d++) {
            mkdir($this->dir . "/d$d");
            file_put_contents($this->dir . "/d$d/CLAUDE.md", "RULE-D$d\n" . str_repeat("padding line\n", 60));
            for ($i = 0; $i < 20; $i++) {
                file_put_contents(sprintf('%s/d%d/needle-file-%02d.php', $this->dir, $d, $i), "<?php\n");
            }
        }

        $loader = new InstructionFileLoader($this->dir);
        $content = (new Glob($this->dir, $loader, [], null, 2048))
            ->execute(['pattern' => '**/*.php', 'path' => $this->dir])
            ->content();

        $shownDirs = [];
        foreach (explode("\n", $content) as $line) {
            if (str_starts_with($line, $this->dir) && str_ends_with($line, '.php')) {
                $shownDirs[\dirname($line)] = true;
            }
        }

        self::assertNotSame([], $shownDirs, 'the fixture must leave some paths visible');
        foreach ($loader->emittedPaths() as $emitted) {
            self::assertArrayHasKey(
                \dirname($emitted),
                $shownDirs,
                "instruction file $emitted was retired for the session without being shown",
            );
        }
    }

    // =========================================================================
    // The byte accounting the whole split rests on
    // =========================================================================

    /**
     * THE ENTRY FLOOR, PINNED AGAINST ITS OWN DERIVATION — the one number in
     * this design that output cannot check.
     *
     * {@see \SugarCraft\Crush\Tools\Concerns\TruncatesOutput::instructionSectionCeiling()}
     * is `max(quarter, one entry floor)` and the loop admits a body only while
     * a whole floor still fits, so this number decides both how small a cap
     * can get before the rules may outgrow their quarter and how many entries
     * a section holds. It is deliberately a WORST CASE that no real file
     * reaches: the marker inside it is sized at `PHP_INT_MAX` in both slots,
     * 120 bytes, where the marker for a 9,611-byte `CLAUDE.md` is 90. What the
     * floor prices is an entry clipped all the way down to its bounded head,
     * and on every fixture in this file that costs 212 bytes — head 120, the
     * newline after it, a 90-byte marker, the newline before the next entry —
     * against a 242-byte reservation.
     *
     * THAT SLACK IS EXACTLY WHY NO OUTPUT ASSERTION REACHES IT. Dropping the
     * two `+ 1` the derivation below spells out — the newline after the kept
     * head, and the newline `implode()` puts between entries — leaves 240,
     * and 240 still covers every entry a real file can produce, so both bounds
     * asserted in {@see testTheSectionAccountingHoldsOverAnAdversarialTree()}
     * stay green. MEASURED: that mutation changed the output at 175 of 29,025
     * (fixture, cap) pairs swept one cap at a time and violated NEITHER bound
     * at any of them. Sizing the marker at `(0, 0)` instead leaves 206, which
     * does overrun — 111 of the same 29,025 pairs returned more than their
     * cap. One of the two is a bug the bounds catch; the other is a bug only
     * this assertion catches.
     *
     * The expected value is rebuilt from a marker this run actually emitted,
     * not from the format string copied into the test, so a reworded marker
     * moves both sides together and only a changed ARITHMETIC fails here.
     */
    public function testTheEntryFloorIsTheWorstCaseCostOfOneClippedRule(): void
    {
        $this->seedOversizeInstructions();
        $this->seedMatches(5);

        $emitted = null;
        foreach (explode("\n", $this->glob(2048)) as $line) {
            if (str_starts_with($line, '... [instructions truncated:')) {
                $emitted = $line;
            }
        }

        self::assertNotNull($emitted, 'the fixture must actually clip a rule');
        self::assertSame(
            1,
            preg_match('/^\.\.\. \[instructions truncated: (\d+) of (\d+) /', $emitted, $counts),
            'the marker must name both byte counts',
        );

        // The same marker as it would render at its longest, which is what the
        // floor reserves for: same wording, both counts widened to PHP_INT_MAX.
        $widest = strlen($emitted)
            - strlen($counts[1])
            - strlen($counts[2])
            + 2 * strlen((string) PHP_INT_MAX);

        $head = (new \ReflectionClassConstant(Glob::class, 'INSTRUCTION_HEAD_BYTES'))->getValue();
        $floor = (new \ReflectionMethod(Glob::class, 'instructionBodyFloor'))->invoke(new Glob());

        self::assertSame(
            $head + 1 + $widest + 1,
            $floor,
            'the entry floor must cover a maximally clipped body: its head, the newline after it, '
            . 'its marker at the marker\'s longest, and the newline implode() puts before the next entry',
        );

        // And the slack the paragraph above claims, stated as a number rather
        // than asserted away: a real entry costs well under the reservation.
        self::assertLessThan(
            $floor,
            strlen($emitted) + $head + 2,
            'a real clipped entry must fit inside the floor with room to spare',
        );
    }

    /**
     * THE ACCOUNTING, NOT ONLY THE BOUNDS IT PRODUCES.
     *
     * Every byte the section emits is charged to `$spent` before the next body
     * is admitted, and three separate `+ 1`/`- 1` terms in that sum pay for
     * newlines nothing else charges for. A sum that is one byte per entry
     * short is invisible on a fixture with three entries and is 800 bytes over
     * the reserve on one with eight hundred — which is why this sweeps a tree
     * whose section holds HUNDREDS of entries as well as one whose single
     * entry is clipped down to its head.
     *
     * MEASURED, dropping the `+ 1` from `$spent += strlen($clipped) + 1`: over
     * the five-hundred-directory tree below the section runs 63, 100, 135 and
     * 171 bytes past its ceiling at the four caps asserted, and the RESULT
     * runs 31, 82, 94 and 145 bytes past its cap. The same mutation on a
     * three-entry fixture overruns by one to six bytes at 25 of 29,025
     * (fixture, cap) pairs and by nothing at the other 29,000 — the difference
     * between a test that catches it and one that could.
     *
     * The narrow sweep is the other direction: eight directories whose
     * `CLAUDE.md` opens with a 200-byte heading, so the clip lands inside the
     * first line and the head fallback — the one path that can return MORE
     * than the budget it was handed — is what the reserve has to have priced.
     * Reserving the label unconditionally, or emitting the withheld note
     * without checking that it fits, both overrun the cap in that band.
     */
    public function testTheSectionAccountingHoldsOverAnAdversarialTree(): void
    {
        $floor = (new \ReflectionMethod(Glob::class, 'instructionBodyFloor'))->invoke(new Glob());

        // MANY ENTRIES, none of them clipped: the per-entry newline is the
        // only thing being tested, so nothing else may move.
        $this->seedTinyGovernedDirs(500);

        foreach ([18000, 20000, 22000, 24000] as $cap) {
            $content = $this->globAll($cap);
            $section = $this->rulesSection($content);

            self::assertLessThanOrEqual($cap, strlen($content), "cap $cap: the result must stay inside its cap");
            self::assertLessThanOrEqual(
                max(intdiv($cap, 4), $floor),
                strlen($section),
                "cap $cap: the section must stay inside its ceiling",
            );

            // Every directory here governs exactly one matched path, so an
            // entry per shown path is the only way nothing was withheld — and
            // where something was, the result has to say so.
            $shown = preg_match_all('/^' . preg_quote($this->dir, '/') . '.*\.php$/m', $content);
            $entries = substr_count($section, '# GOVERN-D');
            if ($entries < $shown) {
                self::assertStringContainsString(
                    'further path(s) not examined',
                    $content,
                    "cap $cap: $entries rules for $shown shown paths, and the shortfall was not counted",
                );
            }
        }
    }

    /**
     * The same accounting at the other end of the scale: a cap small enough
     * that the reserve holds ONE entry and that entry is clipped inside its
     * first line, swept one byte at a time so no knife-edge is missed.
     *
     * The head fallback is the only clip in the instruction path that can
     * return more bytes than the budget it was handed — a 200-byte heading
     * clipped to a 9-byte window comes back as 120 bytes of head plus a
     * marker — so this band is where the entry floor is doing real work.
     */
    public function testTheReserveHoldsAtEveryCapAroundOneEntryFloor(): void
    {
        $floor = (new \ReflectionMethod(Glob::class, 'instructionBodyFloor'))->invoke(new Glob());

        $this->seedGovernedDirsWithLongHeadings(8, 200, 70);

        for ($cap = 350; $cap <= 1200; $cap++) {
            $content = $this->globAll($cap);

            self::assertLessThanOrEqual($cap, strlen($content), "cap $cap: the result must stay inside its cap");
            self::assertLessThanOrEqual(
                max(intdiv($cap, 4), $floor),
                strlen($this->rulesSection($content)),
                "cap $cap: the section must stay inside its ceiling",
            );
        }
    }

    // =========================================================================
    // Read, Edit and Write carried the same exemption
    // =========================================================================

    /**
     * Read's cap is a per-file READ bound — "how much of the file you asked
     * for do you get" — so the file's own share is deliberately NOT reduced to
     * pay for the rules. The total is therefore bounded at the cap PLUS the
     * instruction reserve, which is a stated 1.25x where it used to be an
     * unbounded multiple (48.3x measured).
     */
    public function testReadBoundsTheInstructionBodyItPrepends(): void
    {
        $this->seedOversizeInstructions();
        file_put_contents($this->dir . '/sub/target.php', "<?php\n// the file the caller asked for\n");

        $content = (new Read($this->dir, 2048, null, new InstructionFileLoader($this->dir)))
            ->execute(['file_path' => $this->dir . '/sub/target.php'])
            ->content();

        self::assertLessThanOrEqual(2048 + 512 + 1, strlen($content), 'cap plus its instruction quarter');
        self::assertStringContainsString('the file the caller asked for', $content, 'the file must survive');
        self::assertStringContainsString('instructions truncated:', $content);
    }

    /**
     * Edit and Write have no output cap to take a fraction of — their result
     * is one line — so the instruction body they prepend is bounded by the
     * standalone default instead. It was bounded by nothing.
     */
    public function testEditAndWriteBoundTheInstructionBodyTheyPrepend(): void
    {
        // Larger than the 16 KiB standalone default, so the bound has to bite.
        file_put_contents(
            $this->dir . '/sub/CLAUDE.md',
            "# BIG-RULE\n" . str_repeat("RULE: a long instruction line that keeps going.\n", 800),
        );
        file_put_contents($this->dir . '/sub/target.php', "<?php\n// original\n");

        $edit = (new Edit($this->dir, instructionLoader: new InstructionFileLoader($this->dir)))->execute([
            'file_path' => $this->dir . '/sub/target.php',
            'old_string' => '// original',
            'new_string' => '// replaced',
        ])->content();

        $write = (new Write($this->dir, instructionLoader: new InstructionFileLoader($this->dir)))->execute([
            'file_path' => $this->dir . '/sub/other.php',
            'content' => "<?php\n",
        ])->content();

        foreach (['Edit' => $edit, 'Write' => $write] as $tool => $content) {
            self::assertLessThanOrEqual(16384 + 256, strlen($content), "$tool must bound the instruction body");
            self::assertStringContainsString('instructions truncated:', $content, $tool);
        }
    }

    // =========================================================================
    // Many governed directories — the shape one directory cannot show
    // =========================================================================

    /**
     * THE SHIPPED DEFAULT, THROUGH THE CONSTRUCTOR A CALLER ACTUALLY USES.
     *
     * `new Glob($dir, $loader)` with no cap argument is what the tool registry
     * builds, and it is where the first cut of this fix failed worst: the set
     * loop handed `intdiv($remaining, $count - $i)` straight into a helper
     * whose documented "no cap" sentinel is a non-positive budget, so once the
     * reserve ran out every remaining body was emitted verbatim. MEASURED at
     * f1fda934, two files and one `CLAUDE.md` per directory:
     *
     *   200 dirs ->   199,767 B (3.0x)
     *   300 dirs ->   551,537 B (8.4x)
     *   500 dirs -> 1,091,833 B (16.7x)
     *
     * Nothing in the suite at that commit failed, because every fixture in it
     * had one instruction file in one directory.
     */
    public function testTheShippedDefaultCapHoldsOverManyGovernedDirectories(): void
    {
        $this->seedGovernedDirs(300);

        $glob = (new Glob($this->dir, new InstructionFileLoader($this->dir)))
            ->execute(['pattern' => '**/*.php', 'path' => $this->dir])
            ->content();
        $grep = (new Grep($this->dir, 65536, new InstructionFileLoader($this->dir)))
            ->execute(['pattern' => 'NEEDLE_TOKEN', 'path' => $this->dir])
            ->content();

        self::assertLessThanOrEqual(65536, strlen($glob), 'Glob at the shipped default');
        self::assertLessThanOrEqual(65536, strlen($grep), 'Grep at the shipped default');
        self::assertGreaterThanOrEqual(1, $this->pathCount($glob), 'a real result must survive');
    }

    /**
     * A BYTE BOUND IS NECESSARY AND IS NOT SUFFICIENT.
     *
     * Every entry costs a floor — its heading, its own marker, the newline
     * between entries — that no byte share is charged for, so a section with
     * one entry per governing file grows LINEARLY in the number of governing
     * files whatever the share arithmetic says. MEASURED with the share
     * guarded and the count unbounded, one file per directory: 800 directories
     * -> 129,517 B (2.0x the default cap), 1,500 -> 144,245 B (2.2x). The count
     * is therefore bounded too, and this asserts the consequence: the same cap
     * holds, and the same handful of entries appears, whether 200 directories
     * govern the answer or 800.
     */
    public function testTheEntryCountIsBoundedSoTheSectionCannotGrowWithTheTree(): void
    {
        $sizes = [];
        foreach ([200, 800] as $dirs) {
            self::rmrf($this->dir);
            mkdir($this->dir . '/sub', 0o777, true);
            $this->seedGovernedDirs($dirs);

            $content = $this->globAll(65536);
            $sizes[$dirs] = substr_count($content, '# BIG-RULE-D');

            self::assertLessThanOrEqual(65536, strlen($content), "cap at $dirs governed dirs");
            self::assertLessThanOrEqual(
                16384,
                strlen($this->rulesSection($content)),
                "quarter at $dirs governed dirs",
            );
        }

        self::assertSame($sizes[200], $sizes[800], 'the entry count must not track the tree size');
    }

    /**
     * The count bound is applied BEFORE the load, and that ordering is the
     * whole of it.
     *
     * {@see InstructionFileLoader::loadForPath()} marks a file emitted AT LOAD
     * TIME, so bounding the count by loading every body and dropping the ones
     * that do not fit would retire instruction files for the whole session
     * that the model never saw — the same defect the bound was added to fix,
     * wearing a bound. A path that is never EXAMINED is never marked.
     */
    public function testNoInstructionFileIsSpentWithoutBeingShown(): void
    {
        $this->seedGovernedDirs(300);

        $loader = new InstructionFileLoader($this->dir);
        $content = (new Glob($this->dir, $loader, [], null, 16384))
            ->execute(['pattern' => '**/*.php', 'path' => $this->dir])
            ->content();

        self::assertNotSame([], $loader->emittedPaths(), 'the fixture must spend something');

        foreach ($loader->emittedPaths() as $emitted) {
            self::assertStringContainsString(
                '# ' . trim(explode("\n", (string) file_get_contents($emitted))[0], '# '),
                $content,
                "instruction file $emitted was retired for the session without being shown",
            );
        }
    }

    /**
     * What was not looked at is COUNTED, not silently dropped.
     *
     * A section that shows five rules out of three hundred and says nothing
     * about the other two hundred and ninety-five reads as the complete set of
     * rules governing the answer, which is the same class of wrong-and-
     * confident that the result truncation marker exists to prevent.
     */
    public function testThePathsNotExaminedForRulesAreCounted(): void
    {
        $this->seedGovernedDirs(300);

        foreach (['Grep' => $this->grepAll(16384), 'Glob' => $this->globAll(16384)] as $tool => $content) {
            self::assertStringContainsString('further path(s) not examined', $content, $tool);
        }
    }

    /**
     * The room left is re-read before every entry rather than divided 1/n up
     * front, so a short rule leaves its unused room to the rules after it.
     *
     * MEASURED over the two-directory fixture below at cap 4096: the section
     * comes to 983 bytes of its 1,024-byte quarter. Handing each of the two
     * bodies a fixed half instead caps the long one at ~470 and the section at
     * ~577, which is the arithmetic this assertion rejects.
     */
    public function testAShortRuleLeavesItsUnusedRoomToTheNextOne(): void
    {
        foreach (['tiny' => "# TINY\n", 'huge' => "# HUGE\n" . str_repeat("RULE: a long instruction line.\n", 600)] as $name => $body) {
            mkdir($this->dir . '/' . $name);
            file_put_contents($this->dir . '/' . $name . '/CLAUDE.md', $body);
            file_put_contents($this->dir . '/' . $name . '/needle-file-00.php', "<?php\n// NEEDLE_TOKEN\n");
        }

        $section = $this->rulesSection($this->globAll(4096));

        self::assertGreaterThanOrEqual(800, strlen($section), 'the unused room must reach the next rule');
        self::assertLessThanOrEqual(1024, strlen($section), 'and must still stay inside the quarter');
    }

    /**
     * A window with NO newline in it is a FRAGMENT of the first line, and the
     * bounded head beats it.
     *
     * The result clip keeps a partial line on purpose — for a hit list a
     * fragment beats nothing. For a rule it does not: the fallback is there so
     * a withheld rule NAMES ITS SUBJECT, and 9 bytes of a heading names it no
     * better than none. Worse, it made a smaller budget produce a BETTER
     * answer, because only an empty window took the head path at all.
     *
     * MEASURED over the fixture below at a $maxBytes of 400: the room left
     * after the marker is 9 bytes, so the fragment is 9 'H's, where the head
     * carries 120 and reaches the marker 60 bytes in.
     */
    public function testAHeadingLongerThanTheRoomIsKeptToTheHeadNotToTheRoom(): void
    {
        file_put_contents(
            $this->dir . '/sub/CLAUDE.md',
            str_repeat('H', 60) . 'HEADING-TAIL' . str_repeat('H', 200) . "\n"
            . str_repeat("RULE line that goes on.\n", 400),
        );
        file_put_contents($this->dir . '/sub/target.php', "<?php\n");

        foreach ([400, 800] as $cap) {
            $content = (new Read($this->dir, $cap, null, new InstructionFileLoader($this->dir)))
                ->execute(['file_path' => $this->dir . '/sub/target.php'])
                ->content();

            self::assertStringContainsString('HEADING-TAIL', $content, "at maxBytes $cap");
            self::assertStringContainsString('instructions truncated:', $content, "at maxBytes $cap");
        }
    }

    // =========================================================================
    // The sentinel that a quarter can round down to
    // =========================================================================

    /**
     * A non-positive budget is this trait's "no cap" sentinel throughout, so a
     * cap small enough for `intdiv($cap, 4)` to round the reserve to ZERO used
     * to disable the very bound it was computing. MEASURED at f1fda934:
     * `Glob` at a cap of 1 returned 10,068 bytes — the whole rule book verbatim
     * — where caps 2 to 8 returned 183; `Read` at a $maxBytes of 1, 2 and 3
     * returned 9,629, 9,630 and 9,631 where 4 returned 122.
     */
    public function testACapWhoseQuarterRoundsToZeroStillBoundsTheRules(): void
    {
        $this->seedOversizeInstructions();
        $this->seedMatches(300);
        file_put_contents($this->dir . '/sub/target.php', "<?php\n// the file the caller asked for\n");

        // 300 matches, not five: at a cap whose floor rounds to the sentinel
        // the probe is UNBOUNDED, and a five-file fixture is small enough to
        // fit inside the cap anyway — it cannot tell a bound from its absence.
        // MEASURED with the floor guard removed: 16,724 bytes at every one of
        // these caps, against 187 to 241 with it.
        //
        // Bounded against the UNWIRED tool at the same cap rather than against
        // a round number, because a round number is where this hid: at a cap
        // of 100 the guarded Grep returns 187 bytes and the unguarded one 298,
        // and both are under any bound loose enough to be written by hand.
        // Wiring a loader may not make a result LONGER than the same tool
        // without one, beyond the cap itself — which is the property, stated
        // in the units the failure actually moves.
        foreach ([1, 2, 3, 4, 100, 200, 243] as $cap) {
            $bareGlob = (new Glob($this->dir, null, [], null, $cap))
                ->execute(['pattern' => 'sub/*.php', 'path' => $this->dir])
                ->content();
            $bareGrep = (new Grep($this->dir, $cap))
                ->execute(['pattern' => 'NEEDLE_TOKEN', 'path' => $this->dir])
                ->content();

            self::assertLessThanOrEqual(
                max($cap, strlen($bareGlob)),
                strlen($this->glob($cap)),
                "Glob at cap $cap must not fall through to the no-cap sentinel",
            );
            self::assertLessThanOrEqual(
                max($cap, strlen($bareGrep)),
                strlen($this->grep($cap)),
                "Grep at cap $cap must not fall through to the no-cap sentinel",
            );
        }

        // Read's quarter rounds to zero the same way, and its body was then
        // bounded by nothing: MEASURED at f1fda934, 9,629 / 9,630 / 9,631
        // bytes at $maxBytes 1, 2 and 3 where 4 returned 122.
        foreach ([1, 2, 3, 4] as $cap) {
            $read = (new Read($this->dir, $cap, null, new InstructionFileLoader($this->dir)))
                ->execute(['file_path' => $this->dir . '/sub/target.php'])
                ->content();

            self::assertLessThanOrEqual(1024, strlen($read), "Read at maxBytes $cap");
        }
    }

    // =========================================================================
    // What the bytes are, not just how many
    // =========================================================================

    /**
     * The heading clip is the one cut in this path with no line-boundary
     * fallback behind it, so a plain byte cut lands inside a UTF-8 sequence
     * and puts invalid bytes into a result the model reads. MEASURED through
     * `Read` before the fix, first line 118 'A' then U+2014: the output bytes
     * at offset 114 were `41 41 41 41 e2 80 0a`, a truncated three-byte
     * sequence, and `mb_check_encoding()` returned false at first-line offsets
     * 118 and 119.
     *
     * The result clip is swept too. Its newline trim RESCUES a mid-sequence
     * cut only when the kept window holds a newline, and the case it documents
     * as keeping a partial line is exactly the case where it does not: a
     * single-line file returned invalid UTF-8 at caps 439 and 440.
     */
    public function testAClippedRuleAndAClippedHitStayValidUtf8(): void
    {
        file_put_contents($this->dir . '/sub/target.php', "<?php\n");

        for ($run = 116; $run <= 121; $run++) {
            file_put_contents(
                $this->dir . '/sub/CLAUDE.md',
                str_repeat('A', $run) . "\u{2014}" . str_repeat('B', 60) . "\n"
                . str_repeat("RULE: a long instruction line that keeps going.\n", 200),
            );

            $content = (new Read($this->dir, 200, null, new InstructionFileLoader($this->dir)))
                ->execute(['file_path' => $this->dir . '/sub/target.php'])
                ->content();

            self::assertTrue(mb_check_encoding($content, 'UTF-8'), "heading cut at first-line offset $run");
        }

        file_put_contents(
            $this->dir . '/sub/min.js',
            'NEEDLE_TOKEN ' . str_repeat('x', 200) . "\u{2014}" . str_repeat('y', 4000) . "\n",
        );

        // The cut lands at a fixed offset from the START of the hit line, and
        // a hit line begins with the file's own path — so the caps that split
        // the sequence move with the length of the temp root and cannot be
        // written down as constants. MEASURED with the byte cut restored:
        // invalid UTF-8 at exactly `strlen($path) + 402` and `+ 403`.
        $offset = strlen($this->dir . '/sub/min.js');

        foreach (range($offset + 400, $offset + 405) as $cap) {
            $content = (new Grep($this->dir, $cap))
                ->execute(['pattern' => 'NEEDLE_TOKEN', 'path' => $this->dir])
                ->content();

            self::assertTrue(mb_check_encoding($content, 'UTF-8'), "hit cut at cap $cap");
        }
    }

    // =========================================================================
    // E66 — the skill nudge is spent INSIDE the cap, not beside it
    // =========================================================================

    /**
     * The nudge was appended after the clip and carried each matching skill's
     * whole frontmatter `description`, so the cap bounded the ANSWER and not
     * the RESULT.
     *
     * MEASURED at 8add627b over a 30-file fixture, twenty `paths:`-scoped
     * auto-invocable skills with 20,000-byte descriptions: Grep at cap 1,000
     * returned 401,372 bytes (401.4x) and Glob 401,378 (401.4x). Five skills
     * with 5,000-byte descriptions gave 26,182 and 26,188 (26.2x), and ONE
     * skill with a 200-byte description was already over at 1,334 and 1,340
     * (1.3x) — so this is not a pathological-input defect.
     */
    public function testGrepAndGlobStayInsideTheirCapWithAnOversizeSkillNudge(): void
    {
        $this->seedMatches(30);

        foreach ([[1, 200], [5, 5000], [20, 20000]] as [$count, $descLen]) {
            foreach ([1024, 4096, 16384, 65536] as $cap) {
                $grep = (new Grep($this->dir, $cap, null, $this->fatNudge($count, $descLen)))
                    ->execute(['pattern' => 'NEEDLE_TOKEN', 'path' => $this->dir])
                    ->content();
                self::assertLessThanOrEqual(
                    $cap,
                    strlen($grep),
                    "Grep cap $cap overrun by $count skills x $descLen-byte descriptions",
                );

                $glob = (new Glob($this->dir, null, [], $this->fatNudge($count, $descLen), $cap))
                    ->execute(['pattern' => 'sub/*.php', 'path' => $this->dir])
                    ->content();
                self::assertLessThanOrEqual(
                    $cap,
                    strlen($glob),
                    "Glob cap $cap overrun by $count skills x $descLen-byte descriptions",
                );
            }
        }
    }

    /**
     * The answer is not what the reservation sacrifices. A cap spent entirely
     * on a nudge would satisfy the bound above and be a worse tool than the
     * one that overran it.
     */
    public function testTheSkillNudgeCannotStarveTheAnswer(): void
    {
        $this->seedMatches(30);

        foreach ([1024, 4096, 16384, 65536] as $cap) {
            $grep = (new Grep($this->dir, $cap, null, $this->fatNudge(20, 20000)))
                ->execute(['pattern' => 'NEEDLE_TOKEN', 'path' => $this->dir])
                ->content();
            $glob = (new Glob($this->dir, null, [], $this->fatNudge(20, 20000), $cap))
                ->execute(['pattern' => 'sub/*.php', 'path' => $this->dir])
                ->content();

            self::assertGreaterThanOrEqual(1, $this->hitCount($grep), "no hit survived at cap $cap");
            self::assertGreaterThanOrEqual(1, $this->pathCount($glob), "no path survived at cap $cap");
        }

        // And at the SHIPPED default the nudge is still there, so the bound
        // above is not being satisfied by never emitting one. The eighth is a
        // real reserve at every cap that can hold an entry: MEASURED, the
        // chrome plus a full-length entry plus the deferred-note reserve costs
        // 515 bytes, so the nudge first appears at cap 4,120 for a
        // maximum-length entry and at 1,960 for a 30-byte one. Below that it
        // is deferred, not dropped — see
        // {@see testASkillTheReservationCannotHoldIsNotSpent()}.
        $default = (new Grep($this->dir, 65536, null, $this->fatNudge(20, 20000)))
            ->execute(['pattern' => 'NEEDLE_TOKEN', 'path' => $this->dir])
            ->content();
        self::assertStringContainsString('<system-reminder>', $default);
        self::assertGreaterThanOrEqual(1, $this->hitCount($default));
    }

    /**
     * Read's cap is a per-file READ bound, so — exactly as for the instruction
     * body prepended above it — the FILE's share is not reduced to pay for the
     * nudge. The nudge instead takes its own eighth, which makes the stated
     * total 1.375x $maxBytes where before it was an unbounded multiple:
     * MEASURED at 8add627b at $maxBytes 200, twenty skills with 20,000-byte
     * descriptions returned 400,406 bytes — 2,002.0x.
     */
    public function testReadBoundsTheSkillNudgeItAppends(): void
    {
        file_put_contents($this->dir . '/sub/target.php', "<?php\n// a short file\n");

        foreach ([200, 1024, 8192, 65536] as $maxBytes) {
            $content = (new Read($this->dir, $maxBytes, null, null, [], $this->fatNudge(20, 20000)))
                ->execute(['file_path' => $this->dir . '/sub/target.php'])
                ->content();

            self::assertLessThanOrEqual(
                (int) ($maxBytes * 1.375),
                strlen($content),
                "Read at maxBytes $maxBytes overran its stated 1.375x",
            );
        }
    }

    /**
     * A tool built with no nudge tracker, and a tool whose every scoped skill
     * has already been announced, must be BYTE-IDENTICAL to what they returned
     * before the reservation existed — the reservation is taken only where a
     * nudge is actually produced.
     */
    public function testACallWithNoNudgeToShowGetsTheWholeCap(): void
    {
        $this->seedMatches(30);
        $nudge = $this->fatNudge(3, 100);

        $withNudge = (new Grep($this->dir, 65536, null, $nudge))
            ->execute(['pattern' => 'NEEDLE_TOKEN', 'path' => $this->dir])
            ->content();
        self::assertStringContainsString('<system-reminder>', $withNudge, 'the first call must nudge');

        // Same tracker, second call: announce-once means there is nothing left
        // to say, so the cap is the hit list's alone.
        $spent = (new Grep($this->dir, 65536, null, $nudge))
            ->execute(['pattern' => 'NEEDLE_TOKEN', 'path' => $this->dir])
            ->content();
        $unwired = (new Grep($this->dir, 65536))
            ->execute(['pattern' => 'NEEDLE_TOKEN', 'path' => $this->dir])
            ->content();

        self::assertSame($unwired, $spent, 'a call with nothing to nudge must match the unwired tool');
        self::assertNotSame($unwired, $withNudge, 'and the first call must genuinely differ, or this proves nothing');
    }

    /**
     * A skill withheld because the reservation could not hold it is NOT spent:
     * the next call surfaces it. Spending a mark on a nudge the model never
     * received retires the skill for the session.
     */
    public function testASkillTheReservationCannotHoldIsNotSpent(): void
    {
        $this->seedMatches(30);
        $nudge = $this->fatNudge(1, 20000);

        // A cap whose eighth (128 bytes) cannot hold the chrome plus one entry.
        $tight = (new Grep($this->dir, 1024, null, $nudge))
            ->execute(['pattern' => 'NEEDLE_TOKEN', 'path' => $this->dir])
            ->content();
        self::assertStringNotContainsString('<system-reminder>', $tight, 'nothing should fit at this cap');

        $roomy = (new Grep($this->dir, 65536, null, $nudge))
            ->execute(['pattern' => 'NEEDLE_TOKEN', 'path' => $this->dir])
            ->content();
        self::assertStringContainsString('<system-reminder>', $roomy, 'the skill must not have been retired unseen');
    }

    /**
     * $count `paths:`-scoped auto-invocable skills, each with a $descLen-byte
     * description, all matching every `.php` file under the fixture root.
     */
    private function fatNudge(int $count, int $descLen): SkillPathNudge
    {
        $registry = new SkillRegistry();
        $skills = [];
        for ($i = 0; $i < $count; $i++) {
            $name = "fat-$i";
            $skills[$name] = Skill::parse(
                "---\ndescription: " . str_repeat('d', $descLen) . "\npaths:\n  - '*'\n---\nbody\n",
                $name,
            );
        }
        $registry->register($skills);

        return SkillPathNudge::new($registry);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * MANY DIRECTORIES, FEW FILES EACH — the shape that reproduces a
     * per-governing-file cost, and the one no fixture in this file had.
     *
     * Many files inside ONE directory does not reproduce it: announce-once
     * means the second file in a directory loads nothing, so a thousand
     * matches under one `CLAUDE.md` still cost exactly one entry.
     */
    private function seedGovernedDirs(int $dirs, int $filesPerDir = 2): void
    {
        for ($d = 0; $d < $dirs; $d++) {
            mkdir($this->dir . "/d$d");
            file_put_contents(
                $this->dir . "/d$d/CLAUDE.md",
                "# BIG-RULE-D$d\n" . str_repeat("RULE: a long instruction line that keeps going.\n", 70),
            );
            for ($i = 0; $i < $filesPerDir; $i++) {
                file_put_contents(sprintf('%s/d%d/needle-file-%02d.php', $this->dir, $d, $i), "<?php\n// NEEDLE_TOKEN\n");
            }
        }
    }

    /**
     * MANY GOVERNED DIRECTORIES, EACH WITH A RULE TOO SHORT TO CLIP — the
     * shape that makes the PER-ENTRY cost the only variable in the section's
     * byte sum. One matched file per directory, so an entry per shown path is
     * exactly what "nothing was withheld" looks like.
     */
    private function seedTinyGovernedDirs(int $dirs): void
    {
        for ($d = 0; $d < $dirs; $d++) {
            mkdir($this->dir . "/d$d");
            file_put_contents($this->dir . "/d$d/CLAUDE.md", "# GOVERN-D$d\n");
            file_put_contents($this->dir . "/d$d/needle-file-00.php", "<?php\n// NEEDLE_TOKEN\n");
        }
    }

    /**
     * A `CLAUDE.md` whose FIRST LINE is longer than any small cap's reserve,
     * so the clip lands inside it and the bounded-head fallback fires — the
     * one path in the instruction clip that can return more bytes than the
     * budget it was given, and therefore the one the entry floor exists to
     * price.
     */
    private function seedGovernedDirsWithLongHeadings(int $dirs, int $headBytes, int $bodyLines): void
    {
        for ($d = 0; $d < $dirs; $d++) {
            mkdir($this->dir . "/d$d");
            file_put_contents(
                $this->dir . "/d$d/CLAUDE.md",
                "# GOVERN-D$d" . str_repeat('H', $headBytes) . "\n"
                . str_repeat("RULE: a long instruction line that keeps going.\n", $bodyLines),
            );
            for ($i = 0; $i < 2; $i++) {
                file_put_contents(sprintf('%s/d%d/needle-file-%02d.php', $this->dir, $d, $i), "<?php\n// NEEDLE_TOKEN\n");
            }
        }
    }

    /** Grep over the whole fixture root, not only `sub/`. */
    private function grepAll(int $cap): string
    {
        return $this->grep($cap);
    }

    /** Glob over the whole fixture root, not only `sub/`. */
    private function globAll(int $cap): string
    {
        return (new Glob($this->dir, new InstructionFileLoader($this->dir), [], null, $cap))
            ->execute(['pattern' => '**/*.php', 'path' => $this->dir])
            ->content();
    }

    /**
     * Everything from the start of the instruction section — the rules plus
     * the label and the withheld note that frame them.
     *
     * FOUND BY ELIMINATING THE ANSWER rather than by locating the label, and
     * that is a repair. The label is emitted only where the reserve can hold
     * it: on `seedGovernedDirs()`'s fixture every cap up to 1,595 ships the
     * section UNLABELLED, and the previous form of this helper — `substr()`
     * from the first `... [instructions:` — then found the WITHHELD NOTE
     * instead, which sits at the END of the section rather than its start.
     * MEASURED at cap 1,024: it reported 73 bytes for a 226-byte section, so a
     * "the section stays inside its quarter" assertion built on it could not
     * have failed. Every cap its callers actually pass is above that band, so
     * no shipped assertion was wrong — it was a trap laid for the next
     * fixture, which is why it is repaired rather than documented.
     *
     * The ANSWER is the part that can be identified exactly, at any cap: every
     * line of it is either a path under the fixture root (a Glob match or a
     * Grep hit) or one of the `... [` notes that annotate the answer. The
     * section is whatever follows the last such line — true whether or not the
     * label survived, and true for a section whose first surviving byte is a
     * clipped body's own marker.
     */
    private function rulesSection(string $content): string
    {
        $offset = 0;
        foreach (explode("\n", $content) as $line) {
            $answerLine = $line === ''
                || str_starts_with($line, $this->dir)
                || (str_starts_with($line, '... [') && !str_starts_with($line, '... [instructions'));

            if (!$answerLine) {
                return substr($content, $offset);
            }

            $offset += strlen($line) + 1;
        }

        return '';
    }

    private function seedOversizeInstructions(): void
    {
        file_put_contents(
            $this->dir . '/sub/CLAUDE.md',
            "# BIG-RULE\n" . str_repeat("RULE: a long instruction line that keeps going.\n", 200),
        );
    }

    private function seedMatches(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            file_put_contents(
                sprintf('%s/sub/needle-file-%03d.php', $this->dir, $i),
                "<?php\n// NEEDLE_TOKEN in file $i\n",
            );
        }
    }

    private function grep(int $cap): string
    {
        return (new Grep($this->dir, $cap, new InstructionFileLoader($this->dir)))
            ->execute(['pattern' => 'NEEDLE_TOKEN', 'path' => $this->dir])
            ->content();
    }

    private function glob(int $cap): string
    {
        return (new Glob($this->dir, new InstructionFileLoader($this->dir), [], null, $cap))
            ->execute(['pattern' => 'sub/*.php', 'path' => $this->dir])
            ->content();
    }

    private function hitCount(string $content): int
    {
        return preg_match_all('/needle-file-\d+\.php:\d+:/', $content);
    }

    private function pathCount(string $content): int
    {
        return preg_match_all('/needle-file-\d+\.php$/m', $content);
    }

    /** Everything up to the instruction label, i.e. the answer plus its notes. */
    private static function beforeInstructions(string $content): string
    {
        $at = strpos($content, '... [instructions:');

        return $at === false ? $content : substr($content, 0, $at);
    }

    private static function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) && !is_link($full) ? self::rmrf($full) : @unlink($full);
        }
        @rmdir($path);
    }
}
