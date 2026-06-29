<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\Renderer\HalfBlockRenderer;
use SugarCraft\Mosaic\Renderer\KittyRenderer;
use SugarCraft\Mosaic\Renderer\Renderer;
use SugarCraft\Mosaic\TmuxPassthroughDecorator;

/**
 * @covers \SugarCraft\Mosaic\TmuxPassthroughDecorator
 */
final class TmuxPassthroughDecoratorTest extends TestCase
{
    public function testImplementsRendererInterface(): void
    {
        $inner = new HalfBlockRenderer();
        $decorator = new TmuxPassthroughDecorator($inner);

        $this->assertInstanceOf(Renderer::class, $decorator);
    }

    public function testIsInlineDelegatesToInnerForInlineRenderer(): void
    {
        $inner = new HalfBlockRenderer();
        $decorator = new TmuxPassthroughDecorator($inner);

        $this->assertTrue($decorator->isInline());
    }

    public function testIsInlineDelegatesToInnerForNonInlineRenderer(): void
    {
        $inner = new KittyRenderer();
        $decorator = new TmuxPassthroughDecorator($inner);

        $this->assertFalse($decorator->isInline());
    }

    public function testDeleteWrapsInnerSequenceForKittyRenderer(): void
    {
        $inner = new KittyRenderer();
        $decorator = new TmuxPassthroughDecorator($inner);

        $output = $decorator->delete('1');

        // Output should be wrapped in tmux passthrough envelope.
        $this->assertStringStartsWith("\x1bPtmux;", $output);
        $this->assertStringEndsWith("\x1b\\", $output);
        // Inner ESC bytes should be doubled inside the envelope.
        $this->assertStringContainsString("\x1b\x1b", $output);
    }

    public function testDeleteWrapsInnerSequenceForHalfBlockRenderer(): void
    {
        $inner = new HalfBlockRenderer();
        $decorator = new TmuxPassthroughDecorator($inner);

        // HalfBlockRenderer::delete() returns ''.
        $output = $decorator->delete('1');

        $this->assertSame('', $output);
    }

    public function testWrapEnvelopesDcsSequence(): void
    {
        $inner = new HalfBlockRenderer();
        $decorator = new TmuxPassthroughDecorator($inner);

        // DCS: \x1bP … \x1b\\
        $dcs = "\x1bP=1\x1b\\";
        $wrapped = $decorator->wrap($dcs);

        $this->assertStringStartsWith("\x1bPtmux;", $wrapped);
        $this->assertStringEndsWith("\x1b\\", $wrapped);
        // ESC inside should be doubled.
        $this->assertStringContainsString("\x1b\x1bP", $wrapped);
    }

    public function testWrapEnvelopesApcSequence(): void
    {
        $inner = new HalfBlockRenderer();
        $decorator = new TmuxPassthroughDecorator($inner);

        // APC: \x1b_ … \x1b\\
        $apc = "\x1b_foo\x1b\\";
        $wrapped = $decorator->wrap($apc);

        $this->assertStringStartsWith("\x1bPtmux;", $wrapped);
        $this->assertStringEndsWith("\x1b\\", $wrapped);
        $this->assertStringContainsString("\x1b\x1b_", $wrapped);
    }

    public function testWrapEnvelopesOscWithBelTerminator(): void
    {
        $inner = new HalfBlockRenderer();
        $decorator = new TmuxPassthroughDecorator($inner);

        // OSC with BEL terminator: \x1b] … \x07
        $osc = "\x1b]50; Гарри\x07";
        $wrapped = $decorator->wrap($osc);

        $this->assertStringStartsWith("\x1bPtmux;", $wrapped);
        $this->assertStringEndsWith("\x1b\\", $wrapped);
    }

    public function testWrapLeavesPlainBytesUnchanged(): void
    {
        $inner = new HalfBlockRenderer();
        $decorator = new TmuxPassthroughDecorator($inner);

        // Plain ASCII with no DCS/APC/OSC escape sequences — must pass through unchanged.
        $plain = "Hello, World!";
        $wrapped = $decorator->wrap($plain);

        $this->assertSame($plain, $wrapped);
    }

    public function testWrapEmptyStringReturnsEmpty(): void
    {
        $inner = new HalfBlockRenderer();
        $decorator = new TmuxPassthroughDecorator($inner);

        $this->assertSame('', $decorator->wrap(''));
    }

    public function testSupportsAlphaDelegatesToInner(): void
    {
        $inner = new HalfBlockRenderer();
        $decorator = new TmuxPassthroughDecorator($inner);

        $this->assertFalse($decorator->supportsAlpha());
    }

    public function testNameIncludesTmuxPrefix(): void
    {
        $inner = new HalfBlockRenderer();
        $decorator = new TmuxPassthroughDecorator($inner);

        $this->assertSame('tmux(halfblock)', $decorator->name());
    }
}
