<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use PHPUnit\Framework\TestCase;

/**
 * The README roster of libraries that consume `ColorProfile` directly claims it
 * is derived from `use SugarCraft\ColorProfile\` imports in the monorepo's
 * production `src/` trees. This guard is the other half of that claim: it
 * re-derives the set from every sibling package present on disk and fails
 * BIDIRECTIONALLY — a real importer missing from the roster reddens the
 * `unexpected` leg, a roster entry that stopped importing reddens the `missing`
 * leg. Scanning every sibling (not just the roster) is also what proves the
 * README's sharper sentence: candy-code touches the library only under its own
 * `tests/`, so its `src/` stays out of the derived set.
 *
 * Split-repo safety: when the checkout is not the monorepo (no sibling trees at
 * all) the roster is unverifiable and the test skips. Any sibling that IS
 * present is counted, so a partial monorepo still polices every package it
 * ships; only the completeness of the five named consumers is relaxed there.
 */
final class ConsumerRosterDriftTest extends TestCase
{
    /**
     * Packages whose production `src/` imports the library, as documented in the
     * README's `ColorProfile` section. Keep in lockstep with that sentence.
     */
    private const ROSTER = ['candy-mdview', 'sugar-image', 'sugar-log', 'sugar-spring', 'sugar-video'];

    public function testTheDerivedConsumerSetMatchesTheDocumentedRoster(): void
    {
        $root = \dirname(__DIR__, 2);
        if (!\is_dir($root . '/sugar-core')) {
            $this->markTestSkipped('Not running inside the monorepo checkout; no sibling trees to scan.');
        }

        $derived = [];
        $selfDir = \dirname(__DIR__);
        foreach ((array) \scandir($root) as $entry) {
            if ($entry === '' || $entry[0] === '.') {
                continue;
            }

            if ($root . '/' . $entry === $selfDir) {
                continue; // The library's own src/ trivially imports its namespace.
            }

            $srcDir = $root . '/' . $entry . '/src';
            if (!\is_dir($srcDir)) {
                // Only package dirs (with a production src/ tree) are scanned;
                // docs/tools/scripts and other strays carry no imports.
                continue;
            }

            if ($this->srcImportsColorProfile($srcDir)) {
                $derived[] = $entry;
            }
        }

        $unexpected = \array_values(\array_diff($derived, self::ROSTER));
        $missing = \array_values(\array_diff(self::ROSTER, $derived));

        self::assertSame([], $unexpected, 'Siblings import SugarCraft\ColorProfile in production src/ but are absent from the README roster: ' . \implode(', ', $unexpected) . ' — update the roster or the import.');
        self::assertSame([], $missing, 'The README roster names consumers that no longer import SugarCraft\ColorProfile in their production src/: ' . \implode(', ', $missing) . ' — update the roster or restore the usage.');
    }

    /**
     * True when any PHP file under `src/` carries a top-level `use` statement for
     * the library namespace. Anchored at line start so prose citations inside
     * docblocks (`{@see SugarCraft\ColorProfile\...}`) never count as imports.
     */
    private function srcImportsColorProfile(string $srcDir): bool
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = \file_get_contents($file->getPathname());
            if ($contents !== false && \preg_match('/^use SugarCraft\\\\ColorProfile\\\\/m', $contents) === 1) {
                return true;
            }
        }

        return false;
    }
}
