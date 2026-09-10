<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Shine\Renderer;
use SugarCraft\Shine\Theme;

/**
 * E43 step 1 (fence half) — `withFenceColumnBudget()` clips every rendered
 * fenced-code line at the available width. Tables got bounded geometry from
 * E49's column redistribution; a fence has no columns to redistribute, so
 * clipping is the only shrink this API can honestly offer. Default OFF: the
 * legacy overflow path stays byte-identical.
 */
final class FenceColumnBudgetTest extends TestCase
{
    private const LONG = 'x';  // expanded per test

    private function wideFence(string $lang = ''): string
    {
        return "```{$lang}\n" . str_repeat('x', 120) . "\nshort\n```\n";
    }

    /** @return list<int> display width of every physical line */
    private function lineWidths(string $rendered): array
    {
        return array_map(
            static fn (string $line): int => Width::of($line),
            explode("\n", $rendered),
        );
    }

    public function testDefaultBehaviourIsByteIdenticalToTheLegacyRenderer(): void
    {
        $legacy = (new Renderer(Theme::plain(), 40))->render($this->wideFence());
        $off = (new Renderer(Theme::plain(), 40))->fenceColumnBudget(false)->render($this->wideFence());

        $this->assertSame($legacy, $off, 'the new option must be inert when off');
        $this->assertGreaterThan(
            40,
            max($this->lineWidths($legacy)),
            'sanity: the legacy fence DOES overflow — this is the behaviour the option exists to bound',
        );
    }

    public function testPlainFenceLinesAreClippedToTheWrapWidth(): void
    {
        $rendered = (new Renderer(Theme::plain(), 40))->fenceColumnBudget(true)->render($this->wideFence());

        foreach ($this->lineWidths($rendered) as $w) {
            $this->assertLessThanOrEqual(40, $w, 'no rendered fence line may exceed the wrap width');
        }
        $this->assertStringContainsString('short', $rendered);
        $this->assertStringNotContainsString(str_repeat('x', 41), $rendered);
    }

    public function testHighlightedFenceLinesAreClippedToo(): void
    {
        $fence = "```php\n" . str_repeat('$', 100) . "echo\n```";
        $rendered = (new Renderer(Theme::plain(), 40))->fenceColumnBudget(true)->render($fence);

        foreach ($this->lineWidths($rendered) as $w) {
            $this->assertLessThanOrEqual(40, $w, 'the highlighter path must honour the same budget');
        }
    }

    public function testIndentedCodeIsNotClipped(): void
    {
        // The entry scopes the FENCED half: a four-space indented code block
        // rides the untouched path even with the option on.
        $md = "para\n\n    " . str_repeat('i', 120) . "\n";
        $rendered = (new Renderer(Theme::plain(), 40))->fenceColumnBudget(true)->render($md);

        $this->assertGreaterThan(40, max($this->lineWidths($rendered)));
    }

    public function testWithoutWrapWidthTheOptionIsIdentity(): void
    {
        $clipless = (new Renderer(Theme::plain()))->render($this->wideFence());
        $onButUnbounded = (new Renderer(Theme::plain()))->fenceColumnBudget(true)->render($this->wideFence());

        $this->assertSame($clipless, $onButUnbounded, 'nothing to clip against without a wrap width');
    }

    public function testCopyPreservesTheOptionThroughOtherBuilders(): void
    {
        $r = (new Renderer(Theme::plain(), 40))
            ->fenceColumnBudget(true)
            ->withTableColumnBudget(true)
            ->withStandardStyle('plain');

        $rendered = $r->render($this->wideFence());
        foreach ($this->lineWidths($rendered) as $w) {
            $this->assertLessThanOrEqual(40, $w);
        }
    }
}
