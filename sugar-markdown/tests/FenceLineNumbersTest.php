<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\SyntaxHighlighter;
use SugarCraft\Markdown\Theme;

/**
 * SM-S34 (sugar-markdown-033 / MDX-08): Renderer::withLineNumbers() threads
 * the SyntaxHighlighter's existing gutter through fenced code. Default off
 * keeps every byte as-is; the gutter joins with the measurable box-rule
 * separator (never a bare tab), and with fenceColumnBudget on, the numbers
 * consume the available width like any other fence content.
 */
final class FenceLineNumbersTest extends TestCase
{
    private const FENCE = "```php\n\$a = 1;\n\$b = 2;\n```\n";

    public function testDefaultOffIsByteIdenticalToTheHistoricRender(): void
    {
        $md = self::FENCE;

        $this->assertSame(
            (new Renderer(Theme::plain()))->render($md),
            (new Renderer(Theme::plain()))->withLineNumbers(false)->render($md),
            'the flag must stay invisible when off',
        );
    }

    public function testThePinnedOptionRendersTheNumberedGutter(): void
    {
        $out = (new Renderer(Theme::plain()))->withLineNumbers(true)->render(self::FENCE);

        $this->assertStringContainsString('1' . SyntaxHighlighter::GUTTER_SEPARATOR, $out);
        $this->assertStringContainsString('2' . SyntaxHighlighter::GUTTER_SEPARATOR, $out);
        $this->assertStringNotContainsString("\t", $out, 'no tab may ride the gutter');
    }

    public function testTheGutterConsumesTheFenceColumnBudget(): void
    {
        $md = "```php\n" . str_repeat('$x = 123456789012345678901234567890;', 3) . "\n```\n";

        $out = (new Renderer(Theme::plain(), 20))
            ->withFenceColumnBudget(true)
            ->withLineNumbers(true)
            ->render($md);

        foreach (explode("\n", $out) as $line) {
            // The plain theme emits no SGR, so cells == characters here.
            $this->assertLessThanOrEqual(
                20,
                mb_strlen($line),
                "fence line exceeds the 20-cell budget: {$line}",
            );
        }
        $this->assertStringContainsString('1' . SyntaxHighlighter::GUTTER_SEPARATOR, $out);
    }

    public function testTheStreamingChannelStaysByteExactWithTheGutterOn(): void
    {
        $renderer = (new Renderer(Theme::plain()))->withLineNumbers(true);
        $chunks = ["```php\n", "\$a = 1;\n", "\$b = 2;\n", "```\n"];

        $streamed = implode('', iterator_to_array($renderer->stream($chunks), false));
        $this->assertSame($renderer->render(implode('', $chunks)), $streamed);
    }

    public function testPlainFencesWithoutALanguageHintKeepNoGutter(): void
    {
        $md = "```\nplain\nlines\n```\n";

        $this->assertStringNotContainsString(
            '1' . SyntaxHighlighter::GUTTER_SEPARATOR,
            (new Renderer(Theme::plain()))->withLineNumbers(true)->render($md),
        );
    }
}
