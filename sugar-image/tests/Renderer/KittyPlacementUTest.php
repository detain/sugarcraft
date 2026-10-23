<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\KittyOptions;
use SugarCraft\Image\Renderer\KittyRenderer;

/**
 * The a=p placement frame's U parameter was hardcoded 1 (under text),
 * so over-text placement was un-expressible (documented seam in the chafa
 * campaign rows). KittyOptions now carries the value — the default keeps
 * every frame emitted today byte-identical, and withPlacementU() opens the
 * other side.
 */
final class KittyPlacementUTest extends TestCase
{
    private KittyRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new KittyRenderer();
    }

    private function image(): ImageSource
    {
        return ImageSource::fromFile(__DIR__ . '/../fixtures/4x2.png');
    }

    public function testThePlacementConstantsAreTheSpecValues(): void
    {
        $this->assertSame(1, KittyOptions::PLACEMENT_UNDER_TEXT);
        $this->assertSame(0, KittyOptions::PLACEMENT_OVER_TEXT);
    }

    public function testPlaceDefaultsToUnderTextByteIdentically(): void
    {
        $out = $this->renderer->renderWithOptions($this->image(), 8, 4, KittyOptions::place(1, 5, 10));

        // The pre-S7.1 frame exactly — the default must not move a byte.
        $this->assertSame("\x1b_Ga=p,i=1,U=1,x=5,y=10\x1b\\", $out);
    }

    public function testPlaceOverTextEmitsUZeroOnTheFrame(): void
    {
        $opts = KittyOptions::place(1, 5, 10)->withPlacementU(KittyOptions::PLACEMENT_OVER_TEXT);

        $out = $this->renderer->renderWithOptions($this->image(), 8, 4, $opts);

        // U=0 must SERIALIZE (a falsy-drop formatter would lose it here):
        // this exact frame is the over-text placement the seam could not
        // express before S7.1.
        $this->assertSame("\x1b_Ga=p,i=1,U=0,x=5,y=10\x1b\\", $out);
    }

    public function testVirtualTrailingFrameFollowsPlacementUStoreKeepsUnderFlag(): void
    {
        $opts = KittyOptions::transmit(7)->withUseVirtual(true)->withPlacementU(KittyOptions::PLACEMENT_OVER_TEXT);

        $out = $this->renderer->renderWithOptions($this->image(), 8, 4, $opts);

        // The store frame's U=1 is the background-compositor flag, not a
        // placement choice — it stays 1 whatever the placement value...
        $this->assertStringStartsWith("\x1b_Ga=T,U=1,i=7,f=100,q=2,c=8,r=4,m=1;\x1b\\", $out);
        // ...while the trailing placement frame carries the requested U=0.
        $this->assertStringEndsWith("\x1b_Ga=p,i=7,U=0\x1b\\", $out);
    }

    public function testVirtualDefaultPairStaysByteFrozen(): void
    {
        $opts = KittyOptions::transmit(1)->withUseVirtual(true);

        $out = $this->renderer->renderWithOptions($this->image(), 8, 4, $opts);

        // Same pair KittyVirtualImageTest pins — proves the threading is a
        // no-op at the default for the whole store+place shape.
        $this->assertStringStartsWith("\x1b_Ga=T,U=1,i=1,f=100,q=2,c=8,r=4,m=1;\x1b\\", $out);
        $this->assertStringEndsWith("\x1b_Ga=p,i=1,U=1\x1b\\", $out);
    }

    public function testPlacementSurvivesEveryWithChainLeg(): void
    {
        $opts = KittyOptions::place(2, 1, 2)->withPlacementU(KittyOptions::PLACEMENT_OVER_TEXT);

        // Each with* rebuilds the instance; a dropped placementU carry would
        // silently reset the frame back to under-text placement.
        $opts = $opts->withZIndex(4);
        $this->assertSame(KittyOptions::PLACEMENT_OVER_TEXT, $opts->placementU());

        $opts = $opts->withCompression(1);
        $this->assertSame(KittyOptions::PLACEMENT_OVER_TEXT, $opts->placementU());

        $opts = $opts->withUseVirtual(true);
        $this->assertSame(KittyOptions::PLACEMENT_OVER_TEXT, $opts->placementU());

        $opts = $opts->withCellDimensions(3, 2);
        $this->assertSame(KittyOptions::PLACEMENT_OVER_TEXT, $opts->placementU());

        $opts = $opts->withChunkBytes(300);
        $this->assertSame(KittyOptions::PLACEMENT_OVER_TEXT, $opts->placementU());

        // And the value reaches the wire through the place branch.
        $out = $this->renderer->renderWithOptions($this->image(), 8, 4, $opts);
        $this->assertSame("\x1b_Ga=p,i=2,U=0,x=1,y=2,z=4\x1b\\", $out);
    }

    public function testRefusesPlacementAboveTheTable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Kitty placement U must be 0 (over text) or 1 (under text), got 2');

        KittyOptions::place(1)->withPlacementU(2);
    }

    public function testRefusesNegativePlacement(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Kitty placement U must be 0 (over text) or 1 (under text), got -1');

        KittyOptions::place(1)->withPlacementU(-1);
    }
}
