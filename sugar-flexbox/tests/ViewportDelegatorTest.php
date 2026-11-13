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
        $vp = Viewport::fromContent($wideContent, 20, 10);
        $vp2 = $vp->scrollLeft(2);

        $this->assertNotSame($vp, $vp2);
    }

    public function testScrollRightReturnsNewInstance(): void
    {
        $wideContent = str_repeat("Very long line content here\n", 10);
        $vp = Viewport::fromContent($wideContent, 20, 10);
        $vp2 = $vp->scrollRight(3);

        $this->assertNotSame($vp, $vp2);
    }

    public function testAtLeftmostReturnsTrueAtZeroOffset(): void
    {
        $vp = Viewport::fromContent(\implode("\n", \range(1, 50)), 20, 10);
        $this->assertTrue($vp->atLeftmost());
    }

    public function testAtRightmostNeedsContentWiderThanTheViewport(): void
    {
        // FBX-13: the old case used content NARROWER than the box, where
        // 'rightmost' is trivially true and proves nothing. Real law:
        // wide content starts false, and only scrolling to the far edge
        // flips it — pixels and query state must agree (FBX-02 lineage).
        $content = implode("\n", array_map(
            static fn (int $n): string => str_repeat('x', 40) . $n,
            range(1, 5),
        ));
        $vp = Viewport::fromContent($content, 20, 5);
        $this->assertFalse($vp->atRightmost(), 'wide content starts away from the right edge');
        $this->assertTrue($vp->atLeftmost());

        $scrolled = $vp->scrollRight(60);
        $this->assertTrue($scrolled->atRightmost());
        $this->assertFalse($scrolled->atLeftmost());
        $this->assertSame(1.0, $scrolled->horizontalScrollPercent());
    }

    public function testHorizontalScrollPercent(): void
    {
        $vp = Viewport::fromContent(\implode("\n", \range(1, 50)), 20, 10);
        $percent = $vp->horizontalScrollPercent();

        $this->assertIsFloat($percent);
        $this->assertGreaterThanOrEqual(0.0, $percent);
        $this->assertLessThanOrEqual(1.0, $percent);
    }

    public function testWithHorizontalStepReturnsNewInstance(): void
    {
        $vp = Viewport::fromContent(\implode("\n", \range(1, 50)), 20, 10);
        $vp2 = $vp->withHorizontalStep(5);

        $this->assertNotSame($vp, $vp2);
    }

    // ---- Mouse wheel delegators -------------------------------------------

    public function testWithMouseWheelEnabledReturnsNewInstance(): void
    {
        $vp = Viewport::fromContent(\implode("\n", \range(1, 50)), 20, 10);
        $vp2 = $vp->withMouseWheelEnabled(true);

        $this->assertNotSame($vp, $vp2);
    }

    public function testWithMouseWheelDeltaReturnsNewInstance(): void
    {
        $vp = Viewport::fromContent(\implode("\n", \range(1, 50)), 20, 10);
        $vp2 = $vp->withMouseWheelDelta(5);

        $this->assertNotSame($vp, $vp2);
    }

    // ---- Scrollbar delegators ---------------------------------------------

    public function testWithSmoothScrollReturnsNewInstance(): void
    {
        $vp = Viewport::fromContent(\implode("\n", \range(1, 50)), 20, 10);
        $vp2 = $vp->withSmoothScroll(true);

        $this->assertNotSame($vp, $vp2);
    }

    public function testWithScrollbarRunesReturnsNewInstance(): void
    {
        $vp = Viewport::fromContent(\implode("\n", \range(1, 50)), 20, 10);
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
        $this->assertInstanceOf(\SugarCraft\Forms\Scrollbar\Scrollbar::class, $sb->widgetScrollbar());
        $this->assertSame($sb->widgetScrollbar(), $sb->widgetScrollbar());
    }

    // ---- Dimension delegators ---------------------------------------------

    public function testWithSizeReturnsNewInstance(): void
    {
        $vp = Viewport::fromContent("hello\nworld", 20, 10);
        $vp2 = $vp->withSize(40, 20);

        $this->assertNotSame($vp, $vp2);
        $this->assertSame(40, $vp2->getWidth());
        $this->assertSame(20, $vp2->getHeight());
    }

    public function testSetWidthReturnsNewInstance(): void
    {
        // BC spelling (FBX-06 collapsed sizing onto withSize; the roster
        // pins the equality) — single-dimension resize must still answer
        // a NEW Viewport keeping the other dimension.
        $vp = Viewport::fromContent("hello\nworld", 20, 10);
        $vp2 = $vp->setWidth(30);

        $this->assertNotSame($vp, $vp2);
        $this->assertSame(30, $vp2->getWidth());
        $this->assertEquals($vp->withSize(30, 10), $vp2);
    }

    public function testSetHeightReturnsNewInstance(): void
    {
        // BC spelling (FBX-06) — see the setWidth twin above.
        $vp = Viewport::fromContent("hello\nworld", 20, 10);
        $vp2 = $vp->setHeight(15);

        $this->assertNotSame($vp, $vp2);
        $this->assertSame(15, $vp2->getHeight());
        $this->assertEquals($vp->withSize(20, 15), $vp2);
    }

    public function testWithContentIsTheInstanceSetterAndKeepsGeometry(): void
    {
        // FBX-06: the retired static BC seat made room for the setter; the
        // receiver's geometry must survive (the old misuse rebuilt 80x24).
        $vp = Viewport::new(20, 4);
        $next = $vp->withContent("a\nb");

        $this->assertNotSame($vp, $next);
        $this->assertSame(20, $next->width());
        $this->assertSame(4, $next->height());
        $this->assertSame(2, $next->totalLineCount());
        $this->assertEquals(Viewport::fromContent("a\nb", 20, 4), $next);
    }

    public function testRetiredStaticWithContentFailsWithTheEnginesNonStaticError(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessageMatches('/non-static method .*withContent/i');
        Viewport::{'withContent'}('abc', 20, 10);
    }

    public function testWithOffsetSettersMoveTheWindow(): void
    {
        $vp = Viewport::fromContent(implode("\n", range(1, 30)), 20, 5);
        $this->assertSame(7, $vp->withYOffset(7)->yOffset());
        $this->assertSame(0, $vp->yOffset(), 'receiver unchanged');
        $wide = Viewport::fromContent(str_repeat('x', 60), 10, 2);
        $this->assertSame(4, $wide->withXOffset(4)->xOffset());
    }

    // ---- X offset delegators ----------------------------------------------

    public function testSetXOffsetReturnsNewInstance(): void
    {
        $wideContent = str_repeat("Very long line content here that exceeds width\n", 5);
        $vp = Viewport::fromContent($wideContent, 20, 10);
        $vp2 = $vp->withXOffset(5);

        $this->assertNotSame($vp, $vp2);
    }

    // ---- Injected scrollbar component (FBX-01) -------------------------

    public function testXOffsetDefaultsToZero(): void
    {
        $vp = Viewport::fromContent(\implode("\n", \range(1, 50)), 20, 10);
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
        $vp = Viewport::fromContent($content, 20, 6)
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
        $a = Viewport::fromContent($content, 20, 6)->showScrollbar(true)->view();
        $b = Viewport::fromContent($content, 20, 6)->withScrollbar(true)->view();

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
        $vp = Viewport::fromContent($content, 40, 4)->showScrollbar(true);
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
        $vp = Viewport::fromContent($content, 20, 6)
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
        $view = Viewport::fromContent($content, 20, 6)->showScrollbar(true)->view();
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
        $vp = Viewport::fromContent($content, 20, 4)->showScrollbar(true)->withXOffset(15);
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
            Viewport::fromContent($content, 20, 6)->view(),
            Viewport::fromContent($content, 20, 6)->showScrollbar(false)->view(),
            'bar off is literally the old path',
        );
    }

    // ---- FBX-13: drive the delegator for real through update() ---------

    /** @return \SugarCraft\Core\Msg\MouseWheelMsg */
    private static function wheel(\SugarCraft\Core\MouseButton $button): \SugarCraft\Core\Msg\MouseWheelMsg
    {
        return new \SugarCraft\Core\Msg\MouseWheelMsg(
            0, 0, $button, \SugarCraft\Core\MouseAction::Press,
        );
    }

    public function testWheelDownThroughUpdateMovesByTheDefaultDelta(): void
    {
        [$after, $cmd] = $this->fifty()->update(self::wheel(\SugarCraft\Core\MouseButton::WheelDown));

        $this->assertSame(3, $after->yOffset(), 'default wheel delta is 3 lines');
        $this->assertNull($cmd, 'the wheel leg never arms an animation');
        // Immutability law: the receiver answers zero.
        $this->assertSame(0, $this->fifty()->yOffset());
    }

    public function testWheelUpThroughUpdateMovesBackExactly(): void
    {
        $vp = $this->fifty()->withYOffset(10);
        [$after, $cmd] = $vp->update(self::wheel(\SugarCraft\Core\MouseButton::WheelUp));

        $this->assertSame(7, $after->yOffset());
        $this->assertNull($cmd);
    }

    public function testWheelMovesByTheConfiguredDelta(): void
    {
        [$after,] = $this->fifty()
            ->withMouseWheelDelta(5)
            ->update(self::wheel(\SugarCraft\Core\MouseButton::WheelDown));
        $this->assertSame(5, $after->yOffset(), 'withMouseWheelDelta feeds the wheel leg');
    }

    public function testWheelIsIgnoredWhileDisabled(): void
    {
        [$after, $cmd] = $this->fifty()
            ->withMouseWheelEnabled(false)
            ->update(self::wheel(\SugarCraft\Core\MouseButton::WheelDown));

        $this->assertSame(0, $after->yOffset(), 'the disabled door must move nothing');
        $this->assertNull($cmd);
    }

    public function testWheelWithANonWheelButtonCarriesNoVerticalMeaning(): void
    {
        $msg = new \SugarCraft\Core\Msg\MouseWheelMsg(
            0, 0, \SugarCraft\Core\MouseButton::Left, \SugarCraft\Core\MouseAction::Press,
        );
        [$after, $cmd] = $this->fifty()->update($msg);

        $this->assertSame(0, $after->yOffset());
        $this->assertNull($cmd);
    }

    public function testHorizontalStepKeysDriveXOffsetAndReachTheFrame(): void
    {
        // Content must actually be WIDER than the box or the delegate
        // clamps every horizontal move to 0 (the old identity-only
        // forward could never notice).
        $content = implode("\n", array_map(
            static fn (int $n): string => \str_pad("L{$n}", 8, '_', \STR_PAD_RIGHT) . str_repeat('x', 33),
            range(1, 5),
        )); // each line 41 cells in a 20-wide box

        $vp = Viewport::fromContent($content, 20, 5)->withHorizontalStep(7);
        $right = new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Right);

        [$once,] = $vp->update($right);
        $this->assertSame(7, $once->xOffset(), 'one Right key = one configured step');
        [$twice,] = $once->update($right);
        $this->assertSame(14, $twice->xOffset());

        $left = new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Left);
        [$back,] = $twice->update($left);
        $this->assertSame(7, $back->xOffset(), 'Left steps back the same budget');

        // Pixels agree with the query state (the FBX-02 law), exactly:
        // on the boxed (scrollbar) path the first frame row is the raw
        // slice the offset promises, clamped to the body budget (width
        // minus the one-cell bar column) with the thumb rune appended.
        // (The bare plain path returns unclamped variable-width rows —
        // that path-shape contract is the FBX-43 decision, not this pin.)
        $line0 = 'L1______' . str_repeat('x', 33);
        $boxed = $twice->showScrollbar(true);
        $rows = explode("\n", $boxed->view());
        $this->assertSame(\substr($line0, 14, 19) . '█', $rows[0], 'step-scrolled pixels start at the column the state names');
        $zeroRows = explode("\n", $vp->showScrollbar(true)->view());
        $this->assertSame(\substr($line0, 0, 19) . '█', $zeroRows[0], 'offset 0 still paints from column 0');
    }

    public function testSmoothScrollInterpolationIsPinnedAcrossTickFrames(): void
    {
        // Deterministic lerp: factor 0.15 per ViewportTickMsg, ten frames,
        // snap on the last. No wall clock is involved, so the exact
        // vector is safe to pin — a changed easing must be a deliberate
        // (disclosed) move, not a silent law change.
        $vp = $this->fifty()->withSmoothScroll(true);

        [$anim, $cmd] = $vp->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::End));
        $this->assertNotNull($cmd, 'a smooth-scrolled move arms a tick command');
        $this->assertSame(0, $anim->yOffset(), 'the first frame stays put and lets ticks run');

        $seen = [];
        for ($frame = 0; $frame < 10; $frame++) {
            [$anim, $cmd] = $anim->update(new \SugarCraft\Forms\Viewport\ViewportTickMsg());
            $seen[] = $anim->yOffset();
        }
        $this->assertSame([6, 11, 15, 19, 22, 25, 27, 29, 31, 40], $seen);
        $this->assertNull($cmd, 'the animation releases its command at the snap');
        // A post-completion tick answers unchanged with no command.
        [$settled, $cmd2] = $anim->update(new \SugarCraft\Forms\Viewport\ViewportTickMsg());
        $this->assertSame(40, $settled->yOffset());
        $this->assertNull($cmd2);
    }

    public function testWheelBypassesSmoothScrollEvenWhenArmed(): void
    {
        // The delegate documents the wheel as an instant jump; the
        // wrapper must not smuggle it into the animation either.
        [$anim, $cmd] = $this->fifty()
            ->withSmoothScroll(true)
            ->update(self::wheel(\SugarCraft\Core\MouseButton::WheelDown));

        $this->assertSame(3, $anim->yOffset(), 'no easing frame: the jump lands immediately');
        $this->assertNull($cmd);
    }

    // ---- Core\Model contract (FBX-21) -----------------------------------

    public function testViewportIsARealCoreModel(): void
    {
        $this->assertInstanceOf(\SugarCraft\Core\Model::class, Viewport::new(20, 5));
    }

    public function testSubscriptionsForwardToTheWrappedViewport(): void
    {
        $vp = Viewport::fromContent("a\nb", 20, 5);
        $this->assertNull($vp->subscriptions());
        $this->assertSame($vp->subscriptions(), Viewport::new(20, 5)->subscriptions());
    }

    public function testUpdateKeepsTheWrapperAndItsStickyRegions(): void
    {
        $vp = $this->fifty()->withStickyHeader(2);
        [$next, ] = $vp->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Down));

        $this->assertInstanceOf(Viewport::class, $next);
        $this->assertSame(1, $next->yOffset());
        $this->assertSame($vp->withYOffset(1)->view(), $next->view(), 'sticky header survives update()');
    }

    public function testInitMatchesTheDelegate(): void
    {
        $this->assertNull(Viewport::new(20, 5)->init());
    }

    private function fifty(): Viewport
    {
        return Viewport::fromContent(\implode("\n", \range(1, 50)), 20, 10);
    }
}
