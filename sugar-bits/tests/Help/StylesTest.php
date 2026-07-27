<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Help;

use PHPUnit\Framework\TestCase;
use SugarCraft\Bits\Help\Styles;
use SugarCraft\Sprinkles\Style;

/**
 * Tests for {@see Styles} — per-element style slots for Help rendering.
 */
final class StylesTest extends TestCase
{
    public function testDefaultsAreNoOpStyles(): void
    {
        $s = new Styles();
        $this->assertInstanceOf(Style::class, $s->shortKey);
        $this->assertInstanceOf(Style::class, $s->shortDesc);
        $this->assertInstanceOf(Style::class, $s->shortSeparator);
        $this->assertInstanceOf(Style::class, $s->fullKey);
        $this->assertInstanceOf(Style::class, $s->fullDesc);
        $this->assertInstanceOf(Style::class, $s->fullSeparator);
        $this->assertInstanceOf(Style::class, $s->ellipsis);
    }

    public function testAllStylesCanBeCustomized(): void
    {
        $shortKey       = Style::new()->bold();
        $shortDesc      = Style::new()->italic();
        $shortSeparator = Style::new()->faint();
        $fullKey        = Style::new()->underline();
        $fullDesc       = Style::new()->strikethrough();
        $fullSeparator  = Style::new()->foreground(\SugarCraft\Core\Util\Color::ansi(4));
        $ellipsis       = Style::new()->dim();

        $s = new Styles(
            $shortKey,
            $shortDesc,
            $shortSeparator,
            $fullKey,
            $fullDesc,
            $fullSeparator,
            $ellipsis,
        );

        $this->assertSame($shortKey,       $s->shortKey);
        $this->assertSame($shortDesc,      $s->shortDesc);
        $this->assertSame($shortSeparator, $s->shortSeparator);
        $this->assertSame($fullKey,        $s->fullKey);
        $this->assertSame($fullDesc,       $s->fullDesc);
        $this->assertSame($fullSeparator, $s->fullSeparator);
        $this->assertSame($ellipsis,       $s->ellipsis);
    }

    public function testNullArgumentsDefaultToNoOp(): void
    {
        $s = new Styles(null, null, null, null, null, null, null);
        $this->assertInstanceOf(Style::class, $s->shortKey);
        $this->assertInstanceOf(Style::class, $s->shortDesc);
        $this->assertInstanceOf(Style::class, $s->shortSeparator);
        $this->assertInstanceOf(Style::class, $s->fullKey);
        $this->assertInstanceOf(Style::class, $s->fullDesc);
        $this->assertInstanceOf(Style::class, $s->fullSeparator);
        $this->assertInstanceOf(Style::class, $s->ellipsis);
    }

    public function testUniformFactoryStylesAllSlots(): void
    {
        $shared = Style::new()->bold();
        $s = Styles::uniform($shared);

        $this->assertSame($shared, $s->shortKey);
        $this->assertSame($shared, $s->shortDesc);
        $this->assertSame($shared, $s->shortSeparator);
        $this->assertSame($shared, $s->fullKey);
        $this->assertSame($shared, $s->fullDesc);
        $this->assertSame($shared, $s->fullSeparator);
        $this->assertSame($shared, $s->ellipsis);
    }

    public function testPartialConstruction(): void
    {
        $bold = Style::new()->bold();
        $s = new Styles(shortKey: $bold);

        $this->assertSame($bold, $s->shortKey);
        $this->assertInstanceOf(Style::class, $s->shortDesc);
        $this->assertInstanceOf(Style::class, $s->shortSeparator);
        $this->assertInstanceOf(Style::class, $s->fullKey);
        $this->assertInstanceOf(Style::class, $s->fullDesc);
        $this->assertInstanceOf(Style::class, $s->fullSeparator);
        $this->assertInstanceOf(Style::class, $s->ellipsis);
    }
}
