<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseMotionMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Files\ConfirmState;
use SugarCraft\Files\Manager;
use SugarCraft\Files\Renderer;
use SugarCraft\Files\Tests\Support\FsTestCase;

/**
 * CF-55: pointer support - wheel scroll, click-to-cursor/activate,
 * ctrl-click toggle, double-click open, tab-bar clicks. Geometry is
 * asserted against the rendered frame itself so the hit-test cannot
 * drift from what is drawn.
 */
final class ManagerMouseTest extends FsTestCase
{
    private float $now = 100.0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = 100.0;
        Manager::useClock(fn(): float => $this->now);
        foreach (['a.txt', 'b.txt', 'c.txt', 'd.txt'] as $f) {
            file_put_contents($this->tmp . '/' . $f, 'x');
        }
        mkdir($this->tmp . '/sub');
    }

    protected function tearDown(): void
    {
        Manager::useClock(null);
        parent::tearDown();
    }

    private function sized(int $cols = 80, int $rows = 24): Manager
    {
        [$m] = $this->manager()->update(new WindowSizeMsg($cols, $rows));
        return $m;
    }

    private function click(Manager $m, int $x, int $y, bool $ctrl = false, MouseButton $b = MouseButton::Left): array
    {
        return $m->update(new MouseClickMsg($x, $y, $b, MouseAction::Press, false, false, $ctrl));
    }

    /** y of the row listing $name in the active pane (dirs sort first, '..' on top). */
    private function rowOf(Manager $m, string $name): int
    {
        foreach ($m->activePane()->entries as $i => $e) {
            if ($e->name === $name) {
                return self::ROW0 + $i;
            }
        }
        $this->fail("no entry {$name}");
    }

    /** y of the first entry row (top border, header, rule above it). */
    private const ROW0 = 4;

    public function testClickParksTheCursorOnTheRow(): void
    {
        $m0 = $this->sized();
        [$m] = $this->click($m0, 5, $this->rowOf($m0, 'b.txt'));
        $this->assertSame('b.txt', $m->activePane()->currentEntry()?->name);
    }

    public function testRowGeometryMatchesTheDrawnFrame(): void
    {
        $m = $this->sized();
        $lines = explode("\n", $m->view());
        $y = $this->rowOf($m, 'a.txt');
        $this->assertStringContainsString('a.txt', $lines[$y - 1]);
        $this->assertSame(['kind' => 'row', 'side' => 0, 'index' => 2], Renderer::hit($m, 5, $y));
    }

    public function testClickInTheRightPaneActivatesIt(): void
    {
        [$m] = $this->click($this->sized(), 60, self::ROW0 + 1);
        $this->assertSame(1, $m->activeIdx);
        $this->assertSame(1, $m->activePane()->cursor);
        $this->assertSame(0, $m->left->cursor, 'the other pane keeps its cursor');
    }

    public function testClickOnPaneChromeActivatesWithoutMovingCursor(): void
    {
        [$m] = $this->click($this->sized(), 60, 2);
        $this->assertSame(1, $m->activeIdx);
        $this->assertSame(0, $m->right->cursor);
    }

    public function testGutterAndPastTheLastRowAreInert(): void
    {
        $m = $this->sized();
        $this->assertNull(Renderer::hit($m, 40, self::ROW0));
        $this->assertSame(['kind' => 'pane', 'side' => 0], Renderer::hit($m, 5, self::ROW0 + 20));
        [$same] = $this->click($m, 40, self::ROW0);
        $this->assertSame($m, $same);
    }

    public function testWheelScrollsTheHoveredPaneWithoutStealingFocus(): void
    {
        $m = $this->sized();
        [$m] = $m->update(new MouseWheelMsg(60, 8, MouseButton::WheelDown, MouseAction::Press));
        $this->assertSame(3, $m->right->cursor);
        $this->assertSame(0, $m->activeIdx);
        [$m] = $m->update(new MouseWheelMsg(60, 8, MouseButton::WheelUp, MouseAction::Press));
        $this->assertSame(0, $m->right->cursor);
    }

    public function testCtrlClickTogglesSelectionLikeSpace(): void
    {
        $m = $this->sized();
        $y = $this->rowOf($m, 'a.txt');
        [$m] = $this->click($m, 5, $y, ctrl: true);
        $this->assertArrayHasKey('a.txt', $m->activePane()->selected);
        [$m] = $this->click($m, 5, $y, ctrl: true);
        $this->assertSame([], $m->activePane()->selected);
    }

    public function testDoubleClickOnADirectoryEntersIt(): void
    {
        $m = $this->sized();
        $row = null;
        foreach ($m->activePane()->entries as $i => $e) {
            if ($e->name === 'sub') {
                $row = $i;
            }
        }
        [$m] = $this->click($m, 5, self::ROW0 + $row);
        $this->assertSame($this->tmp, $m->activePane()->cwd);
        $this->now += 0.2;
        [$m] = $this->click($m, 5, self::ROW0 + $row);
        $this->assertSame($this->tmp . '/sub', $m->activePane()->cwd);
    }

    public function testSlowSecondClickIsNotADoubleClick(): void
    {
        $m = $this->sized();
        [$m] = $this->click($m, 5, self::ROW0 + 1);
        $this->now += 1.0;
        [$m, $cmd] = $this->click($m, 5, self::ROW0 + 1);
        $this->assertNull($cmd);
        $this->assertSame($this->tmp, $m->activePane()->cwd);
    }

    public function testDoubleClickOnAFileSpawnsTheEditor(): void
    {
        putenv('EDITOR=/bin/cat');
        putenv('VISUAL=');
        $m = $this->sized();
        $y = $this->rowOf($m, 'a.txt');
        [$m] = $this->click($m, 5, $y);
        $this->now += 0.1;
        [, $cmd] = $this->click($m, 5, $y);
        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertInstanceOf(\SugarCraft\Core\ExecRequest::class, $cmd());
    }

    public function testClicksOnDifferentRowsNeverDouble(): void
    {
        $m = $this->sized();
        [$m] = $this->click($m, 5, self::ROW0 + 1);
        $this->now += 0.1;
        [$m, $cmd] = $this->click($m, 5, self::ROW0 + 2);
        $this->assertNull($cmd);
        $this->assertSame(2, $m->activePane()->cursor);
    }

    public function testReleaseMotionAndOtherButtonsAreInert(): void
    {
        $m = $this->sized();
        [$a] = $m->update(new MouseReleaseMsg(5, self::ROW0 + 2, MouseButton::Left, MouseAction::Release));
        [$b] = $m->update(new MouseMotionMsg(5, self::ROW0 + 2, MouseButton::Left, MouseAction::Motion));
        [$c] = $this->click($m, 5, self::ROW0 + 2, b: MouseButton::Right);
        $this->assertSame(0, $a->activePane()->cursor);
        $this->assertSame(0, $b->activePane()->cursor);
        $this->assertSame(0, $c->activePane()->cursor);
    }

    public function testModalFramesSwallowThePointer(): void
    {
        $m = $this->press($this->press($this->sized(), 'j'), 'd');
        $this->assertNotSame(ConfirmState::None, $m->confirm);
        [$after] = $this->click($m, 5, self::ROW0 + 2);
        $this->assertSame($m->confirm, $after->confirm);
        $this->assertSame(1, $after->activePane()->cursor);

        $help = $this->press($this->sized(), '?');
        [$h2] = $this->click($help, 5, self::ROW0 + 2);
        $this->assertSame(0, $h2->activePane()->cursor);

        $info = $this->press($this->sized(), 'I');
        [$i2] = $this->click($info, 5, self::ROW0 + 2);
        $this->assertNotNull($i2->infoLines);
        $this->assertSame(0, $i2->activePane()->cursor);

        $search = $this->press($this->sized(), '/');
        [$s2] = $this->click($search, 5, self::ROW0 + 2);
        $this->assertSame($search, $s2);
    }

    public function testTabBarClickSwitchesTab(): void
    {
        $m = $this->sized();
        $m = $this->press($m, 't');           // duplicate -> 2 tabs
        $this->assertCount(2, $m->tabs);
        $this->assertSame(1, $m->tabIndex);
        $cell1 = '1 ' . $this->tmp;           // first cell starts at column 1
        $this->assertStringContainsString('1 ', explode("\n", $m->view())[0]);
        [$m] = $this->click($m, 2, 1);
        $this->assertSame(0, $m->tabIndex);
        unset($cell1);
    }

    public function testRowGeometryShiftsDownUnderTheTabBar(): void
    {
        $m = $this->press($this->sized(), 't');
        $this->assertSame(['kind' => 'row', 'side' => 0, 'index' => 1], Renderer::hit($m, 5, self::ROW0 + 2));
        $this->assertSame(['kind' => 'pane', 'side' => 0], Renderer::hit($m, 5, 2));
    }

    public function testWindowedPaneHitMapsThroughTheScrollOffset(): void
    {
        for ($i = 0; $i < 30; $i++) {
            file_put_contents(sprintf('%s/f%02d.dat', $this->tmp, $i), 'x');
        }
        $m = $this->sized(80, 12);
        for ($i = 0; $i < 20; $i++) {
            $m = $this->press($m, 'j');
        }
        $lines = explode("\n", $m->view());
        $cursor = $m->activePane()->cursor;
        $hitRow = null;
        foreach ($lines as $n => $line) {
            if (str_contains(mb_substr($line, 0, 39), '▸')) {
                $hitRow = $n + 1;
                break;
            }
        }
        $this->assertNotNull($hitRow);
        $hit = Renderer::hit($m, 5, $hitRow);
        $this->assertSame(['kind' => 'row', 'side' => 0, 'index' => $cursor], $hit, 'the row carrying the cursor glyph maps back to the cursor entry');
    }

    public function testBinRequestsMouseReporting(): void
    {
        $bin = (string) file_get_contents(__DIR__ . '/../bin/candy-files');
        $this->assertStringContainsString('mouseMode: MouseMode::CellMotion', $bin);
    }
}
