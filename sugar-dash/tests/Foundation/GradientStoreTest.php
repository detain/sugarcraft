<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Foundation;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Dash\Foundation\GradientStore;
use SugarCraft\Dash\Plot\Gradient101;

final class GradientStoreTest extends TestCase
{
    /** @param list<Color> $ramp */
    private static function rgb(array $ramp, int $i): array
    {
        return [$ramp[$i]->r, $ramp[$i]->g, $ramp[$i]->b];
    }

    public function testTwoStopRampIsBtopTruncatingLaw(): void
    {
        $ramp = GradientStore::ramp(Color::rgb(0, 255, 255), null, Color::rgb(255, 0, 0));
        $this->assertCount(101, $ramp);
        $this->assertSame([0, 255, 255], self::rgb($ramp, 0));
        // 0 + 50*255/100 = 127 (trunc); 255 + 50*(-255)/100 = 255 - 127 = 128.
        $this->assertSame([127, 128, 128], self::rgb($ramp, 50));
        $this->assertSame([255, 0, 0], self::rgb($ramp, 100));
    }

    public function testThreeStopRampHitsMidExactlyAtFifty(): void
    {
        // btop default cpu gradient: #77ca9b → #cbc06c → #dc4c4c.
        $ramp = GradientStore::ramp(Color::hex('#77ca9b'), Color::hex('#cbc06c'), Color::hex('#dc4c4c'));
        $this->assertSame('#77ca9b', $ramp[0]->toHex());
        $this->assertSame('#cbc06c', $ramp[50]->toHex());
        $this->assertSame('#dc4c4c', $ramp[100]->toHex());
        // Index 25: 0x77 + 25*(0xcb-0x77)/50 = 119 + 42 = 161; g 202 + 25*(-10)/50 = 197;
        // b 155 + 25*(-47)/50 = 155 - 23 = 132 (trunc toward zero).
        $this->assertSame([161, 197, 132], self::rgb($ramp, 25));
        // Index 75 on the second leg: r 203 + 25*17/50 = 211; g 192 + 25*(-116)/50 = 134;
        // b 108 + 25*(-32)/50 = 92.
        $this->assertSame([211, 134, 92], self::rgb($ramp, 75));
    }

    public function testRampDelegatesToGradient101(): void
    {
        $a = Color::rgb(3, 200, 17);
        $m = Color::rgb(250, 9, 140);
        $b = Color::rgb(1, 1, 255);
        $this->assertEquals(Gradient101::expand([$a, $m, $b]), GradientStore::ramp($a, $m, $b));
        $this->assertEquals(Gradient101::expand([$a, $b]), GradientStore::ramp($a, null, $b));
    }

    public function testStartOnlyFillsWithStart(): void
    {
        $ramp = GradientStore::ramp(Color::rgb(9, 8, 7));
        $this->assertCount(101, $ramp);
        foreach ($ramp as $i => $c) {
            $this->assertSame([9, 8, 7], self::rgb($ramp, $i));
        }
        // A mid without an end is ignored, as btop only iterates when end is set.
        $this->assertSame([9, 8, 7], self::rgb(GradientStore::ramp(Color::rgb(9, 8, 7), Color::rgb(1, 1, 1)), 100));
    }

    public function testRampIsMemoizedBySameStops(): void
    {
        // Stops unique to this test, so no other test's memo entry interferes.
        $first = GradientStore::ramp(Color::rgb(201, 2, 3), Color::rgb(4, 205, 6), Color::rgb(7, 8, 209));
        // Fresh but equal stop instances hit the same memo entry: every
        // element is the identical Color object.
        $second = GradientStore::ramp(Color::rgb(201, 2, 3), Color::rgb(4, 205, 6), Color::rgb(7, 8, 209));
        $this->assertSame($first, $second);
        $other = GradientStore::ramp(Color::rgb(201, 2, 3), null, Color::rgb(7, 8, 209));
        $this->assertNotSame($first[25], $other[25]);
    }

    public function testMemoKeepsAnsiIndexedStopDistinct(): void
    {
        $indexed = Color::ansi(1);
        $twin = Color::rgb($indexed->r, $indexed->g, $indexed->b);
        // Whichever is memoized first, the other must keep its own ansiIndex.
        $this->assertNull(GradientStore::ramp($twin)[0]->ansiIndex);
        $this->assertSame(1, GradientStore::ramp($indexed)[0]->ansiIndex);
        $this->assertNull(GradientStore::ramp($twin)[50]->ansiIndex);
    }

