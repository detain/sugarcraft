<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Module;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg;
use SugarCraft\Dashboard\Module\LegacyModule;
use SugarCraft\Dashboard\Module\LegacyModuleAdapter;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Dashboard\Msg\AdapterTickMsg;
use SugarCraft\Dashboard\Module\Module;

/**
 * Tests for LegacyModuleAdapter.
 *
 * Verifies that old-style LegacyModule implementations are correctly
 * wrapped to satisfy the new Module interface.
 */
final class LegacyModuleAdapterTest extends TestCase
{
    public function testWrapsLegacyModuleAsModule(): void
    {
        $legacy = new class implements LegacyModule {
            public function name(): string { return 'legacy-test'; }
            public function init(): array { return ['name' => 'legacy-test', 'interval' => 0]; }
            public function update(array $state): array { $state['count'] = ($state['count'] ?? 0) + 1; return $state; }
            public function view(array $state, int $_width, int $_height): string { return "count: {$state['count']}"; }
            public function minSize(): array { return [10, 3]; }
        };

        $adapter = LegacyModuleAdapter::from(fn() => $legacy);

        $this->assertInstanceOf(Module::class, $adapter);
        $this->assertInstanceOf(LegacyModuleAdapter::class, $adapter);
        $this->assertSame('legacy-test', $adapter->name());
        $this->assertSame([10, 3], $adapter->minSize());
    }

    public function testInitReturnsNull(): void
    {
        $legacy = new class implements LegacyModule {
            public function name(): string { return 'test'; }
            public function init(): array { return ['name' => 'test', 'interval' => 1]; }
            public function update(array $state): array { return $state; }
            public function view(array $state, int $_width, int $_height): string { return 'hello'; }
            public function minSize(): array { return [20, 4]; }
        };

        $adapter = LegacyModuleAdapter::from(fn() => $legacy);

        $this->assertNull($adapter->init());
    }

    public function testUpdateWithMsgReturnsArrayWithModuleAndNull(): void
    {
        $legacy = new class implements LegacyModule {
            public function name(): string { return 'test'; }
            public function init(): array { return ['name' => 'test', 'interval' => 0]; }
            public function update(array $state): array { $state['x'] = 42; return $state; }
            public function view(array $state, int $_width, int $_height): string { return "x={$state['x']}"; }
            public function minSize(): array { return [10, 3]; }
        };

        $adapter = LegacyModuleAdapter::from(fn() => $legacy);
        $msg = new class implements Msg {};

        $result = $adapter->update($msg);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        [$nextModule, $cmd] = $result;
        $this->assertInstanceOf(Module::class, $nextModule);
        $this->assertNull($cmd);
    }

    public function testViewDelegatesToLegacyViewWithState(): void
    {
        $legacy = new class implements LegacyModule {
            public function name(): string { return 'test'; }
            public function init(): array { return ['name' => 'test', 'interval' => 0]; }
            public function update(array $state): array { $state['value'] = 'updated'; return $state; }
            public function view(array $state, int $_width, int $_height): string { return "value={$state['value']}"; }
            public function minSize(): array { return [10, 3]; }
        };

        $adapter = LegacyModuleAdapter::from(fn() => $legacy);

        // Only the adapter's own refresh tick advances the plugin — the
        // blanket every-msg policy was re-judged (see the gate test below).
        $adapter->update(new AdapterTickMsg());

        // View should reflect the state
        $view = $adapter->view();
        $this->assertStringContainsString('updated', $view);
    }

    public function testUpdateAccumulatesState(): void
    {
        $counter = 0;
        $legacy = new class($counter) implements LegacyModule {
            private int $counter;
            public function __construct(int &$counter) { $this->counter =& $counter; }
            public function name(): string { return 'counter'; }
            public function init(): array { return ['name' => 'counter', 'interval' => 0]; }
            public function update(array $state): array { $this->counter++; $state['count'] = $this->counter; return $state; }
            public function view(array $state, int $_width, int $_height): string { return "count={$state['count']}"; }
            public function minSize(): array { return [10, 3]; }
        };

        $adapter = LegacyModuleAdapter::from(fn() => $legacy);

        // First tick
        [$next1] = $adapter->update(new AdapterTickMsg());
        $view1 = $next1->view();

        // Second tick
        [$next2] = $next1->update(new AdapterTickMsg());
        $view2 = $next2->view();

        // Third tick
        [$next3] = $next2->update(new AdapterTickMsg());
        $view3 = $next3->view();

        $this->assertStringContainsString('count=1', $view1);
        $this->assertStringContainsString('count=2', $view2);
        $this->assertStringContainsString('count=3', $view3);
    }

