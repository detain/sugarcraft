<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Tests\Zones;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Zones\Manager;
use SugarCraft\Mouse\Zones\ZoneRegistry;
use SugarCraft\Mouse\Zones\Zones;

/**
 * P2-8 (c): the Zones layer's static defaults (the facade's default manager
 * and Manager's auto-prefix counter) now live in a {@see ZoneRegistry}
 * instance. Two registries share nothing; the facade routes through one
 * process registry, the only static slot left.
 */
final class ZoneRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        Zones::setRegistry(null);
    }

    public function testTwoRegistriesKeepIndependentPrefixCounters(): void
    {
        $a = ZoneRegistry::new();
        $b = ZoneRegistry::new();

        self::assertSame('1-', $a->newPrefix()->prefix());
        self::assertSame('2-', $a->newPrefix()->prefix());
        self::assertSame('1-', $b->newPrefix()->prefix(), 'b never saw a\'s numbers');
    }

    public function testExplicitNumericPrefixIsReservedWithinTheRegistry(): void
    {
        $registry = ZoneRegistry::new();

        self::assertSame('5-', $registry->newPrefix('5-')->prefix());
        self::assertSame('6-', $registry->newPrefix()->prefix());
        self::assertSame('acme-', $registry->newPrefix('acme-')->prefix());
        self::assertSame('7-', $registry->newPrefix()->prefix(), 'a non-numeric prefix leaves the counter alone');
    }

    public function testDefaultManagerIsPerRegistryAndLazilyRebuilt(): void
    {
        $a = ZoneRegistry::new();
        $b = ZoneRegistry::new();

        self::assertSame($a->defaultManager(), $a->defaultManager());
        self::assertNotSame($a->defaultManager(), $b->defaultManager());

        $a->defaultManager()->scan(Mark::zone('x', 'X'));
        self::assertNotNull($a->defaultManager()->get('x'));
        self::assertNull($b->defaultManager()->get('x'), 'zones never leak between registries');

        $custom = Manager::newPrefix('c-');
        $a->setDefaultManager($custom);
        self::assertSame($custom, $a->defaultManager());

        $a->setDefaultManager(null);
        self::assertNotSame($custom, $a->defaultManager());
        self::assertSame([], $a->defaultManager()->all());
        self::assertSame('', $a->defaultManager()->prefix());
    }

    public function testNewUnprefixedIsIndependentOfTheDefault(): void
    {
        $registry = ZoneRegistry::new();

        self::assertSame('', $registry->newUnprefixed()->prefix());
        self::assertNotSame($registry->defaultManager(), $registry->newUnprefixed());
    }

    public function testFacadeAndStaticNewPrefixRouteThroughTheProcessRegistry(): void
    {
        $registry = ZoneRegistry::new();
        Zones::setRegistry($registry);

        self::assertSame($registry, Zones::registry());
        self::assertSame($registry->defaultManager(), Zones::defaultManager());
        self::assertSame('1-', Manager::newPrefix()->prefix());
        self::assertSame('2-', Zones::newPrefix()->prefix(), 'one counter behind both static doors');
        self::assertSame('3-', $registry->newPrefix()->prefix());

        $manager = Manager::newUnprefixed();
        Zones::setDefaultManager($manager);
        self::assertSame($manager, $registry->defaultManager());
    }

    public function testResettingTheProcessRegistryStartsFresh(): void
    {
        Zones::defaultManager()->scan(Mark::zone('stale', 'S'));
        Manager::newPrefix();

        Zones::setRegistry(null);

        self::assertNull(Zones::get('stale'));
        self::assertSame('1-', Manager::newPrefix()->prefix());
    }

    public function testNewWithIdPrefixDoesNoCounterBookkeeping(): void
    {
        Zones::setRegistry(ZoneRegistry::new());

        self::assertSame('9-', Manager::newWithIdPrefix('9-')->prefix());
        self::assertSame('1-', Manager::newPrefix()->prefix(), 'the raw factory reserves nothing');
        self::assertSame('', Manager::newWithIdPrefix('')->prefix());
    }

    /**
     * The registry is the point: no class of the Zones layer may grow new
     * static state. The facade's registry slot is the single exception.
     */
    public function testTheFacadeRegistrySlotIsTheOnlyStaticState(): void
    {
        $statics = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/{,Zones/,Zones/Msg/}*.php', GLOB_BRACE) ?: [] as $file) {
            $class = 'SugarCraft\\Mouse\\' . str_replace(
                ['/', '.php'],
                ['\\', ''],
                substr($file, strlen(dirname(__DIR__, 2) . '/src/')),
            );
            if (!class_exists($class) && !interface_exists($class) && !enum_exists($class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            foreach ($reflection->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
                if ($property->getDeclaringClass()->getName() !== $reflection->getName()) {
                    continue; // inherited (Lang's sugar-core base), not this package's
                }
                $statics[] = $reflection->getName() . '::$' . $property->getName();
            }
        }

        self::assertSame([Zones::class . '::$registry'], array_values(array_unique($statics)));
    }
}
