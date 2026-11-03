<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Git\App;
use SugarCraft\Git\DiffViewer;
use SugarCraft\Git\Pane;
use SugarCraft\Git\Renderer;
use SugarCraft\Git\StashEntry;
use SugarCraft\Git\StashManager;

/**
 * K3/A26 (2026-11 audit): the renderer used to ignore the reported terminal
 * height entirely — long lists and overlays grew below the frame where no key
 * could reach them — and overlay boxes kept their fixed widths regardless of
 * the terminal. These pins cover the viewport contract: the row-window
 * helper, list windowing with scroll indicators, PageUp/PageDown/Home/End
 * movement, the diff overlay window, width clipping, and the hard frame clip.
 * The null-size legacy path (sizes never reported) must keep showing
 * everything, unwindowed.
 */
final class ViewportTest extends TestCase
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

    /** @return list<array{name:string,sha:string,current:bool}> */
    private static function branchRows(int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['name' => sprintf('branch-%02d', $i), 'sha' => sprintf('abc%02d', $i), 'current' => false];
        }
        return $rows;
    }

    /** @return list<string> a diff with one hunk of four body lines each */
    private static function diffLines(int $hunks): array
    {
        $lines = ['diff --git a/f b/f', 'index 111..222 100644', '--- a/f', '+++ b/f'];
        for ($h = 0; $h < $hunks; $h++) {
            $lines[] = '@@ -' . (1 + $h * 10) . ',2 +' . (1 + $h * 10) . ',2 @@';
            $lines[] = ' context line ' . $h . 'a';
            $lines[] = '-removed ' . $h;
            $lines[] = '+added ' . $h;
            $lines[] = '+added ' . $h . 'b';
        }
        return $lines;
    }

    /** @return array{0:int,1:int,2:int,3:int} */
    private static function rowWindow(int $count, int $cursor, int $visible): array
    {
        $method = new ReflectionMethod(Renderer::class, 'rowWindow');
        $method->setAccessible(true);
        /** @var array{int,int,int,int} $out */
        $out = $method->invoke(null, $count, $cursor, $visible);
        return $out;
    }

    /**
     * @param positive-int $count
     * @param positive-int $minVisible
     */
    #[DataProvider('windowShapes')]
    public function testTheRowWindowAlwaysPinsTheCursorAndSumsToTheList(int $count, int $minVisible): void
    {
        for ($visible = $minVisible; $visible <= $count + 2; $visible++) {
            for ($cursor = 0; $cursor < $count; $cursor++) {
                [$start, $body, $above, $below] = self::rowWindow($count, $cursor, $visible);
                self::assertGreaterThanOrEqual(1, $body, 'a window must show at least one row');
                self::assertLessThanOrEqual($cursor, $start, 'the window never starts past the cursor');
                self::assertGreaterThanOrEqual($cursor, $start + $body - 1, 'cursor row is inside the window');
                self::assertLessThanOrEqual(
                    $visible,
                    $body + ($above > 0 ? 1 : 0) + ($below > 0 ? 1 : 0),
                    'window plus indicators must fit the budget'
                );
                if ($visible >= 3) {
                    // Only from three rows up does the window carry genuine
                    // indicators; below three they are suppressed to zero.
                    self::assertSame($count - $start - $body, $below, 'below counts the unseen tail');
                    self::assertSame($start, $above, 'above counts the unseen head');
                }
            }
        }
    }

    /** @return array<string, array{0:int, 1:int}> */
    public static function windowShapes(): array
    {
        return [
            'ten rows from one visible' => [10, 1],
            'twenty-five rows from three visible' => [25, 3],
            'six rows from six visible (exact fit)' => [6, 6],
        ];
    }

    public function testAnEmptyListWindowsToNothingWithoutMovingTheCursor(): void
    {
        [$start, $body, $above, $below] = self::rowWindow(0, 0, 4);
        self::assertSame([0, 0, 0, 0], [$start, $body, $above, $below]);
    }

    public function testAWindowBigEnoughShowsEverythingUnindicated(): void
    {
        [$start, $body, $above, $below] = self::rowWindow(4, 2, 4);
        self::assertSame([0, 4, 0, 0], [$start, $body, $above, $below]);
    }

    public function testTheStatusListWindowsAroundTheCursor(): void
    {
        $git = new FixtureGit(self::statusRows(30), [], []);
        $app = new App($git, status: self::statusRows(30), width: 80, height: 20);
        $view = $app->view();

        self::assertStringContainsString('src/file00.php', $view);
        self::assertStringNotContainsString('src/file29.php', $view);
        self::assertStringContainsString('below', $view);
    }

    public function testTheStatusListTrailsAnAboveIndicatorWhenScrolledToTheEnd(): void
    {
        $git = new FixtureGit(self::statusRows(30), [], []);
        $app = new App($git, status: self::statusRows(30), statusCursor: 29, width: 80, height: 20);
        $view = $app->view();

        self::assertStringContainsString('src/file29.php', $view);
        self::assertStringNotContainsString('src/file00.php', $view);
        self::assertStringContainsString('above', $view);
    }

    public function testAnUnreportedHeightKeepsTheLegacyShowEverythingFrame(): void
    {
        $rows = self::statusRows(30);
        $git = new FixtureGit($rows, [], []);
        $app = new App($git, status: $rows);
        $view = $app->view();

        foreach ($rows as $row) {
            self::assertStringContainsString($row['path'], $view);
        }
        self::assertStringNotContainsString(' below', $view);
        self::assertStringNotContainsString(' above', $view);
    }

    public function testPageDownHomeEndMoveTheBranchesCursorByWholePages(): void
    {
        $git = new FixtureGit([], self::branchRows(20), []);
        $app = new App($git, branches: self::branchRows(20), pane: Pane::Branches, width: 80, height: 20);

        [$paged, ] = $app->update(new KeyMsg(KeyType::PageDown, ''));
        $step = max(1, $app->branchesBodyRows() - 2);
        self::assertSame(min(20 - 1, $step), $paged->branchesCursor);

        [$toEnd, ] = $app->update(new KeyMsg(KeyType::End, ''));
        self::assertSame(19, $toEnd->branchesCursor);

        [$toHome, ] = $toEnd->update(new KeyMsg(KeyType::Home, ''));
        self::assertSame(0, $toHome->branchesCursor);

        [$pageUpAtTop, ] = $toHome->update(new KeyMsg(KeyType::PageUp, ''));
        self::assertSame(0, $pageUpAtTop->branchesCursor);

        [$halfDown, ] = $toHome->update(new KeyMsg(KeyType::PageDown, ''));
        [$oneMore, ] = $halfDown->update(new KeyMsg(KeyType::PageDown, ''));
        self::assertGreaterThan($halfDown->branchesCursor, $oneMore->branchesCursor);
    }

    public function testDiffHunkNavigationFollowsPageUpPageDownHomeEnd(): void
    {
        $git = new FixtureGit([], [], []);
        $viewer = DiffViewer::fromRawDiff('src/f.php', self::diffLines(3));
        $app = new App($git, diffViewer: $viewer, width: 80, height: 24);
        self::assertSame($viewer->hunkStarts[0], $app->diffViewer->hunkCursor);

        [$down, ] = $app->update(new KeyMsg(KeyType::PageDown, ''));
        self::assertSame($viewer->hunkStarts[1], $down->diffViewer->hunkCursor);

        [$end, ] = $app->update(new KeyMsg(KeyType::End, ''));
        self::assertSame($viewer->hunkStarts[2], $end->diffViewer->hunkCursor);

        [$home, ] = $app->update(new KeyMsg(KeyType::Home, ''));
        self::assertSame($viewer->hunkStarts[0], $home->diffViewer->hunkCursor);

        [$back, ] = $home->update(new KeyMsg(KeyType::PageUp, ''));
        self::assertSame($viewer->hunkStarts[0], $back->diffViewer->hunkCursor, 'PageUp at the first hunk stays put');
    }

    public function testATallDiffOverlayWindowsAroundTheSelectedHunk(): void
    {
        $git = new FixtureGit([], [], []);
        $viewer = DiffViewer::fromRawDiff('src/big.php', self::diffLines(10));
        $app = new App($git, diffViewer: $viewer, width: 80, height: 24);
        $view = $app->view();

        self::assertStringContainsString('@@ -1,2 +1,2 @@', $view, 'the selected first hunk is visible');
        self::assertStringNotContainsString('added 9b', $view, 'the far tail of a 10-hunk diff cannot flood the frame');
        self::assertStringContainsString('below', $view);
        self::assertLessThanOrEqual(24, count(explode("\n", rtrim($view, "\n"))));
    }

    public function testAnUnreportedHeightDiffOverlayShowsEveryLine(): void
    {
        $git = new FixtureGit([], [], []);
        $viewer = DiffViewer::fromRawDiff('src/big.php', self::diffLines(4));
        $app = new App($git, diffViewer: $viewer);
        $view = $app->view();

        self::assertStringContainsString('added 3b', $view);
        self::assertStringNotContainsString(' below', $view);
    }

    public function testEveryRenderedLineRespectsTheReportedWidth(): void
    {
        $wide = ['diff --git a/f b/f', '--- a/f', '+++ b/f', '@@ -1 +1 @@'];
        $wide[] = '+' . str_repeat('x', 200);
        $wide[] = '-' . str_repeat('y', 200);
        $git = new FixtureGit(self::statusRows(1), [], []);
        $viewer = DiffViewer::fromRawDiff(str_repeat('p', 90) . '.php', $wide);
        $app = new App($git, status: self::statusRows(1), diffViewer: $viewer, width: 60, height: 24);

        foreach (explode("\n", rtrim($app->view(), "\n")) as $line) {
            $plain = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $line);
            self::assertLessThanOrEqual(60, Width::of($plain), 'frame line exceeded the terminal width: ' . $plain);
        }
    }

    public function testTheWholeFrameIsClippedToTheReportedHeight(): void
    {
        $git = new FixtureGit(self::statusRows(60), self::branchRows(10), []);
        $app = new App($git, status: self::statusRows(60), width: 80, height: 8);
        self::assertLessThanOrEqual(8, count(explode("\n", rtrim($app->view(), "\n"))));
    }

    public function testTheStashOverlayWindowsItsEntries(): void
    {
        $entries = [];
        for ($i = 0; $i < 25; $i++) {
            $entries[] = new StashEntry($i, sprintf('sha%02d', $i), 'main', 'stash message number ' . $i);
        }
        $git = new FixtureGit([], [], []);
        $app = new App($git, stashManager: new StashManager($entries, 0), width: 80, height: 20);
        $view = $app->view();

        self::assertStringContainsString('stash@{0}', $view);
        self::assertStringNotContainsString('stash@{24}', $view);
        self::assertStringContainsString('below', $view);
        self::assertLessThanOrEqual(20, count(explode("\n", rtrim($view, "\n"))));
    }
}
