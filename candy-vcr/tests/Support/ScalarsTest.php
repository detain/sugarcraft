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
        $this->expectExceptionMessage('candy-vcr: field.path must be int, array given');
        Scalars::int([], 'field.path');
    }

    public function testStringRejectsArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('candy-vcr: field.path must be string, array given');
        Scalars::string([], 'field.path');
    }

    public function testFloatRejectsArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('candy-vcr: field.path must be float, array given');
        Scalars::float([], 'field.path');
    }

    public function testBoolRejectsArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('candy-vcr: field.path must be bool, array given');
        Scalars::bool([], 'field.path');
    }

    public function testObjectAlsoRejectedWithItsClassName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('candy-vcr: payload.t must be float, stdClass given');
        Scalars::float(new \stdClass(), 'payload.t');
    }
}
