<?php

declare(strict_types=1);

namespace SugarCraft\Stickers\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Stickers\Viewport;

final class SyncViewportTest extends TestCase
{
    // ---- syncWith --------------------------------------------------------

    public function testSyncWithReturnsNewInstance(): void
    {
        $a = Viewport::withContent("left content\nline2\nline3", 40, 24);
        $b = Viewport::withContent("right content\nline2\nline3", 40, 24);

        $synced = $a->syncWith($b);

        $this->assertNotSame($a, $synced);
    }

    public function testSyncWithEstablishesSyncRelationship(): void
    {
        $a = Viewport::withContent("left\nright\ncontent", 40, 24);
        $b = Viewport::withContent("right\nleft\ncontent", 40, 24);

        $syncedA = $a->syncWith($b);

        $this->assertTrue($syncedA->isSynced());
    }

    public function testIsSyncedIsFalseByDefault(): void
    {
        $vp = Viewport::withContent("some\ncontent\nhere", 40, 24);
        $this->assertFalse($vp->isSynced());
    }

    public function testIsSyncedIsTrueAfterSyncWith(): void
    {
        $a = Viewport::withContent("left\ncontent", 40, 24);
        $b = Viewport::withContent("right\ncontent", 40, 24);

        $synced = $a->syncWith($b);

        $this->assertTrue($synced->isSynced());
    }

    public function testSyncWithBothViewportsAreIndependent(): void
    {
        $a = Viewport::withContent("A content here", 40, 24);
        $b = Viewport::withContent("B different content", 40, 24);

        $syncedA = $a->syncWith($b);

        // Each viewport maintains its own content.
        $this->assertStringContainsString('A content', $syncedA->view());
        // The synced viewport's content is not rendered by $syncedA.
        $this->assertStringNotContainsString('B different', $syncedA->view());
    }

    public function testSyncWithCanBeOverwritten(): void
    {
        $a = Viewport::withContent("A\nB\nC", 40, 10);
        $b = Viewport::withContent("1\n2\n3", 40, 10);
        $c = Viewport::withContent("alpha\nbeta\ngamma", 40, 10);

        // A synced with B, then B synced with C (B's syncWith overwrites).
        $ab = $a->syncWith($b);
        $bc = $b->syncWith($c);

        $this->assertTrue($ab->isSynced());
        $this->assertTrue($bc->isSynced());
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

        $a = Viewport::withContent($content, 40, 10);
        $b = Viewport::withContent($content, 40, 10);

        $synced = $a->syncWith($b)->setYOffset(1);

        $this->assertTrue($synced->isSynced());
        $this->assertSame(1, $synced->yOffset());
    }

    public function testSetContentPreservesSyncRelationship(): void
    {
        $a = Viewport::withContent("original\ncontent", 40, 24);
        $b = Viewport::withContent("other\npanel", 40, 24);

        $synced = $a->syncWith($b)->setContent("new\ncontent\nhere");

        $this->assertTrue($synced->isSynced());
    }

    public function testSetYOffsetPreservesSyncRelationship(): void
    {
        // Use 50 lines so content exceeds viewport height, allowing scroll.
        $lines = [];
        for ($i = 0; $i < 50; $i++) {
            $lines[] = "line{$i}";
        }
        $content = implode("\n", $lines);

        $a = Viewport::withContent($content, 40, 24);
        $b = Viewport::withContent($content, 40, 24);

        $synced = $a->syncWith($b)->setYOffset(5);

        $this->assertTrue($synced->isSynced());
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

        $left = Viewport::withContent($leftContent, 40, 24);
        $right = Viewport::withContent($rightContent, 40, 24);

        // Caller establishes sync relationship.
        $leftSynced = $left->syncWith($right);

        // Caller coordinates scroll: apply same offset to both viewports.
        $scrollOffset = 20;
        $leftScrolled = $leftSynced->setYOffset($scrollOffset);
        $rightScrolled = $right->setYOffset($scrollOffset);

        $this->assertSame($scrollOffset, $leftScrolled->yOffset());
        $this->assertSame($scrollOffset, $rightScrolled->yOffset());
        $this->assertTrue($leftScrolled->isSynced());

        // Left panel shows content from scroll offset.
        $this->assertStringContainsString('function20', $leftScrolled->view());
        // Right panel shows translated content from same offset.
        $this->assertStringContainsString('func20', $rightScrolled->view());
    }
}
