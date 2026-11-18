<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Plugins\Plugin;
use SugarCraft\Plugins\PluginContext;
use SugarCraft\Plugins\Registry\PluginRegistry;

final class PluginRegistryTest extends TestCase
{
    /** A plugin that records its lifecycle into $events. */
    private function plugin(string $name, array &$events, bool $throwOnDispose = false): Plugin
    {
        return new class ($name, $events, $throwOnDispose) implements Plugin {
            /** @param list<string> $events */
            public function __construct(
                private readonly string $pluginName,
                private array &$events,
                private readonly bool $throwOnDispose,
            ) {
            }

            public function name(): string
            {
                return $this->pluginName;
            }

            public function start(PluginContext $context): void
            {
                $this->events[] = 'start:' . $this->pluginName;
            }

            public function dispose(): void
            {
                $this->events[] = 'dispose:' . $this->pluginName;
                if ($this->throwOnDispose) {
                    throw new \RuntimeException('dispose failed for ' . $this->pluginName);
                }
            }
        };
    }

    public function testARegisteredPluginIsFoundByItsName(): void
    {
        $events = [];
        $registry = PluginRegistry::new();
        $plugin = $this->plugin('alpha', $events);

        $this->assertTrue($registry->register('demo', $plugin));

        $this->assertSame($plugin, $registry->pluginNamed('alpha'));
        $this->assertNull($registry->pluginNamed('missing'));
    }

    public function testACollisionIsRefusedAndNeverOverridden(): void
    {
        $events = [];
        $registry = PluginRegistry::new();
        $first = $this->plugin('alpha', $events);
        $second = $this->plugin('alpha', $events);
        $registry->register('one', $first);

        $accepted = $registry->register('two', $second, $reason);

        $this->assertFalse($accepted);
        $this->assertSame('collision', $reason);
        $this->assertSame($first, $registry->pluginNamed('alpha'));
    }

    public function testABlankNameIsRefusedAsAnInvalidNameNotACollision(): void
    {
        $events = [];
        $registry = PluginRegistry::new();

        $accepted = $registry->register('one', $this->plugin('  ', $events), $reason);

        $this->assertFalse($accepted);
        $this->assertSame('invalid-name', $reason);
        $this->assertNull($registry->pluginNamed(''));

        $this->assertFalse($registry->register('two', $this->plugin('', $events), $reason));
        $this->assertSame('invalid-name', $reason, 'a second blank name must not be refused as a collision');
    }

    public function testANameOutsideTheRouteSlugGrammarIsRefusedAsInvalidName(): void
    {
        $events = [];

        foreach (['my channel', 'a/b', 'b|c', '-lead', '.dot', "tab\there"] as $name) {
            $registry = PluginRegistry::new();

            $this->assertFalse($registry->register('demo', $this->plugin($name, $events), $reason), $name);
            $this->assertSame('invalid-name', $reason, $name);
            $this->assertNull($registry->pluginNamed($name), $name);
        }
    }

    public function testABundleAndNameSeparatorCannotForgeAnotherPluginsKey(): void
    {
        $events = [];
        $registry = PluginRegistry::new();
        $first = $this->plugin('a', $events);
        $this->assertTrue($registry->register('a', $first));

        // With the grammar refusing '|' in a name, the key "a|b|c" can only mean
        // bundle "a|b" and name "c", so no second plugin can overwrite the first.
        $this->assertFalse($registry->register('a', $this->plugin('b|c', $events), $reason));
        $this->assertSame('invalid-name', $reason);
        $this->assertTrue($registry->register('a|b', $second = $this->plugin('c', $events), $reason));
        $this->assertNull($reason);

        $this->assertSame($first, $registry->pluginNamed('a'));
        $this->assertSame($second, $registry->pluginNamed('c'));
        $this->assertNull($registry->pluginNamed('b|c'));
    }

