<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Image\Renderer\ChafaRenderer;
use SugarCraft\Image\Renderer\HalfBlockRenderer;
use SugarCraft\Image\Renderer\Iterm2Renderer;
use SugarCraft\Image\Renderer\KittyRenderer;
use SugarCraft\Image\Renderer\QuarterBlockRenderer;
use SugarCraft\Image\Renderer\SixelRenderer;

/**
 * Tests for the Renderer::delete() API across all backends.
 */
final class DeleteApiTest extends TestCase
{
    public function testKittyRendererDeleteEmitsApcSequence(): void
    {
        $renderer = new KittyRenderer();
        $out = $renderer->delete('42');

        // Must emit the APC sequence for delete action with the image id.
        $this->assertStringStartsWith(Ansi::APC . 'G', $out);
        $this->assertStringEndsWith(Ansi::ST, $out);
        $this->assertStringContainsString('a=d', $out);
        $this->assertStringContainsString('i=42', $out);
    }

    public function testIterm2RendererDeleteEmitsOsc1337Pop(): void
    {
        $renderer = new Iterm2Renderer();
        $out = $renderer->delete('ignored-id');

        // Must emit OSC 1337 Pop sequence (ignores the image id).
        $this->assertSame(Ansi::iterm2Delete(), $out);
    }

    public function testSixelRendererDeleteReturnsEmptyString(): void
    {
        $renderer = new SixelRenderer();
        $out = $renderer->delete('any-id');
        $this->assertSame('', $out);
    }

    public function testHalfBlockRendererDeleteReturnsEmptyString(): void
    {
        $renderer = new HalfBlockRenderer();
        $out = $renderer->delete('any-id');
        $this->assertSame('', $out);
    }

    public function testQuarterBlockRendererDeleteReturnsEmptyString(): void
    {
        $renderer = new QuarterBlockRenderer();
        $out = $renderer->delete('any-id');
        $this->assertSame('', $out);
    }

    public function testChafaRendererDeleteReturnsEmptyString(): void
    {
        $renderer = new ChafaRenderer();
        $out = $renderer->delete('any-id');
        $this->assertSame('', $out);
    }

    /**
     * Verify the WezTerm detection fix: WezTerm must NOT appear in
     * the iTerm2 capability block — it belongs to the Kitty family.
     *
     * @see Detect::probeEnv() — WezTerm is handled exclusively in the
     *      Kitty block (TERM_PROGRAM=WezTerm → Capability::kitty).
     */
    public function testWezTermDetectedAsKittyNotIterm2(): void
    {
        // Simulate WezTerm environment.
        putenv('TERM_PROGRAM=WezTerm');
        try {
            \SugarCraft\Image\Detect::reset();
            $cap = \SugarCraft\Image\Detect::probe();

            // WezTerm must be detected as Kitty (supports Kitty protocol).
            $this->assertTrue($cap->kitty, 'WezTerm should be detected as kitty capability');
            // WezTerm must NOT be detected as iTerm2.
            $this->assertFalse($cap->iterm2, 'WezTerm must NOT be detected as iterm2 capability');
        } finally {
            putenv('TERM_PROGRAM');
            \SugarCraft\Image\Detect::reset();
        }
    }
}
