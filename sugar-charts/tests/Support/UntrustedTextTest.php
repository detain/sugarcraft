<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\BarChart\BarChart;
use SugarCraft\Charts\Heatmap\Heatmap;
use SugarCraft\Charts\Legend\Legend;
use SugarCraft\Charts\Support\UntrustedText;

/**
 * Pins for the terminal-injection trust boundary (review CRITICAL XC1):
 * every chart string ingress passes through UntrustedText::guard(), and
 * hostile label/title/legend text must be unable to move the emulator.
 * Assertions are byte-level on purpose — a Contains-string check would
 * miss a surviving bare ESC.
 */
final class UntrustedTextTest extends TestCase
{
    public function testGuardIsByteIdenticalOnOrdinaryPrintableText(): void
    {
        foreach (['Revenue 2026', 'Ünïcödé ✓', 'a) b] c{ d}'] as $clean) {
            $this->assertSame($clean, UntrustedText::guard($clean));
        }
    }

    public function testGuardIsIdempotent(): void
    {
        $hostile = "\x1b[2J" . "\x1b]8;;http://evil\x07pr" . "\x0e" . "om\n\tpt" . "\u{e000}sent";
        $once = UntrustedText::guard($hostile);
        $this->assertSame($once, UntrustedText::guard($once));
    }

    public function testGuardStripsCsiOscAndSentinelsAndFlattensWhitespace(): void
    {
        $hostile = "\x1b[2J" . "\x1b]8;;http://evil\x07pr" . "\x0e" . "om\n\tpt" . "\u{e000}sent\u{e001}inel";
        $guarded = UntrustedText::guard($hostile);

        // Hex-level: no ESC (1b), BEL (07), SO (0e), LF (0a), CR (0d), TAB
        // (09), DEL (7f), and no U+E000/E001 zone sentinels (ee8080 / ee8081).
        $this->assertStringNotContainsString("\x1b", $guarded);
        $this->assertStringNotContainsString("\x07", $guarded);
        $this->assertStringNotContainsString("\x0e", $guarded);
        $this->assertStringNotContainsString("\n", $guarded);
        $this->assertStringNotContainsString("\r", $guarded);
        $this->assertStringNotContainsString("\t", $guarded);
        $this->assertStringNotContainsString("\u{e000}", $guarded);
        $this->assertStringNotContainsString("\u{e001}", $guarded);
        $this->assertMatchesRegularExpression('/^[\x20-\x7e\x{80}-\x{10FFFF}]*$/u', $guarded);

        // The visible payload of the attack survives as inert text.
        $this->assertStringContainsString('prom', $guarded);
        $this->assertStringContainsString('pt', $guarded);
    }

    public function testGuardNeutralisesBidiOverridesAndInvalidUtf8(): void
    {
        $rtl = UntrustedText::guard("acct\u{202e}bal");
        $this->assertStringNotContainsString("\u{202e}", $rtl);
        $this->assertStringContainsString('acct', $rtl);
        $this->assertStringContainsString('bal', $rtl);

        $broken = UntrustedText::guard("bad\x{c0}\x{80}bytes");
        $this->assertTrue(mb_check_encoding($broken, 'UTF-8'));
    }

    public function testGuardItemsRewritesLabelsOnly(): void
    {
        $items = UntrustedText::guardItems([
            ['label' => "\x1b[31mred", 'color' => 'green'],
        ]);
        $this->assertStringNotContainsString("\x1b", $items[0]['label']);
        $this->assertSame('green', $items[0]['color']);
    }

    public function testPoisonedTitleCannotReachTheRenderedFrame(): void
    {
        $poison = "\x1b]0;evil-title\x07\u{202e}\n";
        $views = [
            BarChart::new([["ok", 1.0]], 5, 2)->withTitle($poison)->view(),
            BarChart::new([[$poison, 1.0]], 5, 2)->view(),
            BarChart::new([["a", 1.0]], 5, 2)->withXLabel($poison)->view(),
            BarChart::new([["a", 1.0]], 5, 2)->withYLabel($poison)->view(),
            Heatmap::new([[1.0]])->view(),
            Legend::new([['label' => $poison, 'color' => 'red']])->view(),
        ];
        foreach ($views as $i => $view) {
            $this->assertStringNotContainsString("\x1b]0;", $view, "view {$i} leaked OSC 0");
            $this->assertStringNotContainsString("\x07", $view, "view {$i} leaked BEL");
            $this->assertStringNotContainsString("\u{202e}", $view, "view {$i} leaked bidi override");
            $this->assertStringNotContainsString("\n\n\n", $view, "view {$i} gained smuggled rows");
        }
    }

    /**
     * CH-01 (sugar-charts-013): the Heatmap fill rune moved from
     * sweep-and-store to refuse-at-door — the swept poison spells a
     * multi-cell remainder, and a fill rune must be exactly one cell.
     */
    public function testPoisonedHeatmapRuneIsRefusedAtTheDoor(): void
    {
        $poison = "\x1b]0;evil-title\x07\u{202e}\n";
        $this->expectException(\InvalidArgumentException::class);
        Heatmap::new([[1.0]])->withRune($poison);
    }

    public function testNewlineSmugglingCannotAddRowsToABarFrame(): void
    {
        $base = BarChart::new([["a", 1.0]], 5, 2)->view();
        $sneak = BarChart::new([["\na\nb", 1.0]], 5, 2)->view();
        $this->assertSame(substr_count($base, "\n"), substr_count($sneak, "\n"));
    }
}
