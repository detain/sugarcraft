<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Files\Lang;
use SugarCraft\Files\Manager;
use SugarCraft\Files\Msg\SearchDoneMsg;
use React\EventLoop\Loop;

/**
 * CF-17 (closes 013): the bounded recursive walk behind search.
 * Real temp trees — the walk IS the real filesystem; the seam is the
 * promise→Msg→update() circuit, driven exactly like the async copy
 * tests drive theirs.
 */
final class ManagerSearchRecursiveTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sugarcraft-find-test-' . uniqid('', true);
        $this->seed($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function seed(string $root): void
    {
        mkdir("$root/sub", 0755, true);
        file_put_contents("$root/a_top.txt", 'top');
        file_put_contents("$root/sub/c_mid.txt", 'mid');
        mkdir("$root/.hsec", 0755, true);
        file_put_contents("$root/.hsec/secret.txt", 'hidden');
        // exactly at the depth budget: 8 levels down
        $deep = $root;
        for ($i = 1; $i <= 8; $i++) {
            $deep .= "/x$i";
        }
        mkdir("$deep/deeper/x9/x10", 0755, true);
        file_put_contents("$deep/target8.txt", 'found by the budget');
        file_put_contents("$deep/deeper/x9/x10/never.txt", 'beyond the budget');
        // 60 look-alikes for the hits cap
        mkdir("$root/many", 0755, true);
        for ($i = 0; $i < 60; $i++) {
            file_put_contents(sprintf("$root/many/match_%02d.txt", $i), 'x');
        }
    }

    private function removeDir(string $path): void
    {
        $items = @scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            is_dir($full) && !is_link($full) ? $this->removeDir($full) : @unlink($full);
        }
        @rmdir($path);
    }

    private function manager(): Manager
    {
        return Manager::start($this->tmpDir, $this->tmpDir);
    }

    /** Type '/' + the query, press ^r, and return the model + armed Cmd. */
    private function armRecursive(Manager $m, string $query): array
    {
        [$m] = $m->update(new KeyMsg(KeyType::Char, '/'));
        foreach (mb_str_split($query) as $ch) {
            [$m] = $m->update(new KeyMsg(KeyType::Char, $ch));
        }
        return $m->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
    }

    /**
     * Run the armed walk through the loop and feed its resolution back
     * through update() — the Program hop (see ManagerCopyTest).
     *
     * @return array{Manager, bool} final model + whether the msg was delivered
     */
    private function drain(Manager $pending, ?\Closure $cmd): Manager
    {
        $this->assertInstanceOf(\Closure::class, $cmd, 'the toggle arms the walk as a Cmd');
        $async = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $async);
        $final = $pending;
        $async->promise->then(static function (?Msg $resolved) use ($pending, &$final): void {
            if ($resolved instanceof SearchDoneMsg) {
                [$final] = $pending->update($resolved);
            }
        });
        Loop::run();
        return $final;
    }

    private function resultNames(Manager $m): array
    {
        return array_map(static fn($e) => $e->name, $m->searchResults);
    }

    public function testToggleIsPerModeAndCtrlOnly(): void
    {
        $m = $this->manager();
        [$searching] = $m->update(new KeyMsg(KeyType::Char, '/'));
        $this->assertFalse($searching->searchRecursive);
        [$armed, $cmd] = $searching->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $this->assertTrue($armed->searchRecursive);
        $this->assertNull($cmd, 'an empty query schedules nothing yet');
        $this->assertSame(Lang::t('status.search_recursive_on'), $armed->status);
        // bare r STILL types into the query (the CF-09 law survives the new chord)
        [$typed] = $armed->update(new KeyMsg(KeyType::Char, 'r'));
        $this->assertSame('r', $typed->searchQuery, 'a bare r is a query character, not the toggle');
        // ^r again drops back
        [$off, $cmd2] = $typed->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $this->assertFalse($off->searchRecursive);
        $this->assertNull($cmd2, 'the off leg never schedules');
        $this->assertSame(Lang::t('status.search_recursive_off'), $off->status);
    }

    public function testDeepFileFoundThroughTheRealWalk(): void
    {
        $m = $this->manager();
        // A live width this test needs: the search rows are cell-clamped,
        // and the whole point of this one is the 48-cell relpath label.
        [$m] = $m->update(new WindowSizeMsg(120, 40));
        [$armed, $cmd] = $this->armRecursive($m, 'target8');
        $final = $this->drain($armed, $cmd);
        $this->assertContains('target8.txt', $this->resultNames($final));
        // the parallel dir says WHERE, aligned with the row
        $idx = array_search('target8.txt', $this->resultNames($final), true);
        $this->assertIsInt($idx);
        $this->assertSame(
            $this->tmpDir . '/x1/x2/x3/x4/x5/x6/x7/x8',
            $final->searchResultDirs[$idx],
        );
        // ...and the frame shows the RELPATH, not a bare basename
        $view = $final->view();
        $this->assertStringContainsString('x1/x2/x3/x4/x5/x6/x7/x8/target8.txt', $view);
    }

    public function testDepthBudgetTruncatesInsteadOfLying(): void
    {
        $m = $this->manager();
        [$armed, $cmd] = $this->armRecursive($m, 'never');
        $final = $this->drain($armed, $cmd);
        $this->assertNotContains('never.txt', $this->resultNames($final), 'level 10 is past the depth 8 budget');
        $this->assertSame(Lang::t('status.search_partial'), $final->status, 'the truncated walk says partial');
    }

    public function testHiddenTreeIsPrunedAtTheWalk(): void
    {
        $m = $this->manager();
        [$armed, $cmd] = $this->armRecursive($m, 'secret');
        $final = $this->drain($armed, $cmd);
        $this->assertNotContains('secret.txt', $this->resultNames($final), 'search may not surface what the UI hides');
    }

    public function testTopLevelHitsDoNotDuplicateThePaneCorpus(): void
    {
        $m = $this->manager();
        [$armed, $cmd] = $this->armRecursive($m, 'a_top');
        $final = $this->drain($armed, $cmd);
        $names = $this->resultNames($final);
        $this->assertSame(1, count(array_keys($names, 'a_top.txt', true)), 'walk dir==cwd rows are deduped');
        $idx = array_search('a_top.txt', $names, true);
        $this->assertSame('', $final->searchResultDirs[$idx], 'the surviving row is the pane-level one');
    }

    public function testStaleAnswerIsDroppedAndEscDissolvesTheWish(): void
    {
        $m = $this->manager();
        [$armed, $cmd] = $this->armRecursive($m, 'target8');
        // The user keeps typing; a late answer to the PREVIOUS query must not land.
        [$typing] = $armed->update(new KeyMsg(KeyType::Char, 'z'));
        $stale = $typing->update(new SearchDoneMsg('target8', [
            ['dir' => $this->tmpDir . '/x1', 'name' => 'ghost.txt', 'isDir' => false],
        ], false))[0];
        $this->assertNotContains('ghost.txt', $this->resultNames($stale));

        // Esc exits the mode — and the recursive wish with it.
        [$gone] = $armed->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertFalse($gone->searchRecursive);
        $late = $gone->update(new SearchDoneMsg('target8', [
            ['dir' => $this->tmpDir . '/x1', 'name' => 'target8.txt', 'isDir' => false],
        ], false))[0];
        $this->assertNotContains('target8.txt', $this->resultNames($late));
    }

    public function testHitsBudgetTrimsAndSaysPartial(): void
    {
        $m = $this->manager();
        [$sized] = $m->update(new WindowSizeMsg(80, 10)); // hits cap 40, display cap 4
        [$armed, $cmd] = $this->armRecursive($sized, 'match');
        $final = $this->drain($armed, $cmd);
        $this->assertNotEmpty($final->searchResults);
        $this->assertLessThanOrEqual(4, count($final->searchResults), 'four screens at rows=10');
        $this->assertSame(Lang::t('status.search_partial'), $final->status);
        $this->assertCount(count($final->searchResults), $final->searchResultDirs, 'alignment law survives budgets');
    }

    public function testEnterOnDeepHitHomesThePane(): void
    {
        $m = $this->manager();
        [$armed, $cmd] = $this->armRecursive($m, 'target8');
        $final = $this->drain($armed, $cmd);
        $idx = array_search('target8.txt', $this->resultNames($final), true);
        $this->assertIsInt($idx);
        $landed = $final;
        for ($i = 0; $i < $idx; $i++) {
            [$landed] = $landed->update(new KeyMsg(KeyType::Down, ''));
        }
        [$done] = $landed->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNull($done->searchQuery, 'Enter lands and exits search');
        $this->assertSame($this->tmpDir . '/x1/x2/x3/x4/x5/x6/x7/x8', $done->activePane()->cwd);
        $this->assertSame('target8.txt', $done->activePane()->currentEntry()?->name);
    }
}
