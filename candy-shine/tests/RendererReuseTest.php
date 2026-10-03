<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Shine\Render\BlockStack;
use SugarCraft\Shine\Renderer;
use SugarCraft\Shine\Theme;

/**
 * A reused {@see Renderer} starts every render() from fresh block state
 * (crush_libs shine #4: each call pushed another Document context and
 * never popped it, so a long-lived renderer's stack grew without bound).
 */
final class RendererReuseTest extends TestCase
{
    private static function stack(Renderer $r): ?BlockStack
    {
        return (new \ReflectionProperty(Renderer::class, 'blockStack'))->getValue($r);
    }

    public function testTheBlockStackDoesNotGrowAcrossRenders(): void
    {
        $r = new Renderer(Theme::plain());
        for ($i = 0; $i < 5; $i++) {
            $r->render("# h\n\n> quote\n\n- item");
            $this->assertSame(1, self::stack($r)?->depth(), "after render #{$i}");
        }
    }

    public function testAReusedRendererRendersLikeAFreshOne(): void
    {
        $md = "# h\n\n> quote with a long line that wraps\n\n- item\n  - nested item text";
        $reused = (new Renderer(Theme::ansi()))->withWordWrap(20);
        for ($i = 0; $i < 3; $i++) {
            $reused->render($md);
        }

        $this->assertSame((new Renderer(Theme::ansi()))->withWordWrap(20)->render($md), $reused->render($md));
    }
}
