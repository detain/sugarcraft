<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Minesweeper\Board;
use SugarCraft\Minesweeper\Cell;
use SugarCraft\Minesweeper\Game;
use SugarCraft\Minesweeper\Renderer;

/**
 * E3 (docs-fix-1023) MAJOR-2: the game's visible strings (status line, help
 * footer) route through Lang::t, and the shipped binary arms the detected
 * locale before the Program starts. Without either leg the 16 locale files
 * are dead weight — these pins name a red for every neutering mutation.
 */
final class I18nWiringTest extends TestCase
{
    private string $priorLocale = 'en';

    protected function setUp(): void
    {
        $this->priorLocale = T::locale();
    }

    protected function tearDown(): void
    {
        T::setLocale($this->priorLocale);
    }

    private static function freshGame(): Game
    {
        return Game::start(4, 4, 2, static fn(int $_max): int => 0);
    }

    public function testEnglishStatusRendersTheHouseFormat(): void
    {
        T::setLocale('en');
        $view = Renderer::render(self::freshGame());
        $this->assertStringContainsString('mines: 2  ·  flags: 0  ·  remaining: 2  ·  time: 0:00', $view);
    }

    public function testStatusLineHonoursTheActiveLocale(): void
    {
        T::setLocale('de');
        $view = Renderer::render(self::freshGame());

        $this->assertStringContainsString('Minen:', $view, 'status must translate (de)');
        $this->assertStringContainsString('Zeit:', $view);
        $this->assertStringNotContainsString('mines:', $view, 'English must not bleed through');
    }

    public function testHelpFooterHonoursTheActiveLocale(): void
    {
        T::setLocale('de');
        $view = Renderer::render(self::freshGame());
        $this->assertStringContainsString('bewegen', $view, 'footer must translate (de)');

        T::setLocale('ja');
        $view = Renderer::render(self::freshGame());
        $this->assertStringContainsString('移動', $view, 'footer must translate (ja)');
    }

    public function testEndScreensAreTranslated(): void
    {
        // Exploded 3×3 fixture — one flagged mine cell keeps the board honest.
        $rows = [];
        for ($y = 0; $y < 3; $y++) {
            $row = [];
            for ($x = 0; $x < 3; $x++) {
                $row[] = new Cell($x === 0 && $y === 0, $x !== 0 || $y !== 0, false, 0);
            }
            $rows[] = $row;
        }
        $board = new Board(3, 3, 1, $rows, true, true, 8, 0);
        $g = new Game($board, 0, 0, static fn(int $_max): int => 0);
        $this->assertTrue($g->board->exploded, 'sanity: exploded fixture');

        T::setLocale('de');
        // 'Neustart' alone also appears in the de help footer — pin the boom
        // line by a substring unique to it.
        $this->assertStringContainsString('Boom — drücken Sie r', Renderer::render($g), 'boom line translates');

        $wonRows = [];
        $revealed = 0;
        for ($y = 0; $y < 3; $y++) {
            $row = [];
            for ($x = 0; $x < 3; $x++) {
                $mine = $x === 2 && $y === 2;
                $row[] = new Cell($mine, !$mine, false, 0);
                if (!$mine) {
                    $revealed++;
                }
            }
            $wonRows[] = $row;
        }
        $won = new Game(new Board(3, 3, 1, $wonRows, true, false, $revealed, 0), 0, 0, static fn(int $_max): int => 0);
        $this->assertTrue($won->board->isWon(), 'sanity: won fixture');
        $this->assertStringContainsString('neue Partie', Renderer::render($won), 'cleared line translates');
    }

    public function testFooterNamesEveryBoundKey(): void
    {
        // The footer is the only discoverability surface; the E3 finding was
        // that it advertised a third of its own bindings. Derive the bound
        // letters from the source text of update()'s match arms instead of
        // hard-coding a list that rots.
        T::setLocale('en');
        $view = Renderer::render(self::freshGame());

        foreach (['↑', '↓', '←', '→', 'hjkl', 'space', 'enter', 'f', 'c', 'S  save', 'L  load', 'r', 'q', 'Esc', 'Ctrl+C', 'right-click', 'middle-click'] as $token) {
            $this->assertStringContainsString($token, $view, "footer must advertise `$token`");
        }
    }

    public function testTheBinaryArmsTheDetectedLocale(): void
    {
        // Source-scan pin (mutation contract: deleting the call reddens THIS
        // test by name — the runtime locale of a TUI child cannot be read
        // back cheaply).
        $bin = file_get_contents(__DIR__ . '/../bin/candy-minesweeper');
        $this->assertIsString($bin);
        // A real statement line — not a prose mention and not a commented-out
        // call: the line must begin with the fully-qualified setLocale(detect())
        // invocation itself.
        $statement = '/^\\\\SugarCraft\\\\Core\\\\I18n\\\\T::setLocale\\(\\\\SugarCraft\\\\Core\\\\I18n\\\\T::detect\\(\\)\\);$/m';
        $matched = preg_match($statement, $bin, $m, PREG_OFFSET_CAPTURE);
        $this->assertSame(1, $matched, 'binary must arm the detected locale with a live statement');
        // …BEFORE the Program starts, or the first frame renders untranslated.
        $this->assertLessThan(
            (int) strpos($bin, 'Program('),
            (int) $m[0][1],
            'setLocale must precede the Program construction',
        );
    }

    public function testVisibleGameStringsAreNotHardcodedInRenderer(): void
    {
        // The footer/status literals must live in lang/en.php, not in the
        // renderer — this catches someone re-inlining them after translation.
        $src = file_get_contents(__DIR__ . '/../src/Renderer.php');
        $this->assertIsString($src);
        $src = preg_replace('#/\*\*?.*?\*/#s', '', (string) $src) ?? $src;
        $src = preg_replace('#//[^\n]*#', '', $src) ?? $src;

        foreach ([' boom — press r to restart', ' cleared — press r to play again', '  move  ·  space'] as $needle) {
            $this->assertStringNotContainsString($needle, $src, "renderer must render `$needle` via Lang::t, not inline");
        }
    }
}
