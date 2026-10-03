<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Util\TokenCount;

final class TokenCountTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function counts(): iterable
    {
        yield 'zero' => [0, '0'];
        yield 'below a thousand stays exact' => [999, '999'];
        yield 'a round thousand drops its .0' => [1000, '1K'];
        yield 'one decimal of K' => [1234, '1.2K'];
        yield 'tens of K' => [12_400, '12.4K'];
        yield 'rounds up into the next unit, never 1000K' => [999_950, '1M'];
        yield 'millions' => [2_140_000, '2.1M'];
        yield 'negative clamps to zero' => [-5, '0'];
    }

    /** @dataProvider counts */
    public function testCompact(int $tokens, string $expected): void
    {
        $this->assertSame($expected, TokenCount::compact($tokens));
    }
}