    /**
     * This test used to pin the OPPOSITE policy — "every message advances
     * the legacy module exactly once" — as a documented contract. That
     * contract is now re-judged and rejected: LegacyModule::update() drives
     * a synchronous write+read round-trip against the plugin child, so
     * advancing on every message made EVERY routed keystroke block the
     * whole UI on a child process round-trip.
     *
     * The adapter now advances only on its own refresh tick (produced by
     * the interval subscription it advertises) and on window resizes
     * (which refresh too, since the plugin renders to the new grid).
     * Unrelated messages are passed through the reducer untouched.
     */
    public function testOnlyRefreshTicksAndResizesAdvanceTheLegacyModule(): void
    {
        $calls = 0;
        $legacy = new class($calls) implements LegacyModule {
            private int $calls;
            public function __construct(int &$calls) { $this->calls =& $calls; }
            public function name(): string { return 'any-msg'; }
            public function init(): array { return ['name' => 'any-msg', 'interval' => 0]; }
            public function update(array $state): array { $this->calls++; return $state; }
            public function view(array $state, int $_width, int $_height): string { return ''; }
            public function minSize(): array { return [10, 3]; }
        };

        $adapter = LegacyModuleAdapter::from(fn() => $legacy);

        $unrelated = new class implements Msg {};
        [$next, $cmd] = $adapter->update($unrelated);
        $this->assertSame($adapter, $next, 'unrelated messages must not even copy the adapter');
        $this->assertNull($cmd);
        $this->assertSame(0, $calls, 'an unrelated message must not block on a child round-trip');

        [$next] = $adapter->update(new AdapterTickMsg());
        $this->assertSame(1, $calls);

        [$next] = $next->update(new WindowSizeMsg(120, 40));
        $this->assertSame(2, $calls, 'a resize re-renders against the new grid, so it advances');
    }

    public function testMinSizeDelegatesToLegacy(): void
    {
        $legacy = new class implements LegacyModule {
            public function name(): string { return 'test'; }
            public function init(): array { return ['name' => 'test', 'interval' => 0]; }
            public function update(array $state): array { return $state; }
            public function view(array $state, int $_width, int $_height): string { return ''; }
            public function minSize(): array { return [50, 10]; }
        };

        $adapter = LegacyModuleAdapter::from(fn() => $legacy);

        $this->assertSame([50, 10], $adapter->minSize());
    }

    public function testNameDelegatesToLegacy(): void
    {
        $legacy = new class implements LegacyModule {
            public function name(): string { return 'my-legacy-module'; }
            public function init(): array { return ['name' => 'my-legacy-module', 'interval' => 0]; }
            public function update(array $state): array { return $state; }
            public function view(array $state, int $_width, int $_height): string { return ''; }
            public function minSize(): array { return [20, 4]; }
        };

        $adapter = LegacyModuleAdapter::from(fn() => $legacy);

        $this->assertSame('my-legacy-module', $adapter->name());
    }

    /**
     * The handshake used to run TWICE — once eagerly inside from() (whose
     * result was thrown away, orphaning the spawned child) and again in
     * init(). Construction must be side-effect free; init() is the single
     * handshake point.
     */
    public function testConstructionRunsNoHandshakeAndInitRunsExactlyOne(): void
    {
        $inits = 0;
        $legacy = new class($inits) implements LegacyModule {
            private int $inits;
            public function __construct(int &$inits) { $this->inits =& $inits; }
            public function name(): string { return 'handshake'; }
            public function init(): array { $this->inits++; return ['name' => 'handshake', 'interval' => 0]; }
            public function update(array $state): array { return $state; }
            public function view(array $state, int $_width, int $_height): string { return ''; }
            public function minSize(): array { return [10, 3]; }
        };

        $adapter = LegacyModuleAdapter::from(fn() => $legacy);
        $this->assertSame(0, $inits, 'from() must not handshake');

        $adapter->init();
        $this->assertSame(1, $inits);

        $adapter->init();
        $this->assertSame(1, $inits, 'a second init must not spawn a second child');
    }

    /**
     * view() used to hardcode 80x24 forever. Real dimensions now ride in
     * via WindowSizeMsg, with 80x24 as the documented cold-start default.
     */
    public function testRealDimensionsReachTheLegacyView(): void
    {
        $legacy = new class implements LegacyModule {
            public function name(): string { return 'dims'; }
            public function init(): array { return ['name' => 'dims', 'interval' => 0]; }
            public function update(array $state): array { return $state; }
            public function view(array $state, int $width, int $height): string
            {
                return "$width x $height";
            }
            public function minSize(): array { return [10, 3]; }
        };

        $adapter = LegacyModuleAdapter::from(fn() => $legacy);
        $this->assertSame('80 x 24', $adapter->view(), 'cold start renders at the documented default');

        [$resized] = $adapter->update(new WindowSizeMsg(120, 40));
        $this->assertSame('120 x 40', $resized->view(), 'the plugin re-renders against the real grid');
    }

    /**
     * The interval a plugin advertises in its init meta was decoration —
     * nothing scheduled it. The adapter now turns it into a real tick
     * subscription.
     */
    public function testAdvertisedIntervalBecomesATickSubscription(): void
    {
        $legacy = new class implements LegacyModule {
            public function name(): string { return 'paced'; }
            public function init(): array { return ['name' => 'paced', 'interval' => 7]; }
            public function update(array $state): array { return $state; }
            public function view(array $state, int $_width, int $_height): string { return ''; }
            public function minSize(): array { return [10, 3]; }
        };

        $adapter = LegacyModuleAdapter::from(fn() => $legacy);
        $adapter->init();

        $subs = $adapter->subscriptions();
        $this->assertInstanceOf(Subscriptions::class, $subs);
        $this->assertTrue($subs->has('plugin-refresh'));

        $tick = null;
        foreach ($subs->all() as $subscription) {
            if ($subscription->id === 'plugin-refresh') {
                $tick = $subscription;
            }
        }
        $this->assertNotNull($tick);
        $this->assertSame(7.0, $tick->params['seconds'], 'the plugin-advertised interval is honoured');
        $this->assertInstanceOf(AdapterTickMsg::class, ($tick->produce)());
    }
}
