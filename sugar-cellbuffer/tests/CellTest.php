<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\CellBuffer\Hyperlink;
use SugarCraft\CellBuffer\Style;

final class CellTest extends TestCase
{
    public function testNewDefault(): void
    {
        $cell = Cell::new();

        $this->assertSame(' ', $cell->rune());
        $this->assertNull($cell->style());
        $this->assertNull($cell->link());
        $this->assertSame(1, $cell->width());
    }

    public function testNewWithRune(): void
    {
        $cell = Cell::new('x');

        $this->assertSame('x', $cell->rune());
        $this->assertSame(1, $cell->width());
    }

    public function testNewWithStyle(): void
    {
        $style = Style::bold();
        $cell = Cell::new('x', $style);

        $this->assertSame($style, $cell->style());
    }

    public function testNewWithHyperlink(): void
    {
        $link = Hyperlink::new('https://example.com', 'id1');
        $cell = Cell::new('x', null, $link);

        $this->assertSame($link, $cell->link());
        $this->assertSame('https://example.com', $cell->link()->url());
        $this->assertSame('id1', $cell->link()->id());
    }

    public function testNewWithExplicitWidth(): void
    {
        $cell = Cell::new('x', null, null, 2);

        $this->assertSame(2, $cell->width());
    }

    public function testContinuationCell(): void
    {
        $cell = Cell::continuation();

        $this->assertSame('', $cell->rune());
        $this->assertNull($cell->style());
        $this->assertNull($cell->link());
        $this->assertSame(0, $cell->width());
    }

    public function testWideCharCell(): void
    {
        // CJK character '中' has display width 2
        $cell = Cell::new('中', null, null, 2);

        $this->assertSame('中', $cell->rune());
        $this->assertSame(2, $cell->width());
    }

    public function testStyleEquality(): void
    {
        $style1 = Style::new(0xff0000, 0x000000, Style::ATTR_BOLD);
        $style2 = Style::new(0xff0000, 0x000000, Style::ATTR_BOLD);
        $cell1 = Cell::new('x', $style1);
        $cell2 = Cell::new('x', $style2);

        $this->assertEquals($style1, $style2);
    }

    public function testEqualsConsidersRuneStyleLinkWidth(): void
    {
        $style = Style::bold();
        $link = Hyperlink::new('https://example.com');

        $cell1 = Cell::new('x', $style, $link, 1);
        $cell2 = Cell::new('x', $style, $link, 1);
        $this->assertTrue($cell1->equals($cell2));

        // Rune differs
        $cell3 = Cell::new('y', $style, $link, 1);
        $this->assertFalse($cell1->equals($cell3));

        // Style differs
        $cell4 = Cell::new('x', Style::new(null, null, Style::ATTR_ITALIC), $link, 1);
        $this->assertFalse($cell1->equals($cell4));

        // Link differs
        $cell5 = Cell::new('x', $style, Hyperlink::new('https://other.com'), 1);
        $this->assertFalse($cell1->equals($cell5));

        // Width differs — the key divergence from DiffOptimiser::cellsEqual
        $cell6 = Cell::new('x', $style, $link, 2);
        $this->assertFalse($cell1->equals($cell6));
    }

    /**
     * Pins the documented control-byte contract (see Cell class docblock):
     * Cell deliberately does NOT validate its rune — control bytes are
     * stored and returned VERBATIM, so callers rendering untrusted text
     * must sanitize before building cells. A hard reject in Cell::new()/
     * the constructor was evaluated but deferred because foundation
     * consumers (sugar-treelist, candy-code, sugar-table) splat pre-rendered
     * strings char-by-char into runes and rely on this pass-through. If the
     * pass-through is ever swapped for a hard reject, this test fails —
     * forcing that decision (and its dependents' sanitization) to be
     * revisited deliberately.
     */
    public function testRuneStoresControlBytesVerbatim(): void
    {
        foreach (["\x00", "\x07", "\x1b", "\x1f", "\x7f"] as $ctrl) {
            $cell = Cell::new($ctrl);
            $this->assertSame($ctrl, $cell->rune());
        }

        // Same pass-through via the raw constructor (the applyDiff sink path).
        $viaCtor = new Cell("\x1b[0m", null, null, 1);
        $this->assertSame("\x1b[0m", $viaCtor->rune());
    }

