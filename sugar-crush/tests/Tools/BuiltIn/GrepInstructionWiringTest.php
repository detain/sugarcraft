<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillPathNudge;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\BuiltIn\Grep;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\CarriesSessionState;
use SugarCraft\Crush\Tools\ParallelSafe;

/**
 * P8.9: `Grep` was the one path-resolving built-in constructed without the
 * announce-once pair, so a `CLAUDE.md` governing a matched file stayed
 * unannounced when a search was what surfaced it, and a `paths:`-scoped skill
 * stayed silent on a search naming every file it covers.
 *
 * Every test here except {@see
 * testAGrepWithNoCollaboratorsIsByteIdenticalToBeforeTheWiring()} fails
 * against `new Grep($root)` as `Bootstrap::tools()` built it at 82b8ee3e —
 * that one is the regression guard for the unwired shape and passes both
 * before and after, deliberately.
 *
 * @see Grep::isParallelSafe() for the concurrency verdict this wiring forced a
 *      re-justification of
 */
final class GrepInstructionWiringTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-grep-wiring-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/sub', 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    // =========================================================================
    // The instruction loader
    // =========================================================================

    public function testAHitSurfacesTheInstructionFileGoverningItsDirectory(): void
    {
        file_put_contents($this->dir . '/sub/CLAUDE.md', "SUBDIR-RULE-ALPHA\n");
        file_put_contents($this->dir . '/sub/a.php', "<?php // needle\n");

        $content = $this->grep(new Grep($this->dir, instructionLoader: new InstructionFileLoader($this->dir)));

        self::assertStringContainsString('/sub/a.php:1:', $content, 'the hit itself must still be there');
        self::assertStringContainsString('SUBDIR-RULE-ALPHA', $content);
    }

    /**
     * The block is labelled where {@see Read} and
     * {@see \SugarCraft\Crush\Tools\BuiltIn\Glob} emit the body raw: it lands
     * at the end of a `path:line:text` record stream, after a run of
     * `... [note]` lines, and an unlabelled markdown body in that position is
     * indistinguishable from output that failed to parse.
     */
    public function testTheSurfacedInstructionBlockIsLabelledAndCounted(): void
    {
        file_put_contents($this->dir . '/sub/CLAUDE.md', "SUBDIR-RULE-ALPHA\n");
        file_put_contents($this->dir . '/sub/a.php', "<?php // needle\n");

        $content = $this->grep(new Grep($this->dir, instructionLoader: new InstructionFileLoader($this->dir)));

        self::assertStringContainsString('... [instructions: 1 file(s) govern the matched paths', $content);
        self::assertTrue(
            strpos($content, '... [instructions:') < strpos($content, 'SUBDIR-RULE-ALPHA'),
            'the label must precede the body it labels',
        );
    }

    public function testAGrepWithNoCollaboratorsIsByteIdenticalToBeforeTheWiring(): void
    {
        file_put_contents($this->dir . '/sub/CLAUDE.md', "SUBDIR-RULE-ALPHA\n");
        file_put_contents($this->dir . '/sub/a.php', "<?php // needle\n");

        $content = $this->grep(new Grep($this->dir));

        self::assertStringContainsString('/sub/a.php:1:', $content);
        self::assertStringNotContainsString('SUBDIR-RULE-ALPHA', $content);
        self::assertStringNotContainsString('... [instructions:', $content);
    }

    public function testTheSameInstructionFileIsSurfacedOnlyOncePerSession(): void
    {
        file_put_contents($this->dir . '/sub/CLAUDE.md', "SUBDIR-RULE-ALPHA\n");
        file_put_contents($this->dir . '/sub/a.php', "<?php // needle\n");

        $tool = new Grep($this->dir, instructionLoader: new InstructionFileLoader($this->dir));

        self::assertStringContainsString('SUBDIR-RULE-ALPHA', $this->grep($tool));
        self::assertStringNotContainsString('SUBDIR-RULE-ALPHA', $this->grep($tool));
    }

    /**
     * The dedup is per LOADER, and `Bootstrap::tools()` hands every wired tool
     * the same one — so a rule Grep surfaced is not re-sent when the model goes
     * on to read the file. This is the property that makes wiring Grep a net
     * addition rather than a duplicate: the body reaches the model once, from
     * whichever tool touched the path first.
     */
    public function testARuleGrepSurfacedIsNotRepeatedByALaterReadOnTheSharedLoader(): void
    {
        file_put_contents($this->dir . '/sub/CLAUDE.md', "SUBDIR-RULE-ALPHA\n");
        file_put_contents($this->dir . '/sub/a.php', "<?php // needle\n");

        $loader = new InstructionFileLoader($this->dir);

        self::assertStringContainsString('SUBDIR-RULE-ALPHA', $this->grep(new Grep($this->dir, instructionLoader: $loader)));

        $read = (new Read($this->dir, instructionLoader: $loader))
            ->execute(['id' => 'r', 'file_path' => $this->dir . '/sub/a.php'])
            ->content();

        self::assertStringNotContainsString('SUBDIR-RULE-ALPHA', $read);
    }

    // =========================================================================
    // Placement relative to the byte cap
    // =========================================================================

    /**
     * THE DOCBLOCK THIS REPLACES WAS TRUE WHEN WRITTEN AND IS NOT NOW. It said
     * Grep "appends after the clip instead", contrasted with a `Glob` that
     * "prepends instruction bodies BEFORE its clip", and cited a 200-byte cap
     * over a 500-line `sub/CLAUDE.md`. Both tools have since been given a
     * split budget — the instruction section takes at most a quarter, the
     * answer keeps the rest — so neither the contrast nor the mechanism it
     * named survives, even though this test's assertions are unchanged and
     * still pass.
     *
     * What it pins now is the outcome rather than the mechanism: an
     * instruction file an order of magnitude larger than the cap does not cost
     * the hit list its answer. `ToolOutputBudgetTest` measures the budget
     * itself, in both tools, at several caps.
     */
    public function testAnOversizeInstructionFileDoesNotDisplaceTheHits(): void
    {
        file_put_contents($this->dir . '/sub/CLAUDE.md', "BIG-RULE\n" . str_repeat("padding line\n", 500));
        file_put_contents($this->dir . '/sub/a.php', "<?php // needle\n");

        $content = $this->grep(new Grep($this->dir, 400, new InstructionFileLoader($this->dir)));

        self::assertStringContainsString('/sub/a.php:1:', $content, 'the hit must survive an oversize CLAUDE.md');
        self::assertStringContainsString('BIG-RULE', $content);
    }

    /**
     * The other half of the same choice, and the one that needed a real
     * measurement rather than a plausible fixture.
     *
     * The announce-once mark is spent against the CLIPPED hit list, not
     * against grep's raw stdout. Reading the raw list instead looks harmless —
     * the capture is bounded by the same `maxOutputBytes` — but the two bounds
     * are NOT the same point: the capture stops at exactly the cap, while
     * {@see \SugarCraft\Crush\Tools\Concerns\TruncatesOutput} additionally
     * reserves room for the truncation marker and drops the partial line the
     * cut landed in. Whole hits live in that gap.
     *
     * MEASURED over this exact fixture with the read point moved to
     * `$run['stdout']`:
     *
     *   cap= 300  visible=[]            announced=[bbb]
     *   cap= 500  visible=[bbb]         announced=[aaa,bbb]
     *   cap= 900  visible=[aaa,bbb,fff] announced=[aaa,bbb,ccc,ddd,fff]
     *
     * Every extra name there is an instruction file retired without ever being
     * shown to anyone — it would never surface again for the rest of the
     * session.
     *
     * The names are illustrative and the assertions below do NOT depend on
     * them: `grep -rn` walks in readdir order, and the numbers additionally
     * move with the length of the temp root, since that prefix is repeated on
     * every hit line.
     *
     * THIS ASSERTED SET EQUALITY AND NOW ASSERTS CONTAINMENT, WHICH IS A
     * WEAKER RELATION AND THE ONLY ONE THAT WAS EVER A LAW. Equality held
     * because the section happened to be large enough to pull the final clip
     * down onto the probe; it was an artifact of one fixture's arithmetic, not
     * a property of the code. The section is now bounded in COUNT as well as
     * in bytes, so a call can legitimately show three paths and announce one —
     * MEASURED here at cap 1024: visible [aaa,bbb,fff], announced [bbb], with
     * `2 further path(s) not examined` said out loud. What must never happen
     * is the reverse, and that is what containment forbids: the raw-stdout
     * variant this test was written against announces rules for files the
     * model cannot see, which puts a name in `announced` that is not in
     * `visible` and fails below. The two assertions after it close the gap
     * equality used to cover — the shortfall is COUNTED in the result, and
     * every unannounced rule is still unspent, which is the thing that
     * actually matters about a file that was not shown.
     */
    public function testTheAnnouncedRulesAreExactlyTheOnesWhoseHitsSurvivedTheClip(): void
    {
        $dirs = ['aaa', 'bbb', 'ccc', 'ddd', 'eee', 'fff'];
        foreach ($dirs as $d) {
            mkdir($this->dir . '/' . $d, 0o777, true);
            file_put_contents($this->dir . '/' . $d . '/CLAUDE.md', 'RULE-' . strtoupper($d) . "\n");
            file_put_contents($this->dir . '/' . $d . '/' . str_repeat($d[0], 120) . '.php', "<?php // needle\n");
        }

        $loader = new InstructionFileLoader($this->dir);
        $content = $this->grep(new Grep($this->dir, 1024, $loader));

        $visible = [];
        $announced = [];
        foreach ($dirs as $d) {
            if (str_contains($content, '/' . $d . '/' . str_repeat($d[0], 120) . '.php:')) {
                $visible[] = $d;
            }
            if (str_contains($content, 'RULE-' . strtoupper($d))) {
                $announced[] = $d;
            }
        }

        // `grep -rn` walks in readdir order, so WHICH directories survive the
        // clip is not asserted — only that the clip bit and that something got
        // through, which is what makes the equality below meaningful.
        self::assertNotSame([], $visible, 'fixture must leave at least one hit visible');
        self::assertNotSame($dirs, $visible, 'fixture must leave at least one hit clipped away');

        self::assertNotSame([], $announced, 'the fixture must actually announce something');
        self::assertSame(
            [],
            array_values(array_diff($announced, $visible)),
            'a rule may be announced only for a file whose hits the model can actually see',
        );

        // The shortfall equality used to cover is not silent: what was not
        // looked at is counted in the result the model reads.
        if ($announced !== $visible) {
            self::assertStringContainsString('further path(s) not examined', $content);
        }

        // And every rule not announced — whether its file was shown or not —
        // is still unspent for whoever touches it next.
        foreach (array_diff($dirs, $announced) as $d) {
            $read = (new Read($this->dir, instructionLoader: $loader))
                ->execute(['id' => 'r', 'file_path' => $this->dir . '/' . $d . '/' . str_repeat($d[0], 120) . '.php'])
                ->content();

            self::assertStringContainsString('RULE-' . strtoupper($d), $read, "{$d}'s rule was retired unseen");
        }
    }

    // =========================================================================
    // The skill nudge
    // =========================================================================

    public function testAHitAnnouncesAPathScopedSkillOnce(): void
    {
        file_put_contents($this->dir . '/sub/a.php', "<?php // needle\n");
        $tool = new Grep($this->dir, skillNudge: $this->nudge());

        $first = $this->grep($tool);
        self::assertSame(1, substr_count($first, '<system-reminder>'));
        self::assertStringContainsString('php-audit', $first);

        self::assertStringNotContainsString('<system-reminder>', $this->grep($tool));
    }

    public function testASharedTrackerAnnouncesASkillOnlyOnceAcrossReadAndGrep(): void
    {
        file_put_contents($this->dir . '/sub/a.php', "<?php // needle\n");
        $nudge = $this->nudge();

        $read = (new Read($this->dir, skillNudge: $nudge))
            ->execute(['id' => 'r', 'file_path' => $this->dir . '/sub/a.php'])
            ->content();

        self::assertStringContainsString('<system-reminder>', $read);
        self::assertStringNotContainsString('<system-reminder>', $this->grep(new Grep($this->dir, skillNudge: $nudge)));
        self::assertSame(['php-audit'], $nudge->announced());
    }

    public function testASearchThatMatchesNothingAnnouncesNothing(): void
    {
        file_put_contents($this->dir . '/sub/CLAUDE.md', "SUBDIR-RULE-ALPHA\n");
        file_put_contents($this->dir . '/sub/a.php', "<?php\n");

        $loader = new InstructionFileLoader($this->dir);
        $content = $this->grep(new Grep($this->dir, instructionLoader: $loader, skillNudge: $this->nudge()));

        self::assertStringNotContainsString('SUBDIR-RULE-ALPHA', $content);
        self::assertStringNotContainsString('<system-reminder>', $content);
        self::assertSame([], $loader->emittedPaths(), 'nothing was touched, so nothing may be marked emitted');
    }

    // =========================================================================
    // What the concurrency verdict now rests on
    // =========================================================================

    /**
     * `isParallelSafe()` used to be justified by "this tool holds no
     * session-scoped state for a fork to strand". It now holds exactly that
     * state, and {@see ParallelSafe} point 2 permits it ONLY because the state
     * crosses the fork via {@see CarriesSessionState}. The verdict and the seam
     * are therefore pinned TOGETHER: `true` on its own is not the claim.
     */
    public function testTheParallelSafeVerdictIsPairedWithTheSessionStateSeam(): void
    {
        $tool = new Grep($this->dir);

        self::assertInstanceOf(ParallelSafe::class, $tool);
        self::assertTrue($tool->isParallelSafe());
        self::assertInstanceOf(
            CarriesSessionState::class,
            $tool,
            'a parallel-safe Grep that carries the announce-once collaborators MUST export them',
        );
    }

    /**
     * What a forked child hands back. Without the merge, a `CLAUDE.md` a forked
     * Grep surfaced re-surfaces on the next call for the rest of the session —
     * the exact failure {@see CarriesSessionState} exists to prevent, and one
     * that is invisible to a same-process test.
     */
    public function testTheExportedMarksMergeBackAcrossAForkBoundary(): void
    {
        file_put_contents($this->dir . '/sub/CLAUDE.md', "SUBDIR-RULE-ALPHA\n");
        file_put_contents($this->dir . '/sub/a.php', "<?php // needle\n");

        // The child's copy-on-write collaborators, as a fork would leave them.
        $childLoader = new InstructionFileLoader($this->dir);
        $childNudge = $this->nudge();
        self::assertStringContainsString(
            'SUBDIR-RULE-ALPHA',
            $this->grep(new Grep($this->dir, instructionLoader: $childLoader, skillNudge: $childNudge)),
        );

        $exported = (new Grep($this->dir, instructionLoader: $childLoader, skillNudge: $childNudge))
            ->exportSessionState();

        self::assertNotSame([], $exported['emittedInstructionPaths']);
        self::assertSame(['php-audit'], $exported['announcedSkills']);

        // The parent, which never ran the search itself.
        $parentLoader = new InstructionFileLoader($this->dir);
        $parentNudge = $this->nudge();
        $parent = new Grep($this->dir, instructionLoader: $parentLoader, skillNudge: $parentNudge);

        // Serialised exactly as Runtime carries it: scalars only, no objects.
        $parent->mergeSessionState(unserialize(serialize($exported), ['allowed_classes' => false]));

        $after = $this->grep($parent);
        self::assertStringNotContainsString('SUBDIR-RULE-ALPHA', $after, 'the emitted mark must have crossed the fork');
        self::assertStringNotContainsString('<system-reminder>', $after, 'the announced mark must have crossed too');
    }

    public function testMergingIsAUnionAndAnUnknownKeyIsIgnored(): void
    {
        $loader = new InstructionFileLoader($this->dir);
        $tool = new Grep($this->dir, instructionLoader: $loader, skillNudge: $this->nudge());

        $tool->mergeSessionState(['emittedInstructionPaths' => ['/a'], 'somethingFromAnOlderBuild' => 1]);
        $tool->mergeSessionState(['emittedInstructionPaths' => ['/b'], 'announcedSkills' => 'not-an-array']);
        $tool->mergeSessionState(['emittedInstructionPaths' => ['/a']]);

        self::assertSame(['/a', '/b'], $loader->emittedPaths());
    }

    // =========================================================================

    private function grep(Grep $tool): string
    {
        return $tool->execute([
            'id' => 'c1',
            'pattern' => 'needle',
            'path' => $this->dir,
            'description' => 'find the needle',
        ])->content();
    }

    private function nudge(): SkillPathNudge
    {
        $registry = new SkillRegistry();
        $registry->register([
            'php-audit' => Skill::parse(
                <<<SKILL
                ---
                description: Security audit for PHP code
                paths:
                  - "*.php"
                ---
                body
                SKILL,
                'php-audit'
            ),
        ]);

        return SkillPathNudge::new($registry);
    }
}
