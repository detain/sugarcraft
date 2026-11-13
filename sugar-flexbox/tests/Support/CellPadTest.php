<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Flexbox\Support\CellPad;

/**
 * The FBX-36 acceptance contract: the combined fit+pad path is
 * BYTE-IDENTICAL to the naive core pairing (truncateAnsi then pad*),
 * forever. This differential is the permanent guard — CellPad may get
 * faster, but it may never become a second truth of layout arithmetic.
 */
final class CellPadTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function corpus(): array
    {
        return [
            'empty'       => ['', 'empty'],
            'plain'       => ['hello world', 'plain'],
            'accented'    => ["\xC3\xA4\x63\x63\x65\x6E\x74", 'accented'],
            'combining'   => ["e\xCC\x81\x7C\x65\xCC\x80\x7C", 'combining'],
            'cjk'         => ['東京東京東京', 'cjk'],
            'emoji'       => ['🙂🙂🙂🙂', 'emoji'],
            'wide-mix'    => ['ab東京c🙂d', 'wide-mix'],
            'styled'      => ["\x1b[31mred text\x1b[0m", 'styled'],
            'styled-tail' => ["plain \x1b[1mbold", 'styled-tail'],
            'over-wide'   => ['a very long line that overshoots every cell budget', 'over-wide'],
            'tabs'        => ["a\tb", 'tabs'],
        ];
    }

    #[DataProvider('corpus')]
    public function testFitPlusPadEqualsNaiveCorePairing(string $line, string $label): void
    {
        foreach ([0, 1, 3, 5, 7, 11, 17, 40] as $cells) {
            [$fitted, $width] = CellPad::fit($line, $cells);
            $truncated = Width::truncateAnsi($line, $cells);

            $this->assertSame(
                $truncated,
                $fitted,
                "{$label}: fit must truncate exactly like the core walk at {$cells} cells"
            );

            $naive = [
                'right'  => Width::padRight($truncated, $cells),
                'left'   => Width::padLeft($truncated, $cells),
                'center' => Width::padCenter($truncated, $cells),
            ];
            foreach ($naive as $side => $expected) {
                $this->assertSame(
                    $expected,
                    CellPad::pad($fitted, $width, $cells, $side),
                    "{$label}: CellPad::pad({$side}) must match the core pairing at {$cells} cells"
                );
            }
        }
    }

    public function testPadIsANoOpWhenTheKnownWidthAlreadyFits(): void
    {
        $this->assertSame('exact', CellPad::pad('exact', 5, 5, 'right'));
        $this->assertSame('wide', CellPad::pad('wide', 9, 5, 'center'), 'no negative str_repeat');
    }

    public function testUnknownSideRefusesByValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("got 'sideways'");
        CellPad::pad('x', 1, 5, 'sideways');
    }
}
