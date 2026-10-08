<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\View;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Surface;

final class SurfaceTest extends TestCase
{
    public function testBlankSurfaceIsExactlyWidthByHeight(): void
    {
        $s = Surface::new(7, 3);
        $this->assertSame(['       ', '       ', '       '], $s->plainLines());
        foreach ($s->lines() as $line) {
            $this->assertSame(7, Width::string($line));
        }
    }

    public function testPutClipsAtTheRightEdge(): void
    {
        $s = Surface::new(5, 1);
        $this->assertSame(3, $s->put(2, 0, 'abcdef'));
        $this->assertSame(['  abc'], $s->plainLines());
    }

    public function testWideClusterThatWouldOverhangBecomesASpace(): void
    {
        $s = Surface::new(4, 1);
        $s->put(0, 0, 'ab世界');
        $this->assertSame(['ab世'], $s->plainLines());
        $this->assertSame(4, Width::string($s->lines()[0]));

        $edge = Surface::new(3, 1);
        $edge->put(0, 0, 'a世');
        // The overhang write lands on 世's right half, so 世 cannot survive.
        $edge->put(2, 0, '界');
        $this->assertSame('a  ', $edge->plainLines()[0]);
        $this->assertSame(3, Width::string($edge->lines()[0]));
    }

    public function testOverwritingHalfAWideClusterBlanksTheOtherHalf(): void
    {
        $s = Surface::new(4, 1);
        $s->put(0, 0, '世界');
        $s->put(1, 0, 'x');
        $this->assertSame(' x界', $s->plainLines()[0]);
        $s->put(2, 0, 'y');
        $this->assertSame(' xy ', $s->plainLines()[0]);
    }

    public function testControlCharactersAreDropped(): void
    {
        $s = Surface::new(10, 1);
        $s->put(0, 0, "a\x1b[31mb\nc\x07d\xc2\x9be");
        $this->assertSame('a[31mbcde ', $s->plainLines()[0]);
    }

    public function testPutRespectsClipRectAndRows(): void
    {
        $s = Surface::new(6, 2);
        $clip = Rect::new(1, 0, 3, 1);
        $s->put(0, 0, 'abcdef', '', $clip);
        $s->put(0, 1, 'zzz', '', $clip);
        $this->assertSame([' bcd  ', '      '], $s->plainLines());
    }

    public function testAnsiFoldsSgrIntoCanonicalStateUntilReset(): void
    {
        $s = Surface::new(6, 1);
        $n = $s->ansi(0, 0, "\x1b[1mA\x1b[31mB\x1b[0mC\x1b[2KD");
        $this->assertSame(4, $n);
        $this->assertSame('ABCD  ', $s->plainLines()[0]);
        $this->assertSame("\x1b[1m", $s->style(0, 0));
        $this->assertSame("\x1b[1;31m", $s->style(1, 0));
        $this->assertSame('', $s->style(2, 0));
        $this->assertSame('', $s->style(3, 0));
    }

    public function testAnsiReplacesColoursInsteadOfAppending(): void
    {
        $s = Surface::new(5, 1);
        $s->ansi(0, 0, "\x1b[1m\x1b[91mA\x1b[97mB\x1b[38;2;1;2;3mC\x1b[22;44mD\x1b[39;49mE");
        $this->assertSame("\x1b[1;91m", $s->style(0, 0));
        $this->assertSame("\x1b[1;97m", $s->style(1, 0));
        $this->assertSame("\x1b[1;38;2;1;2;3m", $s->style(2, 0));
        $this->assertSame("\x1b[38;2;1;2;3;44m", $s->style(3, 0));
        $this->assertSame('', $s->style(4, 0));
    }

    public function testCanonicalFoldsAttributesColoursAndResets(): void
    {
        $this->assertSame('', Surface::canonical(''));
        $this->assertSame('', Surface::canonical("\x1b[1m\x1b[0m"));
        $this->assertSame("\x1b[1;4;31;42m", Surface::canonical("\x1b[42m\x1b[4m\x1b[31m\x1b[1m"));
        $this->assertSame("\x1b[3;38;5;200m", Surface::canonical("\x1b[1;3;38;5;9m\x1b[22;38;5;200m"));
        $this->assertSame("\x1b[4:3;58:2::1:2:3m", Surface::canonical("\x1b[4:3m\x1b[58:2::1:2:3m"));
    }

    public function testLongGradientRowOutputStaysLinear(): void
    {
        // A 200-cell gradient row: a distinct truecolour fg per cell, as a
        // pre-rendered graph row hands it over. Appending escapes made this
        // quadratic (3.8 KB in -> 325 KB out).
        $line = "\x1b[1m";
        for ($i = 0; $i < 200; $i++) {
            $line .= sprintf("\x1b[38;2;%d;%d;%dm█", $i, 255 - $i, ($i * 7) % 256);
        }
        $s = Surface::new(200, 1, "\x1b[48;2;0;0;0m\x1b[38;2;200;200;200m");
        $this->assertSame(200, $s->ansi(0, 0, $line));
        $out = $s->render();
        $perCell = strlen("\x1b[0m") + strlen($s->base) + strlen("\x1b[1;38;2;255;255;255m") + strlen('█');
        $this->assertLessThanOrEqual(200 * $perCell + strlen("\x1b[0m"), strlen($out));
        $this->assertLessThan(3 * strlen($line), strlen($out));
        $this->assertSame("\x1b[1;38;2;199;56;113m", $s->style(199, 0));
    }

    public function testRenderSnapshotReappliesBaseAfterEveryReset(): void
    {
        $s = Surface::new(3, 2, "\x1b[40m");
        $s->put(1, 0, 'x', "\x1b[31m");
        $this->assertSame(
            "\x1b[0m\x1b[40m \x1b[0m\x1b[40m\x1b[31mx\x1b[0m\x1b[40m \x1b[0m\n\x1b[0m\x1b[40m   \x1b[0m",
            $s->render(),
        );
    }

    public function testRegionIsLocalAndClipped(): void
    {
        $s = Surface::new(8, 3);
        $r = $s->region(Rect::new(2, 1, 4, 1));
        $this->assertSame(4, $r->width());
        $r->put(0, 0, 'abcdefgh');
        $r->put(0, 1, 'nope');
        $r->sub(Rect::new(2, 0, 10, 1))->put(0, 0, 'XY');
        $this->assertSame(['        ', '  abXY  ', '        '], $s->plainLines());
    }
}
