<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Tape;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Encode\PhpGifEncoder;
use SugarCraft\Vcr\Encode\TapeToGif;
use SugarCraft\Vcr\Raster\GdRasterizer;
use SugarCraft\Vcr\Render\Renderer;
use SugarCraft\Vcr\Tape\Compiler;
use SugarCraft\Vcr\Tape\Lexer;
use SugarCraft\Vcr\Tape\Parser;

use SugarCraft\Vterm\Terminal;

/**
 * Dimension ceilings + numeric-form validation (finding 011): the grid and
 * canvas numbers a tape or cassette carries are checked BEFORE they reach
 * Terminal::new / imagecreatetruecolor. Two failure classes are pinned out —
 * a garbage `Set Width abc` silently becoming a 1-pixel canvas (the old
 * blind-cast path), and an absurd number allocating terabytes (the uncapped
 * path). Over-cap refusals throw in both modes: refusing to allocate is a
 * memory-safety door, not a tape-grammar opinion.
 */
final class DimensionCeilingTest extends TestCase
{
    private function compile(string $src, bool $strict = false, string $path = '/ceiling.tape'): Cassette
    {
        $ast = (new Parser())->parse((new Lexer())->tokenize($src));

        return (new Compiler())->compile($ast, $path, $strict);
    }

    public function testOverCapWidthRefusesToAllocateUnderDefaultMode(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Set Width 100000000 exceeds the 16384-pixel limit — refusing to allocate');
        $this->compile("Set Width 100000000\nType \"x\"\n");
    }

    public function testOverCapHeightRefusesToAllocateUnderStrictMode(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Set Height 99999999 exceeds the 16384-pixel limit — refusing to allocate');
        $this->compile("Set Height 99999999\nType \"x\"\n", true);
    }

    public function testOverCapFontSizeRefusesToAllocate(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Set FontSize 1000 exceeds the 255-point limit — refusing to allocate');
        $this->compile("Set FontSize 1000\nType \"x\"\n");
    }

    public function testGarbageWidthIsNamedAndBookedAsAWarning(): void
    {
        // The non-strict door warns by name — and the warning list proves the
        // silent 1-pixel path is gone (widthPx stays unset, so the VHS
        // default, not a 1px canvas, keeps the seat).
        $compiler = new Compiler();
        $ast = (new Parser())->parse((new Lexer())->tokenize("Set Width abc\nType \"x\"\n"));
        $cassette = $compiler->compile($ast, '/ceiling.tape');
        // The VHS default image width keeps its seat — no silent 1px canvas.
        $this->assertSame(1200, $cassette->header->widthPx);
        $this->assertStringContainsString(
            "Unparseable Set Width value 'abc'",
            implode("\n", $compiler->warnings()),
        );
    }

    public function testGarbageWidthThrowsUnderStrictMode(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unparseable Set Width value 'abc'");
        $this->compile("Set Width abc\nType \"x\"\n", true);
    }

    public function testGarbageFontSizeWarnsNonStrictAndThrowsStrict(): void
    {
        $compiler = new Compiler();
        $ast = (new Parser())->parse((new Lexer())->tokenize("Set FontSize huge\nType \"x\"\n"));
        $cassette = $compiler->compile($ast, '/ceiling.tape');
        $this->assertNull($cassette->header->fontSize);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unparseable Set FontSize value 'huge'");
        $this->compile("Set FontSize huge\nType \"x\"\n", true);
    }

    public function testZeroFontSizeWarnsAndKeepsPreviousSize(): void
    {
        $compiler = new Compiler();
        $ast = (new Parser())->parse((new Lexer())->tokenize("Set FontSize 0\nType \"x\"\n"));
        $cassette = $compiler->compile($ast, '/ceiling.tape');
        $this->assertNull($cassette->header->fontSize);
    }

    public function testZeroDimensionKeepsLegacyOnePixelFloor(): void
    {
        // Pre-existing max(1, …) seat: digits-form 0 still clamps to 1px, it
        // is only NON-numeric forms that changed behaviour.
        $cassette = $this->compile("Set Width 0\nType \"x\"\n");
        $this->assertSame(1, $cassette->header->widthPx);
    }

    public function testValidSizesPassThroughUnchanged(): void
    {
        $cassette = $this->compile("Set Width 1200\nSet Height 600\nSet FontSize 22\nType \"x\"\n");
        $this->assertSame(1200, $cassette->header->widthPx);
        $this->assertSame(600, $cassette->header->heightPx);
        $this->assertSame(22, $cassette->header->fontSize);
    }

    public function testCeilingBoundarySizesAreAccepted(): void
    {
        $cassette = $this->compile("Set Width 16384\nSet Height 16384\nSet FontSize 255\nType \"x\"\n");
        $this->assertSame(16384, $cassette->header->widthPx);
        $this->assertSame(255, $cassette->header->fontSize);
        // The grid derived from the capped pixels still fits the header door
        $this->assertLessThanOrEqual(CassetteHeader::MAX_COLS_ROWS, $cassette->header->cols);
        $this->assertLessThanOrEqual(CassetteHeader::MAX_COLS_ROWS, $cassette->header->rows);
    }

    public function testHeaderDoorRefusesOversizedGrid(): void
    {
        try {
            $this->makeHeader(cols: 5000, rows: 24);
            $this->fail('expected refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('must not exceed 4096x4096, got 5000x24', $e->getMessage());
        }
    }

    public function testHeaderDoorRefusesOversizedPixels(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('widthPx must not exceed');
        // Second ceiling axis: pixels cap independently of the grid cap.
        new CassetteHeader(
            version: 1,
            createdAt: '2026-01-01T00:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'test',
            widthPx: 16385,
        );
    }

    public function testHeaderDoorRefusesOversizedFontSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fontSize must be between 1 and 255, got 256');
        new CassetteHeader(
            version: 1,
            createdAt: '2026-01-01T00:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'test',
            fontSize: 256,
        );
    }

    public function testHeaderDoorHonoursCeilingEdges(): void
    {
        $header = new CassetteHeader(
            version: 1,
            createdAt: '2026-01-01T00:00:00Z',
            cols: 4096,
            rows: 4096,
            runtime: 'test',
            fontSize: 255,
            widthPx: 16384,
            heightPx: 16384,
        );
        $this->assertSame(4096, $header->cols);
    }

    public function testRenderDoorRefusesOversizedTapeBeforeAnyAllocation(): void
    {
        $tape = (string) tempnam(sys_get_temp_dir(), 'vcrceil') . '.tape';
        file_put_contents($tape, "Set Width 100000000\nType \"x\"\n");
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('exceeds the 16384-pixel limit');
            $pipeline = new TapeToGif(
                new Lexer(),
                new Parser(),
                new Compiler(),
                new Renderer(),
                new GdRasterizer(),
                new PhpGifEncoder(),
            );
            $pipeline->render($tape, $tape . '.gif');
        } finally {
            @unlink($tape);
        }
    }

    public function testRasterizerBackstopRefusesCanvasBeyondCeiling(): void
    {
        $terminal = Terminal::new(80, 24);
        $snapshot = $terminal->snapshot();
        // 80 cols * 30000 px/cell blows the 4096*512 canvas backstop without
        // ever reaching imagecreatetruecolor — refusal is named.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside the supported allocation limits');
        (new GdRasterizer())->rasterize($snapshot, 30000, 16);
    }

    private function makeHeader(int $cols, int $rows): CassetteHeader
    {
        return new CassetteHeader(
            version: 1,
            createdAt: '2026-01-01T00:00:00Z',
            cols: $cols,
            rows: $rows,
            runtime: 'test',
        );
    }
}