<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Encode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Encode\PhpGifEncoder;
use SugarCraft\Vcr\Encode\TapeToGif;
use SugarCraft\Vcr\Raster\GdRasterizer;
use SugarCraft\Vcr\Tape\Compiler;
use SugarCraft\Vcr\Tape\Lexer;
use SugarCraft\Vcr\Tape\Parser;
use SugarCraft\Vcr\Render\Renderer;

/**
 * Output-extension honesty (finding 009): candy-vcr emits GIF bytes and
 * nothing else, so a `.mp4`/`.webm`/extension-less target is rewritten to
 * `.gif` at the single write door — CLI -o, the tape's own Output directive,
 * and the fallback alike — with the swap booked as a named warning. The
 * failure mode pinned out is a file that lies about itself: GIF bytes
 * wearing a video suffix because nobody looked at the name.
 */
final class OutputExtensionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = (string) realpath(sys_get_temp_dir()) . '/vcrext' . uniqid('', true);
        mkdir($this->dir, 0o777, true);
        file_put_contents(
            $this->dir . '/a.tape',
            "Set Width 200\nSet Height 100\nSet FontSize 14\nType \"hi\"\n",
        );
        file_put_contents(
            $this->dir . '/b.tape',
            "Output \"demo.mp4\"\nSet Width 200\nSet Height 100\nSet FontSize 14\nType \"hi\"\n",
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->dir);
    }

    private function pipeline(): TapeToGif
    {
        return new TapeToGif(new Lexer(), new Parser(), new Compiler(), new Renderer(), new GdRasterizer(), new PhpGifEncoder());
    }

    public function testDefaultFallbackNeedsNoWarning(): void
    {
        $gif = $this->pipeline()->render($this->dir . '/a.tape', null, []);
        $this->assertSame($this->dir . '/a.gif', $gif);
        $this->assertFileExists($gif);
        $this->assertStringStartsWith('GIF8', (string) file_get_contents($gif));
    }

    public function testExplicitMp4TargetIsSwappedToGifWithNamedWarning(): void
    {
        $pipeline = $this->pipeline();
        $gif = $pipeline->render($this->dir . '/a.tape', $this->dir . '/x.mp4', []);
        $this->assertSame($this->dir . '/x.gif', $gif);
        $this->assertFileExists($gif);
        $this->assertStringStartsWith('GIF8', (string) file_get_contents($gif));
        $this->assertFileDoesNotExist($this->dir . '/x.mp4');
        $joined = implode("\n", $pipeline->warnings());
        $this->assertStringContainsString("Output path '{$this->dir}/x.mp4' is not a .gif file", $joined);
        $this->assertStringContainsString("writing '{$this->dir}/x.gif' instead", $joined);
    }

    public function testTapeOutputDirectiveIsAlsoSwapped(): void
    {
        $pipeline = $this->pipeline();
        $gif = $pipeline->render($this->dir . '/b.tape', null, []);
        // The directive is first confined to the tape dir (finding 005-era
        // door), then the extension honesty applies inside that confinement.
        $this->assertSame($this->dir . '/demo.gif', $gif);
        $this->assertFileExists($gif);
        $this->assertFileDoesNotExist($this->dir . '/demo.mp4');
        $this->assertStringContainsString(
            'is not a .gif file',
            implode("\n", $pipeline->warnings()),
        );
    }

    public function testExtensionlessTargetGainsGifSuffix(): void
    {
        $gif = $this->pipeline()->render($this->dir . '/a.tape', $this->dir . '/plain', []);
        $this->assertSame($this->dir . '/plain.gif', $gif);
        $this->assertFileExists($gif);
    }

    public function testGifTargetsAreUntouchedCaseInsensitively(): void
    {
        $pipeline = $this->pipeline();
        $lower = $pipeline->render($this->dir . '/a.tape', $this->dir . '/ok.gif', []);
        $this->assertSame($this->dir . '/ok.gif', $lower);
        $upper = $pipeline->render($this->dir . '/a.tape', $this->dir . '/ok2.GIF', []);
        $this->assertSame($this->dir . '/ok2.GIF', $upper);
        $this->assertStringNotContainsString('is not a .gif file', implode("\n", $pipeline->warnings()));
    }

    public function testRenderWarningsResetBetweenRunsOnAReusedPipeline(): void
    {
        $pipeline = $this->pipeline();
        $pipeline->render($this->dir . '/a.tape', $this->dir . '/y.webm', []);
        $this->assertStringContainsString('is not a .gif file', implode("\n", $pipeline->warnings()));
        $pipeline->render($this->dir . '/a.tape', $this->dir . '/z.gif', []);
        // The batch-cadence promise: a clean run reports nothing from the
        // previous tape's swap.
        $this->assertStringNotContainsString('is not a .gif file', implode("\n", $pipeline->warnings()));
    }

    public function testDotfileNamedTargetStaysSane(): void
    {
        // pathinfo treats '.gif' as extension-less; the honest .gif name that
        // comes out must still be a writable GIF, never the original bytes
        // mislabeled.
        $gif = $this->pipeline()->render($this->dir . '/a.tape', $this->dir . '/.hidden', []);
        $this->assertSame($this->dir . '/.hidden.gif', $gif);
        $this->assertStringStartsWith('GIF8', (string) file_get_contents($gif));
    }
}
