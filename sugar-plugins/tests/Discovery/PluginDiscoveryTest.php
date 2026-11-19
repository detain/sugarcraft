<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests\Discovery;

use PHPUnit\Framework\TestCase;
use SugarCraft\Plugins\Discovery\PluginDiscovery;
use SugarCraft\Plugins\Discovery\Root;
use SugarCraft\Plugins\Tests\Support\Scratch;

final class PluginDiscoveryTest extends TestCase
{
    /** A bundle is any directory holding a `plugin.json`; the read is the fake parse. */
    private static function scanner(array &$reads = [], bool $unreadable = false): PluginDiscovery
    {
        return new PluginDiscovery(
            static fn (string $dir): bool => is_file($dir . '/plugin.json'),
            static function (string $dir, string $tier, ?string $boundary) use (&$reads, $unreadable): ?object {
                $reads[] = [$dir, $tier, $boundary];

                if ($unreadable) {
                    return null;
                }

                return (object) ['name' => basename($dir), 'tier' => $tier, 'boundary' => $boundary];
            },
            static fn (string $dir, string $real, string $tier, string $boundary): string
                => 'refused:' . $dir . '|' . $real . '|' . $tier . '|' . $boundary,
        );
    }

    /** @return list<string> */
    private static function names(PluginDiscovery $scanner): array
    {
        return array_map(static fn (object $b): string => $b->name, $scanner->bundles());
    }

    public function testRootExposesItsDirectoryTierAndBoundary(): void
    {
        $root = new Root('/install', 'project', '/checkout');

        $this->assertSame('/install', $root->dir);
        $this->assertSame('project', $root->tier);
        $this->assertSame('/checkout', $root->boundary);
        $this->assertNull((new Root('/operator', 'user', null))->boundary);
    }

    public function testAFreshScannerHoldsNothing(): void
    {
        $scanner = self::scanner();

        $this->assertSame([], $scanner->bundles());
        $this->assertSame([], $scanner->warnings());
        $this->assertSame([], $scanner->refusedDirectories());
    }

    public function testAContainerRecordsItsManifestBearingChildrenInSortedOrder(): void
    {
        $container = Scratch::dir('pd-container');
        Scratch::put($container . '/beta/plugin.json', '{}');
        Scratch::put($container . '/alpha/plugin.json', '{}');
        Scratch::put($container . '/loose/README.md', 'no manifest here');
        Scratch::put($container . '/gamma/plugin.json', '{}');

        $scanner = self::scanner();
        $scanner->scan([new Root($container, 'user', null)]);

        $this->assertSame(['alpha', 'beta', 'gamma'], self::names($scanner));
        $this->assertSame([], $scanner->warnings(), 'a directory without a manifest is passed over quietly');

        Scratch::remove($container);
    }

    public function testARootThatCarriesAManifestIsOneBundleNotAContainer(): void
    {
        $bundle = Scratch::dir('pd-single');
        Scratch::put($bundle . '/plugin.json', '{}');
        Scratch::put($bundle . '/nested/plugin.json', '{}');

        $scanner = self::scanner();
        $scanner->scan([new Root($bundle, 'user', null)]);

        $this->assertSame([basename($bundle)], self::names($scanner));

        Scratch::remove($bundle);
    }

    public function testAMissingOrNonDirectoryRootIsSkippedSilently(): void
    {
        $scanner = self::scanner();
        $scanner->scan([new Root('/no/such/discovery/root', 'project', '/anchor')]);

        $this->assertSame([], $scanner->bundles());
        $this->assertSame([], $scanner->warnings());
    }

    public function testAnUnreadableManifestIsWarnedAboutByDirectory(): void
    {
        $container = Scratch::dir('pd-broken');
        Scratch::put($container . '/broken/plugin.json', 'not json');

        $scanner = self::scanner(unreadable: true);
        $scanner->scan([new Root($container, 'user', null)]);

        $this->assertSame([], $scanner->bundles());
        $this->assertCount(1, $scanner->warnings());
        $this->assertStringContainsString('broken', $scanner->warnings()[0]);
        $this->assertStringContainsString('could not be read or parsed', $scanner->warnings()[0]);

        Scratch::remove($container);
    }