    public function testMemoIsBoundedWithFifoEviction(): void
    {
        // Stops in a colour band (blue = 251) no other test uses; each is unique.
        $stop = static fn(int $n): Color => Color::rgb(intdiv($n, 256), $n % 256, 251);
        $a = GradientStore::ramp($stop(0), null, Color::rgb(0, 0, 0));

        // CAP-1 newer entries: $a is still resident (at worst it is now the oldest).
        for ($n = 1; $n < GradientStore::MEMO_CAP; $n++) {
            GradientStore::ramp($stop($n), null, Color::rgb(0, 0, 0));
        }
        $this->assertSame($a, GradientStore::ramp($stop(0), null, Color::rgb(0, 0, 0)));

        // One more insert evicts the oldest — $a — so it is re-expanded.
        GradientStore::ramp($stop(GradientStore::MEMO_CAP), null, Color::rgb(0, 0, 0));
        $again = GradientStore::ramp($stop(0), null, Color::rgb(0, 0, 0));
        $this->assertNotSame($a[50], $again[50]);
        $this->assertEquals($a, $again);
    }

    public function testStoreKeepsItsRampsAfterEviction(): void
    {
        $store = GradientStore::new()->withGradient('x', Color::rgb(3, 3, 252), null, Color::rgb(0, 0, 0));
        $before = $store->gradient('x');
        for ($n = 0; $n <= GradientStore::MEMO_CAP; $n++) {
            GradientStore::ramp(Color::rgb(intdiv($n, 256), $n % 256, 253), null, Color::rgb(0, 0, 0));
        }
        $this->assertSame($before, $store->gradient('x'));
    }

    public function testAtClampsPercent(): void
    {
        $store = GradientStore::new()->withGradient('cpu', Color::rgb(0, 0, 0), null, Color::rgb(200, 100, 50));
        $this->assertSame([0, 0, 0], [$store->at('cpu', -5)->r, $store->at('cpu', -5)->g, $store->at('cpu', -5)->b]);
        $this->assertSame([200, 100, 50], [$store->at('cpu', 250)->r, $store->at('cpu', 250)->g, $store->at('cpu', 250)->b]);
        $this->assertSame([100, 50, 25], [$store->at('cpu', 50)->r, $store->at('cpu', 50)->g, $store->at('cpu', 50)->b]);
        $this->assertSame($store->gradient('cpu')[73], $store->at('cpu', 73));
    }

    public function testUnknownNameThrows(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        GradientStore::new()->at('nope', 10);
    }

    public function testUnknownGradientThrows(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        GradientStore::new()->gradient('nope');
    }

    public function testWithGradientIsImmutableAndReplaces(): void
    {
        $a = GradientStore::new();
        $b = $a->withGradient('proc', Color::rgb(255, 255, 255), null, Color::rgb(0, 0, 0));
        $this->assertFalse($a->has('proc'));
        $this->assertTrue($b->has('proc'));
        $this->assertSame(['proc'], $b->names());

        $c = $b->withGradient('proc', Color::rgb(1, 1, 1));
        $this->assertSame([1, 1, 1], [$c->at('proc', 100)->r, $c->at('proc', 100)->g, $c->at('proc', 100)->b]);
        $this->assertSame(0, $b->at('proc', 100)->r);
    }

    public function testFromStopsScansStartKeysLikeGenerateGradients(): void
    {
        $store = GradientStore::fromStops([
            'main_fg' => Color::hex('#cccccc'),
            'cpu_start' => Color::hex('#77ca9b'),
            'cpu_mid' => Color::hex('#cbc06c'),
            'cpu_end' => Color::hex('#dc4c4c'),
            'free_start' => Color::hex('#384f21'),
            'free_mid' => null,
            'free_end' => Color::hex('#b5e685'),
            'lone_start' => Color::hex('#123456'),
            'unset_start' => null,
        ]);
        $this->assertSame(['cpu', 'free', 'lone'], $store->names());
        $this->assertSame('#cbc06c', $store->at('cpu', 50)->toHex());
        $this->assertEquals(
            GradientStore::ramp(Color::hex('#384f21'), null, Color::hex('#b5e685')),
            $store->gradient('free'),
        );
        $this->assertSame('#123456', $store->at('lone', 77)->toHex());
        $this->assertFalse($store->has('main_fg'));
    }

    public function testNamesAreStrings(): void
    {
        $store = GradientStore::new()->withGradient('1', Color::rgb(0, 0, 0));
        $this->assertSame(['1'], $store->names());
    }
}
