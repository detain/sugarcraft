<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Palette;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Palette\PaletteState;

final class PaletteStateQueryCapTest extends TestCase
{
    public function testHugePasteIsCappedAndCaretClamped(): void
    {
        $s = PaletteState::root()->withQuery(str_repeat('é', 7200));
        self::assertSame(PaletteState::MAX_QUERY_LENGTH, mb_strlen($s->query, 'UTF-8'));
        self::assertSame(PaletteState::MAX_QUERY_LENGTH, $s->queryCursor);
        self::assertTrue($s->queryAtCap());
    }

    public function testShortQueryUntouched(): void
    {
        $s = PaletteState::root()->withQuery('model');
        self::assertSame('model', $s->query);
        self::assertFalse($s->queryAtCap());
    }
}
