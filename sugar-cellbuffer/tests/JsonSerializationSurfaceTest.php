<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\CellBuffer\Hyperlink;
use SugarCraft\CellBuffer\Position;
use SugarCraft\CellBuffer\Region;
use SugarCraft\CellBuffer\Style;

/**
 * The six value objects implement JsonSerializable by delegating to
 * __serialize(); nothing else exercised that delegation, so a regression
 * breaking a jsonSerialize() body would pass CI (sugar-cellbuffer-013).
 *
 * @covers \SugarCraft\CellBuffer\Buffer
 * @covers \SugarCraft\CellBuffer\Cell
 * @covers \SugarCraft\CellBuffer\Position
 * @covers \SugarCraft\CellBuffer\Region
 * @covers \SugarCraft\CellBuffer\Style
 * @covers \SugarCraft\CellBuffer\Hyperlink
 */
final class JsonSerializationSurfaceTest extends TestCase
{
    /**
     * @return array<array-key, array{0: object}>
     */
    public static function serializableSurfaceProvider(): array
    {
        return [
            'buffer' => [Buffer::new(2, 1)->withCellAt(0, 0, Cell::new('x', Style::bold(), new Hyperlink('https://example.com', 'a')))],
            'cell' => [Cell::new('x', new Style(0x010203, null, Style::ATTR_ITALIC, null, null, 0x0A141E), new Hyperlink('https://example.com'), 2)],
            'position' => [new Position(-1, 4)],
            'region' => [new Region(new Position(1, 2), 3, 4)],
            'style' => [new Style(0xFF00FF, 0x0A141E, Style::ATTR_BOLD, 5, null, 0x102030)],
            'hyperlink' => [new Hyperlink('https://example.com/x', 'ident')],
        ];
    }

    /**
     * @param object&\JsonSerializable $subject
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('serializableSurfaceProvider')]
    public function testJsonEncodingDelegatesToTheSerializeHook(\JsonSerializable $subject): void
    {
        $this->assertSame(
            json_encode($subject->__serialize()),
            json_encode($subject)
        );
    }

    public function testTheBufferCopyCapIsRefusedBeforeAnyAllocation(): void
    {
        // MAX_COPY_CELLS guards a DoS surface (a data-driven region cannot
        // force a multi-megabyte extraction). The refusal is by name.
        $region = new Region(new Position(0, 0), 1001, 1000); // 1,001,000 cells

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Buffer::copy() region of 1001000 cells exceeds the 1000000-cell safety cap.');

        Buffer::new(4, 4)->copy($region);
    }
}
