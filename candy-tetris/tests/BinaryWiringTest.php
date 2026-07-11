<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Tetris\Game;

/**
 * Guards the wiring of the `bin/tetris` entry point.
 *
 * The single-player mode must boot through {@see Game::startWithLockDelay()}
 * so the SRS lock-delay feature is actually live in the shipped binary — it
 * was dead in the binary while `bin/tetris` still called `Game::start()`
 * (W15). This is a source-level regression pin: reverting the wiring back to
 * the lock-delay-less `Game::start()` breaks the test.
 */
final class BinaryWiringTest extends TestCase
{
    private function binarySource(): string
    {
        $src = file_get_contents(__DIR__ . '/../bin/tetris');
        self::assertNotFalse($src, 'bin/tetris must be readable');

        return $src;
    }

    public function testSinglePlayerBootsWithLockDelay(): void
    {
        $src = $this->binarySource();
        $this->assertStringContainsString(
            'Game::startWithLockDelay(',
            $src,
            'bin/tetris single-player must boot via Game::startWithLockDelay() so lock delay is live (W15)',
        );
    }

    public function testSinglePlayerDoesNotUseLockDelayLessStart(): void
    {
        // Target the single-player default arm specifically — VS mode still
        // legitimately calls VsGame::start(), so we must not match that.
        $src = $this->binarySource();
        $this->assertStringNotContainsString(
            'default => Game::start(',
            $src,
            'bin/tetris single-player must not fall back to the lock-delay-less Game::start() default (W15)',
        );
    }

    public function testStartWithLockDelayArmsTheLockDelayWindow(): void
    {
        // The path the binary now takes: lock delay is armed (max > 0), so a
        // grounded piece gets a slide/rotate window before locking.
        $game = Game::startWithLockDelay();
        $this->assertGreaterThan(0, $game->lockDelayMax, 'lock delay must be armed');
        $this->assertSame($game->lockDelayMax, $game->lockDelayTicks, 'lock delay starts full');
    }
}
