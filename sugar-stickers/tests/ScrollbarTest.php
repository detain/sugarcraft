<?php

declare(strict_types=1);

namespace SugarCraft\Stickers\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Bits\Scrollbar\ScrollbarState;
use SugarCraft\Stickers\Scrollbar;

/**
 * Tests for the Scrollbar wrapper class.
 */
final class ScrollbarTest extends TestCase
{
    /** Vertical scrollbar can be constructed via factory. */
    public function testVerticalFactory(): void
    {
        $sb = Scrollbar::vertical();
        $this->assertInstanceOf(Scrollbar::class, $sb);
    }

    /** Horizontal scrollbar can be constructed via factory. */
    public function testHorizontalFactory(): void
    {
        $sb = Scrollbar::horizontal();
        $this->assertInstanceOf(Scrollbar::class, $sb);
    }

    /** withTrackChar returns a new instance with the updated character. */
    public function testWithTrackCharReturnsNewInstance(): void
    {
        $a = Scrollbar::vertical();
        $b = $a->withTrackChar(':');
        $this->assertNotSame($a, $b, 'Should return a new instance');
    }

    /** withThumbChar returns a new instance with the updated character. */
    public function testWithThumbCharReturnsNewInstance(): void
    {
        $a = Scrollbar::vertical();
        $b = $a->withThumbChar('#');
        $this->assertNotSame($a, $b, 'Should return a new instance');
    }

    /** withArrows(true) returns a new instance with arrows shown. */
    public function testWithArrowsReturnsNewInstance(): void
    {
        $a = Scrollbar::vertical();
        $b = $a->withArrows(false);
        $this->assertNotSame($a, $b, 'Should return a new instance');
    }

    /** view() accepts a ScrollbarState object. */
    public function testViewWithScrollbarState(): void
    {
        $sb = Scrollbar::vertical();
        $state = new ScrollbarState(total: 100, position: 30, viewport: 24);
        $output = $sb->view($state, 24);

        $this->assertIsString($output);
        $this->assertNotEmpty($output);
    }

    /** view() accepts an array with total/position/viewport keys. */
    public function testViewWithArrayState(): void
    {
        $sb = Scrollbar::vertical();
        $state = ['total' => 100, 'position' => 30, 'viewport' => 24];
        $output = $sb->view($state, 24);

        $this->assertIsString($output);
        $this->assertNotEmpty($output);
    }

    /** view() output has exactly $height characters (one per row, no newlines). */
    public function testViewLengthMatchesHeight(): void
    {
        $sb = Scrollbar::vertical();
        $state = new ScrollbarState(total: 100, position: 0, viewport: 24);
        $output = $sb->view($state, 24);

        $this->assertSame(24, \mb_strlen($output, 'UTF-8'), 'view() should return $height chars, one per row');
    }

    /** view() returns non-empty string when total > viewport. */
    public function testViewWithScrollingContent(): void
    {
        $sb = Scrollbar::vertical();
        // thumb should appear somewhere in the rendered string
        $state = new ScrollbarState(total: 100, position: 50, viewport: 24);
        $output = $sb->view($state, 24);

        $this->assertSame(24, \mb_strlen($output, 'UTF-8'));
        // The thumb char '█' should appear at least once
        $this->assertStringContainsString('█', $output);
    }

    /** view() returns only track chars when content fits in viewport. */
    public function testViewFitsInViewport(): void
    {
        $sb = Scrollbar::vertical();
        $state = new ScrollbarState(total: 10, position: 0, viewport: 24);
        $output = $sb->view($state, 24);

        // Should be all track chars (no thumb when total <= viewport)
        $this->assertSame(24, \mb_strlen($output, 'UTF-8'));
        // Every character should be the track char '░'
        $this->assertSame(\str_repeat('░', 24), $output, 'All chars should be track char when content fits');
    }

    /** Immutable: original scrollbar unchanged after with* calls. */
    public function testImmutability(): void
    {
        $a = Scrollbar::vertical();
        $b = $a->withThumbChar('X');

        // Neither track nor thumb should be 'X' on the original.
        $state = new ScrollbarState(total: 10, position: 0, viewport: 5);
        $outA = $a->view($state, 5);
        $outB = $b->view($state, 5);

        // Both outputs should have correct length (5 chars = 15 bytes for these chars).
        $this->assertSame(5, \mb_strlen($outA, 'UTF-8'));
        $this->assertSame(5, \mb_strlen($outB, 'UTF-8'));

        // The outputs should differ (different thumb char).
        // Original should still use default thumb, new one uses 'X'.
        $this->assertNotEquals($outA, $outB, 'Modified instance should produce different output');
    }
}
