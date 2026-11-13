<?php

declare(strict_types=1);

namespace SugarCraft\Gallery\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Gallery\CardSize;
use SugarCraft\Gallery\PosterCard;
use SugarCraft\Gallery\PosterGrid;
use SugarCraft\Gallery\Rail;

final class CardSizeTest extends TestCase
{
    protected function setUp(): void
    {
        T::setLocale('en');
    }

    protected function tearDown(): void
    {
        T::reset();
    }

    public function testClampsAtIngressAndAccessors(): void
    {
        $s = CardSize::new(1, 0, -3, -4);
        self::assertSame([4, 1, 0, 0], [$s->width(), $s->height(), $s->hSpacing(), $s->vSpacing()]);
        $d = CardSize::new(10, 5);
        self::assertSame([10, 5, 2, 1], [$d->width(), $d->height(), $d->hSpacing(), $d->vSpacing()]);
    }

    public function testWithersReturnNewClampedInstances(): void
    {
        $s = CardSize::new(10, 5);
        self::assertSame(12, $s->withWidth(12)->width());
        self::assertSame(4, $s->withWidth(0)->width());
        self::assertSame(7, $s->withHeight(7)->height());
        self::assertSame(3, $s->withHSpacing(3)->hSpacing());
        self::assertSame(0, $s->withVSpacing(-1)->vSpacing());
        self::assertSame(10, $s->width());
    }

    public function testFromPassesThroughOrRequiresHeight(): void
    {
        $s = CardSize::new(10, 5, 3, 2);
        self::assertSame($s, CardSize::from($s));
        self::assertEquals(CardSize::new(10, 5), CardSize::from(10, 5));
        $this->expectException(\ArgumentCountError::class);
        CardSize::from(10);
    }

    public function testGridVoPathEqualsIntPath(): void
    {
        $int = PosterGrid::new(12, 6, 3, 2);
        $vo = PosterGrid::new(CardSize::new(12, 6, 3, 2));
        self::assertEquals($int, $vo);
        self::assertEquals(CardSize::new(12, 6, 3, 2), $vo->cardSize());
        self::assertEquals($int->withCardSize(20, 8), $int->withCardSize(CardSize::new(20, 8, 3, 2)));
        self::assertSame(0, $int->withCardSize(CardSize::new(20, 8, 1, 0))->vSpacing());
        self::assertSame(1, $int->withCardSize(CardSize::new(20, 8, 1, 0))->hSpacing());
    }

    public function testRailVoPathIsByteIdenticalToIntPath(): void
    {
        $cards = [];
        for ($i = 0; $i < 6; $i++) {
            $cards[] = new PosterCard((string) $i, 'Card ' . $i);
        }
        $rail = Rail::new('R', $cards);
        $size = CardSize::new(12, 3, 2);
        self::assertSame($rail->render(60, true, 12, 3, 2), $rail->render(60, true, $size));
        self::assertSame($rail->render(60, true, 12, 3), $rail->render(60, true, 12, 3, 2));
        self::assertSame(Rail::perRow(60, 12, 2), Rail::perRow(60, $size));
        self::assertSame(Rail::perRow(60, 12), Rail::perRow(60, 12, 2));
        self::assertSame($rail->frameHeight(3), $rail->frameHeight($size));
    }

    public function testCardVoPathIsByteIdenticalToIntPath(): void
    {
        $card = new PosterCard('1', 'A title');
        $size = CardSize::new(14, 4);
        self::assertSame($card->render(true, 14, 4), $card->render(true, $size));
        self::assertSame($card->renderMemoKey(true, 14, 4), $card->renderMemoKey(true, $size));
    }

    public function testIntWidthWithoutHeightFailsLoudly(): void
    {
        $this->expectException(\ArgumentCountError::class);
        (new PosterCard('1', 'A'))->render(true, 14);
    }
}
