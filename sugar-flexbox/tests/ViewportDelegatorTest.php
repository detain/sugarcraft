<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Flexbox\Scrollbar;
use SugarCraft\Flexbox\Viewport;

/**
 * Tests for Viewport delegator methods not covered in other test files.
 */
final class ViewportDelegatorTest extends TestCase
{
    // ---- Horizontal navigation delegators ---------------------------------

    public function testScrollLeftReturnsNewInstance(): void
    {
        // Create content that exceeds viewport width so horizontal scroll is possible
        $wideContent = str_repeat("Very long line content here\n", 10);
        $vp = Viewport::withContent($wideContent, 20, 10);
        $vp2 = $vp->scrollLeft(2);

        $this->assertNotSame($vp, $vp2);
    }

    public function testScrollRightReturnsNewInstance(): void
    {
        $wideContent = str_repeat("Very long line content here\n", 10);
        $vp = Viewport::withContent($wideContent, 20, 10);
        $vp2 = $vp->scrollRight(3);

        $this->assertNotSame($vp, $vp2);
    }

    public function testAtLeftmostReturnsTrueAtZeroOffset(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10);
        $this->assertTrue($vp->atLeftmost());
    }

    public function testAtRightmostReturnsTrueWhenScrolledToEnd(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 5)), 20, 10);
        // Content fits within viewport width, so at rightmost
        $this->assertTrue($vp->atRightmost());
    }

    public function testHorizontalScrollPercent(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10);
        $percent = $vp->horizontalScrollPercent();

        $this->assertIsFloat($percent);
        $this->assertGreaterThanOrEqual(0.0, $percent);
        $this->assertLessThanOrEqual(1.0, $percent);
    }

    public function testWithHorizontalStepReturnsNewInstance(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10);
        $vp2 = $vp->withHorizontalStep(5);

        $this->assertNotSame($vp, $vp2);
    }

    // ---- Mouse wheel delegators -------------------------------------------

    public function testWithMouseWheelEnabledReturnsNewInstance(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10);
        $vp2 = $vp->withMouseWheelEnabled(true);

        $this->assertNotSame($vp, $vp2);
    }

    public function testWithMouseWheelDeltaReturnsNewInstance(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10);
        $vp2 = $vp->withMouseWheelDelta(5);

        $this->assertNotSame($vp, $vp2);
    }

    // ---- Scrollbar delegators ---------------------------------------------

    public function testWithSmoothScrollReturnsNewInstance(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10);
        $vp2 = $vp->withSmoothScroll(true);

        $this->assertNotSame($vp, $vp2);
    }

    public function testWithScrollbarRunesReturnsNewInstance(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10);
        $vp2 = $vp->withScrollbarRunes('█', '░');

        $this->assertNotSame($vp, $vp2);
    }

    /**
     * The unwrap contract that makes {@see Viewport::withVerticalScrollbar()}
     * type-checkable (historically: the signature used to silently demand the
     * widgets scrollbar despite naming the lib's own class).
     */
    public function testScrollbarWrapsAndUnwrapsTheCanonicalWidget(): void
    {
        $sb = \SugarCraft\Flexbox\Scrollbar::vertical();
        $this->assertInstanceOf(\SugarCraft\Widgets\Scrollbar\Scrollbar::class, $sb->widgetScrollbar());
        $this->assertSame($sb->widgetScrollbar(), $sb->widgetScrollbar());
    }

    // ---- Dimension delegators ---------------------------------------------

    public function testWithSizeReturnsNewInstance(): void
    {
        $vp = Viewport::withContent("hello\nworld", 20, 10);
        $vp2 = $vp->withSize(40, 20);

        $this->assertNotSame($vp, $vp2);
        $this->assertSame(40, $vp2->getWidth());
        $this->assertSame(20, $vp2->getHeight());
    }

    public function testSetWidthReturnsNewInstance(): void
    {
        $vp = Viewport::withContent("hello\nworld", 20, 10);
        $vp2 = $vp->setWidth(30);

        $this->assertNotSame($vp, $vp2);
        $this->assertSame(30, $vp2->getWidth());
    }

    public function testSetHeightReturnsNewInstance(): void
    {
        $vp = Viewport::withContent("hello\nworld", 20, 10);
        $vp2 = $vp->setHeight(15);

        $this->assertNotSame($vp, $vp2);
        $this->assertSame(15, $vp2->getHeight());
    }

    // ---- X offset delegators ----------------------------------------------

    public function testSetXOffsetReturnsNewInstance(): void
    {
        $wideContent = str_repeat("Very long line content here that exceeds width\n", 5);
        $vp = Viewport::withContent($wideContent, 20, 10);
        $vp2 = $vp->setXOffset(5);

        $this->assertNotSame($vp, $vp2);
    }

    // ---- Injected scrollbar component (FBX-01) -------------------------

    public function testXOffsetDefaultsToZero(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10);
        $this->assertSame(0, $vp->xOffset());
    }

    public function testInjectedScrollbarArmsAndPaintsThePlainPath(): void
    {
        // Historically the attach hop forwarded the component but never
        // armed the flag (the delegate's copy() omits showScrollbar), so
        // the documented companion painted nothing at all.
        $content = implode("\n", array_map(
            static fn (int $n): string => 'line ' . $n,
            range(1, 30),
        ));
        $vp = Viewport::withContent($content, 20, 6)
            ->withVerticalScrollbar(Scrollbar::vertical());

        $view = $vp->view();
        $this->assertStringContainsString('█', $view, 'the injected thumb must paint');
        $this->assertStringContainsString('░', $view, 'the injected track must paint');
        foreach (explode("\n", $view) as $i => $line) {
            $this->assertSame(20, \SugarCraft\Core\Util\Width::string($line), "plain row $i");
        }
    }

    public function testDeprecatedWithScrollbarToggleIsByteIdenticalToTheBareTwin(): void
    {
        $content = implode("\n", array_map(
            static fn (int $n): string => 'line ' . $n,
            range(1, 30),
        ));
        $a = Viewport::withContent($content, 20, 6)->showScrollbar(true)->view();
        $b = Viewport::withContent($content, 20, 6)->withScrollbar(true)->view();

        $this->assertSame($a, $b);
        $this->assertStringContainsString('█', $a, 'the armed plain toggle paints the rune bar');
    }

    // ---- FBX-03: width-invariant clamp on the base path ---------------

    public function testOverlongContentNeverEscapesTheBoxWhenBarIsOn(): void
    {
        // QA repro: 92-cell lines in a 40-wide box with the bar armed used
        // to emit 41+ cell rows because the delegate's clamp is skipped
        // whenever showScrollbar is true.
        $content = implode("\n", array_map(
            static fn (int $n): string => str_repeat('x', 92) . $n,
            range(1, 10),
        ));
        $vp = Viewport::withContent($content, 40, 4)->showScrollbar(true);
        foreach (explode("\n", $vp->view()) as $i => $line) {
            $this->assertSame(40, \SugarCraft\Core\Util\Width::string($line), "repro row $i");
        }
    }

    public function testWideThumbRuneCannotPushPlainRowsPastWidth(): void
    {
        $content = implode("\n", array_map(
            static fn (int $n): string => 'line ' . $n,
            range(1, 30),
        ));
        $vp = Viewport::withContent($content, 20, 6)
            ->withScrollbarRunes("\u{6771}", '│')
            ->showScrollbar(true);
        foreach (explode("\n", $vp->view()) as $i => $line) {
            $this->assertSame(20, \SugarCraft\Core\Util\Width::string($line), "wide-rune row $i");
        }
        $this->assertStringContainsString("\u{6771}", $vp->view(), 'the wide thumb still paints');
    }

    public function testWellFormedBarFrameStaysByteIdenticalToTheDelegateLaw(): void
    {
        // Short rows + 1-cell runes: the wrapper defence must not move a
        // single byte of the healthy frame — body padded to width-1, bar
        // column last, thumb on the first row of a freshly opened doc.
        $content = implode("\n", array_map(
            static fn (int $n): string => 'line ' . $n,
            range(1, 30),
        ));
        $view = Viewport::withContent($content, 20, 6)->showScrollbar(true)->view();
        $expected = "line 1             █\n"
            . "line 2             │\n"
            . "line 3             │\n"
            . "line 4             │\n"
            . "line 5             │\n"
            . "line 6             │";
        $this->assertSame($expected, $view);
    }

    public function testHorizontalOffsetAndBarTogetherStayInsideTheBox(): void
    {
        $content = implode("\n", array_map(
            static fn (int $n): string => str_repeat('y', 40),
            range(1, 12),
        ));
        $vp = Viewport::withContent($content, 20, 4)->showScrollbar(true)->setXOffset(15);
        foreach (explode("\n", $vp->view()) as $i => $line) {
            $this->assertSame(20, \SugarCraft\Core\Util\Width::string($line), "offset+bar row $i");
        }
    }

    public function testBarOffKeepsThePlainDelegatePathUntouched(): void
    {
        $content = implode("\n", array_map(
            static fn (int $n): string => 'line ' . $n,
            range(1, 30),
        ));
        $this->assertSame(
            Viewport::withContent($content, 20, 6)->view(),
            Viewport::withContent($content, 20, 6)->showScrollbar(false)->view(),
            'bar off is literally the old path',
        );
    }
}
