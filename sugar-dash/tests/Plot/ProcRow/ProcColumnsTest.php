<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\ProcRow;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Plot\ProcRow\ProcColumns;

/**
 * btop sizing (Proc::draw): user = w<75 ? 5 : 10, threads = w<75 ? -1 : 4,
 * prog = w>70 ? 16 : (w>55 ? 8 : w-user-threads-33),
 * cmd = w>55 ? w-prog-user-threads-33 : -1, cmd += 5 without graphs.
 */
final class ProcColumnsTest extends TestCase
{
    /** @return array<string, array{int, bool, int, int, int, int, int}> */
    public static function btopCases(): array
    {
        // [box, graphs, prog, cmd, threads, user, row width]
        return [
            'wide' => [80, true, 16, 17, 4, 10, 78],
            'wide no graphs' => [80, false, 16, 22, 4, 10, 78],
            'threads threshold' => [75, true, 16, 12, 4, 10, 73],
            'just below threads' => [74, true, 16, 21, -1, 5, 72],
            'prog 8 band' => [70, true, 8, 25, -1, 5, 68],
            'mid' => [60, true, 8, 15, -1, 5, 58],
            'narrow drops cmd' => [50, true, 13, -1, -1, 5, 47],
            'narrow no graphs reshows cmd' => [50, false, 13, 4, -1, 5, 47],
        ];
    }

    #[DataProvider('btopCases')]
    public function testBtopSizing(int $box, bool $graphs, int $prog, int $cmd, int $threads, int $user, int $width): void
    {
        $c = ProcColumns::btop($box, $graphs);
        $this->assertSame([$prog, $cmd, $threads, $user, $graphs], [$c->prog, $c->cmd, $c->threads, $c->user, $c->cpuGraphs]);
        $this->assertSame($width, $c->width());
    }

    public function testNewAndValidation(): void
    {
        $c = ProcColumns::new(6, 0, 0, 4, false);
        // 9 + 7 + 5 + 6 + 1 + 6
        $this->assertSame(34, $c->width());
        $this->expectException(\InvalidArgumentException::class);
        ProcColumns::new(-1, 0, 0, 4);
    }
}
