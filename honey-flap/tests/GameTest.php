<?php

declare(strict_types=1);

namespace CandyCore\Flap\Tests;

use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Flap\Game;
use CandyCore\Flap\TickMsg;
use PHPUnit\Framework\TestCase;

final class GameTest extends TestCase
{
    public function testInitialBirdPositionIsCentered(): void
    {
        $g = Game::start(static fn(int $max): int => 0);
        $this->assertSame(Game::BIRD_COL, $g->bird->x);
        $this->assertEqualsWithDelta(Game::HEIGHT / 2, $g->bird->body->position->y, 0.01);
        $this->assertSame(0, $g->score);
        $this->assertFalse($g->crashed);
        $this->assertSame([], $g->pipes);
    }

    public function testPipeSpawnsEveryNTicks(): void
    {
        $g = Game::start(static fn(int $max): int => 0)->tickN(Game::PIPE_EVERY);
        $this->assertCount(1, $g->pipes);
        // The pipe is appended at WIDTH-1 in the same tick that increments
        // the existing pipes, so its first observed x is exactly WIDTH-1.
        $this->assertSame(Game::WIDTH - 1, $g->pipes[0]->x);
    }

    public function testFlapResetsVelocity(): void
    {
        $g = Game::start(static fn(int $max): int => 0);
        $beforeY = $g->bird->row();
        // Without flap, bird drops several rows over 6 ticks.
        $dropped = $g->tickN(6);
        $this->assertGreaterThan($beforeY, $dropped->bird->row());
        // With flap right before those ticks, bird is higher.
        $msg = new KeyMsg(KeyType::Space, '');
        [$g, ] = $g->update($msg);
        $afterFlap = $g->tickN(6);
        $this->assertLessThan($dropped->bird->row(), $afterFlap->bird->row());
    }

    public function testQuitOnQuit(): void
    {
        $g = Game::start(static fn(int $max): int => 0);
        [, $cmd] = $g->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertNotNull($cmd);
    }

    public function testRestartFromCrashedState(): void
    {
        $g = Game::start(static fn(int $max): int => 0);
        // Drop into the floor by ticking many frames.
        $g = $g->tickN(80);
        $this->assertTrue($g->crashed);
        [$g, ] = $g->update(new KeyMsg(KeyType::Char, 'r'));
        $this->assertFalse($g->crashed);
        $this->assertSame(0, $g->score);
        $this->assertSame([], $g->pipes);
    }

    public function testCrashStopsBirdFromMovingAfterFurtherTicks(): void
    {
        $g = Game::start(static fn(int $max): int => 0)->tickN(80);
        $this->assertTrue($g->crashed);
        $rowAtCrash = $g->bird->row();
        $g2 = $g->tickN(10);
        // tickN bypasses the crashed gate (it's a test helper that drives
        // advance() directly), so the bird keeps falling past the floor —
        // but the runtime gate in update(TickMsg) will not advance the model.
        // Verify that update() returns the same model with no further work.
        [$next, $cmd] = $g->update(new TickMsg());
        $this->assertSame($g, $next);
        $this->assertNull($cmd);
    }

    public function testDeterministicWithSeededRand(): void
    {
        // Both runs use the same closure, so pipe layouts should match.
        $rand = static fn(int $max): int => intdiv($max, 2);
        $a = Game::start($rand)->tickN(60);
        $b = Game::start($rand)->tickN(60);
        $this->assertSame(count($a->pipes), count($b->pipes));
        foreach ($a->pipes as $i => $pipe) {
            $this->assertSame($pipe->gapY, $b->pipes[$i]->gapY);
        }
    }
}
