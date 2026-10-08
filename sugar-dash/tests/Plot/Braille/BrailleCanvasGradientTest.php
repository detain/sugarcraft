<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Braille;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Dash\Plot\Braille\BrailleCanvas;

final class BrailleCanvasGradientTest extends TestCase
{
    private static function cyanMagenta(): array
    {
        return [Color::rgb(0, 255, 255), Color::rgb(255, 0, 255)];
    }

    // ═══════════════════════════════════════════════════════════════
    // Snapshot byte
    // ═══════════════════════════════════════════════════════════════

    public function testTwoStopRampEmitsExactSgrTrioAtZeroFiftyHundred(): void
    {
        $canvas = BrailleCanvas::new(6, 4)
            ->withGradient(self::cyanMagenta(), static fn(int|float $v): float => $v / 100)
            ->withValue(0)->setPoint(0, 0)
            ->withValue(50)->setPoint(2, 0)
            ->withValue(100)->setPoint(4, 0);

        $this->assertSame(
            "\x1b[38;2;0;255;255m⠁\x1b[0m"
            . "\x1b[38;2;127;128;255m⠁\x1b[0m"
            . "\x1b[38;2;255;0;255m⠁\x1b[0m",
            $canvas->render(ColorProfile::TrueColor),
        );
    }

    public function testNoGradientNullColorRenderIsUnchanged(): void
    {
        // Default path: withValue alone must not color anything.
        $canvas = BrailleCanvas::new(4, 4)->withValue(0.7)->setPoint(0, 0);

        $this->assertSame("⠁", $canvas->render(ColorProfile::TrueColor));
        $this->assertSame(
            BrailleCanvas::new(4, 4)->setPoint(0, 0)->setPoint(2, 0)->render(ColorProfile::TrueColor),
            BrailleCanvas::new(4, 4)->withValue(0.7)->setPoint(0, 0)->setPoint(2, 0)->render(ColorProfile::TrueColor),
        );
        $this->assertNull($canvas->gradient());
    }

    public function testExplicitColorWinsOverGradient(): void
    {
        $canvas = BrailleCanvas::new(2, 4)
            ->withGradient(self::cyanMagenta())
            ->withValue(1.0)
            ->setPoint(0, 0, Color::rgb(1, 2, 3));

        $this->assertSame("\x1b[38;2;1;2;3m⠁\x1b[0m", $canvas->render(ColorProfile::TrueColor));
    }