    public function testSerializeDefault(): void
    {
        $cell = Cell::new();

        $data = $cell->__serialize();

        $this->assertSame(' ', $data['rune']);
        $this->assertNull($data['style']);
        $this->assertNull($data['link']);
        $this->assertSame(1, $data['width']);
    }

    public function testSerializeWithStyleAndLink(): void
    {
        $style = Style::new(0xff0000, 0x0000ff, Style::ATTR_BOLD);
        $link = Hyperlink::new('https://test.com', 'xid');
        $cell = Cell::new('X', $style, $link, 1);

        $data = $cell->__serialize();

        $this->assertSame('X', $data['rune']);
        $this->assertSame(0xff0000, $data['style']['fg']);
        $this->assertSame(0x0000ff, $data['style']['bg']);
        $this->assertSame(Style::ATTR_BOLD, $data['style']['attrs']);
        $this->assertSame('https://test.com', $data['link']['url']);
        $this->assertSame('xid', $data['link']['id']);
        $this->assertSame(1, $data['width']);
    }

    public function testSerializeWideChar(): void
    {
        $cell = Cell::new('中', null, null, 2);

        $data = $cell->__serialize();

        $this->assertSame('中', $data['rune']);
        $this->assertSame(2, $data['width']);
    }

    public function testUnserializeRoundTrip(): void
    {
        $style = Style::new(0x123456, 0xabcdef, Style::ATTR_ITALIC | Style::ATTR_UNDERLINE);
        $link = Hyperlink::new('https://example.com/path', 'session');
        $original = Cell::new('中', $style, $link, 2);

        $data = $original->__serialize();
        $reconstituted = new Cell(
            $data['rune'],
            $data['style'] !== null ? new Style(
                $data['style']['fg'],
                $data['style']['bg'],
                $data['style']['attrs']
            ) : null,
            $data['link'] !== null ? new Hyperlink(
                $data['link']['url'],
                $data['link']['id']
            ) : null,
            $data['width']
        );

        $this->assertSame($original->rune(), $reconstituted->rune());
        $this->assertSame($original->width(), $reconstituted->width());
        $this->assertTrue($original->equals($reconstituted));
    }

    // ─── Run-length span law (sugar-cellbuffer-015) ─────────────────────

    public function testASpanNarrowerThanItsDeclaredWidthIsRefused(): void
    {
        // The finding's desync probe: a one-char rune behind a 4-column
        // declaration under-paints three columns of every frame. Now the
        // pairing is refused where it is created.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cell run-length span of width 4 requires a rune exactly 4 display columns wide, got 1');

        new Cell('x', null, null, 4);
    }

    public function testAMeasuringRunLengthSpanIsAccepted(): void
    {
        // sugar-table's border-fill idiom (Table.php stores the full-width
        // run as the lead's rune) stays constructible.
        $span = new Cell(str_repeat('─', 5), null, null, 5);

        $this->assertSame(5, $span->width());
    }

    public function testTheSpanLawAlsoCatchesOverlongRunes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a rune exactly 3 display columns wide, got 5');

