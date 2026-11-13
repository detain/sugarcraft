<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Minesweeper\Board;
use SugarCraft\Minesweeper\Cell;
use SugarCraft\Minesweeper\Difficulty;
use SugarCraft\Minesweeper\Game;
use SugarCraft\Minesweeper\Renderer;
use SugarCraft\Testing\Snapshot\Assertions;
use PHPUnit\Framework\TestCase;

final class GoldenRenderTest extends TestCase
{
    private string $fixturesDir;

    private string $priorLocale = 'en';

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/fixtures';
        // n04 (lib-reviews-1102): goldens embed the ENGLISH help/status copy,
        // so they are EN by contract — pin the locale instead of inheriting
        // whatever LANG/LC_ALL the runner happens to carry.
        $this->priorLocale = \SugarCraft\Core\I18n\T::locale();
        \SugarCraft\Core\I18n\T::setLocale('en');
    }

    protected function tearDown(): void
    {
        \SugarCraft\Core\I18n\T::setLocale($this->priorLocale);
    }

    public function testFirstClickSafeAreaRendersAnsi(): void
    {
        $g = Game::withDifficulty(Difficulty::Easy, static fn(int $max): int => 0);

        $keySpace = new KeyMsg(KeyType::Space, '');
        [$g] = $g->update($keySpace);

        $output = Renderer::render($g);

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/mines-first-click-safe-area.golden',
            $output,
        );
    }

    public function testCascadeRevealRendersAnsi(): void
    {
        $g = Game::withDifficulty(Difficulty::Easy, static fn(int $max): int => 0);

        $keySpace = new KeyMsg(KeyType::Space, '');
        [$g] = $g->update($keySpace);

        $b = $g->board;
        for ($y = 0; $y < $b->height; $y++) {
            for ($x = 0; $x < $b->width; $x++) {
                $c = $b->cell($x, $y);
                if ($c !== null && $c->adjacent === 0 && !$c->mine) {
                    while ($g->cursorX !== $x) {
                        [$g] = $g->update($g->cursorX < $x ? new KeyMsg(KeyType::Right, '') : new KeyMsg(KeyType::Left, ''));
                    }
                    while ($g->cursorY !== $y) {
                        [$g] = $g->update($g->cursorY < $y ? new KeyMsg(KeyType::Down, '') : new KeyMsg(KeyType::Up, ''));
                    }
                    [$g] = $g->update($keySpace);
                    break 2;
                }
            }
        }

        $output = Renderer::render($g);

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/mines-cascade-reveal.golden',
            $output,
        );
    }

    public function testWinOverlayRendersAnsi(): void
    {
        $g = Game::withDifficulty(Difficulty::Easy, static fn(int $max): int => 0);

        $keySpace = new KeyMsg(KeyType::Space, '');
        [$g] = $g->update($keySpace);

        $b = $g->board;
        $toReveal = [];
        for ($y = 0; $y < $b->height; $y++) {
            for ($x = 0; $x < $b->width; $x++) {
                $c = $b->cell($x, $y);
                if ($c !== null && !$c->mine && !$c->revealed) {
                    $toReveal[] = [$x, $y];
                }
            }
        }

        foreach ($toReveal as [$x, $y]) {
            while ($g->cursorX !== $x) {
                [$g] = $g->update($g->cursorX < $x ? new KeyMsg(KeyType::Right, '') : new KeyMsg(KeyType::Left, ''));
            }
            while ($g->cursorY !== $y) {
                [$g] = $g->update($g->cursorY < $y ? new KeyMsg(KeyType::Down, '') : new KeyMsg(KeyType::Up, ''));
            }
            [$g] = $g->update($keySpace);
            if ($g->board->isWon()) {
                break;
            }
        }

        $this->assertTrue($g->board->isWon(), 'Should reach win state with deterministic rand');

        $output = Renderer::render($g);

        $this->assertNotEmpty($output);
        $this->assertStringContainsString('cleared', $output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/mines-win-overlay.golden',
            $output,
        );
    }

    public function testResolveClickReturnsCorrectCell(): void
    {
        $g = Game::withDifficulty(Difficulty::Easy, static fn(int $max): int => 0);

        $keySpace = new KeyMsg(KeyType::Space, '');
        [$g] = $g->update($keySpace);

        [, $scanner] = Renderer::renderWithScanner($g);

        $b = $g->board;
        for ($y = 0; $y < $b->height; $y++) {
            for ($x = 0; $x < $b->width; $x++) {
                $c = $b->cell($x, $y);
                if ($c !== null && !$c->mine && $c->revealed) {
                    $zoneId = 'cell:' . $y . ':' . $x;
                    $zone = $scanner->get($zoneId);
                    $this->assertNotNull($zone, "Zone {$zoneId} should exist in scanner");

                    $result = Renderer::resolveClick($g, $zone->startCol, $zone->startRow);
                    $this->assertNotNull($result, "resolveClick should hit cell at screen ({$zone->startCol}, {$zone->startRow})");
                    [$col, $row] = $result;
                    $this->assertSame($x, $col, 'resolved col should match board x');
                    $this->assertSame($y, $row, 'resolved row should match board y');
                    return;
                }
            }
        }
        $this->fail('No revealed non-mine cell found to test resolveClick');
    }

    public function testResolveClickReturnsNullForOutOfBounds(): void
    {
        $g = Game::withDifficulty(Difficulty::Easy, static fn(int $max): int => 0);

        $result = Renderer::resolveClick($g, -1, -1);
        $this->assertNull($result, 'Out-of-bounds click should return null');

        $result = Renderer::resolveClick($g, 999, 999);
        $this->assertNull($result, 'Out-of-bounds click should return null');
    }

    /**
     * The malformed-zone guard in Renderer::resolveClick() (C1 fix,
     * lib-reviews-1103). The pre-fix version of this test tampered a scanner
     * it had built itself and then invoked resolveClick WITHOUT it — the
     * method rescanned a pristine board, so the null it returned came from
     * the ordinary out-of-zone path and the regex guard never executed.
     * resolveClick() now takes the scanner as an injection seam, the
     * tampered zone is genuinely hit, and the guard decides the outcome.
     */
    public function testResolveClickReturnsNullForMalformedZoneId(): void
    {
        $g = Game::withDifficulty(Difficulty::Easy, static fn(int $max): int => 0);
        [$output, $scanner] = Renderer::renderWithScanner($g);
        $this->assertNotEmpty($output, 'sanity: the game renders');

        $zonesProp = new \ReflectionProperty(\SugarCraft\Mouse\Scanner::class, 'zones');
        /** @var array<string, \SugarCraft\Mouse\Zone> $zones */
        $zones = $zonesProp->getValue($scanner);
        $this->assertArrayHasKey('cell:0:0', $zones, 'sanity: scanner carries cell zones');
        $real = $zones['cell:0:0'];

        $positive = Renderer::resolveClick($g, $real->startCol, $real->startRow, $scanner);
        $this->assertSame([0, 0], $positive, 'sanity: the click coordinates hit cell (0,0) on the injected scan');

        // Replace the zone covering that coordinate with one whose id
        // matches no "cell:r:c" pattern on a FRESH scan of the same layout
        // (Scanner::hit() memoises its lookup grid at first touch — the
        // positive leg above has already warmed this object's, and the
        // Renderer reuses one Scanner per board). Without the regex guard
        // the hit would resolve to a guessed [0, 0] from uninitialized
        // matches — the null below can only come from the guard.
        $g2 = Game::withDifficulty(Difficulty::Easy, static fn(int $max): int => 0);
        [, $tampered] = Renderer::renderWithScanner($g2);
        $zones = $zonesProp->getValue($tampered);
        unset($zones['cell:0:0']);
        $zones['malformed-zone-id'] = new \SugarCraft\Mouse\Zone(
            'malformed-zone-id',
            $real->startCol,
            $real->startRow,
            $real->endCol,
            $real->endRow,
        );
        $zonesProp->setValue($tampered, $zones);

        $result = Renderer::resolveClick($g, $real->startCol, $real->startRow, $tampered);
        $this->assertNull($result, 'a malformed zone id must resolve to nothing, not a guess');
    }

    public function testOneScannerIsReusedPerBoardAndNotAcrossBoards(): void
    {
        // msw-10/-014 pin: the click path holds one scan per board object.
        $g = Game::withDifficulty(Difficulty::Easy, static fn(int $max): int => 0);
        [, $first] = Renderer::renderWithScanner($g);
        [, $again] = Renderer::renderWithScanner($g);
        $this->assertSame($first, $again, 'the same board must not pay a second full scan');

        $g2 = Game::withDifficulty(Difficulty::Easy, static fn(int $max): int => 0);
        [, $other] = Renderer::renderWithScanner($g2);
        $this->assertNotSame($first, $other, 'a different board object re-scans');

        // A board mutation invalidates through identity: revealing re-scans.
        [$moved, ] = $g->update(new KeyMsg(KeyType::Space, ''));
        [, $afterMove] = Renderer::renderWithScanner($moved);
        $this->assertNotSame($first, $afterMove, 'the new board instance must not ride the stale scan');
    }

    public function testRenderWithScannerReturnsScanner(): void
    {
        $g = Game::withDifficulty(Difficulty::Easy, static fn(int $max): int => 0);

        [$output, $scanner] = Renderer::renderWithScanner($g);

        $this->assertNotEmpty($output);
        $this->assertInstanceOf(\SugarCraft\Mouse\Scanner::class, $scanner);
    }

    public function testExplodedBoardRendersAnsi(): void
    {
        $g = Game::withDifficulty(Difficulty::Easy, static fn(int $max): int => 0);

        $keySpace = new KeyMsg(KeyType::Space, '');
        [$g] = $g->update($keySpace);

        $b = $g->board;
        for ($y = 0; $y < $b->height; $y++) {
            for ($x = 0; $x < $b->width; $x++) {
                $c = $b->cell($x, $y);
                if ($c !== null && $c->mine && !$c->revealed) {
                    while ($g->cursorX !== $x) {
                        [$g] = $g->update($g->cursorX < $x ? new KeyMsg(KeyType::Right, '') : new KeyMsg(KeyType::Left, ''));
                    }
                    while ($g->cursorY !== $y) {
                        [$g] = $g->update($g->cursorY < $y ? new KeyMsg(KeyType::Down, '') : new KeyMsg(KeyType::Up, ''));
                    }
                    [$g] = $g->update($keySpace);
                    if ($g->board->exploded) {
                        break 2;
                    }
                }
            }
        }

        $this->assertTrue($g->board->exploded, 'Should trigger explosion with deterministic rand');

        $output = Renderer::render($g);

        $this->assertNotEmpty($output);
        $this->assertStringContainsString('boom', $output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/mines-exploded.golden',
            $output,
        );
    }
}
