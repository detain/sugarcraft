<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CellBuffer\Hyperlink;

/**
 * @covers \SugarCraft\CellBuffer\Hyperlink
 */
final class HyperlinkTest extends TestCase
{
    public function testAcceptsNormalUrl(): void
    {
        $link = new Hyperlink('https://example.com/a?b=c#d');
        $this->assertSame('https://example.com/a?b=c#d', $link->url());
        $this->assertSame('', $link->id());
    }

    public function testAcceptsNormalUrlWithId(): void
    {
        $link = new Hyperlink('https://example.com/path', 'myid');
        $this->assertSame('https://example.com/path', $link->url());
        $this->assertSame('myid', $link->id());
    }

    public function testRejectsEscInUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('control characters');
        new Hyperlink("https://example.com/\x1b\\evil");
    }

    public function testRejectsBelInUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('control characters');
        new Hyperlink("https://example.com/\x07evil");
    }

    public function testRejectsControlInId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('control characters');
        new Hyperlink('https://example.com', "id\x1bwithesc");
    }

    public function testRejectsAllC0ControlsInUrl(): void
    {
        // Test a representative sample: NUL, LF, TAB, ESC
        $this->expectException(\InvalidArgumentException::class);
        new Hyperlink("https://example.com/\x00null");
    }

    public function testFactoryNewAcceptsNormalUrl(): void
    {
        $link = Hyperlink::new('https://safe.site/page');
        $this->assertSame('https://safe.site/page', $link->url());
    }

    public function testFactoryNewRejectsControlChars(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Hyperlink::new("https://evil\x1b.site");
    }

    public function testEqualsTrue(): void
    {
        $a = new Hyperlink('https://example.com/path', 'myid');
        $b = new Hyperlink('https://example.com/path', 'myid');

        $this->assertTrue($a->equals($b));
    }

    public function testEqualsFalseDueToUrl(): void
    {
        $a = new Hyperlink('https://a.com');
        $b = new Hyperlink('https://b.com');

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsFalseDueToId(): void
    {
        $a = new Hyperlink('https://example.com', 'id1');
        $b = new Hyperlink('https://example.com', 'id2');

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsBothEmptyId(): void
    {
        $a = new Hyperlink('https://example.com', '');
        $b = new Hyperlink('https://example.com', '');

        $this->assertTrue($a->equals($b));
    }

    public function testEqualsDifferentInstancesSameValues(): void
    {
        $a = Hyperlink::new('https://safe.site', 'x');
        $b = Hyperlink::new('https://safe.site', 'x');

        $this->assertTrue($a->equals($b));
        $this->assertNotSame($a, $b);
    }

    public function testSerialize(): void
    {
        $link = new Hyperlink('https://example.com/path', 'myid');

        $data = $link->__serialize();

        $this->assertSame('https://example.com/path', $data['url']);
        $this->assertSame('myid', $data['id']);
    }

    public function testSerializeEmptyId(): void
    {
        $link = new Hyperlink('https://example.com');

        $data = $link->__serialize();

        $this->assertSame('https://example.com', $data['url']);
        $this->assertSame('', $data['id']);
    }

    public function testUnserializeRoundTrip(): void
    {
        $original = new Hyperlink('https://test.example.com/path?a=1#b', 'session123');
        $data = $original->__serialize();

        $reconstituted = new Hyperlink($data['url'], $data['id']);

        $this->assertSame($original->url(), $reconstituted->url());
        $this->assertSame($original->id(), $reconstituted->id());
        $this->assertTrue($original->equals($reconstituted));
    }

    public function testJsonSerialize(): void
    {
        $link = new Hyperlink('https://example.com', 'anchor');

        $json = json_encode($link);
        $decoded = json_decode($json, true);

        $this->assertSame('https://example.com', $decoded['url']);
        $this->assertSame('anchor', $decoded['id']);
    }

    public function testJsonSerializeEmptyId(): void
    {
        $link = Hyperlink::new('https://example.com');

        $json = json_encode($link);
        $decoded = json_decode($json, true);

        $this->assertSame('https://example.com', $decoded['url']);
        $this->assertSame('', $decoded['id']);
    }

    public function testUrlReturnsVerbatim(): void
    {
        $link = new Hyperlink('https://example.com/a b/c');
        $this->assertSame('https://example.com/a b/c', $link->url());
    }

    public function testTheIdentifierRejectsSemicolons(): void
    {
        // The id rides the OSC 8 parameter list as `id=<value>`; a bare ';'
        // would still split the parameter and shift the URL field.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Hyperlink ID must not contain semicolons');

        Hyperlink::new('https://example.com', 'a;b');
    }

    public function testUnserializationReforgesTheControlByteGuards(): void
    {
        $forged = "a\x1b[31m";

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Hyperlink URL must not contain control characters');

        $this->blankHyperlink()->__unserialize(['url' => $forged, 'id' => '']);
    }

    public function testUnserializationReforgesTheSemicolonGuard(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Hyperlink ID must not contain semicolons');

        $this->blankHyperlink()->__unserialize(['url' => 'https://ok.dev', 'id' => 'a;b']);
    }

    public function testUnserializationOfAForgedMissingFieldFailsClosed(): void
    {
        // A payload that never carried an id restores it as '' (back-compat
        // shape), but never as attacker-controlled bytes.
        $link = $this->blankHyperlink();
        $link->__unserialize(['url' => 'https://ok.dev']);

        $this->assertSame('https://ok.dev', $link->url());
        $this->assertSame('', $link->id());
    }

    public function testTheC1RangeIsRefusedRawAndUtf8Encoded(): void
    {
        // 8-bit C1 controls are escape introducers: \x9B is CSI and \x9C is ST
        // on their own. Their UTF-8 spellings (C2 80-BF) decode back to them,
        // so both shapes are refused in both fields.
        foreach ([
            "https://example.com/\x9Cst",
            "https://example.com/\x9bnext",
            "https://example.com/\xC2\x9Cevil",
            "https://example.com/\xC2\x9Bevil",
        ] as $poisoned) {
            try {
                new Hyperlink($poisoned, 'ok');
                $this->fail('raw/encoded C1 in url was accepted: ' . addcslashes($poisoned, "\x80-\x9f"));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('control characters', $e->getMessage());
            }
        }

        foreach (["id\x9Cx", "id\x9Bx", "id\xC2\x9Cx", "id\xC2\x9Bx"] as $poisonedId) {
            try {
                new Hyperlink('https://example.com', $poisonedId);
                $this->fail('raw/encoded C1 in id was accepted: ' . addcslashes($poisonedId, "\x80-\x9f"));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('control characters', $e->getMessage());
            }
        }

        // A legal multibyte character before the poison does not launder it:
        // the lone C1 after a complete sequence stays refused (fail-closed).
        foreach ([
            "https://example.com/→\x9Cst",
            "https://example.com/😀\xC2\x9Bevil",
        ] as $poisoned) {
            try {
                new Hyperlink($poisoned, 'ok');
                $this->fail('valid multibyte + C1 mix was accepted: ' . addcslashes($poisoned, "\x80-\x9f"));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('control characters', $e->getMessage());
            }
        }
    }

    public function testUnserializationReforgesTheC1Guards(): void
    {
        // The unserialization gate must re-apply the same C1 refusal — a
        // cached payload cannot smuggle an escape introducer past the type.
        $shapes = [
            ['url' => "https://example.com/\x9Cst", 'id' => 'ok'],
            ['url' => 'https://example.com', 'id' => "id\xC2\x9Bx"],
        ];

        foreach ($shapes as $data) {
            try {
                $this->blankHyperlink()->__unserialize($data);
                $this->fail('unserialize accepted a C1-bearing payload');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('control characters', $e->getMessage());
            }
        }
    }

    public function testWellFormedUtf8OutsideTheC1RangeStillPasses(): void
    {
        // Real IRI payloads: '→' is E2 86 92, the grinning face is F0 9F 98 80
        // and Cyrillic 'ет' is D0 B5 D1 82 — each carries bytes inside the
        // 0x80-0x9F window as UTF-8 CONTINUATION bytes, which a byte-blind
        // [\x80-\x9f] class wrongly refuses (review round 1, MAJOR-1). café carries
        // C3 A9 and NBSP carries C2 A0 — neither is a C1 control either, so
        // the refusal must not turn into a bare-UTF8 ban.
        $payloads = [
            'arrow' => 'https://example.com/→next',
            'emoji' => 'https://example.com/😀',
            'cyrillic' => 'https://example.com/ет',
            'accent' => 'https://example.com/café',
        ];
        foreach ($payloads as $label => $url) {
            $link = new Hyperlink($url, $label);
            $this->assertSame($url, $link->url(), $label . ' url must survive untouched');
            $this->assertSame($label, $link->id(), $label . ' id must survive untouched');
        }

        $nbsp = new Hyperlink("https://example.com/a\xC2\xA0b", 'ok');
        $this->assertSame("https://example.com/a\xC2\xA0b", $nbsp->url());

        // The reforged unserialization gate must accept the same multibyte
        // payloads it accepts in the constructor — it guards controls, not
        // IRI bytes.
        $link = $this->blankHyperlink();
        $link->__unserialize(['url' => 'https://example.com/→next', 'id' => 'arrow']);
        $this->assertSame('https://example.com/→next', $link->url());
        $this->assertSame('arrow', $link->id());
    }

    private function blankHyperlink(): Hyperlink
    {
        // The hook runs on a freshly allocated instance (as unserialize()
        // does); readonly promoted properties may only be written once.
        return (new \ReflectionClass(Hyperlink::class))->newInstanceWithoutConstructor();
    }

    public function testTheCheapPrefilterKeepsEveryVerdictItSkips(): void
    {
        // Fast path (no byte in 0x80-0x9F at all): pure-ASCII values accept
        // without Ansi::strip ever building a copy.
        foreach (['https://example.com/a?b=1#f', 'urn:x-oath:demo'] as $clean) {
            new Hyperlink($clean);
        }
        // Slow path but ACCEPTED: valid multibyte whose continuation bytes
        // land numerically inside the C1 range — U+0440 Cyrillic ER (D1 80), U+02CB MODIFIER LETTER CARON (CB 8B), U+2192 RIGHTWARDS
        // ARROW (E2 86 92). A prefilter that rejected on any raw 0x80-0x9F
        // byte would refuse these; the byte-presence gate defers to the
        // double-leg decision instead.
        new Hyperlink('https://кириллица.test/па');
        new Hyperlink('https://example.com/ʋ→');
        new Hyperlink('https://example.com/😀');
        // Slow path and REFUSED: a lone raw C1 byte survives neither leg.
        foreach (["https://example.com/\x8b", "\x80", "\x9f tail"] as $forged) {
            $threw = false;
            try {
                new Hyperlink($forged);
            } catch (\InvalidArgumentException) {
                $threw = true;
            }
            $this->assertTrue($threw, 'lone C1 byte must be refused: ' . bin2hex($forged));
        }
    }
}
