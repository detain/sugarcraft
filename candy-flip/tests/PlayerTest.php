<?php

declare(strict_types=1);

namespace CandyCore\Flip\Tests;

use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Flip\Frame;
use CandyCore\Flip\Player;
use CandyCore\Flip\Renderer;
use CandyCore\Flip\TickMsg;
use PHPUnit\Framework\TestCase;

final class PlayerTest extends TestCase
{
    private function frames(int $n): array
    {
        $f = [];
        for ($i = 0; $i < $n; $i++) {
            $f[] = new Frame([[[($i * 30) % 255, 0, 0]]]);
        }
        return $f;
    }

    public function testTickAdvancesIndex(): void
    {
        $p = new Player($this->frames(3));
        [$p, ] = $p->update(new TickMsg());
        $this->assertSame(1, $p->index);
        [$p, ] = $p->update(new TickMsg());
        $this->assertSame(2, $p->index);
    }

    public function testTickWrapsAtEnd(): void
    {
        $p = new Player($this->frames(3), index: 2);
        [$p, ] = $p->update(new TickMsg());
        $this->assertSame(0, $p->index);
    }

    public function testSpaceTogglesPause(): void
    {
        $p = new Player($this->frames(3));
        [$p, ] = $p->update(new KeyMsg(KeyType::Space, ''));
        $this->assertTrue($p->paused);
        [$p, ] = $p->update(new KeyMsg(KeyType::Space, ''));
        $this->assertFalse($p->paused);
    }

    public function testTickIgnoredWhilePaused(): void
    {
        $p = new Player($this->frames(3), paused: true);
        [$p2, $cmd] = $p->update(new TickMsg());
        $this->assertSame(0, $p2->index);
        $this->assertNull($cmd);
    }

    public function testManualStepWithArrows(): void
    {
        $p = new Player($this->frames(3));
        [$p, ] = $p->update(new KeyMsg(KeyType::Right, ''));
        $this->assertSame(1, $p->index);
        [$p, ] = $p->update(new KeyMsg(KeyType::Left, ''));
        $this->assertSame(0, $p->index);
        [$p, ] = $p->update(new KeyMsg(KeyType::Left, ''));
        $this->assertSame(2, $p->index);   // wraps backwards
    }

    public function testQuit(): void
    {
        $p = new Player($this->frames(2));
        [, $cmd] = $p->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertNotNull($cmd);
    }

    public function testPresetToggle(): void
    {
        $p = new Player($this->frames(2), preset: Renderer::PRESET_SOLID);
        [$p, ] = $p->update(new KeyMsg(KeyType::Char, 'd'));
        $this->assertSame(Renderer::PRESET_DENSITY, $p->preset);
        [$p, ] = $p->update(new KeyMsg(KeyType::Char, 'd'));
        $this->assertSame(Renderer::PRESET_SOLID, $p->preset);
    }

    public function testEmptyFramesRendersGracefully(): void
    {
        $p = new Player([]);
        $this->assertStringContainsString('no frames', $p->view());
    }
}
