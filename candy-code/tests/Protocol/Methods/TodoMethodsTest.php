<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol\Methods;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Protocol\MethodRegistry;
use SugarCraft\Code\Protocol\Methods\TodoMethods;
use SugarCraft\Code\Protocol\Scope;

final class TodoMethodsTest extends TestCase
{
    public function testItRegistersExactlyTodoGetAsAReadOnlyMethod(): void
    {
        $registry = MethodRegistry::new();

        TodoMethods::register($registry);

        self::assertSame(['todo.get'], $registry->names());
        $spec = $registry->get('todo.get');
        self::assertNotNull($spec);
        self::assertSame(Scope::Read, $spec->scope);
        self::assertFalse($spec->sideEffects);
        self::assertNotSame('', $spec->description);
    }

    public function testRegisteringTwiceIsRefused(): void
    {
        $registry = MethodRegistry::new();
        TodoMethods::register($registry);

        $this->expectException(\Throwable::class);
        TodoMethods::register($registry);
    }
}
