<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\LineStyle;
use SugarCraft\Charts\MarkLine;

/**
 * CH-17 (sugar-charts-088): the MarkLine style vocabulary moved from
 * string constants to the {@see LineStyle} enum, with the legacy
 * spellings kept as accepted input (deprecated alias window per house
 * law). Acceptance pin: old-const vs new-enum renders are byte-identical.
 */
final class MarkLineStyleTest extends TestCase
{
    public function testLegacyConstantsResolveToEnumCases(): void
    {
        $this->assertSame(LineStyle::Solid, LineStyle::tryFrom(MarkLine::STYLE_SOLID));
        $this->assertSame(LineStyle::Dashed, LineStyle::tryFrom(MarkLine::STYLE_DASHED));
        $this->assertSame(LineStyle::Dotted, LineStyle::tryFrom(MarkLine::STYLE_DOTTED));
    }

    public function testLegacyConstantInputNormalisesToCase(): void
    {
        $arms = [
            [MarkLine::STYLE_SOLID, LineStyle::Solid],
            [MarkLine::STYLE_DASHED, LineStyle::Dashed],
            [MarkLine::STYLE_DOTTED, LineStyle::Dotted],
        ];
        foreach ($arms as [$spelling, $case]) {
            $this->assertSame($case, MarkLine::at(1.0, 'x', $spelling)->style);
        }
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

    public function testRenderParityOldConstVsNewEnum(): void
    {
        $arms = [
            [MarkLine::STYLE_SOLID, LineStyle::Solid],
            [MarkLine::STYLE_DASHED, LineStyle::Dashed],
            [MarkLine::STYLE_DOTTED, LineStyle::Dotted],
        ];
        foreach ($arms as $i => [$spelling, $case]) {
            $old = LineChart::new([0, 10], 5, 3)->withMarkLines([MarkLine::at(5.0, '', $spelling)])->view();
            $new = LineChart::new([0, 10], 5, 3)->withMarkLines([MarkLine::at(5.0, '', $case)])->view();
            // Sanity that each arm actually paints: without it a no-op mark
            // renderer would make both sides vacuously equal.
            $this->assertNotSame(LineChart::new([0, 10], 5, 3)->view(), $old, "parity arm {$i} must paint glyphs");
            $this->assertSame($old, $new, "parity arm {$i}: enum must render the legacy bytes exactly");
        }
    }

    public function testDefaultStyleIsDashedCase(): void
    {
        $this->assertSame(LineStyle::Dashed, MarkLine::at(1.0)->style);
        $this->assertSame(LineStyle::Dashed, MarkLine::min([1, 2, 3])->style);
    }

    public function testUnknownStyleRejectedNamingAcceptedSpellings(): void
    {
        try {
            MarkLine::at(1.0, '', 'squiggly');
            $this->fail('unknown style spelling must throw');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid MarkLine style: squiggly', $e->getMessage());
            $this->assertStringContainsString('solid, dashed, dotted', $e->getMessage());
        }
    }

    public function testDeprecatedRoutingIsDocumentedInSource(): void
    {
        $doc = (new \ReflectionClassConstant(MarkLine::class, 'STYLE_SOLID'))->getDocComment();
        $this->assertIsString($doc);
        $this->assertStringContainsString('@deprecated', $doc, 'the alias window must stay documented for the future cleanup pass');
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
