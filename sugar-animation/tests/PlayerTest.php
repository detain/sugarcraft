<?php

declare(strict_types=1);

namespace SugarCraft\Animation\Tests;

use SugarCraft\Core\I18n\T;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Animation\Frame;
use SugarCraft\Animation\Player;
use SugarCraft\Animation\Renderer;
use SugarCraft\Animation\TickMsg;
use PHPUnit\Framework\TestCase;

final class PlayerTest extends TestCase
{
    private function frames(int $n): array
    {
        $f = [];
        for ($i = 0; $i < $n; $i++) {
            $f[] = Frame::new([[[($i * 30) % 255, 0, 0]]], delay: 10);
        }
        return $f;
    }

    private function framesWithDelays(array $delays): array
    {
        return \array_map(
            fn(int $d) => Frame::new([[[255, 0, 0]]], delay: $d),
            $delays
        );
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
        $frame = Frame::new([
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
        $frame = Frame::new([
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

    /**
     * Item 5.3 — per-frame delay scheduling.
     * E-03 flip (disclosed deviation): each Frame::$delay is the hold of
     * the frame it belongs to, so the arm after ARRIVING on a frame is that
     * frame's own delay / 100.0 on the absolute schedule — variable-rate
     * GIFs play at the correct speed and a late tick re-arms for the
     * REMAINING distance instead of stretching the cycle. The pre-E-03
     * shape armed the frame being LEFT (a one-frame rotation that never
     * honored the last frame's delay at all).
     */
    public function testPerFrameDelaySchedulesAppropriateInterval(): void
    {
        // Frame 0: 20 centiseconds (0.2s), Frame 1: 5 centiseconds (0.05s)
        $p = new Player($this->framesWithDelays([20, 5]));

        // First tick lands on frame 1 — next tick should schedule ~0.05s
        // (frame 1's own delay).
        [, $cmd] = $p->update(new TickMsg());
        $this->assertInstanceOf(\Closure::class, $cmd);
        $tickRequest = $cmd();
        $this->assertInstanceOf(\SugarCraft\Core\TickRequest::class, $tickRequest);
        $this->assertEqualsWithDelta(0.05, $tickRequest->seconds, 0.001,
            'Arrival at frame 1 arms frame 1\'s delay (5cs)');

        // Advance to frame 1 — a second immediate tick finds the schedule
        // still inside frame 1's window and liveness-advances to frame 0,
        // which gets a fresh full 0.2s hold.
        [$p2, ] = $p->update(new TickMsg());
        $this->assertSame(1, $p2->index);

        [, $cmd2] = $p2->update(new TickMsg());
        $tickRequest2 = $cmd2();
        $this->assertInstanceOf(\SugarCraft\Core\TickRequest::class, $tickRequest2);
        $this->assertEqualsWithDelta(0.2, $tickRequest2->seconds, 0.001,
            'The wrapped arrival (frame 0) arms frame 0\'s delay (20cs)');
    }

    // ---------------------------------------------------------------- SA-P1

    private function statusLocalePinFrames(): array
    {
        return $this->frames(2);
    }

    private function statusOf(string $view): string
    {
        $lines = explode("\n", $view);

        return (string) ($lines[count($lines) - 2] ?? '');
    }

    /**
     * SA-P1 (audits 009/020): the overlay is no longer hardcoded English —
     * it routes through Lang, so a non-English locale renders its own
     * frame counter, preset word and state word, with no English bleed.
     */
    public function testStatusOverlayIsLocalized(): void
    {
        $prior = T::locale();
        T::setLocale('de');
        try {
            $view = (new Player($this->statusLocalePinFrames(), renderer: Renderer::withConstraints(5, 200)))->view();
            $this->assertStringContainsString('Bild 1/2', $view, 'the frame count rides the German key');
            $this->assertStringContainsString('Fläche', $view, 'the preset name is the German one');
            $this->assertStringContainsString('läuft', $view, 'the state word is the German one');
            $this->assertStringNotContainsString('frame 1/2', $view, 'no English bleeds through the overlay');
        } finally {
            T::setLocale($prior);
        }
    }

    /**
     * SA-P1 (audit 013): the Player pauses into a translated state word too,
     * proving the paused/playing keys are wired through Lang, not just the
     * playing default.
     */
    public function testPausedStateWordIsLocalized(): void
    {
        $prior = T::locale();
        T::setLocale('de');
        try {
            [$paused, ] = (new Player($this->statusLocalePinFrames()))->update(new KeyMsg(KeyType::Space, ''));
            $view = $paused->view();
            $this->assertStringContainsString('pausiert', $view, 'the paused overlay translates too');
        } finally {
            T::setLocale($prior);
        }
    }

    /**
     * SA-P1 (audit 013): the empty state is localized AND still advertises
     * the only key that does anything without frames — the way out.
     */
    public function testEmptyStateLocalizedWithQuitHint(): void
    {
        $prior = T::locale();
        T::setLocale('de');
        try {
            $view = (new Player([], renderer: Renderer::withConstraints(5, 200)))->view();
            $this->assertStringContainsString('(keine Bilder)', $view, 'the empty state uses the German key');
            $this->assertStringContainsString('Beenden', $view, 'the empty state keeps advertising the quit chord');
            $this->assertStringNotContainsString('no frames', $view, 'no English bleed');
        } finally {
            T::setLocale($prior);
        }
    }

    /**
     * SA-P1 (audit 013): the status line is now width-clamped. At the
     * 82-column floor the full arrowed hint survives; below it the tail
     * progressively abbreviates. Across every budget from 20 to 120 the
     * English overlay never grows wider than its budget.
     *
     * @dataProvider narrowBudgetProvider
     */
    public function testStatusNeverExceedsColumnBudget(int $cols): void
    {
        $prior = T::locale();
        T::setLocale('en');
        try {
            $view = (new Player($this->statusLocalePinFrames(), renderer: Renderer::withConstraints(5, $cols)))->view();
            foreach (explode("\n", $view) as $row) {
                $this->assertLessThanOrEqual(
                    $cols,
                    Width::string($row),
                    "a {$cols}-col terminal must not wrap; row was: " . var_export($row, true),
                );
            }
        } finally {
            T::setLocale($prior);
        }
    }

    /**
     * @return array<string, array{0:int}>
     */
    public static function narrowBudgetProvider(): array
    {
        $cases = [];
        foreach ([20, 24, 30, 40, 60, 75, 76, 81, 82, 100, 120] as $cols) {
            $cases['cols-' . $cols] = [$cols];
        }

        return $cases;
    }

    /**
     * SA-P1 (audit 013): a CJK locale counts two cells per glyph, so the
     * clamp is cell-based, not byte-based — a 40-col terminal must never see
     * a wrapped Chinese status line.
     */
    public function testClampIsCellBasedNotByteBased(): void
    {
        $prior = T::locale();
        T::setLocale('zh-cn');
        try {
            foreach ([24, 30, 40] as $cols) {
                $view = (new Player($this->statusLocalePinFrames(), renderer: Renderer::withConstraints(5, $cols)))->view();
                foreach (explode("\n", $view) as $row) {
                    $this->assertLessThanOrEqual(
                        $cols,
                        Width::string($row),
                        "zh-cn at {$cols} cols wrapped: " . var_export($row, true),
                    );
                }
            }
        } finally {
            T::setLocale($prior);
        }
    }

    /**
     * SA-P1 (audit 013): the progressive-abbreviation rungs are wired in the
     * right order. At the floor the hint is present; at an honest medium
     * width the hint tail drops to the core alone (still no arrows, still no
     * truncation ellipsis); at a brutal width the core itself truncates.
     */
    public function testHintTailAbbreviatesProgressively(): void
    {
        $prior = T::locale();
        T::setLocale('en');
        try {
            // Medium width: 40 cols. Full hint (76) and its ASCII twin do not
            // fit, so the tail is dropped — but the core (31) fits whole, so
            // no words are clipped.
            $medium = $this->statusOf((new Player($this->statusLocalePinFrames(), renderer: Renderer::withConstraints(5, 40)))->view());
            $this->assertStringContainsString('frame 1/2', $medium, 'the core is intact at medium width');
            $this->assertStringContainsString('playing', $medium, 'the state word survives — nothing is chopped');
            $this->assertStringNotContainsString('step', $medium, 'the hint tail is gone');
            $this->assertStringNotContainsString('…', $medium, 'not truncated, just abbreviated');

            // Brutal width: 20 cols. Even the core (31) no longer fits, so it
            // is truncated to the budget.
            $brutal = $this->statusOf((new Player($this->statusLocalePinFrames(), renderer: Renderer::withConstraints(5, 20)))->view());
            $this->assertLessThanOrEqual(20, Width::string($brutal), 'the last rung truncates to the budget');
        } finally {
            T::setLocale($prior);
        }
    }

    /**
     * SA-P1 (audit 013): at or above the floor the full arrowed hint rides;
     * below it the East-Asian-Ambiguous arrows never reach a narrow terminal
     * that could render them double-wide.
     */
    public function testArrowTailFollowsUnicodeFloor(): void
    {
        $prior = T::locale();
        T::setLocale('en');
        try {
            $wide = $this->statusOf((new Player($this->statusLocalePinFrames(), renderer: Renderer::withConstraints(5, 82)))->view());
            $this->assertStringContainsString('←/→', $wide, 'at the floor the unicode arrows are kept');

            $justBelow = $this->statusOf((new Player($this->statusLocalePinFrames(), renderer: Renderer::withConstraints(5, 81)))->view());
            $this->assertStringContainsString('<->', $justBelow, 'below the floor the ASCII twin rides');
            $this->assertStringNotContainsString('←', $justBelow, 'no double-wide risk below the floor');
        } finally {
            T::setLocale($prior);
        }
    }

    /**
     * SA-P1 (audit 013): the empty-state overlay honors the budget too — the
     * quit tail is dropped when the line would not fit, never allowed to
     * wrap on a narrow host.
     */
    public function testEmptyStateRespectsBudget(): void
    {
        $prior = T::locale();
        T::setLocale('en');
        try {
            $view = (new Player([], renderer: Renderer::withConstraints(5, 16)))->view();
            $this->assertLessThanOrEqual(16, Width::string(rtrim($view, "\n")), 'the empty overlay is clamped');
            $this->assertStringNotContainsString('quit', $view, 'the tail is dropped when it will not fit');
        } finally {
            T::setLocale($prior);
        }
    }
}
