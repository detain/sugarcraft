<?php

declare(strict_types=1);

namespace SugarCraft\Flip\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Flip\Frame;
use SugarCraft\Flip\Player;
use SugarCraft\Flip\Renderer;
use SugarCraft\Flip\TickMsg;
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

    /**
     * Step 9 — when a constrained Renderer is passed to the Player,
     * view() must clamp output so it never exceeds the column limit.
     * A wide frame (4 cols) rendered with a 2-col constraint emits at most
     * 2 columns of ANSI per row.
     */
    public function testViewClampsToRendererConstraints(): void
    {
        // 4-column frame with 4 distinct colours so each cell is different.
        $frame = new Frame([
            [[255, 0, 0], [0, 255, 0], [0, 0, 255], [255, 255, 0]],
        ]);
        // Clamp to 2 cols.
        $renderer = Renderer::withConstraints(1, 2);
        $p = new Player([$frame], renderer: $renderer);
        $view = $p->view();

        // The pic (ANSI output) ends before the first "\n" that starts the
        // status line.  After that newline the status text begins.
        $firstNl = strpos($view, "\n");
        $pic = $firstNl !== false ? substr($view, 0, $firstNl) : $view;

        // Strip ANSI escapes to count raw cell characters.
        $cells = preg_replace('/\033\[[0-9;]+m/', '', $pic);
        // The pic of a 1-row frame is a single line.
        $this->assertLessThanOrEqual(2, strlen($cells),
            'Clamped pic must be ≤ 2 chars, got ' . strlen($cells));
    }

    /**
     * Step 10 — WindowSizeMsg received by update() must return a new Player
     * carrying a Renderer re-clamped to the new dimensions (rows-1 for the
     * status line), with no Cmd emitted.
     */
    public function testWindowSizeMsgReclampsRenderer(): void
    {
        $frame = new Frame([
            [[255, 0, 0], [255, 0, 0], [255, 0, 0]],
        ]);
        // Start with unconstrained renderer.
        $p = new Player([$frame]);
        $this->assertNull($p->renderer);

        // Simulate SIGWINCH: new size 80 cols × 10 rows.
        [$next, $cmd] = $p->update(new WindowSizeMsg(80, 10));

        $this->assertNotSame($p, $next, 'WindowSizeMsg must produce a new Player');
        $this->assertNull($cmd, 'WindowSizeMsg must not emit a Cmd');
        $this->assertNotNull($next->renderer, 'WindowSizeMsg must set a renderer on the Player');

        // Verify the renderer clamped dimensions by checking that the rendered
        // pic does not exceed 80 columns.
        $view = $next->view();
        $firstNl = strpos($view, "\n");
        $pic = $firstNl !== false ? substr($view, 0, $firstNl) : $view;
        $cells = preg_replace('/\033\[[0-9;]+m/', '', $pic);
        $this->assertLessThanOrEqual(80, strlen($cells),
            'Re-clamped renderer output must be ≤ 80 chars');
    }
}