    public function testAReservedBundleIsRefused(): void
    {
        $events = [];
        $registry = PluginRegistry::new(['candy-code', 'builtin']);

        $accepted = $registry->register('candy-code', $this->plugin('shadow', $events), $reason);

        $this->assertFalse($accepted);
        $this->assertSame('reserved-namespace', $reason);
        $this->assertNull($registry->pluginNamed('shadow'));
    }

    public function testAnObjectThatIsNotAPluginIsRefused(): void
    {
        $registry = PluginRegistry::new();

        $accepted = $registry->register('demo', new \stdClass(), $reason);

        $this->assertFalse($accepted);
        $this->assertSame('not-a-plugin', $reason);
    }

    public function testAnAcceptedRegistrationClearsTheReasonWord(): void
    {
        $events = [];
        $registry = PluginRegistry::new();

        $this->assertTrue($registry->register('demo', $this->plugin('clean', $events), $reason));
        $this->assertNull($reason);
    }

    public function testUnregisterRemovesTheName(): void
    {
        $events = [];
        $registry = PluginRegistry::new();
        $registry->register('demo', $this->plugin('gone', $events));

        $registry->unregister('demo', 'gone');

        $this->assertNull($registry->pluginNamed('gone'));
        $this->assertTrue($registry->register('demo', $this->plugin('gone', $events)), 'the name is free again');
    }

    public function testAnotherBundlesUnregisterNeverFreesAForeignName(): void
    {
        $events = [];
        $registry = PluginRegistry::new();
        $owner = $this->plugin('foo', $events);
        $this->assertTrue($registry->register('bundleA', $owner));

        $registry->unregister('bundleB', 'foo');

        $this->assertSame($owner, $registry->pluginNamed('foo'), 'the name still belongs to bundleA');
        $this->assertFalse($registry->register('bundleC', $this->plugin('foo', $events), $reason));
        $this->assertSame('collision', $reason);
    }

    public function testStartedNamesAreInStartOrder(): void
    {
        $events = [];
        $registry = PluginRegistry::new();
        foreach (['a', 'b', 'c'] as $name) {
            $plugin = $this->plugin($name, $events);
            $registry->register('demo', $plugin);
            $registry->markStarted($plugin);
        }

        $this->assertSame(['a', 'b', 'c'], $registry->startedNames());
    }

    public function testDisposeAllRunsInReverseStartOrder(): void
    {
        $events = [];
        $registry = PluginRegistry::new();
        foreach (['a', 'b', 'c'] as $name) {
            $plugin = $this->plugin($name, $events);
            $registry->register('demo', $plugin);
            $registry->markStarted($plugin);
        }

        $registry->disposeAll(static function (string $line): void {
        });

        $this->assertSame(['dispose:c', 'dispose:b', 'dispose:a'], array_values(array_filter(
            $events,
            static fn (string $e): bool => str_starts_with($e, 'dispose:'),
        )));
    }

    public function testAThrowingDisposeIsLoggedAndSwallowedAndTheRestStillDispose(): void
    {
        $events = [];
        $lines = [];
        $registry = PluginRegistry::new();
        $first = $this->plugin('first', $events);
        $faulty = $this->plugin('faulty', $events, throwOnDispose: true);
        $registry->register('demo', $first);
        $registry->register('demo', $faulty);
        $registry->markStarted($first);
        $registry->markStarted($faulty);

        $registry->disposeAll(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $this->assertContains('dispose:first', $events, 'a fault must not strand the other plugin');
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('plugin dispose faulty', $lines[0]);
        $this->assertStringContainsString('dispose failed for faulty', $lines[0]);
    }

    public function testDisposeAllIsIdempotent(): void
    {
        $events = [];
        $registry = PluginRegistry::new();
        $plugin = $this->plugin('once', $events);
        $registry->register('demo', $plugin);
        $registry->markStarted($plugin);

        $registry->disposeAll(static function (string $line): void {
        });
        $registry->disposeAll(static function (string $line): void {
        });

        $this->assertSame(['dispose:once'], $events);
        $this->assertSame([], $registry->startedNames());
        $this->assertNull($registry->pluginNamed('once'));
    }
}
