<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Scrollbar;

use PHPUnit\Framework\TestCase;
use SugarCraft\Bits\Scrollbar\Scrollbar;
use SugarCraft\Bits\Scrollbar\ScrollbarState;

final class ScrollbarTest extends TestCase
{
    // ── ScrollbarState construction ──────────────────────────────────────────

    public function testNewWithDefaults(): void
    {
        $s = ScrollbarState::new();
        $this->assertSame(0, $s->total);
        $this->assertSame(0, $s->position);
        $this->assertSame(0, $s->viewport);
    }

    public function testNewWithValues(): void
    {
        $s = ScrollbarState::new(total: 100, position: 25, viewport: 20);
        $this->assertSame(100, $s->total);
        $this->assertSame(25, $s->position);
        $this->assertSame(20, $s->viewport);
    }

    public function testConstructorRejectsNegativeTotal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ScrollbarState(total: -1, position: 0, viewport: 10);
    }

    public function testConstructorRejectsNegativeViewport(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ScrollbarState(total: 100, position: 0, viewport: -1);
    }

    public function testConstructorRejectsNegativePosition(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ScrollbarState(total: 100, position: -1, viewport: 20);
    }

    public function testConstructorRejectsPositionBeyondMax(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // total=100, viewport=20 → max position = 80
        new ScrollbarState(total: 100, position: 81, viewport: 20);
    }

    public function testConstructorAllowsZeroViewport(): void
    {
        $s = new ScrollbarState(total: 100, position: 0, viewport: 0);
        $this->assertSame(0, $s->viewport);
    }

    public function testConstructorAllowsZeroTotal(): void
    {
        $s = new ScrollbarState(total: 0, position: 0, viewport: 10);
        $this->assertSame(0, $s->total);
        $this->assertSame(0, $s->position);
        $this->assertSame(10, $s->viewport);
    }

    // ── Scrollbar factory methods ────────────────────────────────────────────

    public function testVerticalDefaults(): void
    {
        $sb = Scrollbar::vertical();
        $this->assertTrue($sb->vertical);
        $this->assertSame('░', $sb->trackChar);
        $this->assertSame('█', $sb->thumbChar);
        $this->assertTrue($sb->showArrows);
    }

    public function testHorizontalDefaults(): void
    {
        $sb = Scrollbar::horizontal();
        $this->assertFalse($sb->vertical);
        $this->assertSame('░', $sb->trackChar);
        $this->assertSame('█', $sb->thumbChar);
        $this->assertFalse($sb->showArrows);
    }

    // ── Scrollbar with* mutators ─────────────────────────────────────────────

    public function testWithTrackChar(): void
    {
        $sb = Scrollbar::vertical()->withTrackChar('·');
        $this->assertSame('·', $sb->trackChar);
    }

    public function testWithThumbChar(): void
    {
        $sb = Scrollbar::vertical()->withThumbChar('▓');
        $this->assertSame('▓', $sb->thumbChar);
    }

    public function testWithArrowsTrue(): void
    {
        $sb = Scrollbar::vertical()->withArrows(true);
        $this->assertTrue($sb->showArrows);
    }

    public function testWithArrowsFalse(): void
    {
        $sb = Scrollbar::vertical()->withArrows(false);
        $this->assertFalse($sb->showArrows);
    }

    public function testWithTrackCharReturnsNewInstance(): void
    {
        $original = Scrollbar::vertical();
        $modified = $original->withTrackChar('·');
        $this->assertNotSame($original, $modified);
        $this->assertSame('░', $original->trackChar);
        $this->assertSame('·', $modified->trackChar);
    }

    // ── Scrollbar view() rendering ───────────────────────────────────────────

    public function testViewWithZeroHeight(): void
    {
        $sb = Scrollbar::vertical();
        $state = ScrollbarState::new(total: 100, position: 0, viewport: 20);
        $this->assertSame('', $sb->view($state, 0));
    }

    public function testViewWithNegativeHeight(): void
    {
        $sb = Scrollbar::vertical();
        $state = ScrollbarState::new(total: 100, position: 0, viewport: 20);
        $this->assertSame('', $sb->view($state, -1));
    }

    public function testViewContentFitsAllTrack(): void
    {
        // total=10, viewport=20 → content fits in viewport
        $sb = Scrollbar::vertical()->withArrows(false);
        $state = ScrollbarState::new(total: 10, position: 0, viewport: 20);
        $rendered = $sb->view($state, 10);
        $this->assertSame(str_repeat('░', 10), $rendered);
    }

    public function testViewVerticalWithArrowsTopAndBottom(): void
    {
        $sb = Scrollbar::vertical()->withArrows(true)->withTrackChar('░')->withThumbChar('█');
        // total=100, viewport=20, position=0 → thumb at top
        // height=5: arrows take row 0 and 4, available space=3
        // thumbHeight = max(1, round(20/100 * 5)) = max(1, 1) = 1
        // maxThumbStart = 3 - 1 = 2
        // thumbStart = round(0 / max(1, 100-20) * 2) = 0
        $state = ScrollbarState::new(total: 100, position: 0, viewport: 20);
        $rendered = $sb->view($state, 5);
        // Row 0: ▲, Row 1-3: track/thumb, Row 4: ▼
        $this->assertSame(5, mb_strlen($rendered, 'UTF-8'));
        $this->assertSame('▲', mb_substr($rendered, 0, 1, 'UTF-8'));
        $this->assertSame('▼', mb_substr($rendered, 4, 1, 'UTF-8'));
    }

    public function testViewVerticalThumbPositionMiddle(): void
    {
        $sb = Scrollbar::vertical()->withArrows(false)->withTrackChar('░')->withThumbChar('█');
        // total=100, viewport=20 → max position = 80
        // position=40 → middle of scroll range
        // height=10: thumbHeight = max(1, round(20/100 * 10)) = max(1, 2) = 2
        // availableSpace = 10
        // maxThumbStart = 10 - 2 = 8
        // thumbStart = round(40 / 80 * 8) = round(4) = 4
        $state = ScrollbarState::new(total: 100, position: 40, viewport: 20);
        $rendered = $sb->view($state, 10);
        $this->assertSame(10, mb_strlen($rendered, 'UTF-8'));
        // thumb should be at positions 4-5
        $this->assertSame('█', mb_substr($rendered, 4, 1, 'UTF-8'));
        $this->assertSame('█', mb_substr($rendered, 5, 1, 'UTF-8'));
        // track elsewhere
        $this->assertSame('░', mb_substr($rendered, 0, 1, 'UTF-8'));
        $this->assertSame('░', mb_substr($rendered, 9, 1, 'UTF-8'));
    }

    public function testViewVerticalThumbAtBottom(): void
    {
        $sb = Scrollbar::vertical()->withArrows(false)->withTrackChar('░')->withThumbChar('█');
        // total=100, viewport=20, position=80 (max)
        // height=10: thumbHeight = 2, thumbStart = 8
        $state = ScrollbarState::new(total: 100, position: 80, viewport: 20);
        $rendered = $sb->view($state, 10);
        $this->assertSame(10, mb_strlen($rendered, 'UTF-8'));
        // thumb at positions 8-9
        $this->assertSame('█', mb_substr($rendered, 8, 1, 'UTF-8'));
        $this->assertSame('█', mb_substr($rendered, 9, 1, 'UTF-8'));
        $this->assertSame('░', mb_substr($rendered, 0, 1, 'UTF-8'));
    }

    public function testViewHorizontalNoArrows(): void
    {
        $sb = Scrollbar::horizontal()->withArrows(false)->withTrackChar('░')->withThumbChar('█');
        $state = ScrollbarState::new(total: 100, position: 0, viewport: 20);
        $rendered = $sb->view($state, 10);
        $this->assertSame(10, mb_strlen($rendered, 'UTF-8'));
        // Same thumb logic, no arrows
        $this->assertSame('█', mb_substr($rendered, 0, 1, 'UTF-8')); // thumb at start
    }

    public function testViewWithCustomChars(): void
    {
        $sb = Scrollbar::vertical()->withArrows(false)->withTrackChar('.')->withThumbChar('#');
        $state = ScrollbarState::new(total: 100, position: 40, viewport: 20);
        $rendered = $sb->view($state, 10);
        $this->assertSame(10, strlen($rendered));
        $this->assertStringContainsString('.', $rendered);
        $this->assertStringContainsString('#', $rendered);
        $this->assertStringNotContainsString('░', $rendered);
        $this->assertStringNotContainsString('█', $rendered);
    }

    public function testViewArrowsDisabledRendersNoArrows(): void
    {
        $sb = Scrollbar::vertical()->withArrows(false)->withTrackChar('░');
        // total=100, viewport=20, position=0, height=5
        // availableSpace = 5, thumbHeight = 1, maxThumbStart = 4, thumbStart = 0
        $state = ScrollbarState::new(total: 100, position: 0, viewport: 20);
        $rendered = $sb->view($state, 5);
        $this->assertSame(5, mb_strlen($rendered, 'UTF-8'));
        $this->assertStringNotContainsString('▲', $rendered);
        $this->assertStringNotContainsString('▼', $rendered);
    }

    // ── Edge cases ───────────────────────────────────────────────────────────

    public function testViewWithHeightOneNoArrows(): void
    {
        $sb = Scrollbar::vertical()->withArrows(false)->withTrackChar('░')->withThumbChar('█');
        $state = ScrollbarState::new(total: 100, position: 0, viewport: 20);
        $rendered = $sb->view($state, 1);
        $this->assertSame(1, mb_strlen($rendered, 'UTF-8'));
        // When height=1 and no arrows, availableSpace=1, thumbHeight=1
        // thumbStart = 0, so it's all thumb
        $this->assertSame('█', $rendered);
    }

    public function testViewWithHeightTwoNoArrows(): void
    {
        $sb = Scrollbar::vertical()->withArrows(false)->withTrackChar('░')->withThumbChar('█');
        $state = ScrollbarState::new(total: 100, position: 0, viewport: 20);
        $rendered = $sb->view($state, 2);
        $this->assertSame(2, mb_strlen($rendered, 'UTF-8'));
        // availableSpace=2, thumbHeight = max(1, round(20/100*2)) = max(1, 0) = 1
        // maxThumbStart = 2-1 = 1, thumbStart = 0
        // So position 0 is thumb, position 1 is track
        $this->assertSame('█', mb_substr($rendered, 0, 1, 'UTF-8'));
        $this->assertSame('░', mb_substr($rendered, 1, 1, 'UTF-8'));
    }

    public function testViewWithZeroViewport(): void
    {
        $sb = Scrollbar::vertical()->withArrows(false)->withTrackChar('░');
        // total=100, viewport=0 → max position = 100
        // When viewport=0, thumbHeight = max(1, round(0/100 * height)) = 1
        $state = ScrollbarState::new(total: 100, position: 0, viewport: 0);
        $rendered = $sb->view($state, 5);
        $this->assertSame(5, mb_strlen($rendered, 'UTF-8'));
        // All track since content doesn't fit (total > viewport)
        // But with viewport=0, availableSpace=5, thumbHeight=1, maxThumbStart=4
        // thumbStart = round(0/100 * 4) = 0
        // So first position is thumb, rest are track
        $this->assertSame('█', mb_substr($rendered, 0, 1, 'UTF-8'));
    }

    public function testViewWithZeroTotal(): void
    {
        $sb = Scrollbar::vertical()->withArrows(false)->withTrackChar('░');
        // total=0, viewport=10 → content fits (0 <= 10)
        $state = ScrollbarState::new(total: 0, position: 0, viewport: 10);
        $rendered = $sb->view($state, 5);
        $this->assertSame(5, mb_strlen($rendered, 'UTF-8'));
        // All track when content fits
        $this->assertSame(str_repeat('░', 5), $rendered);
    }

    public function testViewHeightLargerThanTotal(): void
    {
        $sb = Scrollbar::vertical()->withArrows(false)->withTrackChar('░')->withThumbChar('█');
        // total=5, viewport=3, height=10
        // max position = 5-3 = 2
        // thumbHeight = max(1, round(3/5 * 10)) = max(1, 6) = 6
        // availableSpace = 10, maxThumbStart = 10-6 = 4
        // thumbStart = round(0 / max(1, 5-3) * 4) = 0
        $state = ScrollbarState::new(total: 5, position: 0, viewport: 3);
        $rendered = $sb->view($state, 10);
        $this->assertSame(10, mb_strlen($rendered, 'UTF-8'));
        // Should have thumb at 0-5, track at 6-9
        $this->assertSame('█', mb_substr($rendered, 0, 1, 'UTF-8'));
        $this->assertSame('█', mb_substr($rendered, 5, 1, 'UTF-8'));
        $this->assertSame('░', mb_substr($rendered, 6, 1, 'UTF-8'));
    }
}
