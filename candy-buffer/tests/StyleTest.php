<?php

declare(strict_types=1);

namespace SugarCraft\Buffer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Buffer\Style;

/**
 * @covers \SugarCraft\Buffer\Style
 */
final class StyleTest extends TestCase
{
    public function testNewDefault(): void
    {
        $style = Style::new();

        $this->assertNull($style->fg());
        $this->assertNull($style->bg());
        $this->assertSame(0, $style->attrs());
    }

    public function testNewWithColors(): void
    {
        $style = Style::new(0xff0000, 0x0000ff);

        $this->assertSame(0xff0000, $style->fg());
        $this->assertSame(0x0000ff, $style->bg());
    }

    public function testBold(): void
    {
        $style = Style::bold();

        $this->assertTrue($style->hasBold());
        $this->assertFalse($style->hasItalic());
        $this->assertFalse($style->hasUnderline());
        $this->assertFalse($style->hasStrike());
        $this->assertFalse($style->hasFaint());
        $this->assertFalse($style->hasBlink());
        $this->assertFalse($style->hasReverse());
        $this->assertFalse($style->hasOverline());
        $this->assertFalse($style->hasInvisible());
    }

    public function testReverse(): void
    {
        $style = Style::reverse();

        $this->assertFalse($style->hasBold());
        $this->assertTrue($style->hasReverse());
    }

    public function testHasBold(): void
    {
        $style = Style::new(null, null, Style::ATTR_BOLD);
        $this->assertTrue($style->hasBold());

        $style = Style::new();
        $this->assertFalse($style->hasBold());
    }

    public function testHasItalic(): void
    {
        $style = Style::new(null, null, Style::ATTR_ITALIC);
        $this->assertTrue($style->hasItalic());

        $style = Style::new();
        $this->assertFalse($style->hasItalic());
    }

    public function testHasUnderline(): void
    {
        $style = Style::new(null, null, Style::ATTR_UNDERLINE);
        $this->assertTrue($style->hasUnderline());

        $style = Style::new();
        $this->assertFalse($style->hasUnderline());
    }

    public function testHasStrike(): void
    {
        $style = Style::new(null, null, Style::ATTR_STRIKE);
        $this->assertTrue($style->hasStrike());

        $style = Style::new();
        $this->assertFalse($style->hasStrike());
    }

    public function testHasFaint(): void
    {
        $style = Style::new(null, null, Style::ATTR_FAINT);
        $this->assertTrue($style->hasFaint());

        $style = Style::new();
        $this->assertFalse($style->hasFaint());
    }

    public function testHasBlink(): void
    {
        $style = Style::new(null, null, Style::ATTR_BLINK);
        $this->assertTrue($style->hasBlink());

        $style = Style::new();
        $this->assertFalse($style->hasBlink());
    }

    public function testHasReverse(): void
    {
        $style = Style::new(null, null, Style::ATTR_REVERSE);
        $this->assertTrue($style->hasReverse());

        $style = Style::new();
        $this->assertFalse($style->hasReverse());
    }

    public function testHasOverline(): void
    {
        $style = Style::new(null, null, Style::ATTR_OVERLINE);
        $this->assertTrue($style->hasOverline());

        $style = Style::new();
        $this->assertFalse($style->hasOverline());
    }

    public function testHasInvisible(): void
    {
        $style = Style::new(null, null, Style::ATTR_INVISIBLE);
        $this->assertTrue($style->hasInvisible());

        $style = Style::new();
        $this->assertFalse($style->hasInvisible());
    }

    public function testMultipleAttributes(): void
    {
        $style = Style::new(null, null, Style::ATTR_BOLD | Style::ATTR_ITALIC | Style::ATTR_UNDERLINE);

        $this->assertTrue($style->hasBold());
        $this->assertTrue($style->hasItalic());
        $this->assertTrue($style->hasUnderline());
        $this->assertFalse($style->hasStrike());
    }

    // ─── Fluent builder tests ──────────────────────────────────────────

    public function testWithFgReturnsNewInstance(): void
    {
        $original = Style::new(0xff0000);
        $changed = $original->withFg(0x00ff00);

        // New instance returned; original unchanged
        $this->assertNotSame($original, $changed);
        // Foreground updated
        $this->assertSame(0x00ff00, $changed->fg());
        // Original preserved
        $this->assertSame(0xff0000, $original->fg());
        // Other fields preserved
        $this->assertNull($changed->bg());
        $this->assertSame(0, $changed->attrs());
    }

