<?php

declare(strict_types=1);

namespace SugarCraft\PluginInstaller\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\PluginInstaller\Scope;

final class ScopeTest extends TestCase
{
    public function testFromValueAcceptsUser(): void
    {
        $this->assertSame(Scope::User, Scope::fromValue('user'));
    }

    public function testFromValueAcceptsProject(): void
    {
        $this->assertSame(Scope::Project, Scope::fromValue('project'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badValues(): iterable
    {
        yield 'global' => ['global'];
        yield 'wrong case' => ['USER'];
        yield 'empty' => [''];
        yield 'padded' => [' project'];
        yield 'integer' => [1];
        yield 'null' => [null];
        yield 'bool' => [true];
        yield 'list' => [['user']];
    }

    #[DataProvider('badValues')]
    public function testFromValueRefusesAnythingElse(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be "user" or "project"');

        Scope::fromValue($value);
    }
}
