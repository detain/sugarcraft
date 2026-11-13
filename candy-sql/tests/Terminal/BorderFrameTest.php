<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Terminal;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Sql\App;
use SugarCraft\Sql\App\AdminState;
use SugarCraft\Sql\App\BrowseState;
use SugarCraft\Sql\App\ConnectionState;
use SugarCraft\Sql\App\UiState;
use SugarCraft\Sql\Admin\AdminPane;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Pane;
use SugarCraft\Sql\Renderer;
use SugarCraft\Sql\Terminal\BorderFrame;
use SugarCraft\Sql\Tests\Admin\FakeDatabase;

/**
 * client-019: BorderFrame's 138 lines were exercised only through renderer
 * snapshots — direct unit cases for the title/status builders and the
 * tiny-terminal clamp branches.
 */
final class BorderFrameTest extends TestCase
{
    protected function tearDown(): void
    {
        // The Renderer size cache is process-static; hand the next test class
        // back a virgin detection path.
        $prop = new \ReflectionProperty(Renderer::class, 'terminalSize');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    private function app(Pane $pane = Pane::Tables, AdminPane $adminPane = AdminPane::ProcessList, bool $paused = false): App
    {
        return new App(
            ConnectionState::new(new FakeDatabase(), Flavor::MySQL, null, ''),
            BrowseState::new()->withTables(['alpha', 'beta']),
            ui: UiState::new()->withPane($pane),
            admin: AdminState::new()->withPane($adminPane)->withPaused($paused),
        );
    }

    private function titleBar(App $a): string
    {
        $m = new ReflectionMethod(BorderFrame::class, 'buildTitleBar');
        $m->setAccessible(true);
        return (string) $m->invoke(null, $a);
    }

    private function statusBar(App $a): string
    {
        $m = new ReflectionMethod(BorderFrame::class, 'buildStatusBar');
        $m->setAccessible(true);
        return (string) $m->invoke(null, $a);
    }

    private function frame(App $a, int $cols, int $rows): string
    {
        [$a2,] = $a->update(new WindowSizeMsg($cols, $rows));
        return BorderFrame::wrap($a2, 'body');
    }

    public function testTitleBarUsesThePackageCasingOnce(): void
    {
        // client-023: chrome brand is the package name verbatim.
        $out = $this->titleBar($this->app());
        $this->assertStringContainsString('candy-sql', $out);
        $this->assertStringNotContainsString('CandySQL', $out);
        $this->assertStringContainsString('Tables: 2', $out);
    }

    public function testTitleBarOmitsEmptyDsnAndShowsFlavorFallbackVersion(): void
    {
        $out = $this->titleBar($this->app());
        // FakeDatabase answers an empty dsn — the segment must be absent,
        // and the version falls back to the flavor without a serverContext.
        $this->assertStringContainsString('mysql', $out);
        $this->assertStringNotContainsString('│ │', $out);
    }

    public function testStatusBarIsPaneAware(): void
    {
        $this->assertStringContainsString('Enter:load', $this->statusBar($this->app(Pane::Tables)));
        $this->assertStringNotContainsString('Ctrl+R:run', $this->statusBar($this->app(Pane::Tables)));
        $this->assertStringContainsString('Ctrl+R:run', $this->statusBar($this->app(Pane::Query)));
        $this->assertStringContainsString('q:quit', $this->statusBar($this->app(Pane::Query)));
    }

    public function testAdminSegmentReadsTheLabelMapNotTheRawEnumValue(): void
    {
        // client-023: chrome reads like the docs — a label, not `processlist`.
        $out = $this->statusBar($this->app(Pane::Admin, AdminPane::ProcessList));
        $this->assertStringContainsString('[Process List]', $out);
        $this->assertStringNotContainsString('[admin:processlist]', $out);
        $this->assertStringContainsString('1-' . count(AdminPane::orderedCases()) . ':select', $out);

        $queryStats = $this->statusBar($this->app(Pane::Admin, AdminPane::QueryStats));
        $this->assertStringContainsString('[Query Stats]', $queryStats);
    }

    public function testPausedIndicatorArmsOnlyOnAdminPane(): void
    {
        $this->assertStringContainsString('[PAUSED]', $this->statusBar($this->app(Pane::Admin, paused: true)));
        $this->assertStringNotContainsString('[PAUSED]', $this->statusBar($this->app(Pane::Tables, paused: true)));
    }

    public function testWrapClampsDegenerateWidths(): void
    {
        // corner/clamp legs: tiny widths truncate instead of crash — every
        // case returns a non-empty exact-height frame.
        foreach ([1, 2, 6, 7, 80] as $cols) {
            $out = $this->frame($this->app(), $cols, 6);
            $this->assertNotSame('', $out, "wrap at {$cols}x6");
        }
    }

    public function testWrapRefusesUnderMinimumHeight(): void
    {
        // The frame chrome needs 6 rows (Frame's fail-loud floor); the
        // adapter must surface that refusal, not a half-painted screen.
        $this->expectException(\InvalidArgumentException::class);
        $this->frame($this->app(), 80, 3);
    }

    public function testWrapCarriesTitleAndStatusIntoTheFrame(): void
    {
        $out = $this->frame($this->app(Pane::Query), 100, 8);
        $this->assertStringContainsString('candy-sql', $out);
        $this->assertStringContainsString('Ctrl+R:run', $out);
        $this->assertStringContainsString('body', $out);
    }
}