        new Cell(str_repeat('─', 5), null, null, 3);
    }

    public function testWidthsBelowThreeAreNotReMeasured(): void
    {
        // The law binds spans only: 0/1/2 keep their historic leniency so
        // combining clusters and hand-declared wide cells never regress.
        $this->assertSame(2, (new Cell('x', null, null, 2))->width());
        $this->assertSame(1, (new Cell('ab', null, null, 1))->width());
        $this->assertSame(0, Cell::continuation()->width());
    }

    public function testUnserializationRefusesAForgedMismatchedSpan(): void
    {
        $fresh = (new \ReflectionClass(Cell::class))->newInstanceWithoutConstructor();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cell run-length span of width 3 requires a rune exactly 3 display columns wide');

        $fresh->__unserialize(['rune' => 'x', 'style' => null, 'link' => null, 'width' => 3]);
    }

    public function testSerializationRoundTripCarriesALegalSpan(): void
    {
        $span = new Cell(str_repeat('─', 4), null, null, 4);
        $reconstituted = (new \ReflectionClass(Cell::class))->newInstanceWithoutConstructor();
        $reconstituted->__unserialize($span->__serialize());

        $this->assertTrue($span->equals($reconstituted));
    }

    public function testJsonSerialize(): void
    {
        $cell = Cell::new('A', Style::bold());

        $json = json_encode($cell);
        $decoded = json_decode($json, true);

        $this->assertSame('A', $decoded['rune']);
        $this->assertSame(Style::ATTR_BOLD, $decoded['style']['attrs']);
        $this->assertSame(1, $decoded['width']);
    }

    public function testJsonSerializeWideChar(): void
    {
        $cell = Cell::new('日', null, null, 2);

        $json = json_encode($cell);
        $decoded = json_decode($json, true);

        $this->assertSame('日', $decoded['rune']);
        $this->assertSame(2, $decoded['width']);
    }

    public function testJsonSerializeNullLinkAndStyle(): void
    {
        $cell = Cell::new('B');

        $json = json_encode($cell);
        $decoded = json_decode($json, true);

        $this->assertSame('B', $decoded['rune']);
        $this->assertNull($decoded['style']);
        $this->assertNull($decoded['link']);
        $this->assertSame(1, $decoded['width']);
    }

    public function testConstructorWithAllArguments(): void
    {
        $style = Style::new(0xff0000);
        $link = Hyperlink::new('https://test.com');
        $cell = new Cell('R', $style, $link, 2);

        $this->assertSame('R', $cell->rune());
        $this->assertSame($style, $cell->style());
        $this->assertSame($link, $cell->link());
        $this->assertSame(2, $cell->width());
    }

    public function testNewWithExplicitNullStyle(): void
    {
        $cell = Cell::new('X', null);

        $this->assertNull($cell->style());
    }

    public function testEqualsWithBothNullStyles(): void
    {
        $a = Cell::new('x');
        $b = Cell::new('x');

        $this->assertTrue($a->equals($b));
    }

    public function testEqualsNullStyleVsNonNullStyle(): void
    {
        $a = Cell::new('x');
        $b = Cell::new('x', Style::bold());

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsDifferentRune(): void
    {
        $a = Cell::new('A');
        $b = Cell::new('B');

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsDifferentWidth(): void
    {
        $a = Cell::new('X', null, null, 1);
        $b = Cell::new('X', null, null, 2);

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsSameStyleDifferentInstances(): void
    {
        $a = Cell::new('x', Style::bold());
        $b = Cell::new('x', Style::bold());

        $this->assertTrue($a->equals($b));
        $this->assertNotSame($a->style(), $b->style());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function missingKeyProvider(): array
    {
        return [
            'rune' => ['rune'],
            'style' => ['style'],
            'link' => ['link'],
            'width' => ['width'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('missingKeyProvider')]
    public function testUnserializeRefusesEachMissingKey(string $missing): void
    {
        $payload = ['rune' => 'x', 'style' => null, 'link' => null, 'width' => 1];
        unset($payload[$missing]);
        $fresh = (new \ReflectionClass(Cell::class))->newInstanceWithoutConstructor();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Cell payload missing key '{$missing}'");
        $fresh->__unserialize($payload);
    }

    public function testUnserializeRefusesMissingNestedStyleAndLinkKeys(): void
    {
        foreach ([
            [['rune' => 'x', 'style' => ['bg' => null, 'attrs' => 0], 'link' => null, 'width' => 1], "Style payload missing key 'fg'"],
            [['rune' => 'x', 'style' => ['fg' => null, 'attrs' => 0], 'link' => null, 'width' => 1], "Style payload missing key 'bg'"],
            [['rune' => 'x', 'style' => ['fg' => null, 'bg' => null], 'link' => null, 'width' => 1], "Style payload missing key 'attrs'"],
            [['rune' => 'x', 'style' => null, 'link' => ['url' => 'https://a.dev'], 'width' => 1], "Hyperlink payload missing key 'id'"],
        ] as [$payload, $needle]) {
            $fresh = (new \ReflectionClass(Cell::class))->newInstanceWithoutConstructor();
            $threw = false;
            try {
                $fresh->__unserialize($payload);
            } catch (\InvalidArgumentException $e) {
                $threw = str_contains($e->getMessage(), $needle);
                $this->assertTrue($threw, "expected '{$needle}', got: " . $e->getMessage());
            }
            $this->assertTrue($threw, "expected refusal naming '{$needle}'");
        }
    }
}
