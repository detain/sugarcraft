<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Widgets\Help\Help;
use SugarCraft\Widgets\Progress\Progress;
use SugarCraft\Widgets\Tabs\Tabs;

/**
 * W-09: the three wide-ctor components expose ::new() as the sole door.
 */
final class PrivateConstructorTest extends TestCase
{
    /** @return array<string, array{class-string}> */
    public static function components(): array
    {
        return ['Progress' => [Progress::class], 'Help' => [Help::class], 'Tabs' => [Tabs::class]];
    }

    #[DataProvider('components')]
    public function testConstructorIsPrivate(string $class): void
    {
        self::assertTrue((new \ReflectionClass($class))->getConstructor()?->isPrivate());
    }

    #[DataProvider('components')]
    public function testDirectNewFailsWithClearError(string $class): void
    {
        try {
            (static fn () => new $class())();
            self::fail('direct construction must fail');
        } catch (\Error $e) {
            self::assertStringContainsString('private', $e->getMessage());
            self::assertStringContainsString('__construct', $e->getMessage());
        }
    }

    public function testFactoriesStillConstruct(): void
    {
        self::assertInstanceOf(Progress::class, Progress::new());
        self::assertInstanceOf(Help::class, Help::new());
        self::assertInstanceOf(Tabs::class, Tabs::new(['a']));
    }
}
