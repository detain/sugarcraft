<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Cli\ParsedArgs;
use SugarCraft\Cli\ValueSource;

final class ParsedArgsTest extends TestCase
{
    private static function args(): ParsedArgs
    {
        return ParsedArgs::new(
            ['app', 'run'],
            ['model' => 'gpt', 'count' => 3, 'mode' => 'fast', 'other' => null],
            ['model' => ValueSource::Argv, 'count' => ValueSource::Env, 'mode' => ValueSource::Default],
            ['a', 'b'],
        );
    }

    public function testAccessors(): void
    {
        $args = self::args();

        self::assertSame(['app', 'run'], $args->command());
        self::assertSame('gpt', $args->value('model'));
        self::assertSame(['model' => 'gpt', 'count' => 3, 'mode' => 'fast', 'other' => null], $args->values());
        self::assertSame(['a', 'b'], $args->arguments());
    }

    public function testHasCountsArgvAndEnvButNotDefault(): void
    {
        $args = self::args();

        self::assertTrue($args->has('model'));
        self::assertTrue($args->has('count'));
        self::assertFalse($args->has('mode'));
        self::assertFalse($args->has('other'));
    }

    public function testSourceReportsOriginOrNull(): void
    {
        $args = self::args();

        self::assertSame(ValueSource::Argv, $args->source('model'));
        self::assertSame(ValueSource::Env, $args->source('count'));
        self::assertSame(ValueSource::Default, $args->source('mode'));
        self::assertNull($args->source('other'));
    }

    public function testValueOfUndeclaredOptionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No option "--nope"');
        self::args()->value('nope');
    }

    public function testHasOfUndeclaredOptionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::args()->has('nope');
    }

    public function testSourceOfUndeclaredOptionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::args()->source('nope');
    }
}
