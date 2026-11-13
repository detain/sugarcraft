<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Workflows\RunContextValue;

final class RunContextValueTest extends TestCase
{
    public function testSplitPlainPair(): void
    {
        self::assertSame(['name', 'value', false], RunContextValue::split('name=value'));
    }

    public function testSplitJsonOperator(): void
    {
        self::assertSame(['tags', '["a","b"]', true], RunContextValue::split('tags:=["a","b"]'));
    }

    public function testSplitWithoutEqualsIsNull(): void
    {
        self::assertNull(RunContextValue::split('novalue'));
        self::assertNull(RunContextValue::split(''));
    }

    public function testSplitOnlyTheFirstEquals(): void
    {
        self::assertSame(['url', 'a=b=c', false], RunContextValue::split('url=a=b=c'));
    }

    public function testEmptyValueAndEmptyKey(): void
    {
        self::assertSame(['k', '', false], RunContextValue::split('k='));
        self::assertSame(['', 'v', false], RunContextValue::split('=v'));
    }

    public function testColonInsideValueIsNotTheJsonOperator(): void
    {
        self::assertSame(['k', '=:=x', false], RunContextValue::split('k==:=x'));
        self::assertSame(['time', '12:30', false], RunContextValue::split('time=12:30'));
    }

    public function testOperatorConstant(): void
    {
        self::assertSame(':=', RunContextValue::JSON_OPERATOR);
    }

    public function testDecodeJsonValues(): void
    {
        self::assertSame(['a', 'b'], RunContextValue::decode('k', '["a","b"]'));
        self::assertSame(['x' => 1], RunContextValue::decode('k', '{"x":1}'));
        self::assertSame(3, RunContextValue::decode('k', '3'));
        self::assertTrue(RunContextValue::decode('k', 'true'));
        self::assertNull(RunContextValue::decode('k', 'null'));
    }

    public function testDecodeRejectsMalformedJsonNamingTheKey(): void
    {
        try {
            RunContextValue::decode('files', '[oops');
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('`files:=`', $e->getMessage());
            self::assertStringContainsString('[oops', $e->getMessage());
            self::assertInstanceOf(\JsonException::class, $e->getPrevious());
        }
    }
}
