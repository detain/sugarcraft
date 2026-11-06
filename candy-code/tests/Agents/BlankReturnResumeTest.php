<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\BlankReturnResume;

/**
 * The blank-return resume policy: the streak machine, its cap, its reset, and
 * the two disclosure notes the fleet paths append verbatim.
 */
final class BlankReturnResumeTest extends TestCase
{
    public function testWhitespaceOnlyReportsCountAsBlank(): void
    {
        self::assertTrue(BlankReturnResume::isBlank(''));
        self::assertTrue(BlankReturnResume::isBlank('   '));
        self::assertTrue(BlankReturnResume::isBlank("\n\t \r\n"));
        self::assertFalse(BlankReturnResume::isBlank('done'));
        self::assertFalse(BlankReturnResume::isBlank(' done '));
    }

    public function testTheResumeInstructionIsTheMinimalContinueWord(): void
    {
        self::assertSame('continue', BlankReturnResume::INSTRUCTION);
    }

    public function testTheFirstNineBlanksEachBuyAResumeAndTheTenthGivesUp(): void
    {
        $policy = new BlankReturnResume();

        for ($blank = 1; $blank <= 9; ++$blank) {
            self::assertTrue($policy->observeBlank(), 'blank #' . $blank . ' is still owed a resume');
            self::assertSame($blank, $policy->blankStreak());
            self::assertSame($blank, $policy->resumesIssued());
        }

        // The tenth CONSECUTIVE blank is the give-up point: no resume is
        // issued for it, and the streak stays visible for the reason.
        self::assertFalse($policy->observeBlank(), 'the tenth consecutive blank must exhaust the budget');
        self::assertSame(10, $policy->blankStreak());
        self::assertSame(9, $policy->resumesIssued(), 'exhaustion itself must not bill a resume');
    }

    public function testARealReportResetsTheStreakSoALaterStreakStartsWhole(): void
    {
        $policy = new BlankReturnResume();

        self::assertTrue($policy->observeBlank());
        self::assertTrue($policy->observeBlank());
        $policy->nonBlank();
        self::assertSame(0, $policy->blankStreak(), 'the non-blank completion must zero the streak');
        self::assertSame(2, $policy->resumesIssued(), 'resumes already issued stay on the record');

        // The whole nine-resume budget is available to the new streak again.
        for ($blank = 1; $blank <= 9; ++$blank) {
            self::assertTrue($policy->observeBlank(), 'blank #' . $blank . ' of the second streak');
        }
        self::assertFalse($policy->observeBlank(), 'the second streak ends at its own tenth blank');
        self::assertSame(11, $policy->resumesIssued(), 'two resumes before the break plus nine in the fresh streak');
    }

    public function testTheCapIsTenConsecutiveBlanksAndNoLooser(): void
    {
        // Read alongside the loop above: the cap is a COUNT OF BLANKS, not of
        // resumes — a run may see ten blank completions in a row while only
        // nine continue instructions go over the wire.
        self::assertSame(10, BlankReturnResume::MAX_CONSECUTIVE_BLANKS);

        $policy = new BlankReturnResume();
        $observed = 0;
        while ($policy->observeBlank()) {
            ++$observed;
        }
        self::assertSame(9, $observed, 'exactly MAX minus one resumes per streak');
    }

    public function testTheStallNoteNamesTheStreakTheResumesAndTheStall(): void
    {
        $policy = new BlankReturnResume();
        while ($policy->observeBlank()) {
            // drive to exhaustion
        }

        $note = $policy->stallNote();
        self::assertStringContainsString('blank-return stall', $note);
        self::assertStringContainsString('10 consecutive blank returns', $note);
        self::assertStringContainsString('9 continue-resume(s) consumed', $note);
        self::assertStringStartsWith(' [', $note, 'the bracket shape mirrors the verdict-reason suffixes');
    }

    public function testTheResumedNoteIsSilentForACleanRunAndDisclosesCountedResumes(): void
    {
        $clean = new BlankReturnResume();
        $clean->nonBlank();
        self::assertSame('', $clean->resumedNote(), 'a first-shot report must not gain a tail');

        $resumed = new BlankReturnResume();
        self::assertTrue($resumed->observeBlank());
        self::assertTrue($resumed->observeBlank());
        $resumed->nonBlank();
        self::assertSame("\n\n[resumed 2 blank return(s) with a continue instruction]", $resumed->resumedNote());
    }
}
