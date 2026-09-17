<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\Vt\Cell;
use SugarCraft\Vt\Rendition;

/**
 * The emulator's `SugarCraft\Vt\Cell\Cell` is a {@see class_alias} onto the
 * canonical {@see Cell} after the two Cell classes were unified. Per the
 * façade rule this file pins ONLY the alias contract — the real behaviour of
 * both former classes is exercised from their two facets by {@see CellTest}
 * (emulator/Sgr vocabulary) and {@see Cell\SimpleCellTest} (renderer/palette
 * vocabulary) running against this one class. Duplicating either suite under
 * the aliased name would just re-test identical code.
 */
final class CellAliasTest extends TestCase
{
    public function testEmulatorCellSymbolResolvesToCanonicalClass(): void
    {
        $canonical = new ReflectionClass(Cell::class);
        $alias = new ReflectionClass('SugarCraft\\Vt\\Cell\\Cell');

        self::assertSame(Cell::class, $alias->getName(), 'the Vt\\Cell\\Cell alias resolves to the canonical Vt\\Cell');
        self::assertSame(
            $canonical->getFileName(),
            $alias->getFileName(),
            'both names load from the same file — no duplicated class body',
        );
    }

    public function testConstructThroughAliasYieldsCanonicalInstance(): void
    {
        $aliasName = 'SugarCraft\\Vt\\Cell\\Cell';
        /** @var Cell $viaAlias */
        $viaAlias = new $aliasName(char: 'Q', fg: 4, bg: 2);

        self::assertInstanceOf(Cell::class, $viaAlias);
        self::assertSame('Q', $viaAlias->char);
        self::assertSame('Q', $viaAlias->grapheme, 'char and grapheme carry the same value');
        self::assertSame(4, $viaAlias->fg);
        self::assertSame(2, $viaAlias->bg);
    }

    public function testFluentSettersRoundTripAcrossBothVocabularies(): void
    {
        $cell = new Cell(char: 'x', fg: 1, bg: 2, attrs: Cell::ATTR_BOLD);

        $repainted = $cell->withFgTruecolor(0x102030)->withRendition(Rendition::DoubleTop);

        self::assertNull($cell->fgTruecolor, 'immutable: original untouched');
        self::assertSame(Rendition::None, $cell->rendition);
        self::assertSame([16, 32, 48], $repainted->fgRgb());
        self::assertSame(Rendition::DoubleTop, $repainted->rendition);
        self::assertTrue($repainted->equals($repainted), 'a cell equals itself');
        self::assertFalse($cell->equals($repainted), 'rendition/truecolor take part in renderer equality');
    }
}
