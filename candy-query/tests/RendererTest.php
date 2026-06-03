<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Query\App;
use SugarCraft\Query\Database;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    private function db(): Database
    {
        $db = new Database(new \PDO('sqlite::memory:'));
        $db->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $db->pdo->exec("INSERT INTO users VALUES (1, 'alice'), (2, 'bob')");
        return $db;
    }

    public function testRenderIncludesTitleHeader(): void
    {
        $out = Renderer::render(App::start($this->db()));
        $this->assertStringContainsString('SugarSQL', $out);
    }

    public function testRenderShowsTablesAndRows(): void
    {
        $out = Renderer::render(App::start($this->db()));
        $this->assertStringContainsString('users', $out);
        $this->assertStringContainsString('alice', $out);
        $this->assertStringContainsString('bob', $out);
    }

    public function testRenderShowsHelpFooter(): void
    {
        $out = Renderer::render(App::start($this->db()));
        $this->assertStringContainsString('switch pane', $out);
        $this->assertStringContainsString('run query', $out);
    }

    public function testRenderShowsEmptyState(): void
    {
        $emptyDb = new Database(new \PDO('sqlite::memory:'));
        $a = App::start($emptyDb);
        $out = Renderer::render($a);
        $this->assertStringContainsString('no tables', $out);
        $this->assertStringContainsString('empty', $out);
    }

    public function testRenderShowsQueryPromptHint(): void
    {
        $out = Renderer::render(App::start($this->db()));
        $this->assertStringContainsString('type SQL', $out);
    }

    /**
     * Build a DB whose single table holds a binary BLOB value containing raw
     * control bytes (NUL, BEL, a stray ESC), mimicking a geometry/blob column.
     */
    private function binaryDb(): Database
    {
        $db = new Database(new \PDO('sqlite::memory:'));
        $db->pdo->exec('CREATE TABLE locs (id INTEGER PRIMARY KEY, name TEXT, coords BLOB)');
        $stmt = $db->pdo->prepare('INSERT INTO locs (id, name, coords) VALUES (1, ?, ?)');
        $stmt->execute(['TEB1', "\x00\x00\x01\x1b[31m}\xcd\x91dD@\x07R"]);
        return $db;
    }

    /**
     * Regression: raw control bytes from binary columns must never reach the
     * frame. A stray ESC (0x1b) injects a bogus escape sequence that desyncs
     * the frame-diff renderer; NUL/BEL garble the terminal and beep.
     */
    public function testRenderSanitizesControlBytesFromBlobData(): void
    {
        try {
            Renderer::setSize(200, 50);
            $out = Renderer::render(App::start($this->binaryDb(), Flavor::MySQL));
            // Strip valid SGR colour sequences the renderer legitimately emits.
            $noSgr = preg_replace('/\x1b\[[0-9;]*m/', '', $out);
            $this->assertSame(0, substr_count($noSgr, "\x1b"), 'no stray ESC bytes');
            $this->assertSame(0, substr_count($noSgr, "\x00"), 'no NUL bytes');
            $this->assertSame(0, substr_count($noSgr, "\x07"), 'no BEL bytes');
        } finally {
            Renderer::resetSizeCache();
        }
    }

    /**
     * Regression: no rendered line may exceed the terminal width. Over-wide
     * lines wrap, which breaks the diff renderer's one-line-per-row model and
     * cascades into the screen corruption reported with binary/wide data.
     */
    public function testNoRenderedLineExceedsTerminalWidth(): void
    {
        foreach ([[80, 24], [120, 40], [200, 50]] as [$cols, $rows]) {
            try {
                Renderer::setSize($cols, $rows);
                $out = Renderer::render(App::start($this->binaryDb(), Flavor::MySQL));
                foreach (explode("\n", $out) as $i => $line) {
                    $this->assertLessThanOrEqual(
                        $cols,
                        Width::string($line),
                        "line $i exceeds $cols cols at {$cols}x{$rows}",
                    );
                }
            } finally {
                Renderer::resetSizeCache();
            }
        }
    }

    /**
     * Build a DB whose values are long enough to force per-cell and per-line
     * truncation, exercising the BorderFrame truncation -> pad path.
     */
    private function wideDb(): Database
    {
        $db = new Database(new \PDO('sqlite::memory:'));
        $db->pdo->exec('CREATE TABLE big (id INTEGER PRIMARY KEY, note TEXT, email TEXT)');
        $stmt = $db->pdo->prepare('INSERT INTO big (id, note, email) VALUES (?, ?, ?)');
        for ($i = 0; $i < 40; $i++) {
            $stmt->execute([
                $i + 1,
                'a_very_long_value_that_overflows_the_column_budget_' . str_repeat('x', 40),
                "user{$i}@a-very-long-domain-name.example.com",
            ]);
        }
        // Pad the table list so it scrolls.
        for ($i = 0; $i < 60; $i++) {
            $db->pdo->exec("CREATE TABLE extra_table_number_{$i} (id INTEGER)");
        }
        return $db;
    }

    /**
     * Regression: the frame must fill EXACTLY the terminal — every line is
     * exactly `cols` cells and the frame is exactly `rows` lines. A line that
     * is short misplaces the right border `║` ("break on the right side"); a
     * frame shorter than `rows` leaves blank rows at the bottom; one taller
     * scrolls the alt-screen and desyncs the diff renderer. Cover sizes and
     * cursor positions that force both cell- and line-level truncation.
     */
    public function testFrameFillsTerminalExactly(): void
    {
        foreach ([[80, 24], [100, 30], [141, 47], [200, 50]] as [$cols, $rows]) {
            try {
                Renderer::setSize($cols, $rows);
                $a = App::start($this->wideDb(), Flavor::Sqlite);
                foreach ([0, 15, 40, 90] as $down) {
                    $b = $a;
                    for ($k = 0; $k < $down; $k++) {
                        [$b] = $b->update(new KeyMsg(KeyType::Down, ''));
                    }
                    $lines = explode("\n", Renderer::render($b));
                    $this->assertCount(
                        $rows,
                        $lines,
                        "frame must be exactly $rows lines at {$cols}x{$rows} (cursor $down)",
                    );
                    foreach ($lines as $i => $line) {
                        $this->assertSame(
                            $cols,
                            Width::string($line),
                            "line $i must be exactly $cols cols at {$cols}x{$rows} (cursor $down)",
                        );
                    }
                }
            } finally {
                Renderer::resetSizeCache();
            }
        }
    }

    public function testRenderShowsErrorBanner(): void
    {
        $a = App::start($this->db());
        // Switch to query pane and submit an invalid SQL.
        [$a] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a] = $a->update(new KeyMsg(KeyType::Tab, ''));
        foreach (str_split('NOPE') as $c) {
            [$a] = $a->update(new KeyMsg(KeyType::Char, $c));
        }
        [$a] = $a->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $out = Renderer::render($a);
        $this->assertStringContainsString('error', $out);
    }

    /**
     * adminContentWidth() is the single source of truth for the admin page
     * content column (shared by adminPane() + DashboardPage::build()). Pure
     * function of the terminal width: inner = max(20, cols-6), minus the
     * sidebar max(20, cols/4) minus a 2-col gap, floored at 10 so a tiny
     * terminal still renders.
     */
    public function testAdminContentWidthBudget(): void
    {
        $this->assertSame(142, Renderer::adminContentWidth(200));
        $this->assertSame(52, Renderer::adminContentWidth(80));
        $this->assertSame(12, Renderer::adminContentWidth(40));
        // Every term clamps at a tiny width, so the result floors at 10.
        $this->assertSame(10, Renderer::adminContentWidth(24));
        $this->assertSame(10, Renderer::adminContentWidth(1));
    }
}
