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
     * reserves room for the truncation marker, reserves a quarter of the cap
     * for the rules, and drops the partial line the cut landed in. Whole hits
     * live in that gap, and their rules would be retired for the session
     * without ever being shown to anyone.
     *
     * THE FIXTURE IS SIXTY DIRECTORIES BECAUSE SIX MADE THIS TEST PASS UNDER
     * ITS OWN REGRESSION.
     *
     * At six directories and the cap this test used to ship with, the
     * raw-stdout variant is BYTE-IDENTICAL to the correct one, and the reason
     * is structural rather than a near miss. The reserve at that cap holds
     * exactly ONE entry; the first hit is the same line in the probe and in
     * the raw capture; so a variant that reads a strictly LARGER set still
     * announces the identical rule. MEASURED on the six-directory fixture this
     * replaces, at this file's own 35-byte temp root, sweeping the cap one
     * byte at a time: the two are BYTE-IDENTICAL from 900 to 1,375, and at the
     * shipped 1,024 both return 898 bytes with `visible=[aaa,bbb,fff]
     * announced=[bbb]`. The window where the containment assertion below fails
     * at all is caps 1,416 to 1,462 — FORTY-SEVEN caps, and the test shipped
     * pointed 392 below the bottom of it. A guard whose sensitivity depends on
     * a 2% band of one parameter is the defect it exists to catch, in a new
     * place.
     *
     * A difference is observable only where the reserve holds MORE entries
     * than the probe holds hits, and that regime is bounded on both sides by
     * the fixture: below it the reserve holds one entry, above it the probe
     * holds every hit and there is no unseen path left to announce. Sixty
     * directories moves the upper bound out by an order of magnitude, because
     * it is `directories × line length ÷ ¾` and nothing else. RE-MEASURED on
     * this fixture with the read point moved to `$filtered['run']['stdout']`,
     * sweeping every cap from 1 to 19,218: the mutation is caught at 13,445 of
     * them, including 12,859 CONSECUTIVE caps from 1,860 to 14,718. The
     * correct code violates containment at none of the 19,218.
     *
     * The four caps below sit inside that band with at least 640 bytes of
     * margin at the low end and 3,700 at the high end, so neither a shorter
     * `sys_get_temp_dir()` (which shortens every hit line) nor readdir order
     * can walk the fixture out of the window. At each of them the mutation
     * announces between 4 and 16 directories the model cannot see.
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
     * a property of the code. The section is bounded in COUNT as well as in
     * bytes, so a call can legitimately show more paths than it announces.
     * What must never happen is the reverse, and that is what containment
     * forbids. The two assertions after it close the gap equality used to
     * cover — the shortfall is COUNTED in the result, and every unannounced
     * rule is still unspent, which is the thing that actually matters about a
     * file that was not shown.
     *
     * @dataProvider capsInsideTheDetectionWindow
     */
    public function testTheAnnouncedRulesAreExactlyTheOnesWhoseHitsSurvivedTheClip(int $cap): void
    {
        $dirs = [];
        for ($i = 0; $i < 60; $i++) {
            $dirs[] = sprintf('d%03d', $i);
        }

        foreach ($dirs as $d) {
            mkdir($this->dir . '/' . $d, 0o777, true);
            file_put_contents($this->dir . '/' . $d . '/CLAUDE.md', 'RULE-' . strtoupper($d) . "\n");
            file_put_contents($this->dir . '/' . $d . '/' . self::hitFileName($d), "<?php // needle\n");
        }

        $loader = new InstructionFileLoader($this->dir);
        $content = $this->grep(new Grep($this->dir, $cap, $loader));

        $visible = [];
        $announced = [];
        foreach ($dirs as $d) {
            if (str_contains($content, '/' . $d . '/' . self::hitFileName($d) . ':')) {
                $visible[] = $d;
            }
            if (str_contains($content, 'RULE-' . strtoupper($d))) {
                $announced[] = $d;
            }
        }

        // `grep -rn` walks in readdir order, so WHICH directories survive the
        // clip is not asserted — only that the clip bit and that something got
        // through, which is what makes the containment below meaningful.
        self::assertNotSame([], $visible, 'fixture must leave at least one hit visible');
        self::assertNotSame($dirs, $visible, 'fixture must leave at least one hit clipped away');

        self::assertNotSame([], $announced, 'the fixture must actually announce something');
        self::assertSame(
            [],
            array_values(array_diff($announced, $visible)),
            "cap $cap: a rule may be announced only for a file whose hits the model can actually see",
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
                ->execute(['id' => 'r', 'file_path' => $this->dir . '/' . $d . '/' . self::hitFileName($d)])
                ->content();

            self::assertStringContainsString('RULE-' . strtoupper($d), $read, "{$d}'s rule was retired unseen");
        }
    }

    /**
     * Four caps spread across the 12,859-cap band in which the raw-stdout
     * regression is observable at all — see the test's own docblock for how
     * that band was measured and why it has the width it has.
     *
     * @return array<string, array{int}>
     */
    public static function capsInsideTheDetectionWindow(): array
    {
        return [
            'cap 2500' => [2500],
            'cap 5000' => [5000],
            'cap 8000' => [8000],
            'cap 11000' => [11000],
        ];
    }

    /**
     * A 120-byte name, so one hit line is long enough that a cap in the
     * thousands still clips the list — the fixture's whole job.
     */
    private static function hitFileName(string $dir): string
    {
        return str_repeat(substr($dir, 1), 40) . '.php';
    }

    // =========================================================================
    // The skill nudge
    // =========================================================================

    /**
     * THE NUDGE IS SCOPED TO EVERY HIT, NOT TO THE HITS THAT SURVIVED THE
     * CLIP — the same rule {@see \SugarCraft\Crush\Tools\BuiltIn\Glob} follows
     * for `$files` versus `$shown`, and the two tools must not disagree about
     * it.
     *
     * {@see SkillPathNudge::forPaths()} answers "does a skill claim this area
     * of the tree", which a byte cap does not change the truth of, and the
     * line it emits names the SKILL rather than the path. So a nudge earned by
     * a path the cap dropped is still true and still actionable, where an
     * instruction BODY shown for an unseen path is neither — which is why the
     * two are read off different text.
     *
     * Scoping it to the probe instead broke the containment in the direction
     * that costs the model something. The probe is the three-quarter floor;
     * the result is the FULL cap whenever no rule was surfaced, which under
     * announce-once is almost every call. A hit landing between the two cuts
     * was therefore VISIBLE while its skill went unannounced.
     *
     * WHAT THIS PARAGRAPH USED TO SAY: "and announce-once means unannounced
     * for the rest of the session".
     * WHAT IS TRUE NOW: that half was wrong and E70 caught it.
     * {@see SkillPathNudge::forPaths()} marks ONLY the entries it actually
     * emits, so a nudge that is never built spends nothing. DRIVEN at
     * ae30fee5 over the one-hit fixture: two Grep calls at cap 1,000 both came
     * back silent with `announced() === []`, and a third at cap 4,000
     * announced. Unannounced is DEFERRED, not retired.
     * WHY THIS STILL EARNS ITS PLACE: deferral is cheap but not free — the
     * model is looking at a hit whose skill it has not been told about, and
     * whether it is ever told depends on a later call arriving with more room.
     * Building the nudge off the full stdout rather than off the probe is what
     * removes that dependence, and it is still the right call.
     *
     * MEASURED with the 201-hit fixture, one cap at a time from 200 to 12,000,
     * counting the caps where `target.zzz.php` is in the result and
     * `zzz-audit` is not: 0 at d7919902, 1,745 at 6569891f (caps 5,233 to
     * 6,977), 0 now. The band moves with the length of `sys_get_temp_dir()`,
     * since that prefix is repeated on every hit line, so the sweep below
     * steps in 250s across a range an order of magnitude wider than the band
     * rather than naming caps inside it.
     */
    /**
     * WHAT THIS TEST ASSERTED: `!$visible || $announced` at every cap — "a
     * skill is announced for every hit the model can see" — with the failure
     * message "announce-once means it will not be announced again this
     * session".
     *
     * WHAT IS TRUE NOW: the law is VIOLABLE and the message was wrong, both
     * MEASURED at ae30fee5 (E70). The nudge is given an eighth of the cap, and
     * an eighth of a small cap cannot hold even the header, footer and one
     * entry. Over a fixture of ONE hit — `sub/target.zzz.php`, a 63-byte
     * result — with the shipped 19-byte description the hit is in the result
     * and `zzz-audit` is silent at caps 1,000 and 1,250, flipping at 1,500;
     * with a 400-byte description the dead band runs 1,000 to 3,250 and flips
     * at 3,500.
     *
     * The old fixture never reached that band. Its 200 competing hits kept
     * `target.zzz.php` out of the capture and out of the result until roughly
     * cap 5,000, by which point the entry had been affordable for thousands of
     * bytes — so the two regimes it swept, "hit visible" and "entry
     * affordable", did not overlap and the law was never evaluated at its own
     * boundary. Passing on a fixture that cannot reach the boundary is not
     * evidence.
     *
     * WHY A LAW STILL EARNS ITS PLACE HERE: two of them do, and neither is
     * fixture luck.
     *
     * 1. The mark is spent EXACTLY when an entry is emitted — never on a call
     *    that says nothing. That is what makes silence recoverable, and it is
     *    the claim the old failure message denied.
     * 2. Whenever the eighth CAN afford the entry, a visible hit is announced.
     *    The threshold is derived from the tracker rather than named, so this
     *    is the qualified form of the old law: true where it is true, and
     *    asserted from BOTH sides across the boundary rather than only above
     *    it.
     *
     * Both are swept over two fixtures, because no single one produces all
     * three regimes: without competing hits the target is visible at every cap
     * and the dead band is reachable; with 200 of them the clip drops it and
     * the clipped-but-announced regime is reachable.
     */
    public function testTheAnnounceOnceMarkIsSpentOnlyOnAHitTheModelWasToldAbout(): void
    {
        $floor = $this->smallestNudgeBudget();

        $seenVisibleAnnounced = false;
        $seenVisibleDeferred = false;
        $seenClippedAnnounced = false;

        foreach ([0, 200] as $competing) {
            $root = $this->fixture($competing);

            for ($cap = 1000; $cap <= 12000; $cap += 250) {
                $nudge = $this->zzzNudge();
                $content = $this->grepIn($root, new Grep($root, $cap, skillNudge: $nudge));

                $visible = str_contains($content, '/sub/target.zzz.php:');
                $announced = str_contains($content, 'zzz-audit');
                $affordable = intdiv($cap, 8) >= $floor;

                // Law 1. Nothing may be retired that the model was not shown,
                // and nothing shown may be left unmarked (it would re-announce
                // on every later call and burn context saying the same thing).
                self::assertSame(
                    $announced ? ['zzz-audit'] : [],
                    $nudge->announced(),
                    "$competing competing hits, cap $cap: the announce-once mark and the emitted entry disagree",
                );

                // Law 2, and its complement one step down. $floor is the
                // tracker's own price for one entry, so this brackets the
                // boundary instead of sitting safely to one side of it.
                if ($affordable && $visible) {
                    self::assertTrue(
                        $announced,
                        "$competing competing hits, cap $cap: an eighth ("
                        . intdiv($cap, 8) . ") covers the {$floor}-byte entry and the hit is in the "
                        . 'result, so the skill must be announced',
                    );
                }
                if (!$affordable) {
                    self::assertFalse(
                        $announced,
                        "$competing competing hits, cap $cap: an eighth ("
                        . intdiv($cap, 8) . ") is under the {$floor}-byte floor, so nothing may be emitted",
                    );
                }

                $seenVisibleAnnounced = $seenVisibleAnnounced || ($visible && $announced);
                $seenVisibleDeferred = $seenVisibleDeferred || ($visible && !$announced);
                $seenClippedAnnounced = $seenClippedAnnounced || (!$visible && $announced);
            }
        }

        // The sweep has to REACH all three regimes or the laws above are being
        // asserted over a fixture that cannot test them — which is exactly the
        // defect this test was rewritten for.
        self::assertTrue($seenVisibleAnnounced, 'the sweep must include caps where the hit survives and is announced');
        self::assertTrue($seenVisibleDeferred, 'the sweep must include the dead band: the hit is visible and the entry does not fit');
        self::assertTrue($seenClippedAnnounced, 'the sweep must include caps where the clip dropped the hit and the skill was announced anyway');
    }

    /**
     * A cap too tight for the nudge DEFERS the skill; it does not retire it.
     * This is the claim the rewritten test's old failure message got backwards,
     * pinned on its own so a regression in {@see SkillPathNudge::forPaths()}'s
     * mark-after-emit ordering fails something that names it.
     */
    public function testACapTooTightForTheNudgeDefersTheSkillRatherThanRetiringIt(): void
    {
        $root = $this->fixture(0);
        $nudge = $this->zzzNudge();
        $tight = 8 * $this->smallestNudgeBudget() - 1;

        $first = $this->grepIn($root, new Grep($root, $tight, skillNudge: $nudge));
        self::assertStringContainsString('/sub/target.zzz.php:', $first, 'the hit itself is well inside the cap');
        self::assertStringNotContainsString('zzz-audit', $first);
        self::assertSame([], $nudge->announced(), 'a nudge that was never emitted must spend no mark');

        self::assertStringNotContainsString('zzz-audit', $this->grepIn($root, new Grep($root, $tight, skillNudge: $nudge)));
        self::assertSame([], $nudge->announced());

        $roomy = $this->grepIn($root, new Grep($root, 12000, skillNudge: $nudge));
        self::assertStringContainsString('zzz-audit', $roomy, 'the deferred skill announces on the next call with room');
        self::assertSame(['zzz-audit'], $nudge->announced());
    }

    /**
     * A fresh root holding one `target.zzz.php` hit plus $competing others.
     *
     * Separate roots per regime, and not one shared tree, because the number
     * of competing hits is what decides which regime a cap lands in.
     */
    private function fixture(int $competing): string
    {
        $root = $this->dir . '/r' . $competing;
        if (is_dir($root)) {
            return $root;
        }

        mkdir($root . '/sub', 0o777, true);
        for ($i = 0; $i < $competing; $i++) {
            file_put_contents(sprintf('%s/sub/f%03d.php', $root, $i), "<?php // needle\n");
        }
        // Sorts last among the hits, so it is the one most likely to land
        // between the probe's cut and the result's.
        file_put_contents($root . '/sub/target.zzz.php', "<?php // needle\n");

        return $root;
    }

    /**
     * The smallest budget {@see SkillPathNudge::forPaths()} will spend on the
     * `zzz-audit` entry, asked of the tracker rather than recomputed here — a
     * recomputation is a second copy of its pricing that agrees with it only
     * until one of them changes.
     */
    private function smallestNudgeBudget(): int
    {
        for ($budget = 1; $budget <= SkillPathNudge::maxBytes(); $budget++) {
            if ($this->zzzNudge()->forPath('/anything/target.zzz.php', $budget) !== null) {
                return $budget;
            }
        }

        self::fail('no budget up to the class ceiling produced a nudge');
    }

    private function grepIn(string $root, Grep $tool): string
    {
        return $tool->execute([
            'id' => 'c1',
            'pattern' => 'needle',
            'path' => $root,
            'description' => 'find the needle',
        ])->content();
    }

    /** A skill scoped to a suffix only ONE fixture file carries. */
    private function zzzNudge(): SkillPathNudge
    {
        $registry = new SkillRegistry();
        $registry->register([
            'zzz-audit' => Skill::parse(
                <<<SKILL
                ---
                description: Audit for zzz files
                paths:
                  - "*.zzz.php"
                ---
                body
                SKILL,
                'zzz-audit'
            ),
        ]);

        return SkillPathNudge::new($registry);
    }

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
