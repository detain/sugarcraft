<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Git\App;
use SugarCraft\Git\DiffViewer;
use SugarCraft\Git\Pane;
use SugarCraft\Git\Renderer;

/**
 * candy-git-032 (medium): the key-hint strip put the rarest verbs first, so
 * `q` and `?` — the two affordances it exists for — never fit any realistic
 * width. One ~220-268-cell sentence truncated tail-first at barWidth(): at
 * 80x24 the visible chrome read "S  stash  ·  V  cherry-pick  ·  i  …"
 * and quit/help/push fell off the tail below ~160 columns. The rebuild is
 * (key, meaning) pairs assembled under budget in essentials-first priority,
 * pane-aware (the focused pane's verbs come before the rare global ones),
 * spanning a second row when App::hintRows() says the frame can pay it.
 * The null-height legacy path keeps the monolith byte-for-byte.
 */
final class KeyHintStripTest extends TestCase
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

    private function sizedApp(int $width, int $height, ?Pane $pane = null): App
    {
        $git = new FixtureGit(self::statusRows(5), self::branchRows(5), self::logRows(5));
        return new App(
            $git,
            status: self::statusRows(5),
            branches: self::branchRows(5),
            log: self::logRows(5),
            pane: $pane ?? Pane::Status,
            width: $width,
            height: $height,
        );
    }

    private static function plain(string $view): string
    {
        return (string) preg_replace('/\x1b\[[0-9;]*m/', '', $view);
    }

    /**
     * The exact regression: at 80x24 the monolith ended at "tab switch"
     * and neither of the two affordances the strip exists to teach was on
     * screen. Essentials-first ordering says otherwise now.
     */
    public function testTheAffordancesTheStripExistsForAreOnScreen(): void
    {
        $view = self::plain($this->sizedApp(80, 24)->view());
        self::assertStringContainsString('show this help', $view, '? must be findable in the always-visible bar');
        self::assertStringContainsString('quit', $view, 'and so must q');
        self::assertStringContainsString('switch pane', $view);
    }

    /**
     * A 40-column frame truncated the monolith after "S  stash  ·  V" —
     * not one affordance survived. The pair bar still carries the general
     * essentials across its two rows.
     */
    public function testEssentialsSurviveANarrowTerminal(): void
    {
        $view = self::plain($this->sizedApp(40, 24)->view());
        self::assertStringContainsString('show this help', $view);
        self::assertStringContainsString('q  quit', $view);
        self::assertStringContainsString('switch pane', $view);
    }

    public function testTheStripIsContextualPerPane(): void
    {
        $status = self::plain($this->sizedApp(80, 24, Pane::Status)->view());
        self::assertStringContainsString('stage / unstage file', $status);
        self::assertStringContainsString('open diff viewer', $status);
        self::assertStringNotContainsString('delete branch', $status, 'D is not a status-pane verb');

        $branches = self::plain($this->sizedApp(80, 24, Pane::Branches)->view());
        self::assertStringContainsString('checkout branch', $branches);
        self::assertStringContainsString('delete branch', $branches, 'the destructive-capable D is pinned where it acts (COPY-11)');

        $log = self::plain($this->sizedApp(80, 24, Pane::Log)->view());
        self::assertStringContainsString('open commit details', $log);
    }

    /**
     * While an overlay owns the keyboard the pane verbs step aside —
     * the strip must not advertise keys the overlay swallows.
     */
    public function testPaneVerbsStepAsideWhileAnOverlayOwnsTheKeys(): void
    {
        $git = new FixtureGit(self::statusRows(5), self::branchRows(5), self::logRows(5));
        $viewer = DiffViewer::fromRawDiff('src/A.php', ['diff --git a/src/A.php b/src/A.php', '+++ b/src/A.php', '@@ -1 +1 @@', '+x']);
        $app = new App($git, branches: self::branchRows(5), pane: Pane::Branches,
            diffViewer: $viewer, width: 80, height: 24);
        $view = self::plain($app->view());
        self::assertStringContainsString('show this help', $view, 'essentials stay');
        self::assertStringNotContainsString('delete branch', $view, 'pane verbs yield to the viewer');
    }

    public function testTheSecondRowIsOnlyTakenWhenTheFrameCanPay(): void
    {
        self::assertSame(2, $this->sizedApp(80, 24)->hintRows());
        self::assertSame(1, $this->sizedApp(80, 10)->hintRows());
        $git = new FixtureGit(self::statusRows(2), self::branchRows(2), self::logRows(2));
        self::assertSame(1, (new App($git))->hintRows(), 'the unsized legacy path never grows');

        // The second row is spent where the first cannot reach. Exact row
        // equality proves both the split point AND that no pair is ever
        // half-shown: a row that hit truncate() would carry '…' here.
        $laptopLines = explode("\n", rtrim(self::plain($this->sizedApp(80, 24, Pane::Status)->view()), "\n"));
        self::assertContains(
            ' ?  show this help · q  quit · tab  switch pane · j/k  move cursor',
            $laptopLines,
            'row one carries exactly the general essentials'
        );
        self::assertContains(
            ' R  refresh · enter  open diff viewer · s  stage / unstage file',
            $laptopLines,
            'row two continues exactly where the budget broke - no mid-pair clip'
        );
        // What the (verbosely translated) tail cannot fit is DROPPED whole:
        // priority order is the contract, and the rarest verbs are the losers.
        self::assertStringNotContainsString('push to remote', implode("\n", $laptopLines));
        // ...and on a single-row frame the pane verbs give way to the
        // essentials, while q/? hold their ground.
        $short = self::plain($this->sizedApp(80, 10)->view());
        self::assertStringNotContainsString('R  refresh', $short, 'one row buys the essentials only');
        self::assertStringNotContainsString('push to remote', $short, 'rarest verbs lose the single-row budget');
        self::assertStringContainsString('show this help', $short, 'essentials never do');
    }

    /** Every strip row stays inside the reported width. */
    public function testStripRowsRespectTheReportedWidth(): void
    {
        $view = self::plain($this->sizedApp(60, 24, Pane::Status)->view());
        foreach (explode("\n", rtrim($view, "\n")) as $line) {
            self::assertLessThanOrEqual(60, Width::of($line), 'frame line exceeded the terminal width: ' . $line);
        }
    }

    /**
     * The null-height legacy contract: the monolithic sentence renders
     * byte-identically (this is the first-frame the historical tests pin).
     */
    public function testTheUnsizedLegacyFrameKeepsTheMonolith(): void
    {
        $git = new FixtureGit(self::statusRows(2), self::branchRows(2), self::logRows(2));
        $view = self::plain((new App($git, status: self::statusRows(2)))->view());
        self::assertStringContainsString('S  stash  ·  V  cherry-pick', $view);
    }

    /** The frame gains the strip's second row and no more. */
    public function testTheTallerFrameStillFitsItsTerminal(): void
    {
        $view = $this->sizedApp(80, 24, Pane::Status)->view();
        self::assertLessThanOrEqual(24, count(explode("\n", rtrim($view, "\n"))));
    }
}