    public function testWithBgReturnsNewInstance(): void
    {
        $original = Style::new(null, 0x0000ff);
        $changed = $original->withBg(0xffff00);

        $this->assertNotSame($original, $changed);
        $this->assertSame(0xffff00, $changed->bg());
        $this->assertSame(0x0000ff, $original->bg());
    }

    public function testWithAttrsReplaces(): void
    {
        $original = Style::new(null, null, Style::ATTR_BOLD);
        $changed = $original->withAttrs(Style::ATTR_ITALIC | Style::ATTR_UNDERLINE);

        $this->assertNotSame($original, $changed);
        $this->assertSame(Style::ATTR_ITALIC | Style::ATTR_UNDERLINE, $changed->attrs());
        // Bold removed, italic/underline added
        $this->assertFalse($changed->hasBold());
        $this->assertTrue($changed->hasItalic());
        $this->assertTrue($changed->hasUnderline());
    }

    public function testWithBoldTogglesBit(): void
    {
        $original = Style::new();

        $on = $original->withBold(true);
        $this->assertTrue($on->hasBold());
        $this->assertFalse($original->hasBold()); // original unchanged

        $off = $on->withBold(false);
        $this->assertFalse($off->hasBold());
        $this->assertTrue($on->hasBold()); // previous unchanged
    }

    public function testWithReverseTogglesBit(): void
    {
        $original = Style::new();

        $on = $original->withReverse(true);
        $this->assertTrue($on->hasReverse());
        $this->assertFalse($original->hasReverse());

        $off = $on->withReverse(false);
        $this->assertFalse($off->hasReverse());
    }

    public function testWithItalicTogglesBit(): void
    {
        $original = Style::new();

        $on = $original->withItalic(true);
        $this->assertTrue($on->hasItalic());
        $this->assertFalse($original->hasItalic());

        $off = $on->withItalic(false);
        $this->assertFalse($off->hasItalic());
        $this->assertTrue($on->hasItalic());
    }

    public function testWithUnderlineTogglesBit(): void
    {
        $original = Style::new();

        $on = $original->withUnderline(true);
        $this->assertTrue($on->hasUnderline());
        $this->assertFalse($original->hasUnderline());

        $off = $on->withUnderline(false);
        $this->assertFalse($off->hasUnderline());
    }

    public function testWithStrikeTogglesBit(): void
    {
        $original = Style::new();

        $on = $original->withStrike(true);
        $this->assertTrue($on->hasStrike());
        $this->assertFalse($original->hasStrike());

        $off = $on->withStrike(false);
        $this->assertFalse($off->hasStrike());
    }

    public function testWithFaintTogglesBit(): void
    {
        $original = Style::new();

        $on = $original->withFaint(true);
        $this->assertTrue($on->hasFaint());
        $this->assertFalse($original->hasFaint());

        $off = $on->withFaint(false);
        $this->assertFalse($off->hasFaint());
    }

    public function testWithBlinkTogglesBit(): void
    {
        $original = Style::new();

        $on = $original->withBlink(true);
        $this->assertTrue($on->hasBlink());
        $this->assertFalse($original->hasBlink());

        $off = $on->withBlink(false);
        $this->assertFalse($off->hasBlink());
    }

    public function testWithOverlineTogglesBit(): void
    {
        $original = Style::new();

        $on = $original->withOverline(true);
        $this->assertTrue($on->hasOverline());
        $this->assertFalse($original->hasOverline());

        $off = $on->withOverline(false);
        $this->assertFalse($off->hasOverline());
    }

    public function testWithInvisibleTogglesBit(): void
    {
        $original = Style::new();

        $on = $original->withInvisible(true);
        $this->assertTrue($on->hasInvisible());
        $this->assertFalse($original->hasInvisible());

        $off = $on->withInvisible(false);
        $this->assertFalse($off->hasInvisible());
    }

    public function testWithFgPreservesOtherFields(): void
    {
        $original = Style::new(0xff0000, 0x0000ff, Style::ATTR_BOLD | Style::ATTR_ITALIC);

        $changed = $original->withFg(0x00ff00);

        $this->assertSame(0x00ff00, $changed->fg());
        $this->assertSame(0x0000ff, $changed->bg());
        $this->assertSame(Style::ATTR_BOLD | Style::ATTR_ITALIC, $changed->attrs());
        $this->assertNotSame($original, $changed);
    }

