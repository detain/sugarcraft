<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Plugins\Host\HostServices;
use SugarCraft\Plugins\Host\RootedHostServices;

/**
 * The root forms of the two calls that need a root argument (PL-5c-A, section 2.6). The interface is
 * additive: HostServices keeps every signature, and a bundle reaches a root only through this interface.
 */
final class RootedHostServicesTest extends TestCase
{
    public function testItIsAnInterfaceSeparateFromHostServices(): void
    {
        $rooted = new \ReflectionClass(RootedHostServices::class);

        self::assertTrue($rooted->isInterface());
        self::assertFalse($rooted->isSubclassOf(HostServices::class), 'the root forms are not part of the HostServices contract');
        self::assertFalse(
            (new \ReflectionClass(HostServices::class))->implementsInterface(RootedHostServices::class),
            'a bundle checks instanceof RootedHostServices, so HostServices must not carry it',
        );
    }

    public function testItCarriesExactlyTheTwoRootForms(): void
    {
        $methods = \array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(RootedHostServices::class))->getMethods(),
        );
        \sort($methods);

        self::assertSame(['createSessionIn', 'pendingPermissionsIn'], $methods);
    }

    public function testCreateSessionInTakesARootAndTheCreateArgumentsAndAnswersAnArray(): void
    {
        $method = new \ReflectionMethod(RootedHostServices::class, 'createSessionIn');
        $params = $method->getParameters();

        self::assertSame(['root', 'name', 'permissionMode'], \array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $params));
        self::assertSame('string', (string) $params[0]->getType());
        self::assertTrue($params[1]->getType()?->allowsNull() ?? false, 'name is nullable, as createSession() has it');
        self::assertTrue($params[2]->getType()?->allowsNull() ?? false, 'permissionMode is nullable, as createSession() has it');
        self::assertSame(PromiseInterface::class, (string) $method->getReturnType());
    }

    public function testPendingPermissionsInTakesOnlyARoot(): void
    {
        $method = new \ReflectionMethod(RootedHostServices::class, 'pendingPermissionsIn');

        self::assertSame(['root'], \array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $method->getParameters()));
        self::assertSame('string', (string) $method->getParameters()[0]->getType());
        self::assertSame(PromiseInterface::class, (string) $method->getReturnType());
    }
}
