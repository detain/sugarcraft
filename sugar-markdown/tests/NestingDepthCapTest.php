<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Markdown\Renderer;

/**
 * Hostile-nesting bounds (review C1): nested blockquotes used to re-wrap
 * the accumulated child at every level, which doubled output AND time per
 * added level once the 1-cell width floor was reached — exponential growth
 * from linear input, a trivial DoS of any consumer rendering untrusted
 * Markdown (candy-mdview on a hostile README). The fix is a documented
 * nesting ceiling (Renderer::MAX_NESTED_BLOCK_DEPTH: deeper blocks render
 * their content with no added decoration) plus a quote re-wrap floor, so
 * per-level work is bounded and output grows at most linearly to a hard
 * ceiling.
 */
final class NestingDepthCapTest extends TestCase
{
    private const BODY = 'word word word word word word word word word word word word word word word word word word word word';

    private function quotes(int $depth): string
    {
        return str_repeat('> ', $depth) . self::BODY . "\n";
    }

    private function render(int $depth): string
    {
        return Renderer::plain()->withWordWrap(40)->render($this->quotes($depth));
    }

    public function testOutputCeilingsPastTheNestingCap(): void
    {
        // Pre-fix this ladder doubled every level (d24 alone was 204 KB).
        $atCap   = $this->render(Renderer::MAX_NESTED_BLOCK_DEPTH);
        $beyond  = $this->render(Renderer::MAX_NESTED_BLOCK_DEPTH + 1);
        $wayOut  = $this->render(40);

        $this->assertSame($atCap, $beyond, 'flattening makes deeper nesting byte-identical');
        $this->assertSame($atCap, $wayOut);
        $this->assertLessThan(64_000, strlen($atCap));
        $this->assertLessThan(64_000, strlen($wayOut));
    }

    public function testDepthGrowthBelowTheCapIsLinearNotExponential(): void
    {
        $prev = $this->render(20);
        foreach ([22, 24, 26, 28, 30] as $depth) {
            $next = $this->render($depth);
            // Pre-fix every single level DOUBLED the output here; per two
            // levels the legitimate growth is bar prefixes on existing
            // lines, comfortably under any factor-2-per-level curve.
            $this->assertLessThanOrEqual(strlen($prev) * 1.8 + 1_000, strlen($next), 'depth ' . $depth);
            $this->assertGreaterThan(strlen($prev), strlen($next));
            $prev = $next;
        }
    }

    public function testParserMaximumNestingRendersInBoundedTimeAndSize(): void
    {
        $start    = microtime(true);
        $output   = $this->render(1000);
        $elapsed  = microtime(true) - $start;

        $this->assertLessThan(64_000, strlen($output));
        $this->assertLessThan(5.0, $elapsed, 'd=1000 hostile quote nest must not approach the old 10s+ blowup');
        // Every letter survives; at the 1-cell wrap floor words are split
        // line-per-cell (documented narrow-pane behavior) and each fragment
        // line carries the bars of every surviving level, so match letters
        // in order across the decoration.
        $this->assertMatchesRegularExpression('/w[^a-z]*o[^a-z]*r[^a-z]*d/s', $output, 'content survives flattening');
    }

    public function testNestedListsAreBoundedByTheSameCap(): void
    {
        $deep = str_repeat('- ', 600) . "bottom\n";
        $start = microtime(true);
        $out   = Renderer::plain()->withWordWrap(40)->render($deep);
        $this->assertLessThan(2.0, microtime(true) - $start);
        $this->assertMatchesRegularExpression('/b[^a-z]*o[^a-z]*t[^a-z]*t[^a-z]*o[^a-z]*m/s', $out);

        // The cap makes deeper lists flatten: two depths past the ceiling
        // differ only by the still-decorated ancestors, growth stays linear.
        $a = Renderer::plain()->withWordWrap(40)->render(str_repeat('- ', 40) . "x\n");
        $b = Renderer::plain()->withWordWrap(40)->render(str_repeat('- ', 41) . "x\n");
        $this->assertLessThanOrEqual(strlen($a) + 100, strlen($b));
    }

    public function testListItemDepthFreezesAtTheCapLikeQuoteSiblings(): void
    {
        // SM-S08: the list cap stopped the List context but its flattened
        // item children still pushed a margin context each, so a '- '
        // chain kept adding decoration — and collapsing the child wrap
        // width toward the 1-cell floor — well past the advertised cap.
        // With the cap on the item leg too, the block stack freezes and
        // the output is byte-stable: a hostile 1,000-level nest renders
        // exactly like one at the ceiling (each nest level stacks two
        // contexts, so the 32-cap freezes the ladder at 16 levels; the
        // ceiling itself is the documented reference point).
        $render = static fn (int $depth): string =>
            Renderer::plain()->withWordWrap(40)->render(str_repeat('- ', $depth) . "x\n");
        $cap = Renderer::MAX_NESTED_BLOCK_DEPTH;

        $this->assertSame($render($cap), $render($cap + 1), 'an item past the ceiling renders content only');
        $this->assertSame($render($cap), $render(1000), 'the frozen stack makes deeper nests byte-identical');
        $this->assertStringContainsString('• • • • x', $render(4), 'ordinary nesting keeps every marker');
    }

    public function testOrdinaryNestingKeepsFullDecoration(): void
    {
        // One level below the cap still wears its bar: the ceiling is a
        // hostile-input bound, not a styling change for real documents.
        $out = $this->render(4);
        $this->assertStringContainsString('▎ ▎ ▎ ▎', $out);
    }
}