    public function testSetLineWithNullColorUsesRamp(): void
    {
        $canvas = BrailleCanvas::new(4, 4)
            ->withGradient(self::cyanMagenta())
            ->withValue(1.0)
            ->setLine(0, 0, 3, 0);

        foreach ($canvas->cells() as [, , , $color]) {
            $this->assertNotNull($color);
            $this->assertSame([255, 0, 255], [$color->r, $color->g, $color->b]);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Memo shape
    // ═══════════════════════════════════════════════════════════════

    public function testMemoHas101EntriesWithExactEndpoints(): void
    {
        $memo = BrailleCanvas::new(2, 4)->withGradient(self::cyanMagenta())->gradient();

        $this->assertIsArray($memo);
        $this->assertCount(101, $memo);
        $this->assertSame(array_keys($memo), range(0, 100));
        $this->assertSame([0, 255, 255], [$memo[0]->r, $memo[0]->g, $memo[0]->b]);
        $this->assertSame([255, 0, 255], [$memo[100]->r, $memo[100]->g, $memo[100]->b]);
    }

    public function testThreeStopRampPinsMidStopAtFifty(): void
    {
        $memo = BrailleCanvas::new(2, 4)
            ->withGradient([Color::rgb(0, 0, 0), Color::rgb(10, 200, 30), Color::rgb(255, 255, 255)])
            ->gradient();

        $this->assertCount(101, $memo);
        $this->assertSame([10, 200, 30], [$memo[50]->r, $memo[50]->g, $memo[50]->b]);
        $this->assertSame([255, 255, 255], [$memo[100]->r, $memo[100]->g, $memo[100]->b]);
    }

    public function testManyStopsStillYield101Entries(): void
    {
        $stops = [];
        for ($i = 0; $i < 150; $i++) {
            $stops[] = Color::rgb($i, 0, 0);
        }
        $memo = BrailleCanvas::new(2, 4)->withGradient($stops)->gradient();

        $this->assertCount(101, $memo);
        $this->assertSame(0, $memo[0]->r);
        $this->assertSame(149, $memo[100]->r);
    }

    public function testTwoStopMemoMatchesBtopTruncatingLaw(): void
    {
        $memo = BrailleCanvas::new(2, 4)->withGradient(self::cyanMagenta())->gradient();

        // btop_theme.cpp generateGradients: start + i*(end-start)/100 in C++
        // int arithmetic, truncating toward zero on the descending green.
        $pins = [1 => [2, 253, 255], 33 => [84, 171, 255], 50 => [127, 128, 255], 99 => [252, 3, 255]];
        foreach ($pins as $i => $rgb) {
            $this->assertSame($rgb, [$memo[$i]->r, $memo[$i]->g, $memo[$i]->b], "entry {$i}");
        }

        foreach ($memo as $i => $c) {
            $this->assertSame(
                [(int) (255 * $i / 100), 255 - (int) (255 * $i / 100), 255],
                [$c->r, $c->g, $c->b],
                "entry {$i}",
            );
        }
    }

    public function testThreeStopMemoMatchesBtopFiftyFiftyOneSplit(): void
    {
        $memo = BrailleCanvas::new(2, 4)
            ->withGradient([Color::rgb(0, 0, 0), Color::rgb(10, 200, 30), Color::rgb(255, 255, 255)])
            ->gradient();

        // i=25: start→mid, 25*delta/50. i=75: mid→end with btop's offset 50.
        $this->assertSame([5, 100, 15], [$memo[25]->r, $memo[25]->g, $memo[25]->b]);
        $this->assertSame([132, 227, 142], [$memo[75]->r, $memo[75]->g, $memo[75]->b]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Resolution cost / detach
    // ═══════════════════════════════════════════════════════════════

    public function testScaleRunsOncePerValueNotPerPoint(): void
    {
        $calls = 0;
        $scale = static function (int|float $v) use (&$calls): float {
            $calls++;
            return $v;
        };
        $canvas = BrailleCanvas::new(40, 40)->withGradient(self::cyanMagenta(), $scale)->withValue(0.5);
        $this->assertSame(2, $calls);

        $canvas = $canvas->setLine(0, 0, 39, 39)->setLine(0, 39, 39, 0)->setPoint(5, 7);
        $this->assertSame(2, $calls);

        foreach ($canvas->cells() as [, , , $color]) {
            $this->assertSame($canvas->gradient()[50], $color);
        }
    }

    public function testWithoutGradientRestoresDefaultPath(): void
    {
        $graded = BrailleCanvas::new(4, 4)->withGradient(self::cyanMagenta())->withValue(0.9);
        $detached = $graded->withoutGradient();

        $this->assertNotSame($graded, $detached);
        $this->assertNull($detached->gradient());
        $this->assertNotNull($graded->gradient());
        $this->assertSame(0.9, $detached->value());
        $this->assertSame(
            BrailleCanvas::new(4, 4)->setPoint(0, 0)->render(ColorProfile::TrueColor),
            $detached->setPoint(0, 0)->render(ColorProfile::TrueColor),
        );
        $this->assertNull(self::colorAt($detached));

        // Re-attaching picks the retained value back up.
        $this->assertSame([229, 26, 255], (static function (?Color $c): array {
            return [$c->r, $c->g, $c->b];
        })(self::colorAt($detached->withGradient(self::cyanMagenta()))));
    }

    // ═══════════════════════════════════════════════════════════════
    // Coercion
    // ═══════════════════════════════════════════════════════════════

    public function testZeroStopsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BrailleCanvas::new(2, 4)->withGradient([]);
    }

    public function testOneStopThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BrailleCanvas::new(2, 4)->withGradient([Color::rgb(1, 1, 1)]);
    }

    public function testNonColorStopThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BrailleCanvas::new(2, 4)->withGradient([Color::rgb(1, 1, 1), '#ffffff']);
    }

    public function testNullScaleIsIdentityAndClamps(): void
    {
        $base = BrailleCanvas::new(2, 4)->withGradient(self::cyanMagenta());

        $this->assertSame($base->gradient()[50], self::colorAt($base->withValue(0.5)));
        $this->assertSame($base->gradient()[0], self::colorAt($base->withValue(-3)));
        $this->assertSame($base->gradient()[100], self::colorAt($base->withValue(42)));
        $this->assertSame($base->gradient()[0], self::colorAt($base->withValue(NAN)));
    }

    public function testScaleReceivesRawValueAndResultIsClamped(): void
    {
        $seen = [];
        $scale = static function (int|float $v) use (&$seen): float {
            $seen[] = $v;
            return ($v + 10) * 100 / 200 / 100; // btop (v+offset)*100/max_value, as a fraction
        };
        $base = BrailleCanvas::new(2, 4)->withGradient(self::cyanMagenta(), $scale);

        $this->assertSame($base->gradient()[50], self::colorAt($base->withValue(90)));
        $this->assertSame($base->gradient()[100], self::colorAt($base->withValue(1000)));
        // Once at attach time (current value 0), then once per withValue().
        $this->assertSame([0, 90, 1000], $seen);
    }

    // ═══════════════════════════════════════════════════════════════
    // Immutability / memo carry
    // ═══════════════════════════════════════════════════════════════

    public function testWithGradientAndWithValueReturnNewInstances(): void
    {
        $plain = BrailleCanvas::new(2, 4);
        $graded = $plain->withGradient(self::cyanMagenta());
        $valued = $graded->withValue(0.3);

        $this->assertNotSame($plain, $graded);
        $this->assertNotSame($graded, $valued);
        $this->assertNull($plain->gradient());
        $this->assertSame(0, $plain->value());
        $this->assertSame(0, $graded->value());
        $this->assertSame(0.3, $valued->value());
    }

    public function testMemoIsCarriedAcrossEveryTransform(): void
    {
        $graded = BrailleCanvas::new(4, 4)->withGradient(self::cyanMagenta(), static fn($v) => $v);
        $memo = $graded->gradient();

        $chain = [
            $graded->withValue(0.4),
            $graded->setPoint(0, 0),
            $graded->setLine(0, 0, 3, 3),
            $graded->clear(),
            $graded->setSize(8, 8),
        ];
        foreach ($chain as $derived) {
            $this->assertInstanceOf(BrailleCanvas::class, $derived);
            $this->assertSame($memo, $derived->gradient());
        }

        $resized = $graded->withValue(0.25)->setSize(8, 8);
        $this->assertSame(0.25, $resized->value());
        $this->assertSame($memo[25], self::colorAt($resized));
    }

    public function testRegradingReplacesMemoWithoutTouchingOriginal(): void
    {
        $first = BrailleCanvas::new(2, 4)->withGradient(self::cyanMagenta());
        $second = $first->withGradient([Color::rgb(0, 0, 0), Color::rgb(255, 255, 255)]);

        $this->assertSame(0, $first->gradient()[0]->r);
        $this->assertSame(255, $first->gradient()[0]->g);
        $this->assertSame(0, $second->gradient()[0]->g);
    }

    // ═══════════════════════════════════════════════════════════════
    // Cell grid — gradient must not alter geometry
    // ═══════════════════════════════════════════════════════════════

    public function testGradientDoesNotAlterDotPlacement(): void
    {
        $draw = static fn(BrailleCanvas $c): BrailleCanvas => $c
            ->setLine(0, 0, 19, 11)
            ->setLine(0, 11, 19, 0)
            ->setPoint(7, 3)
            ->setPoint(19, 11);

        $plain = $draw(BrailleCanvas::new(20, 12));
        $graded = $draw(
            BrailleCanvas::new(20, 12)
                ->withGradient(self::cyanMagenta(), static fn($v) => $v / 100)
                ->withValue(63),
        );

        $this->assertSame(self::bitGrid($plain), self::bitGrid($graded));
        $this->assertSame($plain->getInnerSize(), $graded->getInnerSize());

        // Same runes in the same cells once the SGR is stripped; the plain
        // path pads uncolored runes with a trailing space, so compare runes only.
        $runes = static fn(string $s): string => preg_replace('/\x1b\[[0-9;]*m| /u', '', $s);
        $this->assertSame(
            $runes($plain->render(ColorProfile::TrueColor)),
            $runes($graded->render(ColorProfile::TrueColor)),
        );
    }

    /** @return array<string, int> */
    private static function bitGrid(BrailleCanvas $canvas): array
    {
        $grid = [];
        foreach ($canvas->cells() as [$x, $y, $bits]) {
            $grid["{$x},{$y}"] = $bits;
        }
        return $grid;
    }

    private static function colorAt(BrailleCanvas $canvas): ?Color
    {
        foreach ($canvas->setPoint(0, 0)->cells() as [$x, $y, , $color]) {
            if ($x === 0 && $y === 0) {
                return $color;
            }
        }
        return null;
    }
}
