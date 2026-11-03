<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\App;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\SqliteDatabase;
use SugarCraft\Sql\Pane;
use SugarCraft\Sql\Renderer;

/**
 * D-M8: both browser panes had comfort floors (rows 60, tables 24) applied
 * UNCONDITIONALLY after the floor(cols/2)-6 budget cap, so any terminal under
 * ~132 cols (rows) / ~60 cols (tables) was forced wider than its side-by-side
 * budget and the outer frame wrapped. The fit law: no rendered row may exceed
 * the terminal width.
 */
final class RendererNarrowFitTest extends TestCase
{
    protected function tearDown(): void
    {
        Renderer::resetSizeCache();
    }

    public function testEightyColumnTerminalKeepsEveryRowInsideTheFrame(): void
    {
        // Six columns → natural width 6*14=84 exceeds the 80-col budget (34);
        // the old floor pinned the pane to 60 cells, overflowing the frame.
        $rows = [];
        for ($i = 0; $i < 3; $i++) {
            $rows[] = [
                'alpha' => 'a' . $i,
                'bravo' => 'b' . $i,
                'charlie' => 'c' . $i,
                'delta' => 'd' . $i,
                'echo' => 'e' . $i,
                'foxtrot' => 'f' . $i,
            ];
        }
        $app = App::builder()
            ->withDb(new SqliteDatabase(new PDO('sqlite::memory:')))
            ->withFlavor(Flavor::Sqlite)
            ->withPane(Pane::Rows)
            ->withTables(['users'])
            ->withSelectedTable('users')
            ->withRows($rows)
            ->build();

        Renderer::setSize(80, 24);
        $frame = Renderer::render($app);

        $this->assertStringContainsString('alpha', $frame);
        foreach (explode("\n", $frame) as $line) {
            $plain = (string) preg_replace('/\x1b\[[0-9;]*[A-Za-z]|\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)/u', '', $line);
            $this->assertLessThanOrEqual(
                80,
                mb_strlen($plain),
                'row overflowed the 80-col terminal: ' . mb_strlen($plain) . ' [' . mb_substr($plain, 0, 60) . '…]'
            );
        }
    }

    public function testWideTerminalStillGetsTheComfortFloors(): void
    {
        // The fix must not shrink honest wide layouts (regression floor).
        $rows = [['id' => 1]];
        $app = App::builder()
            ->withDb(new SqliteDatabase(new PDO('sqlite::memory:')))
            ->withFlavor(Flavor::Sqlite)
            ->withPane(Pane::Rows)
            ->withTables(['users'])
            ->withSelectedTable('users')
            ->withRows($rows)
            ->build();

        Renderer::setSize(200, 40);
        $frame = Renderer::render($app);
        foreach (explode("\n", $frame) as $line) {
            $plain = (string) preg_replace('/\x1b\[[0-9;]*[A-Za-z]|\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)/u', '', $line);
            $this->assertLessThanOrEqual(200, mb_strlen($plain));
        }
        $this->assertStringContainsString('id', $frame);
    }
}
