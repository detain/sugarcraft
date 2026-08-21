<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\InstructionFileLoader;
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
 * getting that wrong were live at once — measured by running the tools, over
 * the fixture below, with a 7,211-byte `sub/CLAUDE.md` and five matched files:
 *
 *   Grep, cap 400  ->  7,737 bytes returned (19.3x the cap), hits intact:
 *                      the bodies were appended AFTER the clip, so they were
 *                      never inside the budget at all.
 *   Glob, cap 200  ->    195 bytes returned, ZERO of the five matched paths:
 *                      the bodies were prepended BEFORE the clip, so the
 *                      budget was spent on rules and the answer was what the
 *                      truncation marker reported as dropped.
 *   Read, cap 200  ->  7,428 bytes returned (37.1x the cap): same exemption
 *                      as Grep, on a tool whose cap is a per-file read bound.
 *
 * The fixtures here are deliberately ADVERSARIAL in both directions at once —
 * instruction text far larger than the cap AND a result far larger than the
 * cap — because a fixture shaped like the bug hides the bug: an instruction
 * file that happens to fit the budget passes against the broken code.
 *
 * Two properties are asserted, and they are different properties that were
 * broken in different tools: the returned bytes stay inside the cap, and at
 * least one real result survives.
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

    /** The other side of the same split: the rules cannot overrun their quarter. */
    public function testTheInstructionSectionCannotOverrunItsQuarter(): void
    {
        $this->seedOversizeInstructions();
        $this->seedMatches(60);

        foreach ([$this->grep(4096), $this->glob(4096)] as $content) {
            $section = substr($content, strpos($content, '... [instructions:') ?: 0);
            self::assertLessThanOrEqual(1024, strlen($section), 'a quarter of 4096');
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

        self::assertStringContainsString('BIG-RULE', $this->grep(400));
        self::assertStringContainsString('BIG-RULE', $this->glob(400));
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
    // Read, Edit and Write carried the same exemption
    // =========================================================================

    /**
     * Read's cap is a per-file READ bound — "how much of the file you asked
     * for do you get" — so the file's own share is deliberately NOT reduced to
     * pay for the rules. The total is therefore bounded at the cap PLUS the
     * instruction reserve, which is a stated 1.25x where it used to be an
     * unbounded multiple (37.1x measured).
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
    // Helpers
    // =========================================================================

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
