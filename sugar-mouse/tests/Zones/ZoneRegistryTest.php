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
 * instance. Registries never share zones; every ZoneRegistry::new() registry
 * draws auto prefixes from one process-wide counter (the guarantee the old
 * static Manager::newPrefix() gave), while ZoneRegistry::isolated() keeps a
 * counter of its own. The facade routes through one process registry.
 */
final class ZoneRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        Zones::setRegistry(null);
    }

    public function testTwoDefaultRegistriesNeverHandOutTheSamePrefix(): void
    {
        $a = ZoneRegistry::new();
        $b = ZoneRegistry::new();

        $issued = [
            $a->newPrefix()->prefix(),
            $b->newPrefix()->prefix(),
            $a->newPrefix()->prefix(),
            $b->newPrefix()->prefix(),
            Manager::newPrefix()->prefix(),
        ];
        self::assertSame($issued, array_values(array_unique($issued)), 'one process-wide counter behind every new() registry');
    }

    public function testDefaultRegistryManagersNeverAbsorbEachOthersZones(): void
    {
        $a = ZoneRegistry::new()->newPrefix();
        $b = ZoneRegistry::new()->newPrefix();

        $b->scan($a->mark('x', 'X'));

        self::assertNull($b->get('x'), 'a manager from another default registry must not claim the zone');
    }

    public function testANumericPrefixReservedInOneDefaultRegistryIsSkippedByAnother(): void
    {
        $a = ZoneRegistry::new();
        $b = ZoneRegistry::new();
        $next = (int) rtrim($a->newPrefix()->prefix(), '-') + 1;

        $b->newPrefix($next . '-');

        self::assertSame(($next + 1) . '-', $a->newPrefix()->prefix());
    }

    public function testTwoIsolatedRegistriesKeepPerRegistryPrefixCounters(): void
    {
        $a = ZoneRegistry::isolated();
        $b = ZoneRegistry::isolated();

        self::assertSame('1-', $a->newPrefix()->prefix());
        self::assertSame('2-', $a->newPrefix()->prefix());
        self::assertSame('1-', $b->newPrefix()->prefix(), 'documented: an isolated counter is unique within its registry only');
    }

    public function testAnIsolatedRegistryLeavesTheProcessCounterAlone(): void
    {
        $before = (int) rtrim(ZoneRegistry::new()->newPrefix()->prefix(), '-');
        ZoneRegistry::isolated()->newPrefix('500000-');

        self::assertSame(($before + 1) . '-', ZoneRegistry::new()->newPrefix()->prefix());
    }

    public function testExplicitNumericPrefixIsReservedWithinTheRegistry(): void
    {
        $registry = ZoneRegistry::isolated();

        self::assertSame('5-', $registry->newPrefix('5-')->prefix());
        self::assertSame('6-', $registry->newPrefix()->prefix());
        self::assertSame('acme-', $registry->newPrefix('acme-')->prefix());
        self::assertSame('7-', $registry->newPrefix()->prefix(), 'a non-numeric prefix leaves the counter alone');
    }

    public function testAnUnreachablyLargeNumericPrefixLeavesTheAutoCounterUsable(): void
    {
        $registry = ZoneRegistry::isolated();
        $registry->newPrefix('2-');

        self::assertSame('99999999999999999999-', $registry->newPrefix('99999999999999999999-')->prefix());
        self::assertSame('3-', $registry->newPrefix()->prefix(), 'the auto counter neither overflows nor jumps');
        self::assertSame('999999999999999999-', $registry->newPrefix('999999999999999999-')->prefix());
        self::assertSame('1000000000000000000-', $registry->newPrefix()->prefix(), 'an 18-digit prefix is still reserved');
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
        $registry = ZoneRegistry::isolated();
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

    public function testResettingTheProcessRegistryDropsZonesButNeverReissuesAPrefix(): void
    {
        Zones::setRegistry(ZoneRegistry::isolated());
        Zones::defaultManager()->scan(Mark::zone('stale', 'S'));
        $before = Manager::newPrefix();

        Zones::setRegistry(null);

        self::assertNull(Zones::get('stale'));
        $after = Manager::newPrefix();
        self::assertSame('1-', $before->prefix());
        self::assertSame('2-', $after->prefix(), 'a reset keeps the prefix counter monotonic');

        // The pre-reset manager's zones must not register in the post-reset one.
        $after->scan($before->mark('x', 'X'));
        self::assertNull($after->get('x'));
    }

    public function testSuccessorCarriesTheCounterButNotTheDefaultManager(): void
    {
        $registry = ZoneRegistry::isolated();
        $registry->newPrefix('4-');
        $registry->defaultManager()->scan(Mark::zone('x', 'X'));

        $next = $registry->successor();

        self::assertSame('5-', $next->newPrefix()->prefix());
        self::assertNull($next->defaultManager()->get('x'));
        self::assertSame('5-', $registry->newPrefix()->prefix(), 'the old registry keeps its own counter');
    }

    public function testADefaultRegistrySuccessorKeepsDrawingFromTheProcessCounter(): void
    {
        $registry = ZoneRegistry::new();
        $first = $registry->newPrefix()->prefix();

        $next = $registry->successor();
        $issued = [$first, $next->newPrefix()->prefix(), $registry->newPrefix()->prefix()];

        self::assertSame($issued, array_values(array_unique($issued)));
    }

    public function testNewWithIdPrefixDoesNoCounterBookkeeping(): void
    {
        Zones::setRegistry(ZoneRegistry::isolated());

        self::assertSame('9-', Manager::newWithIdPrefix('9-')->prefix());
        self::assertSame('1-', Manager::newPrefix()->prefix(), 'the raw factory reserves nothing');
        self::assertSame('', Manager::newWithIdPrefix('')->prefix());
    }

    /**
     * The registry is the point: no class of the Zones layer may grow new
     * static state. The facade's registry slot and the one process-wide
     * prefix counter are the only exceptions.
     */
    public function testTheRegistrySlotAndProcessCounterAreTheOnlyStaticState(): void
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

        sort($statics);
        self::assertSame(
            [ZoneRegistry::class . '::$processPrefixCounter', Zones::class . '::$registry'],
            array_values(array_unique($statics)),
        );
    }
}
