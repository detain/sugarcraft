<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Tetris\Score;
use SugarCraft\Tetris\Tetromino;
use SugarCraft\Tetris\VsGame;
use SugarCraft\Tetris\VsRenderer;

/**
 * MA-9: the VS frame used to show score/lines/level and nothing else —
 * no next preview, no hold state (while c-hold was live!), no keys —
 * while the README disclosed only the hold gap. The match strip under
 * both boards now carries all of it.
 */
final class VsStripTest extends TestCase
{
    public function testStripShowsThreePieceNextPreview(): void
    {
        $vs    = VsGame::start();
        $frame = VsRenderer::render($vs);

        self::assertStringContainsString('next:', $frame);
        // Whatever the live queue holds, all three kinds must be on the
        // strip in order (the bag itself is randomised, so derive).
        $letters = 'next: ' . implode('  ', array_map(
            static fn(Tetromino $kind): string => $kind->value,
            $vs->player->bag->peek(3),
        ));
        self::assertStringContainsString($letters, $frame);
    }

    public function testStripShowsHoldStateAndUsedCue(): void
    {
        $vs  = VsGame::start();
        // A real c-hold through the player model lands the piece and
        // exhausts the slot, so the strip must word the state twice.
        $row = $vs->player->mutate(['hold' => Tetromino::T, 'canHold' => false]);
        $mutate = new \ReflectionMethod(VsGame::class, 'mutate');
        $mutate->setAccessible(true);
        $armed = $mutate->invoke($vs, ['player' => $row]);

        $frame = VsRenderer::render($armed);
        self::assertStringContainsString('hold: T (used)', $frame);

        $empty = $mutate->invoke(VsGame::start(), ['player' => $vs->player->mutate(['hold' => null])]);
        self::assertStringContainsString('hold: -', VsRenderer::render($empty));
    }

    public function testStripCarriesKeyListAndBothScores(): void
    {
        $frame = VsRenderer::render(VsGame::start());

        // candy-tetris-015: the strip teaches the FULL live table (the old
        // one-liner showed 5 of the 11 bindings and the movement keys were
        // never named) and one line of the garbage exchange rule.
        self::assertStringContainsString('← → move  ↑ x rotate cw  z rotate ccw', $frame);
        self::assertStringContainsString('↓ soft drop  spc hard drop  c hold', $frame);
        self::assertStringContainsString('p pause  r restart (over)  q ctrl+c quit', $frame);
        self::assertStringContainsString('clear lines — the opponent gets rows with one hole', $frame);
        self::assertStringContainsString('you       score: 0', $frame);
        self::assertStringContainsString('computer  score: 0', $frame);

        // The rows must be sourced per-side: both scores identical by
        // construction would hide a player/computer wiring swap.
        $asym = new \ReflectionMethod(VsGame::class, 'mutate');
        $asym->setAccessible(true);
        $skewed = $asym->invoke(VsGame::start(), [
            'computer' => VsGame::start()->computer->mutate(['score' => new Score(777, 5, 2)]),
        ]);
        $skewFrame = VsRenderer::render($skewed);
        self::assertStringContainsString('you       score: 0       lines: 0   level: 0', $skewFrame);
        self::assertStringContainsString('computer  score: 777     lines: 5   level: 2', $skewFrame);
    }

    public function testComputerNextQueueStaysPrivate(): void
    {
        $frame = VsRenderer::render(VsGame::start());

        self::assertSame(1, substr_count($frame, 'next:'), 'exactly one next line — the human board only');
    }
}
