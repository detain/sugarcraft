<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\LineStyle;
use SugarCraft\Charts\MarkLine;

/**
 * CH-17 (sugar-charts-088) moved the MarkLine style vocabulary onto the
 * {@see LineStyle} enum. C2 retired the legacy `STYLE_*` string spellings
 * after their alias window, so the enum is the only accepted vocabulary.
 */
final class MarkLineStyleTest extends TestCase
{
    public function testEnumCasesCarryTheWireSpellings(): void
    {
        $this->assertSame('solid', LineStyle::Solid->value);
        $this->assertSame('dashed', LineStyle::Dashed->value);
        $this->assertSame('dotted', LineStyle::Dotted->value);
    }

    public function testEnumInputAcceptedAtEveryStyleSurface(): void
    {
        $data = [1, 5, 9];
        $this->assertSame(LineStyle::Dotted, MarkLine::at(2.0, '', LineStyle::Dotted)->style);
        $this->assertSame(LineStyle::Solid, MarkLine::fromDataset($data, MarkLine::MAX, LineStyle::Solid)->style);
        $this->assertSame(LineStyle::Solid, MarkLine::min($data, LineStyle::Solid)->style);
        $this->assertSame(LineStyle::Dashed, MarkLine::max($data, LineStyle::Dashed)->style);
        $this->assertSame(LineStyle::Dotted, MarkLine::average($data, LineStyle::Dotted)->style);
    }

    public function testEveryCasePaintsAMarkLine(): void
    {
        $blank = LineChart::new([0, 10], 5, 3)->view();
        foreach (LineStyle::cases() as $case) {
            $painted = LineChart::new([0, 10], 5, 3)->withMarkLines([MarkLine::at(5.0, '', $case)])->view();
            $this->assertNotSame($blank, $painted, "{$case->name} must paint glyphs");
        }
    }

    public function testDefaultStyleIsDashedCase(): void
    {
        $this->assertSame(LineStyle::Dashed, MarkLine::at(1.0)->style);
        $this->assertSame(LineStyle::Dashed, MarkLine::min([1, 2, 3])->style);
    }

    public function testStringSpellingIsATypeErrorNotAnAlias(): void
    {
        $this->expectException(\TypeError::class);
        MarkLine::at(1.0, '', 'solid');
    }

    public function testRenderMatchNoLongerCarriesStringFallbackArm(): void
    {
        // Exhaustiveness integrity: the enum-typed property lets the render
        // match drop its dead default-throw arm. If a string arm is ever
        // reintroduced here, re-anchor this pin deliberately.
        $src = (string) file_get_contents(\dirname(__DIR__) . '/src/LineChart/LineChart.php');
        $this->assertStringNotContainsString('Invalid MarkLine style: {$mark->style}', $src);
    }
}