    public function testWithBgPreservesOtherFields(): void
    {
        $original = Style::new(0xff0000, 0x0000ff, Style::ATTR_BOLD | Style::ATTR_ITALIC);

        $changed = $original->withBg(0x00ff00);

        $this->assertSame(0xff0000, $changed->fg());
        $this->assertSame(0x00ff00, $changed->bg());
        $this->assertSame(Style::ATTR_BOLD | Style::ATTR_ITALIC, $changed->attrs());
    }

    public function testMultipleWithCallsChained(): void
    {
        $s = Style::new()
            ->withFg(0xff0000)
            ->withBg(0x0000ff)
            ->withBold(true)
            ->withItalic(true)
            ->withUnderline(true);

        $this->assertSame(0xff0000, $s->fg());
        $this->assertSame(0x0000ff, $s->bg());
        $this->assertTrue($s->hasBold());
        $this->assertTrue($s->hasItalic());
        $this->assertTrue($s->hasUnderline());
    }

    // ─── equals ─────────────────────────────────────────────────────────

    public function testEqualsTrue(): void
    {
        $a = Style::new(0xff0000, 0x0000ff, Style::ATTR_BOLD);
        $b = Style::new(0xff0000, 0x0000ff, Style::ATTR_BOLD);

        $this->assertTrue($a->equals($b));
    }

    public function testEqualsFalseDueToFg(): void
    {
        $a = Style::new(0xff0000);
        $b = Style::new(0x00ff00);

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsFalseDueToBg(): void
    {
        $a = Style::new(null, 0xff0000);
        $b = Style::new(null, 0x00ff00);

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsFalseDueToAttrs(): void
    {
        $a = Style::new(null, null, Style::ATTR_BOLD);
        $b = Style::new(null, null, Style::ATTR_ITALIC);

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsBothNullFgBg(): void
    {
        $a = Style::new();
        $b = Style::new();

        $this->assertTrue($a->equals($b));
    }

    public function testEqualsDifferentInstancesSameValues(): void
    {
        $a = Style::bold();
        $b = Style::bold();

        $this->assertTrue($a->equals($b));
        $this->assertNotSame($a, $b);
    }

    // ─── serialization ────────────────────────────────────────────────

    public function testSerialize(): void
    {
        $style = Style::new(0xff0000, 0x0000ff, Style::ATTR_BOLD | Style::ATTR_ITALIC);

        $data = $style->__serialize();

        $this->assertSame(0xff0000, $data['fg']);
        $this->assertSame(0x0000ff, $data['bg']);
        $this->assertSame(Style::ATTR_BOLD | Style::ATTR_ITALIC, $data['attrs']);
    }

    public function testSerializeNullColors(): void
    {
        $style = Style::new(null, null, Style::ATTR_UNDERLINE);

        $data = $style->__serialize();

        $this->assertNull($data['fg']);
        $this->assertNull($data['bg']);
        $this->assertSame(Style::ATTR_UNDERLINE, $data['attrs']);
    }

    public function testUnserialize(): void
    {
        $data = ['fg' => 0x123456, 'bg' => 0xabcdef, 'attrs' => Style::ATTR_STRIKE | Style::ATTR_OVERLINE];

        $style = Style::new(0x123456, 0xabcdef, Style::ATTR_STRIKE | Style::ATTR_OVERLINE);
        // Verify by accessing
        $this->assertSame(0x123456, $style->fg());
        $this->assertSame(0xabcdef, $style->bg());
        $this->assertSame(Style::ATTR_STRIKE | Style::ATTR_OVERLINE, $style->attrs());

        // Round-trip
        $serialized = $style->__serialize();
        $reconstituted = Style::new($serialized['fg'], $serialized['bg'], $serialized['attrs']);

        $this->assertSame($style->fg(), $reconstituted->fg());
        $this->assertSame($style->bg(), $reconstituted->bg());
        $this->assertSame($style->attrs(), $reconstituted->attrs());
        $this->assertTrue($style->equals($reconstituted));
    }

    public function testJsonSerialize(): void
    {
        $style = Style::new(0xff0000, null, Style::ATTR_BOLD);

        $json = json_encode($style);
        $decoded = json_decode($json, true);

        $this->assertSame(0xff0000, $decoded['fg']);
        $this->assertNull($decoded['bg']);
        $this->assertSame(Style::ATTR_BOLD, $decoded['attrs']);
    }

    public function testSerializeDefaultStyle(): void
    {
        $style = Style::new();

        $data = $style->__serialize();

        $this->assertNull($data['fg']);
        $this->assertNull($data['bg']);
        $this->assertSame(0, $data['attrs']);
    }
}
