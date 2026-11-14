<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Encode\TapeToGif;
use SugarCraft\Vcr\Msg\Registry;
use ReflectionClass;
use ReflectionNamedType;

/**
 * VCR-S74 (findings 041/094): AGENTS.md forbids `::create()`, `::make()`
 * and `::default()` factories — every entry point is `::new()` (or a
 * mirror-faithful upstream verb like `open()`). The rulebook's word is a
 * convention until a test makes it structural: these two classes were the
 * lib's offenders, and this pin keeps them (and any future public static
 * factory on them) from drifting back.
 */
final class FactoryNamingTest extends TestCase
{
    public static function forbiddenFactoryProvider(): array
    {
        return [
            'TapeToGif' => [TapeToGif::class],
            'Registry' => [Registry::class],
        ];
    }

    /**
     * @param class-string $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('forbiddenFactoryProvider')]
    public function testNoForbiddenFactoryNames(string $class): void
    {
        $reflection = new ReflectionClass($class);
        foreach (['create', 'make', 'default'] as $forbidden) {
            $method = $reflection->hasMethod($forbidden) ? $reflection->getMethod($forbidden) : null;
            $this->assertTrue(
                $method === null || !$method->isStatic() || !$method->isPublic(),
                "{$class}::{$forbidden}() is a forbidden factory name — use ::new()",
            );
        }
    }

    /**
     * @param class-string $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('forbiddenFactoryProvider')]
    public function testNewFactoryExistsAndSelfReturns(string $class): void
    {
        $reflection = new ReflectionClass($class);
        $this->assertTrue($reflection->hasMethod('new'), "{$class}::new() missing");
        $method = $reflection->getMethod('new');
        $this->assertTrue($method->isStatic() && $method->isPublic());
        $return = $method->getReturnType();
        $this->assertInstanceOf(ReflectionNamedType::class, $return);
        $this->assertSame('self', $return->getName());
    }

    public function testRegistryNewStillHandsBackThePreloadedCodecPair(): void
    {
        // The rename must not silently drop the factory's substance: the
        // registry still claims builtin envelopes and the allow-listed
        // JSON catch-all.
        $registry = Registry::new();
        $this->assertNotNull($registry->decode(['@type' => 'KeyMsg', 'type' => 'char', 'rune' => 'q']));
        $this->assertNull($registry->decode(['@type' => 'Some\User\JsonSerializable']));
    }
}
