<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Theme;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Top\Theme\BoxFlow;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\Theme\ThemeFile;
use SugarCraft\Top\Theme\ThemeRegistry;

/** The optional `<box>_box_mid` / `_box_end` border-flow keys (candy-top extension). */
final class BoxFlowTest extends TestCase
{
    private static function hex(Color $c): string
    {
        return sprintf('%02x%02x%02x', $c->r, $c->g, $c->b);
    }

    public function testThreeStopFlowPassesThroughMid(): void
    {
        $flow = BoxFlow::new(Color::rgb(0, 0, 0), Color::rgb(100, 200, 0), Color::rgb(200, 0, 100));
        self::assertSame('000000', self::hex($flow->at(0.0)));
        self::assertSame('64c800', self::hex($flow->at(0.5)));
        self::assertSame('c80064', self::hex($flow->at(1.0)));
        self::assertSame('326400', self::hex($flow->at(0.25)));
        self::assertSame('966432', self::hex($flow->at(0.75)));
        self::assertSame('000000', self::hex($flow->at(-3.0)), 'clamped low');
        self::assertSame('c80064', self::hex($flow->at(9.0)), 'clamped high');
    }

    public function testTwoStopFlowWithoutMid(): void
    {
        $flow = BoxFlow::new(Color::rgb(0, 0, 0), null, Color::rgb(200, 100, 50));
        self::assertNull($flow->mid);
        self::assertSame('643219', self::hex($flow->at(0.5)));
        self::assertSame('c86432', self::hex($flow->at(1.0)));
    }

    public function testMixRoundsPerChannel(): void
    {
        self::assertSame('020202', self::hex(BoxFlow::mix(Color::rgb(0, 0, 0), Color::rgb(3, 3, 3), 0.5)));
        self::assertSame('ffffff', self::hex(BoxFlow::mix(Color::rgb(0, 0, 0), Color::rgb(255, 255, 255), 1.0)));
    }

    public function testThemeFileKeepsFlowKeysAndStillDropsUnknownOnes(): void
    {
        $parsed = ThemeFile::parse("theme[cpu_box]=\"#102030\"\ntheme[cpu_box_end]=\"#ffffff\"\ntheme[gpu_box_end]=\"#ffffff\"\ntheme[bogus]=\"#000000\"\n");
        self::assertSame(['cpu_box' => '#102030', 'cpu_box_end' => '#ffffff'], $parsed);
    }

    public function testBoxFlowResolvesFromSource(): void
    {
        $t = ThemeConfig::fromString(
            "theme[cpu_box]=\"#102030\"\ntheme[cpu_box_mid]=\"#405060\"\ntheme[cpu_box_end]=\"#708090\"\n"
            . "theme[mem_box_end]=\"10 20 30\"\n"
            . "theme[net_box_mid]=\"#ffffff\"\n"
            . "theme[proc_box_end]=\"#zzzzzz\"\n",
        );
        $cpu = $t->boxFlow('cpu');
        self::assertNotNull($cpu);
        self::assertSame('102030', self::hex($cpu->start));
        self::assertSame('405060', self::hex($cpu->mid ?? Color::rgb(0, 0, 0)));
        self::assertSame('708090', self::hex($cpu->end));

        $mem = $t->boxFlow('mem');
        self::assertNotNull($mem, 'R G B decimals work too');
        self::assertSame('6c6c4b', self::hex($mem->start), 'start is mem_box (Default here)');
        self::assertNull($mem->mid);
        self::assertSame('0a141e', self::hex($mem->end));

        self::assertNull($t->boxFlow('net'), 'a mid without an end is flat');
        self::assertNull($t->boxFlow('proc'), 'an invalid end is flat');
        self::assertNull($t->boxFlow('gpu'), 'no such family');
    }

    public function testFlowKeysNeverChangeTheBtopKeys(): void
    {
        $plain = ThemeConfig::fromString("theme[cpu_box]=\"#102030\"\n");
        $flowing = ThemeConfig::fromString("theme[cpu_box]=\"#102030\"\ntheme[cpu_box_end]=\"#ffffff\"\n");
        self::assertEquals($plain->colors(), $flowing->colors());
        foreach ($plain->gradientNames() as $g) {
            self::assertEquals($plain->gradient($g), $flowing->gradient($g), $g);
        }
    }

    public function testOnlyPastelShipsFlows(): void
    {
        self::assertNull(ThemeConfig::new()->boxFlow('cpu'), 'Default stays flat');
        foreach (ThemeRegistry::fromDirs(ThemeRegistry::bundledDir())->entries() as $entry) {
            if ($entry->path() === null) {
                continue;
            }
            $t = ThemeConfig::fromFile($entry->path());
            foreach (['cpu', 'mem', 'net', 'proc'] as $box) {
                if ($entry->name() === 'pastel') {
                    self::assertNotNull($t->boxFlow($box), "pastel {$box}");
                } else {
                    self::assertNull($t->boxFlow($box), "{$entry->name()} {$box} renders exactly as in btop");
                }
            }
        }
    }
}
