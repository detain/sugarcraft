<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Chart;

use SugarCraft\Charts\Chart\Position;
use PHPUnit\Framework\TestCase;

/**
 * @see Position
 */
final class PositionTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        $cases = Position::cases();
        self::assertCount(4, $cases);
    }

    public function testTopCase(): void
    {
        $case = Position::Top;
        self::assertSame('Top', $case->name);
    }

    public function testBottomCase(): void
    {
        $case = Position::Bottom;
        self::assertSame('Bottom', $case->name);
    }

    public function testLeftCase(): void
    {
        $case = Position::Left;
        self::assertSame('Left', $case->name);
    }

    public function testRightCase(): void
    {
        $case = Position::Right;
        self::assertSame('Right', $case->name);
    }

    /**
     * @dataProvider casesProvider
     */
    public function testMatchExhaustive(Position $position): void
    {
        $result = match ($position) {
            Position::Top    => 'top',
            Position::Bottom => 'bottom',
            Position::Left   => 'left',
            Position::Right  => 'right',
        };

        self::assertNotEmpty($result);
    }

    /**
     * @return iterable<string, array{Position}>
     */
    public static function casesProvider(): iterable
    {
        foreach (Position::cases() as $case) {
            yield $case->name => [$case];
        }
    }
}
