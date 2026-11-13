<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin\Dashboard;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Admin\Dashboard\Widget;
use SugarCraft\Sql\Admin\Dashboard\WidgetRegistry;
use SugarCraft\Sql\Db\ServerVersion;

/**
 * SQL-P17 (adminpages-018): catalog tooltips must be renderable templates.
 *
 * They used to carry Python-style %(name)s placeholders — a latent
 * "Unknown format specifier" the day the ->tooltip field was wired to
 * anything. The cure is {Brace} tokens + Widget::renderTooltip as the
 * named filling seam; this test proves the corpus and the seam.
 */
final class TooltipPlaceholderTest extends TestCase
{
    /** @return list<Widget> */
    private function allWidgets(): array
    {
        $widgets = WidgetRegistry::build(ServerVersion::parse('MySQL version 8.0.33'));
        foreach (WidgetRegistry::buildForPostgres() as $widget) {
            $widgets[] = $widget;
        }

        return $widgets;
    }

    public function testNoTooltipCarriesPythonPercentPlaceholders(): void
    {
        $offenders = [];
        foreach ($this->allWidgets() as $widget) {
            if (str_contains($widget->tooltip, '%(')) {
                $offenders[] = $widget->caption . ': ' . $widget->tooltip;
            }
        }

        $this->assertSame([], $offenders, 'Python %-placeholders are unrenderable by PHP');
    }

    public function testEveryTooltipTokenIsWordNamed(): void
    {
        // {Brace} tokens must be \w+ (the seam's grammar): a dotted or spaced
        // name would never match and re-introduce a silent-unrender class.
        foreach ($this->allWidgets() as $widget) {
            if ($widget->tooltip === '') {
                continue;
            }
            preg_match_all('/\{([^{}]*)\}/', $widget->tooltip, $matches);
            foreach ($matches[1] as $token) {
                $this->assertSame(
                    1,
                    preg_match('/^\w+$/', $token),
                    'Tooltip token must be word-named: ' . $widget->tooltip,
                );
            }
        }
    }

    public function testEveryCatalogatedTooltipRendersFully(): void
    {
        $rendered = 0;
        foreach ($this->allWidgets() as $widget) {
            if ($widget->tooltip === '') {
                continue;
            }
            preg_match_all('/\{(\w+)\}/', $widget->tooltip, $matches);
            if ($matches[1] === []) {
                continue; // prose-only tooltip (e.g. 'Connections level vs max_connections') — nothing to fill
            }
            $vars = array_fill_keys($matches[1], '42');
            $out = $widget->renderTooltip($vars);

            $this->assertStringNotContainsString('{', $out);
            $this->assertStringNotContainsString('%(', $out);
            $this->assertStringContainsString('42', $out);
            $rendered++;
        }

        // Non-vacuity: the eight rewritten sites must actually be present.
        $this->assertSame(8, $rendered);
    }

    public function testRenderLeavesUnknownTokensVisible(): void
    {
        $widget = WidgetRegistry::network()[0];
        $probe = new Widget(
            caption: $widget->caption,
            kind: $widget->kind,
            calc: $widget->calc,
            format: $widget->format,
            color: $widget->color,
            tooltip: 'Traffic: {Bytes_missing} bytes',
        );

        // A missing value reads as missing — it never vanishes silently.
        $this->assertSame('Traffic: {Bytes_missing} bytes', $probe->renderTooltip([]));
        $this->assertSame('Traffic: 7 bytes', $probe->renderTooltip(['Bytes_missing' => 7]));
    }
}
