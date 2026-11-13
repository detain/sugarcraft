<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CellBuffer\Style;

/**
 * @covers \SugarCraft\CellBuffer\Style
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

    // ─── Palette-slot identity (review M12) ───

    public function testPaletteSlotsOutsideZeroTo255AreRefused(): void
    {
        $this->expectExceptionMessage('Style palette slot foreground must be 0-255 or null, got 256');
        new Style(null, null, 0, 256);
    }

    public function testBackgroundSlotBoundsAreRefused(): void
    {
        $this->expectExceptionMessage('Style palette slot background must be 0-255 or null, got -1');
        new Style(null, null, 0, null, -1);
    }

    public function testUnderlineSlotBoundsAreRefused(): void
    {
        $this->expectExceptionMessage('Style palette slot underline-slot must be 0-255 or null, got 999');
        new Style(null, null, 0, null, null, null, 999);
    }

    public function testSlotEdgesZeroAndTwoHundredFiftyFiveAreAccepted(): void
    {
        $style = new Style(null, null, 0, 0, 255);

        $this->assertSame(0, $style->fgSlot());
        $this->assertSame(255, $style->bgSlot());
    }

    public function testSettingRgbForegroundDiscardsSlotIdentity(): void
    {
        $style = Style::new()->withFgSlot(3)->withFg(0xff0000);

        $this->assertSame(0xff0000, $style->fg());
        $this->assertNull($style->fgSlot());
    }

    public function testSettingSlotClearsTheRgbTwin(): void
    {
        $style = Style::new(0x0a141e)->withFgSlot(9);

        $this->assertSame(9, $style->fgSlot());
        $this->assertNull($style->fg());
    }

    public function testClearingTheSlotFormResetsTheChannelToDefault(): void
    {
        $style = Style::new(0x0a141e)->withFgSlot(null);

        $this->assertNull($style->fgSlot());
        $this->assertNull($style->fg());
    }

    public function testEqualitySeesASlotOnlyDifference(): void
    {
        $rgb = Style::new(0xff0000);
        $slot = new Style(0xff0000, null, 0, 1);

        $this->assertFalse($rgb->equals($slot));
        $this->assertFalse(Style::valuesEqual($rgb, $slot));
    }

    public function testSerializationRoundTripCarriesSlotsAndUnderline(): void
    {
        // Three pens cover all seven fields under the belt law: slot+belt
        // pairs (the canonical co-set shape the parser records), a plain
        // underline RGB, and a slot-only underline. The old single-pen
        // shape paired underline RGB 0x010203 with slot 58 — a contradiction
        // the constructor now refuses (sugar-cellbuffer-006).
        $pens = [
            new Style(0x00ff00, 0xff0000, Style::ATTR_UNDERLINE, 2, 1),
            new Style(null, null, Style::ATTR_UNDERLINE, null, null, 0x010203),
            new Style(null, null, Style::ATTR_UNDERLINE, null, null, null, 58),
        ];

        foreach ($pens as $style) {
            $restored = unserialize(serialize($style));

            $this->assertSame($style->__serialize(), $restored->__serialize());
            $this->assertTrue($style->equals($restored));
        }
    }

    public function testOutOfRangeRgbChannelsSaturateInsteadOfWrapping(): void
    {
        // Clamp, never mask (CALIBER 2026-10-03 law) — the parser's law now
        // lives in the type: -5 goes to 0x000000, 0x1FFFFFF to 0xFFFFFF,
        // never to the wrapped colour a mask would paint.
        $style = new Style(-5, 0x1FFFFFF, 0, null, null, -1);

        $this->assertSame(0x000000, $style->fg());
        $this->assertSame(0xFFFFFF, $style->bg());
        $this->assertSame(0x000000, $style->ul());
    }

    public function testARgbContradictingItsCoSetSlotIsRefused(): void
    {
        // Emission prefers the slot, so a mismatched pair silently loses the
        // hex half — exactly the forgery the with*() law was supposed to
        // prevent but only the constructor can guarantee.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Style foreground pairs palette slot 4 with RGB 0xFF0000");
        new Style(0xFF0000, null, 0, 4);
    }

    public function testABackgroundContradictingItsCoSetSlotIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Style background pairs palette slot');
        new Style(null, 0x123456, 0, null, 3);
    }

    public function testTheCanonicalHexBeltOfASlotIsAccepted(): void
    {
        // 0xFF0000 is the standard palette's slot 1 — the exact pairing
        // fromSgr() records for `31` and `38;5;1`, so the parser's own
        // output must keep constructing.
        $style = new Style(0xFF0000, null, 0, 1);

        $this->assertSame(0xFF0000, $style->fg());
        $this->assertSame(1, $style->fgSlot());
        $this->assertEquals(Style::fromSgr('31'), $style);
    }

    public function testUnserializationRefusesAConflictingBelt(): void
    {
        $style = (new \ReflectionClass(Style::class))->newInstanceWithoutConstructor();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Style foreground pairs palette slot 4 with RGB 0xFF0000");
        $style->__unserialize(['fg' => 0xFF0000, 'bg' => null, 'attrs' => 0, 'fgSlot' => 4]);
    }

    public function testUnserializationSaturatesForgedRgbChannels(): void
    {
        $style = (new \ReflectionClass(Style::class))->newInstanceWithoutConstructor();
        $style->__unserialize(['fg' => -5, 'bg' => 0x1FFFFFF, 'attrs' => 0]);

        $this->assertSame(0x000000, $style->fg());
        $this->assertSame(0xFFFFFF, $style->bg());
    }

    public function testAnOldThreeKeyPayloadRestoresWithNullSlots(): void
    {
        $style = (new \ReflectionClass(Style::class))->newInstanceWithoutConstructor();
        $style->__unserialize(['fg' => 1, 'bg' => 2, 'attrs' => 3]);

        $this->assertSame(1, $style->fg());
        $this->assertNull($style->fgSlot());
        $this->assertNull($style->bgSlot());
        $this->assertNull($style->ul());
        $this->assertNull($style->ulSlot());
    }

    public function testUnserializationRefusesAForgedSlotBound(): void
    {
        $style = (new \ReflectionClass(Style::class))->newInstanceWithoutConstructor();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Style palette slot background must be 0-255 or null, got 300');
        $style->__unserialize(['fg' => null, 'bg' => null, 'attrs' => 0, 'bgSlot' => 300]);
    }

    // ─── Public SGR parser (review M15) ───

    public function testFromSgrRecordsPaletteSlotIdentityWithHexBelt(): void
    {
        $this->assertSame(1, Style::fromSgr('31')?->fgSlot());
        $this->assertSame(0xff0000, Style::fromSgr('31')?->fg());
        $this->assertSame(9, Style::fromSgr('91')?->fgSlot());
        $this->assertSame(1, Style::fromSgr('41')?->bgSlot());
        $this->assertSame(9, Style::fromSgr('101')?->bgSlot());
        $this->assertSame(170, Style::fromSgr('38;5;170')?->fgSlot());
        $this->assertSame(0xd75fd7, Style::fromSgr('38;5;170')?->fg());
    }

    public function testFromSgrTruecolorFormsClearSlotIdentity(): void
    {
        $style = Style::fromSgr('38;2;10;20;30');

        $this->assertSame(0x0a141e, $style?->fg());
        $this->assertNull($style?->fgSlot());
    }

    public function testFromSgrParsesUnderlineColourForms(): void
    {
        $slot = Style::fromSgr('58;5;3');
        $this->assertSame(3, $slot?->ulSlot());
        $this->assertSame(0xffff00, $slot?->ul());

        $rgb = Style::fromSgr('58;2;1;2;3');
        $this->assertSame(0x010203, $rgb?->ul());
        $this->assertNull($rgb?->ulSlot());

        $cleared = Style::fromSgr('58;5;3;59');
        $this->assertNull($cleared?->ul());
        $this->assertNull($cleared?->ulSlot());
    }

    public function testFromSgrClearsChannelDefaultsForBothSpellings(): void
    {
        $style = Style::fromSgr('31;39');

        $this->assertNull($style?->fg());
        $this->assertNull($style?->fgSlot());
    }

    public function testFromSgrAppliesOverlineAndOffCodes(): void
    {
        $this->assertTrue(Style::fromSgr('53')?->hasOverline());
        $this->assertNull(Style::fromSgr('1;22'));
        $this->assertTrue(Style::fromSgr('2;22') === null);
        $this->assertFalse(Style::fromSgr('4;24')?->hasUnderline() ?? false);
        $this->assertTrue(Style::fromSgr('4;24') === null);
        $this->assertTrue(Style::fromSgr('7;27') === null);
        $this->assertTrue(Style::fromSgr('9;29') === null);
        $this->assertTrue(Style::fromSgr('3;23') === null);
        $this->assertTrue(Style::fromSgr('5;25') === null);
        $this->assertTrue(Style::fromSgr('8;28') === null);
        $this->assertFalse(Style::fromSgr('53;55')?->hasOverline() ?? false);
        $this->assertTrue(Style::fromSgr('53;55') === null);
    }

    public function testFromSgrIgnoresCodesOutsideItsVocabulary(): void
    {
        // Documented deliberate leniency (README roster + fromSgr docblock):
        // 6/26 proportional spacing, 21 doubly-underlined (no bit in the
        // nine-bit model; bold-off rides 22), 51/52/54 frames, the 10-20
        // font block — no throw, and no movement of an existing pen.
        $carry = Style::fromSgr('1;31');
        foreach ([6, 10, 15, 20, 21, 26, 51, 52, 54] as $code) {
            $next = Style::fromSgr((string) $code, $carry);
            $this->assertTrue(Style::valuesEqual($carry, $next), "SGR {$code} must not move the pen");
        }
        $this->assertNull(Style::fromSgr('6;21;26;51'));
    }

    public function testFromSgrCarriesThePriorPenThrough(): void
    {
        $carry = Style::fromSgr('31;1');
        $next = Style::fromSgr('22', $carry);

        $this->assertSame(1, $next?->fgSlot());
        $this->assertSame(0, $next?->attrs());
    }

    public function testFromSgrStopsAtATruncatedExtendedColour(): void
    {
        $style = Style::fromSgr('5;38;5');

        $this->assertSame(Style::ATTR_BLINK, $style?->attrs());
        $this->assertNull($style?->fg());
    }

    public function testFromSgrRefusesANonParameterRun(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SGR parameters must be semicolon-separated integers');
        Style::fromSgr('blink');
    }

    // ─── Colon sub-parameters (sugar-cellbuffer-007) ───────────────────

    public function testTheColonTruecolorFormParsesItsTrueTailOperands(): void
    {
        // T.416 colour-space form: the `1` is a colour-space id, NOT red.
        // The old colon→semicolon flattening read (1,200,100) as the colour.
        $style = Style::fromSgr('38:2:1:200:100:50');

        $this->assertSame(0xC86432, $style?->fg());
        $this->assertNull($style?->fgSlot());
    }

    public function testTheColonTruecolorFormAcceptsAnEmptyColourSpaceField(): void
    {
        // The empty-field variant used to THROW on the numeric check.
        $style = Style::fromSgr('38:2::1:2:3');

        $this->assertSame(0x010203, $style?->fg());
    }

    public function testTheColonShortTruecolorFormStillParses(): void
    {
        $this->assertEquals(
            Style::fromSgr('38;2;1;2;3'),
            Style::fromSgr('38:2:1:2:3')
        );
    }

    public function testTheColonColor256FormStillParses(): void
    {
        $this->assertEquals(
            Style::fromSgr('38;5;57'),
            Style::fromSgr('38:5:57')
        );
    }

    public function testTheColonBackgroundFormParsesItsTrueTailOperands(): void
    {
        $style = Style::fromSgr('48:2:7:10:20:30');

        $this->assertSame(0x0A141E, $style?->bg());
    }

    public function testAColonExtensionOnANonColourCodeIsDroppedNotReinterpreted(): void
    {
        // `4:0` (underline style) must not flatten into `4;0` — that would
        // underline then RESET the whole pen. Bold survives, nothing else.
        $style = Style::fromSgr('1;4:0');

        $this->assertSame(Style::ATTR_BOLD, $style?->attrs());

        $this->assertNull(Style::fromSgr('4:0'));
    }

    public function testAColonColourModeOutsideTheVocabularyFailStopsTheParse(): void
    {
        // CSA `38:6:…` and over-long colon operands forward the bare
        // introducer: the merge loop stops there and never reaches the 31.
        $this->assertNull(Style::fromSgr('38:6:1:2:3;31'));
        $this->assertNull(Style::fromSgr('38:2:1:2:3:4:5;31'));

        // Truncated colon form behaves like `38;5`: the operands after the
        // stop are colour data, not codes.
        $style = Style::fromSgr('1;38:5');
        $this->assertSame(Style::ATTR_BOLD, $style?->attrs());
        $this->assertNull($style?->fg());
    }

    // ─── Direct-construction builders (sugar-cellbuffer-016) ───────────

    /**
     * The setters construct the new pen with named arguments instead of the
     * old array-merge + re-spread mutate() (~2× measured win on the
     * per-composition path). This pin holds the promise of that rewrite:
     * every setter carries the six untouched fields exactly and applies the
     * mutual-exclusion law, with equals() as the oracle.
     */
    public function testEverySetterPreservesTheUntouchedChannels(): void
    {
        $base = new Style(0x112233, 0x445566, Style::ATTR_BOLD | Style::ATTR_ITALIC, null, null, 0x778899, null);

        self::assertTrue($base->withFg(0xAABBCC)->equals(new Style(0xAABBCC, 0x445566, Style::ATTR_BOLD | Style::ATTR_ITALIC, null, null, 0x778899, null)));
        self::assertTrue($base->withBg(0x102030)->equals(new Style(0x112233, 0x102030, Style::ATTR_BOLD | Style::ATTR_ITALIC, null, null, 0x778899, null)));
        self::assertTrue($base->withUl(0x010203)->equals(new Style(0x112233, 0x445566, Style::ATTR_BOLD | Style::ATTR_ITALIC, null, null, 0x010203, null)));
        self::assertTrue($base->withAttrs(Style::ATTR_STRIKE)->equals(new Style(0x112233, 0x445566, Style::ATTR_STRIKE, null, null, 0x778899, null)));

        // Slot setters carry the exclusivity law: the RGB twin clears and the
        // channel's slot lands, everything else rides through.
        $slotted = $base->withFgSlot(5)->withBgSlot(6)->withUlSlot(7);
        self::assertTrue($slotted->equals(new Style(null, null, Style::ATTR_BOLD | Style::ATTR_ITALIC, 5, 6, null, 7)));
        self::assertTrue($slotted->withFg(0x112233)->equals(new Style(0x112233, null, Style::ATTR_BOLD | Style::ATTR_ITALIC, null, 6, null, 7)));
        self::assertTrue($slotted->withBg(0x445566)->equals(new Style(null, 0x445566, Style::ATTR_BOLD | Style::ATTR_ITALIC, 5, null, null, 7)));
        self::assertTrue($slotted->withUl(0x778899)->equals(new Style(null, null, Style::ATTR_BOLD | Style::ATTR_ITALIC, 5, 6, 0x778899, null)));

        // The nine toggles flip only their own bit and commute with equals().
        $bits = [
            'withBold' => Style::ATTR_BOLD,
            'withItalic' => Style::ATTR_ITALIC,
            'withUnderline' => Style::ATTR_UNDERLINE,
            'withStrike' => Style::ATTR_STRIKE,
            'withFaint' => Style::ATTR_FAINT,
            'withBlink' => Style::ATTR_BLINK,
            'withReverse' => Style::ATTR_REVERSE,
            'withOverline' => Style::ATTR_OVERLINE,
            'withInvisible' => Style::ATTR_INVISIBLE,
        ];
        foreach ($bits as $setter => $bit) {
            $off = $base->withAttrs($base->attrs() & ~$bit);
            $on = $off->{$setter}();
            self::assertTrue($on->equals($off->withAttrs($off->attrs() | $bit)), $setter);
            self::assertTrue($on->{$setter}(false)->equals($off), $setter . ' off');
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function missingKeyProvider(): array
    {
        return ['fg' => ['fg'], 'bg' => ['bg'], 'attrs' => ['attrs']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('missingKeyProvider')]
    public function testUnserializeRefusesEachMissingKey(string $missing): void
    {
        $payload = ['fg' => null, 'bg' => null, 'attrs' => 0];
        unset($payload[$missing]);
        $fresh = (new \ReflectionClass(Style::class))->newInstanceWithoutConstructor();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Style payload missing key '{$missing}'");
        $fresh->__unserialize($payload);
    }

    public function testUnserializeStillRestoresTheThreeKeyLegacyPayload(): void
    {
        // fg/bg/attrs are REQUIRED; the five late keys stay optional —
        // the pre-palette-slot shape must keep restoring (README Install
        // caveat frozen old providers).
        $fresh = (new \ReflectionClass(Style::class))->newInstanceWithoutConstructor();
        $fresh->__unserialize(['fg' => 0x0A0B0C, 'bg' => null, 'attrs' => 1]);

        $this->assertTrue($fresh->equals(new Style(fg: 0x0A0B0C, bg: null, attrs: 1)));
    }

    /**
     * CB-31: Style::toAnsi() is the canonical emitter's one-shot surface —
     * byte-identical to SgrEmitter::emit for the same style (slots, belts,
     * underlines and all), and exactly the transition substring the diff
     * encoder writes when its pen lands on this style.
     */
    public function testToAnsiIsTheEmitterIdentitySurface(): void
    {
        $cases = [
            new Style(),
            new Style(fg: 0xFF0000, bg: 0x00FF00, attrs: Style::ATTR_BOLD | Style::ATTR_ITALIC),
            new Style(fgSlot: 9, attrs: Style::ATTR_UNDERLINE, ul: 0x123456),
            Style::fromSgr('38;5;9;4;7') ?? new Style(),
            Style::fromSgr('58;2;1;2;3;4') ?? new Style(),
        ];

        foreach ($cases as $i => $style) {
            self::assertSame(
                \SugarCraft\CellBuffer\Support\SgrEmitter::emit($style),
                $style->toAnsi(),
                "case {$i}: toAnsi must be the emitter verbatim",
            );
        }

        // End-to-end: the same bytes ride the diff pen-transition path.
        $style = $cases[2];
        $prev = \SugarCraft\CellBuffer\Buffer::new(1, 1);
        $curr = $prev->withCellAt(0, 0, new \SugarCraft\CellBuffer\Cell('x', $style, null, 1));
        self::assertStringContainsString($style->toAnsi(), $curr->diffToAnsi($prev));
    }
}
