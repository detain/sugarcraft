<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Flexbox\Viewport;

final class SyncViewportTest extends TestCase
{
    // ---- withLinkedViewport --------------------------------------------------------

    public function testSyncWithReturnsNewInstance(): void
    {
        $a = Viewport::fromContent("left content\nline2\nline3", 40, 24);
        $b = Viewport::fromContent("right content\nline2\nline3", 40, 24);

        $synced = $a->withLinkedViewport($b);

        $this->assertNotSame($a, $synced);
    }

    public function testSyncWithEstablishesSyncRelationship(): void
    {
        $a = Viewport::fromContent("left\nright\ncontent", 40, 24);
        $b = Viewport::fromContent("right\nleft\ncontent", 40, 24);

        $syncedA = $a->withLinkedViewport($b);

        $this->assertTrue($syncedA->hasLinkedViewport());
    }

    public function testIsSyncedIsFalseByDefault(): void
    {
        $vp = Viewport::fromContent("some\ncontent\nhere", 40, 24);
        $this->assertFalse($vp->hasLinkedViewport());
    }

    public function testIsSyncedIsTrueAfterSyncWith(): void
    {
        $a = Viewport::fromContent("left\ncontent", 40, 24);
        $b = Viewport::fromContent("right\ncontent", 40, 24);

        $synced = $a->withLinkedViewport($b);

        $this->assertTrue($synced->hasLinkedViewport());
    }

    public function testSyncWithBothViewportsAreIndependent(): void
    {
        $a = Viewport::fromContent("A content here", 40, 24);
        $b = Viewport::fromContent("B different content", 40, 24);

        $syncedA = $a->withLinkedViewport($b);

        // Each viewport maintains its own content.
        $this->assertStringContainsString('A content', $syncedA->view());
        // The synced viewport's content is not rendered by $syncedA.
        $this->assertStringNotContainsString('B different', $syncedA->view());
    }

    public function testSyncWithCanBeOverwritten(): void
    {
        $a = Viewport::fromContent("A\nB\nC", 40, 10);
        $b = Viewport::fromContent("1\n2\n3", 40, 10);
        $c = Viewport::fromContent("alpha\nbeta\ngamma", 40, 10);

        // A synced with B, then B synced with C (B's withLinkedViewport overwrites).
        $ab = $a->withLinkedViewport($b);
        $bc = $b->withLinkedViewport($c);

        $this->assertTrue($ab->hasLinkedViewport());
        $this->assertTrue($bc->hasLinkedViewport());
    }

    // ---- Navigation + sync (caller coordinates offsets) ---------------

    public function testNavigationMethodReturnsInstanceWithSyncIntact(): void
    {
        // Use 20 lines so content exceeds viewport height (10), allowing scroll.
        $lines = [];
        for ($i = 0; $i < 20; $i++) {
            $lines[] = "line{$i}";
        }
        $content = implode("\n", $lines);

        $a = Viewport::fromContent($content, 40, 10);
        $b = Viewport::fromContent($content, 40, 10);

        $synced = $a->withLinkedViewport($b)->withYOffset(1);

        $this->assertTrue($synced->hasLinkedViewport());
        $this->assertSame(1, $synced->yOffset());
    }

    public function testSetContentPreservesSyncRelationship(): void
    {
        $a = Viewport::fromContent("original\ncontent", 40, 24);
        $b = Viewport::fromContent("other\npanel", 40, 24);

        $synced = $a->withLinkedViewport($b)->withContent("new\ncontent\nhere");

        $this->assertTrue($synced->hasLinkedViewport());
    }

    public function testSetYOffsetPreservesSyncRelationship(): void
    {
        // Use 50 lines so content exceeds viewport height, allowing scroll.
        $lines = [];
        for ($i = 0; $i < 50; $i++) {
            $lines[] = "line{$i}";
        }
        $content = implode("\n", $lines);

        $a = Viewport::fromContent($content, 40, 24);
        $b = Viewport::fromContent($content, 40, 24);

        $synced = $a->withLinkedViewport($b)->withYOffset(5);

        $this->assertTrue($synced->hasLinkedViewport());
        $this->assertSame(5, $synced->yOffset());
    }

    // ---- Usage pattern: side-by-side diff scrolling ---------------------

    public function testSideBySideScrollingPattern(): void
    {
        $lines = [];
        for ($i = 0; $i < 50; $i++) {
            $lines[] = "function{$i}";
        }
        $leftContent = implode("\n", $lines);
        $rightContent = str_replace('function', 'func', $leftContent);

        $left = Viewport::fromContent($leftContent, 40, 24);
        $right = Viewport::fromContent($rightContent, 40, 24);

        // Caller establishes sync relationship.
        $leftSynced = $left->withLinkedViewport($right);

        // Caller coordinates scroll: apply same offset to both viewports.
        $scrollOffset = 20;
        $leftScrolled = $leftSynced->withYOffset($scrollOffset);
        $rightScrolled = $right->withYOffset($scrollOffset);

        $this->assertSame($scrollOffset, $leftScrolled->yOffset());
        $this->assertSame($scrollOffset, $rightScrolled->yOffset());
        $this->assertTrue($leftScrolled->hasLinkedViewport());

        // Left panel shows content from scroll offset.
        $this->assertStringContainsString('function20', $leftScrolled->view());
        // Right panel shows translated content from same offset.
        $this->assertStringContainsString('func20', $rightScrolled->view());
    }
}
