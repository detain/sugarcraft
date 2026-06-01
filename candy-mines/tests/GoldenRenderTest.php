<?php

declare(strict_types=1);

namespace SugarCraft\Mines\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Mines\Board;
use SugarCraft\Mines\Cell;
use SugarCraft\Mines\Difficulty;
use SugarCraft\Mines\Game;
use SugarCraft\Mines\Renderer;
use SugarCraft\Testing\Snapshot\Assertions;
use PHPUnit\Framework\TestCase;

final class GoldenRenderTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/fixtures';
    }

    public function testFirstClickSafeAreaRendersAnsi(): void
    {
        $g = Game::withDifficulty(Difficulty::EASY, static fn(int $max): int => 0);

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
        $g = Game::withDifficulty(Difficulty::EASY, static fn(int $max): int => 0);

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
        $g = Game::withDifficulty(Difficulty::EASY, static fn(int $max): int => 0);

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
        $g = Game::withDifficulty(Difficulty::EASY, static fn(int $max): int => 0);

        $keySpace = new KeyMsg(KeyType::Space, '');
        [$g] = $g->update($keySpace);

        [$output, $scanner] = Renderer::renderWithScanner($g);

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
        $g = Game::withDifficulty(Difficulty::EASY, static fn(int $max): int => 0);

        $result = Renderer::resolveClick($g, -1, -1);
        $this->assertNull($result, 'Out-of-bounds click should return null');

        $result = Renderer::resolveClick($g, 999, 999);
        $this->assertNull($result, 'Out-of-bounds click should return null');
    }

    public function testRenderWithScannerReturnsScanner(): void
    {
        $g = Game::withDifficulty(Difficulty::EASY, static fn(int $max): int => 0);

        [$output, $scanner] = Renderer::renderWithScanner($g);

        $this->assertNotEmpty($output);
        $this->assertInstanceOf(\SugarCraft\Mouse\Scanner::class, $scanner);
    }

    public function testExplodedBoardRendersAnsi(): void
    {
        $g = Game::withDifficulty(Difficulty::EASY, static fn(int $max): int => 0);

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
