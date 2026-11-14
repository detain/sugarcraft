<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Git\App;
use SugarCraft\Git\Pane;

/**
 * candy-git-033 (medium): height budgets overrun on short terminals and
 * fixed overlays clip silently mid-box.
 *
 * The right column stacked branches over log with independent max(1,·)
 * floors, so the pair demanded more rows than the pane area granted (h=8:
 * area 5, demand 6 — the log body vanished behind the hard ceiling while
 * nothing on screen said so). The ~30-row help box was never windowed at
 * all: array_slice-less, its tail was amputated by the ceiling, hiding the
 * pane-specific key sections — the reason a user opens help — on every
 * laptop-height frame (h<=23 probed).
 *
 * The cure under test: budgets derived from the enforced minimums (a pane
 * appears only when its borders plus one body row fit; the pair then splits
 * the remainder exactly, byte-identical to the old half-split wherever both
 * panes fit), and the help box windowed through rowWindow with the
 * '+N above/below' indicators — scrolled by j/k/arrows/PgUp/PgDn/Home/End
 * while the modal keeps swallowing every git-mutating key. Below threshold
 * the box is replaced by a one-row notice that always fits.
 */
final class HeightBudgetTest extends TestCase
{
    /** @return list<array{index_status:string,work_status:string,path:string}> */
    private static function statusRows(int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['index_status' => ' ', 'work_status' => 'M', 'path' => sprintf('src/file%02d.php', $i)];
        }
        return $rows;
    }

    /** @return list<array{name:string,sha:string,current:bool,remote:bool,ahead:?int,behind:?int,gone:bool}> */
    private static function branchRows(int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['name' => sprintf('branch-%02d', $i), 'sha' => sprintf('abc%02d', $i),
                'current' => false, 'remote' => false, 'ahead' => null, 'behind' => null, 'gone' => false];
        }
        return $rows;
    }

    /** @return list<array{sha:string,subject:string,author:string,ago:string}> */
    private static function logRows(int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['sha' => sprintf('def%02d', $i), 'subject' => sprintf('ZZSUBJECT%02d', $i),
                'author' => 'A. Hacker', 'ago' => '1 hour ago'];
        }
        return $rows;
    }

    private function sizedApp(int $height, ?Pane $pane = null): App
    {
        $git = new FixtureGit(self::statusRows(20), self::branchRows(15), self::logRows(15));
        return new App(
            $git,
            status: self::statusRows(20),
            branches: self::branchRows(15),
            log: self::logRows(15),
            pane: $pane ?? Pane::Status,
            width: 80,
            height: $height,
        );
    }

    /** @return int physical rows of the rendered frame */
    private static function frameRows(string $view): int
    {
        return count(explode("\n", rtrim($view, "\n")));
    }

    // ---------------------------------------------------------------- the column math

    /**
     * The pair budgets must sum to exactly the column remainder after both
     * frames pay their borders — never more — and never fall back onto the
     * double-counted max(1,·) floors that produced the h=8 demand-6-over-5.
     *
     * @param array<int, int> $heights
     */
    #[DataProvider('heightsProvidingNoOverlayPressure')]
    public function testTheStackedColumnNeverOverDemandsItsArea(int $height): void
    {
        $app  = $this->sizedApp($height);
        $area = $app->listRows();
        self::assertSame(
            max(0, $area - 4),
            $app->branchesBodyRows() + $app->logBodyRows(),
            "h={$height}: branches+log budgets must equal the column remainder exactly"
        );
        self::assertLessThanOrEqual(
            $area,
            ($app->branchesBodyRows() > 0 ? $app->branchesBodyRows() + 2 : 0)
                + ($app->logBodyRows() > 0 ? $app->logBodyRows() + 2 : 0),
            "h={$height}: rendered stacked frames must fit the area"
        );
        self::assertLessThanOrEqual(
            $area,
            $app->statusBodyRows() > 0 ? $app->statusBodyRows() + 2 : 0,
            "h={$height}: status frame must fit, or be hidden (contributing no rows)"
        );
    }

    /** @return array<string, array{int}> */
    public static function heightsProvidingNoOverlayPressure(): array
    {
        $out = [];
        foreach ([4, 5, 6, 7, 8, 9, 10, 11, 13, 15, 17, 20, 23, 24, 26, 30, 40] as $h) {
            $out['h=' . $h] = [$h];
        }
        return $out;
    }

    /**
     * The finding's measured shape (h=8: area 5, demand 6) lands one row
     * lower on a fixture with live rows, because the cursor modeline is
     * itself a bar the budget must pay: at h=9 the area is 5 and the old
     * stack still demanded 6. Now the single spare body row goes to
     * branches and the log pane is HIDDEN (not clipped): its frame title
     * and every body row vanish together instead of lying behind the
     * ceiling.
     */
    public function testTheAreaOfFiveGivesItsOneRowToBranchesAndHidesTheLog(): void
    {
        $app = $this->sizedApp(9);
        self::assertSame(5, $app->listRows());
        self::assertSame(1, $app->branchesBodyRows(), 'branches keeps the single spare body row');
        self::assertSame(0, $app->logBodyRows(), 'the log pane cannot fit and must yield');

        $view = $app->view();
        self::assertLessThanOrEqual(9, self::frameRows($view));
        self::assertStringContainsString('branch-00', $view, 'the surviving pane is shown');
        self::assertStringNotContainsString('ZZSUBJECT00', $view, 'the hidden pane renders no body rows at all');
        self::assertStringNotContainsString(' log ', $view, 'and not even its frame title');
    }

    /**
     * One row shorter and even the surviving pane is unrenderable: BOTH
     * stacked panes hide together, leaving status and chrome — an honest
     * degraded frame, never a clipped lie.
     */
    public function testTheAreaOfFourHidesTheWholeStackedColumn(): void
    {
        $app = $this->sizedApp(8);
        self::assertSame(4, $app->listRows());
        self::assertSame(0, $app->branchesBodyRows());
        self::assertSame(0, $app->logBodyRows());

        $view = $app->view();
        self::assertLessThanOrEqual(8, self::frameRows($view));
        self::assertStringNotContainsString('ZZSUBJECT00', $view);
        self::assertStringNotContainsString(' log ', $view);
        self::assertStringNotContainsString(' branches ', $view);
    }

    /**
     * Byte-identity at the sizes everyone actually uses: wherever the
     * remainder fits both panes (area >= 6) the new paired budgets agree
     * with the historical formulas EXACTLY — the flagship split does not
     * move by one row anywhere it used to render fully.
     */
    public function testTheHalfSplitIsUnchangedWhereverBothPanesFit(): void
    {
        $app  = $this->sizedApp(24);
        $area = $app->listRows();
        self::assertGreaterThanOrEqual(6, $area, 'the both-panes regime');
        // The formulas the renderer shipped before candy-git-033:
        $oldBranches = max(1, intdiv($area, 2) - 2);
        $oldLog      = max(1, ($area - intdiv($area, 2)) - 2);
        self::assertSame($oldBranches, $app->branchesBodyRows(), 'branches split unchanged');
        self::assertSame($oldLog, $app->logBodyRows(), 'log split unchanged');
    }

    // ---------------------------------------------------------------- help windowing

    private function helpApp(int $height, ?Pane $pane = null): App
    {
        [$app, ] = $this->sizedApp($height, $pane)->update(new KeyMsg(KeyType::Char, '?'));
        self::assertTrue($app->showHelp, 'help opens for the test');
        return $app;
    }

    /**
     * The laptop-height probe from the finding: at h=23 the status-pane
     * section of the help box sits below the fold — the box must carry a
     * '+N below' cue so the hidden content is at least announced.
     */
    public function testTheHelpBoxAnnouncesWhatFellOffScreen(): void
    {
        $app  = $this->helpApp(23);
        $view = $app->view();
        self::assertStringContainsString(' Help ', $view);
        self::assertStringContainsString('below', $view, 'the box must cue the hidden tail');
        self::assertStringNotContainsString('Status pane:', $view, 'and the section really is off-screen at scroll 0');
        self::assertLessThanOrEqual(23, self::frameRows($view));
    }

    /**
     * End takes you to the bottom of the manual: the pane-specific section
     * the user came to look up is REACHABLE, and the above-cue appears.
     */
    public function testEndReachesTheHiddenPaneSection(): void
    {
        $app = $this->helpApp(23);
        [$app, ] = $app->update(new KeyMsg(KeyType::End, ''));
        $view = $app->view();
        self::assertStringContainsString('Status pane:', $view, 'the section below the fold reaches the screen');
        self::assertStringContainsString('X=index Y=worktree', $view, 'with its porcelain legend line');
        self::assertStringContainsString('above', $view, 'and the other direction is announced too');
        self::assertLessThanOrEqual(23, self::frameRows($view));
    }

    /**
     * j/k move the window one row and the box follows; Home is the top.
     */
    public function testJkPageTheHelpWindowAndHomeReturnsToTop(): void
    {
        $app = $this->helpApp(23);
        [$app, ] = $app->update(new KeyMsg(KeyType::Char, 'j'));
        [$app, ] = $app->update(new KeyMsg(KeyType::Char, 'j'));
        self::assertSame(2, $app->helpScroll, 'j pages the window down');
        [$app, ] = $app->update(new KeyMsg(KeyType::Char, 'k'));
        self::assertSame(1, $app->helpScroll, 'k pages it back');
        [$app, ] = $app->update(new KeyMsg(KeyType::Home, ''));
        self::assertSame(0, $app->helpScroll, 'Home returns to the top');
        // Up/Down arrows are the same lane.
        [$app, ] = $app->update(new KeyMsg(KeyType::Down, ''));
        self::assertSame(1, $app->helpScroll);
        // PageDown scrolls by the page budget (a whole window, not a row).
        [$app, ] = $app->update(new KeyMsg(KeyType::PageDown, ''));
        self::assertGreaterThan(1, $app->helpScroll, 'PageDown moves by more than one row');
    }

    /**
     * Scroll is clamped below zero; the upper end is resolved by rowWindow
     * against the live line count, so repeated j past the bottom cannot
     * strand the box in a scrolled-empty state.
     */
    public function testScrollUpCannotGoNegative(): void
    {
        $app = $this->helpApp(23);
        for ($i = 0; $i < 5; $i++) {
            [$app, ] = $app->update(new KeyMsg(KeyType::Char, 'k'));
        }
        self::assertSame(0, $app->helpScroll);
        [$app, ] = $app->update(new KeyMsg(KeyType::End, ''));
        [$app, ] = $app->update(new KeyMsg(KeyType::Char, 'j'));
        self::assertSame(1000001, $app->helpScroll, 'the raw scroll overshoots; rowWindow clamps it to the tail');
        self::assertStringContainsString('Status pane:', $app->view(), 'while the rendered box still shows the tail');
    }

    /**
     * Closing help resets the window and reopening starts at the top —
     * a box you revisit is never mid-scroll from a past life.
     */
    public function testReopeningHelpResetsTheWindow(): void
    {
        $app = $this->helpApp(23);
        [$app, ] = $app->update(new KeyMsg(KeyType::End, ''));
        [$app, ] = $app->update(new KeyMsg(KeyType::Char, '?'));
        self::assertFalse($app->showHelp);
        self::assertSame(0, $app->helpScroll, 'close resets');
        [$app, ] = $app->update(new KeyMsg(KeyType::Char, '?'));
        self::assertTrue($app->showHelp);
        self::assertSame(0, $app->helpScroll, 'open resets too');
    }

    /**
     * The modal law from candy-git-027 stands: navigation keys page the
     * box, every git-mutating key still dies at the door. 'a' under an
     * open help box must not reach the driver.
     */
    public function testNavigationDoesNotPunchHolesInTheModal(): void
    {
        $git = new FixtureGit(self::statusRows(20), self::branchRows(15), self::logRows(15));
        $app = new App($git, status: self::statusRows(20), branches: self::branchRows(15),
            log: self::logRows(15), width: 80, height: 23);
        [$app, ] = $app->update(new KeyMsg(KeyType::Char, '?'));
        // 'q' is deliberately absent: quitting through the global arm is
        // candy-git-027's pinned design and not a git-state mutation.
        foreach (['a', 's', 'd', 'c', 'R', 'S', 'M'] as $rune) {
            [$app, ] = $app->update(new KeyMsg(KeyType::Char, $rune));
        }
        [$app, ] = $app->update(new KeyMsg(KeyType::Space, ''));
        self::assertTrue($app->showHelp, 'help is still up');
        self::assertFalse($git->stageAllCalled, 'no stage-all behind the box');
        self::assertSame([], $git->stages, 'no single stage either');
        self::assertSame([], $git->commits, 'no commit collector opened behind the box');
        self::assertFalse($app->collectingCommit);
    }

    /**
     * Below threshold (h=10 probed): no box, no silent clip — a one-row
     * notice that always fits inside the reported height.
     */
    public function testTooShortFramesGetANoticeInsteadOfAClippedBox(): void
    {
        $app  = $this->helpApp(10);
        $view = $app->view();
        self::assertStringContainsString('too short', $view, 'the notice says why nothing else does');
        self::assertStringNotContainsString('show this help', $view, 'no clipped key list is shipped');
        self::assertLessThanOrEqual(10, self::frameRows($view), 'and the notice itself survives the ceiling');
    }

    /**
     * The unreported-size legacy path (height null) keeps showing the whole
     * manual unwindowed — byte-stable first-frame contract.
     */
    public function testAnUnsizedTerminalStillShowsTheWholeBoxUnwindowed(): void
    {
        $git  = new FixtureGit(self::statusRows(3), self::branchRows(3), self::logRows(3));
        $app  = new App($git, status: self::statusRows(3), branches: self::branchRows(3), log: self::logRows(3));
        [$app, ] = $app->update(new KeyMsg(KeyType::Char, '?'));
        $view = $app->view();
        self::assertStringContainsString('show this help', $view);
        self::assertStringContainsString('close overlay / quit', $view, 'the last general row renders');
        self::assertStringContainsString('X=index Y=worktree', $view, 'with the pane section, no cues');
        self::assertStringNotContainsString('below', $view);
    }
}
