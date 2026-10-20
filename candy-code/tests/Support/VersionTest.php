<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\Version;

/**
 * Pins for the version ordering `self-update` consults before it writes a
 * byte (DST-3): the exact grammar it accepts, the numeric ordering, and the
 * fail-closed `null` every non-conforming shape must answer with.
 */
final class VersionTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: ?array{0: int, 1: int, 2: int}}>
     */
    public static function shapeProvider(): array
    {
        return [
            'bare triple' => ['1.2.3', [1, 2, 3]],
            'tag spelling' => ['v0.1.0', [0, 1, 0]],
            'zero padded' => ['v00.01.002', [0, 1, 2]],
            'large segments' => ['10.20.300', [10, 20, 300]],
            'surrounding space' => [" 1.2.3\n", [1, 2, 3]],
            'prerelease suffix refused' => ['1.2.3-rc1', null],
            'build metadata refused' => ['1.2.3+build7', null],
            'two segments refused' => ['1.2', null],
            'four segments refused' => ['1.2.3.4', null],
            'bare word refused' => ['dev-master', null],
            'double v refused' => ['vv1.2.3', null],
            'padded tag parses' => [' v1.2.3 ', [1, 2, 3]],
            'empty refused' => ['', null],
            'negative segment refused' => ['-1.2.3', null],
            'trailing dot refused' => ['1.2.3.', null],
            'inner space refused' => ['1. 2.3', null],
        ];
    }

    /**
     * @dataProvider shapeProvider
     */
    public function testParseAcceptsExactlyTheDottedTriple(string $input, ?array $expected): void
    {
        self::assertSame($expected, Version::parse($input));
    }

    public static function orderProvider(): array
    {
        return [
            'major older' => ['v0.9.9', 'v1.0.0', -1],
            'minor newer' => ['1.2.0', '1.1.9', 1],
            'patch equal' => ['v2.3.4', '2.3.4', 0],
            'patch order' => ['2.3.4', '2.3.5', -1],
            'numeric not lexical' => ['1.10.0', '1.9.0', 1],
        ];
    }

    /**
     * @dataProvider orderProvider
     */
    public function testCompareOrdersSegmentsNumerically(string $left, string $right, int $expected): void
    {
        self::assertSame($expected, Version::compare($left, $right));
        self::assertSame(-$expected, Version::compare($right, $left), 'compare must be antisymmetric');
    }

    public static function unorderableProvider(): array
    {
        return [
            'left dev' => ['dev-master', '1.0.0'],
            'right dev' => ['1.0.0', 'dev-master'],
            'left unknown' => ['unknown', '1.0.0'],
            'right rc' => ['1.0.0', '1.0.0-rc1'],
            'both junk' => ['alpha', 'beta'],
        ];
    }

    /**
     * @dataProvider unorderableProvider
     */
    public function testCompareRefusesEveryNonConformingSideWithNull(string $left, string $right): void
    {
        self::assertNull(Version::compare($left, $right), 'an ordering guess is how a dev checkout gets downgraded');
    }

    public function testIsComparableMirrorsParse(): void
    {
        self::assertTrue(Version::isComparable('v0.1.0'));
        self::assertFalse(Version::isComparable('dev-master'));
        self::assertFalse(Version::isComparable('0.1'));
    }
}
