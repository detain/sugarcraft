<?php

declare(strict_types=1);

namespace SugarCraft\Overlay\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Overlay\Position;
use SugarCraft\Overlay\SegmentMemo;
use SugarCraft\Overlay\Veil;
use SugarCraft\Overlay\VeilStack;

/**
 * The compositor keeps the last background's row segments (rank 35): a modal
 * re-composited over an unchanged screen re-scans nothing, and the memo is
 * shared with every clone a veil makes.
 */
final class SegmentMemoTest extends TestCase
{
    private const BG = "\x1b[31mred\x1b[0m line one\nline two 語\nline three";

    private static function memoOf(Veil $veil): SegmentMemo
    {
        $memo = (new \ReflectionProperty(Veil::class, 'segments'))->getValue($veil);
        self::assertInstanceOf(SegmentMemo::class, $memo);

        return $memo;
    }

    public function testRepeatedCompositesOverOneBackgroundSegmentItOnce(): void
    {
        $veil = Veil::new()->withBackdrop(50);
        $first = $veil->withoutSession()->composite('[ok]', self::BG, Position::CENTER, Position::CENTER);
        $veil->composite('[ok]', self::BG, Position::CENTER, Position::CENTER);
        $veil->composite('[no]', self::BG, Position::TOP, Position::LEFT);

        $this->assertSame(1, self::memoOf($veil)->misses('background'));
        $this->assertSame(2, self::memoOf($veil)->misses('foreground'));
        $this->assertSame(
            Veil::new()->withBackdrop(50)->composite('[ok]', self::BG, Position::CENTER, Position::CENTER),
            $first,
            'a memo hit paints the same bytes as a cold composite',
        );
    }

    public function testAChangedBackgroundIsSegmentedAgain(): void
    {
        $veil = Veil::new();
        $veil->composite('x', self::BG, Position::CENTER, Position::CENTER);
        $veil->composite('x', self::BG . '!', Position::CENTER, Position::CENTER);

        $this->assertSame(2, self::memoOf($veil)->misses('background'));
    }

    public function testClonesAndSessionlessCopiesShareTheMemo(): void
    {
        $veil = Veil::new()->withBackdrop(20);
        $veil->composite('x', self::BG, Position::CENTER, Position::CENTER);

        $clone = $veil->withZIndex(3)->withoutSession();
        $clone->composite('x', self::BG, Position::CENTER, Position::CENTER);

        $this->assertSame(self::memoOf($veil), self::memoOf($clone));
        $this->assertSame(1, self::memoOf($veil)->misses('background'));
    }

    public function testStackLayersHitTheMemoAcrossFrames(): void
    {
        $layer = Veil::new()->withContent('[a]');
        $stack = VeilStack::new()->add($layer);

        $first = $stack->composite(self::BG, Position::CENTER, Position::CENTER);
        $second = $stack->composite(self::BG, Position::CENTER, Position::CENTER);

        $this->assertSame($first, $second);
        $this->assertSame(1, self::memoOf($layer)->misses('background'));
    }

    public function testTheMemoReturnsTheSegmentsOfItsText(): void
    {
        $memo = new SegmentMemo();
        $seg = $memo->segment('background', "ab\n語");

        $this->assertSame(['ab', '語'], $seg['lines']);
        $this->assertSame([2, 2], $seg['widths']);
        $this->assertSame(2, $seg['width']);
        $this->assertSame($seg, $memo->segment('background', "ab\n語"));
        $this->assertSame(1, $memo->misses('background'));
        $this->assertSame(['lines' => [], 'segs' => [], 'widths' => [], 'width' => 0], $memo->segment('foreground', ''));
    }
}