    public function testABundleResolvingOutsideItsBoundaryIsRefusedByNameAsGiven(): void
    {
        $inside = Scratch::dir('pd-inside');
        $outside = Scratch::dir('pd-outside');
        Scratch::put($outside . '/plugin.json', '{}');
        $link = realpath($inside) . '/escape';
        symlink($outside, $link);

        $scanner = self::scanner();
        $scanner->scan([new Root($inside, 'project', $inside)]);

        $this->assertSame([], $scanner->bundles(), 'a link may not import a tree from outside its boundary');
        $this->assertSame([$link], array_keys($scanner->refusedDirectories()), 'keyed by the directory as given');
        $this->assertSame(
            'refused:' . $link . '|' . realpath($outside) . '|project|' . $inside,
            $scanner->refusedDirectories()[$link],
            'the refusal reason is the host callable\'s, fed the real path and the boundary',
        );
        $this->assertCount(1, $scanner->warnings());
        $this->assertStringContainsString('resolves outside the project tree', $scanner->warnings()[0]);

        unlink($link);
        Scratch::remove($inside);
        Scratch::remove($outside);
    }

    public function testAContainedSymlinkedBundleIsRecordedUnderItsRealPath(): void
    {
        $boundary = Scratch::dir('pd-bound');
        $target = $boundary . '/real-deal';
        Scratch::put($target . '/plugin.json', '{}');
        symlink($target, $boundary . '/alias');

        $reads = [];
        $scanner = self::scanner($reads);
        $scanner->scan([new Root($boundary, 'project', $boundary)]);

        $this->assertSame(['real-deal', 'real-deal'], self::names($scanner), 'the alias and the target both record the real path');
        $this->assertSame([realpath($target), realpath($target)], array_column($reads, 0));
        $this->assertSame([], $scanner->refusedDirectories());

        unlink($boundary . '/alias');
        Scratch::remove($boundary);
    }

    public function testTheBoundaryIsHandedToTheReaderOnlyWhenPresent(): void
    {
        $container = Scratch::dir('pd-boundary-arg');
        Scratch::put($container . '/one/plugin.json', '{}');

        $reads = [];
        $scanner = self::scanner($reads);
        $scanner->scan([new Root($container, 'project', $container), new Root($container, 'user', null)]);

        $this->assertSame('project', $reads[0][1]);
        $this->assertSame(realpath($container), realpath($reads[0][2]));
        $this->assertSame('user', $reads[1][1]);
        $this->assertNull($reads[1][2]);

        Scratch::remove($container);
    }

    public function testRootsScanInTheOrderTheyAreGiven(): void
    {
        $first = Scratch::dir('pd-first');
        $second = Scratch::dir('pd-second');
        Scratch::put($first . '/zeta/plugin.json', '{}');
        Scratch::put($second . '/alpha/plugin.json', '{}');

        $scanner = self::scanner();
        $scanner->scan([new Root($first, 'project', null), new Root($second, 'user', null)]);

        $this->assertSame(['zeta', 'alpha'], self::names($scanner));

        Scratch::remove($first);
        Scratch::remove($second);
    }

    public function testWarnAppendsInOrderWithScanWarnings(): void
    {
        $container = Scratch::dir('pd-warn-order');
        Scratch::put($container . '/bad/plugin.json', 'x');

        $scanner = self::scanner(unreadable: true);
        $scanner->addWarning('plugin path "/first" is not a directory and was skipped');
        $scanner->scan([new Root($container, 'user', null)]);
        $scanner->addWarning('plugin path "/last" is not a directory and was skipped');

        $warnings = $scanner->warnings();
        $this->assertCount(3, $warnings);
        $this->assertStringContainsString('/first', $warnings[0]);
        $this->assertStringContainsString('could not be read', $warnings[1]);
        $this->assertStringContainsString('/last', $warnings[2]);

        Scratch::remove($container);
    }

    public function testResetClearsEveryBundleWarningAndRefusal(): void
    {
        $container = Scratch::dir('pd-reset');
        Scratch::put($container . '/good/plugin.json', '{}');

        $scanner = self::scanner();
        $scanner->addWarning('stale');
        $scanner->scan([new Root($container, 'user', null)]);
        $this->assertSame(['good'], self::names($scanner));
        $this->assertSame(['stale'], $scanner->warnings());

        $scanner->reset();

        $this->assertSame([], $scanner->bundles());
        $this->assertSame([], $scanner->warnings());
        $this->assertSame([], $scanner->refusedDirectories());

        Scratch::remove($container);
    }
}
