<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Handler;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Handler\OscHandler;
use SugarCraft\Vt\Handler\ScreenHandler;

final class OscHandlerTest extends TestCase
{
    private function newHandler(): ScreenHandler
    {
        return new ScreenHandler(new Buffer(20, 5));
    }

    private function apply(string $data, ScreenHandler $h): void
    {
        (new OscHandler())->apply($data, $h);
    }

    // ─── Window title ──────────────────────────────────────────────────────

    public function testOsc0SetsWindowTitle(): void
    {
        $h = $this->newHandler();
        $this->apply('0;Hello', $h);
        $this->assertSame('Hello', $h->windowTitle);
    }

    public function testOsc1SetsWindowTitle(): void
    {
        $h = $this->newHandler();
        $this->apply('1;Icon', $h);
        $this->assertSame('Icon', $h->windowTitle);
    }

    public function testOsc2SetsWindowTitle(): void
    {
        $h = $this->newHandler();
        $this->apply('2;A title with spaces', $h);
        $this->assertSame('A title with spaces', $h->windowTitle);
    }

    public function testEmptyTitlePayloadStoresEmptyString(): void
    {
        $h = $this->newHandler();
        $this->apply('2;', $h);
        $this->assertSame('', $h->windowTitle);
    }

    // ─── Palette (OSC 4) ───────────────────────────────────────────────────

    public function testOsc4SetsSinglePaletteEntry(): void
    {
        $h = $this->newHandler();
        $this->apply('4;1;rgb:ffff/0000/0000', $h);
        $this->assertArrayHasKey(1, $h->palette);
        $color = $h->palette[1];
        $this->assertSame(3, $color->kind); // truecolor
        $this->assertSame(0xFF0000, $color->value);
    }

    public function testOsc4SetsMultiplePaletteEntries(): void
    {
        $h = $this->newHandler();
        $this->apply('4;1;rgb:ffff/0000/0000;2;rgb:0000/ffff/0000', $h);
        $this->assertSame(0xFF0000, $h->palette[1]->value);
        $this->assertSame(0x00FF00, $h->palette[2]->value);
    }

    public function testOsc4AcceptsHashHexFormat(): void
    {
        $h = $this->newHandler();
        $this->apply('4;5;#abcdef', $h);
        $this->assertSame(0xABCDEF, $h->palette[5]->value);
    }

    public function testOsc4ScalesShortHexComponents(): void
    {
        // 1-digit components are MSB-aligned (left-padded × 16).
        $h = $this->newHandler();
        $this->apply('4;3;rgb:f/0/0', $h);
        $this->assertSame(0xF00000, $h->palette[3]->value);
    }

    public function testOsc4IgnoresMalformedColor(): void
    {
        $h = $this->newHandler();
        $this->apply('4;7;not-a-color', $h);
        $this->assertArrayNotHasKey(7, $h->palette);
    }

    public function testOsc4IgnoresOutOfRangeIndex(): void
    {
        $h = $this->newHandler();
        $this->apply('4;999;rgb:ffff/0000/0000', $h);
        $this->assertSame([], $h->palette);
    }

    // ─── Hyperlink (OSC 8) ─────────────────────────────────────────────────

    public function testOsc8OpensHyperlinkWithUri(): void
    {
        $h = $this->newHandler();
        $this->apply('8;;https://example.com', $h);
        $this->assertNotNull($h->currentHyperlink);
        $this->assertSame('https://example.com', $h->currentHyperlink->uri);
        $this->assertSame('', $h->currentHyperlink->id);
    }

    public function testOsc8ParsesIdParam(): void
    {
        $h = $this->newHandler();
        $this->apply('8;id=anchor1;https://example.com#x', $h);
        $this->assertSame('anchor1', $h->currentHyperlink->id);
        $this->assertSame('https://example.com#x', $h->currentHyperlink->uri);
    }

    public function testOsc8WithEmptyUriClosesHyperlink(): void
    {
        $h = $this->newHandler();
        $this->apply('8;;https://example.com', $h);
        $this->apply('8;;', $h);
        $this->assertNull($h->currentHyperlink);
    }

    public function testOsc8MalformedClosesHyperlink(): void
    {
        // No second semicolon — treat as close.
        $h = $this->newHandler();
        $this->apply('8;;https://example.com', $h);
        $this->apply('8', $h);
        $this->assertNull($h->currentHyperlink);
    }

    public function testHyperlinkAttachedToCellsPrintedWhileOpen(): void
    {
        $h = $this->newHandler();
        $this->apply('8;;https://example.com', $h);
        $h->printChar('A');
        $h->printChar('B');
        $this->apply('8;;', $h); // close
        $h->printChar('C');

        $this->assertNotNull($h->buffer->cell(0, 0)->hyperlink);
        $this->assertSame('https://example.com', $h->buffer->cell(0, 0)->hyperlink->uri);
        $this->assertNotNull($h->buffer->cell(0, 1)->hyperlink);
        $this->assertNull($h->buffer->cell(0, 2)->hyperlink);
    }

    // ─── Clipboard (OSC 52) ────────────────────────────────────────────────

    public function testOsc52WriteRecordsEvent(): void
    {
        $h = $this->newHandler();
        $this->apply('52;c;SGVsbG8=', $h);
        $this->assertSame([
            ['kind' => 'write', 'selection' => 'c', 'payload' => 'SGVsbG8='],
        ], $h->clipboardEvents);
    }

    public function testOsc52ReadRequestRecordsEvent(): void
    {
        $h = $this->newHandler();
        $this->apply('52;p;?', $h);
        $this->assertSame([
            ['kind' => 'read', 'selection' => 'p'],
        ], $h->clipboardEvents);
    }

    public function testOsc52MalformedSkipped(): void
    {
        $h = $this->newHandler();
        $this->apply('52;c', $h); // missing payload field
        $this->assertSame([], $h->clipboardEvents);
    }

    // ─── Unknown OSC ───────────────────────────────────────────────────────

    public function testUnknownOscIgnored(): void
    {
        $h = $this->newHandler();
        $this->apply('999;whatever', $h);
        $this->assertNull($h->windowTitle);
        $this->assertSame([], $h->palette);
        $this->assertSame([], $h->clipboardEvents);
    }

    public function testNonNumericCommandIgnored(): void
    {
        $h = $this->newHandler();
        $this->apply('abc;whatever', $h);
        $this->assertNull($h->windowTitle);
    }
}
