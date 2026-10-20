<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\KittyOptions;
use SugarCraft\Image\Renderer\KittyRenderer;

/**
 * F-10: virtual placement is a store (a=T,U=1) + payload chunks + a
 * control-only placement (a=p,U=1) pair. The pre-F-10 shape streamed the
 * payload under a=p — spec-invalid for the place action — and never
 * emitted U= anywhere, so the "under text" feature did not exist.
 */
final class KittyVirtualImageTest extends TestCase
{
    private KittyRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new KittyRenderer();
    }

    public function testUseVirtualStoresWithUAndAppendsAPlaceControlFrame(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/4x2.png');
        $opts  = KittyOptions::transmit(1)->withUseVirtual(true);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        // Store frame: a=T with the virtual flag, opening the transaction.
        $this->assertStringStartsWith("\x1b_Ga=T,U=1,i=1,f=100,q=2,c=8,r=4,m=1;\x1b\\", $out);
        // Placement frame: control-only, exact bytes — no ';' payload part.
        $this->assertStringEndsWith("\x1b_Ga=p,i=1,U=1\x1b\\", $out);
    }

    public function testUseVirtualWithoutImageIdOmitsI(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/4x2.png');
        $opts  = KittyOptions::transmit()->withUseVirtual(true);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        // Store frame still opens with a=T,U=1 but no i=.
        $this->assertStringStartsWith("\x1b_Ga=T,U=1,f=100,q=2,c=8,r=4,m=1;\x1b\\", $out);
        // Control frame still places virtually, still without i=.
        $this->assertStringEndsWith("\x1b_Ga=p,U=1\x1b\\", $out);
    }

    public function testUseVirtualWithZIndexStoresZOnTheStoreFrame(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/4x2.png');
        $opts  = KittyOptions::transmit(5)->withUseVirtual(true)->withZIndex(3);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        $this->assertStringStartsWith("\x1b_Ga=T,U=1,i=5,z=3,f=100,q=2,c=8,r=4,m=1;\x1b\\", $out);
        $this->assertStringEndsWith("\x1b_Ga=p,i=5,U=1\x1b\\", $out);
    }

    public function testUseVirtualFalseStoresPlainlyWithNoControlFrame(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/4x2.png');
        $opts  = KittyOptions::transmit(1);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        // Exact begin bytes prove U= is absent on a plain transmit.
        $this->assertStringStartsWith("\x1b_Ga=T,i=1,f=100,q=2,c=8,r=4,m=1;\x1b\\", $out);
        $this->assertStringNotContainsString('a=p', $out);
    }

    public function testUseVirtualStreamsPayloadUnderAThenPlacesWithControl(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/4x2.png');
        $opts  = KittyOptions::transmit(1)->withUseVirtual(true);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        // The payload rides the a=T transaction (m-flagged chunks), not a=p:
        // its base64 lands BEFORE the closing control frame, and the control
        // frame above ends the output with zero payload of its own.
        $b64 = base64_encode($image->bytes);
        $this->assertStringContainsString("\x1b_Gm=0;{$b64}\x1b\\", $out);
        $this->assertLessThan(
            (int) strpos($out, "\x1b_Ga=p"),
            (int) strpos($out, $b64),
            'payload must precede the placement control frame',
        );
    }

    public function testPlaceBranchEmitsZIndex(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/4x2.png');
        $opts  = KittyOptions::place(1, 5, 10)->withZIndex(5);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        // F-10: placement is always virtual-flagged (composited under text).
        $this->assertSame("\x1b_Ga=p,i=1,U=1,x=5,y=10,z=5\x1b\\", $out);
    }

    public function testPlaceBranchOmitsZeroZIndex(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/4x2.png');
        $opts  = KittyOptions::place(1);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        // z= should not appear when z-index is 0 (sentinel = no z field);
        // the exact frame byte proves it and the U=1 virtual flag presence.
        $this->assertSame("\x1b_Ga=p,i=1,U=1\x1b\\", $out);
    }
}
