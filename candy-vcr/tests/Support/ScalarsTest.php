<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Support\Scalars;

final class ScalarsTest extends TestCase
{
    public function testIntMatchesNativeCastTable(): void
    {
        self::assertSame(42, Scalars::int(42, 'x'));
        self::assertSame(42, Scalars::int('42', 'x'));
        self::assertSame(42, Scalars::int(42.9, 'x'));
        self::assertSame(1, Scalars::int(true, 'x'));
        self::assertSame(0, Scalars::int(false, 'x'));
        self::assertSame(0, Scalars::int('abc', 'x'));
        self::assertSame(0, Scalars::int(null, 'x'), 'null casts to 0 exactly like (int)');
    }

    public function testStringMatchesNativeCastTable(): void
    {
        self::assertSame('42', Scalars::string(42, 'x'));
        self::assertSame('1', Scalars::string(true, 'x'));
        self::assertSame('', Scalars::string(false, 'x'));
        self::assertSame('', Scalars::string(null, 'x'), 'null casts to "" exactly like (string)');
    }

    public function testFloatMatchesNativeCastTable(): void
    {
        self::assertSame(1.5, Scalars::float('1.5', 'x'));
        self::assertSame(2.0, Scalars::float(2, 'x'));
        self::assertSame(0.0, Scalars::float(null, 'x'));
    }

    public function testBoolMatchesNativeCastTable(): void
    {
        self::assertTrue(Scalars::bool('yes', 'x'));
        self::assertFalse(Scalars::bool('0', 'x'));
        self::assertFalse(Scalars::bool(0, 'x'));
        self::assertTrue(Scalars::bool(0.1, 'x'));
        self::assertFalse(Scalars::bool(null, 'x'));
        self::assertTrue(Scalars::bool(true, 'x'));
    }

    public function testIntRejectsArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('field.path must be int, array given');
        Scalars::int([], 'field.path');
    }

    public function testStringRejectsArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('field.path must be string, array given');
        Scalars::string([], 'field.path');
    }

    public function testFloatRejectsArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('field.path must be float, array given');
        Scalars::float([], 'field.path');
    }

    public function testBoolRejectsArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('field.path must be bool, array given');
        Scalars::bool([], 'field.path');
    }

    public function testObjectAlsoRejectedWithItsClassName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('payload.t must be float, stdClass given');
        Scalars::float(new \stdClass(), 'payload.t');
    }

    public function testSecondsAcceptsNumericLiteralsAndNumericStrings(): void
    {
        self::assertSame(0.0, Scalars::seconds(0, 't'));
        self::assertSame(42.5, Scalars::seconds(42.5, 't'));
        self::assertSame(12.0, Scalars::seconds('12', 't'));
        self::assertSame(1.5, Scalars::seconds('1.5', 't'));
        self::assertSame(1000.0, Scalars::seconds('1e3', 't'));
    }

    /**
     * The laundering shape of finding 001: (float) "abc" === 0.0, which
     * would rewrite a corrupt timestamp into a legal frame at tape start.
     * The seconds door names it instead.
     */
    public function testSecondsRejectsGarbageStringsNotZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("event t must be a finite non-negative number of seconds at most 86400, 'abc' given");
        Scalars::seconds('abc', 'event t');
    }

    public function testSecondsRejectsInfinityNanNegativeAndOversized(): void
    {
        foreach ([\INF, -\INF, \NAN, -1.0, '1e17', 86401.0] as $bad) {
            try {
                Scalars::seconds($bad, 'event t');
                self::fail('expected rejection for ' . var_export($bad, true));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('must be a finite non-negative number of seconds', $e->getMessage());
            }
        }
    }

    public function testSecondsRejectsContainersAndBools(): void
    {
        foreach ([[], true] as $bad) {
            try {
                Scalars::seconds($bad, 'event t');
                self::fail('expected rejection for ' . get_debug_type($bad));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('must be a finite non-negative number of seconds', $e->getMessage());
            }
        }
    }
}
