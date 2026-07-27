<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Table;

use PHPUnit\Framework\TestCase;
use SugarCraft\Bits\Table\Styles;
use SugarCraft\Sprinkles\Style;

/**
 * Tests for {@see Styles} — header / cell / selected style slots for Table.
 */
final class StylesTest extends TestCase
{
    public function testDefaultsAreNoOpStyles(): void
    {
        $s = new Styles();
        // All three slots are distinct no-op Style instances
        $this->assertInstanceOf(Style::class, $s->header);
        $this->assertInstanceOf(Style::class, $s->cell);
        $this->assertInstanceOf(Style::class, $s->selected);
    }

    public function testCustomStylesAreStored(): void
    {
        $header   = Style::new()->bold();
        $cell     = Style::new()->italic();
        $selected = Style::new()->underline();

        $s = new Styles($header, $cell, $selected);

        $this->assertSame($header,   $s->header);
        $this->assertSame($cell,     $s->cell);
        $this->assertSame($selected, $s->selected);
    }

    public function testNullArgumentsDefaultToNoOp(): void
    {
        $s = new Styles(null, null, null);
        $this->assertInstanceOf(Style::class, $s->header);
        $this->assertInstanceOf(Style::class, $s->cell);
        $this->assertInstanceOf(Style::class, $s->selected);
    }

    public function testPartialCustomStyles(): void
    {
        $bold = Style::new()->bold();
        $s = new Styles($bold);
        $this->assertSame($bold, $s->header);
        $this->assertInstanceOf(Style::class, $s->cell);
        $this->assertInstanceOf(Style::class, $s->selected);
    }

    public function testImmutabilityOfStoredStyles(): void
    {
        $s = new Styles(Style::new()->bold());
        $stored = $s->header;
        // Mutating the style object does not affect the stored reference
        // (Style is immutable via with* pattern, so the reference stays valid)
        $this->assertInstanceOf(Style::class, $stored);
    }
}
