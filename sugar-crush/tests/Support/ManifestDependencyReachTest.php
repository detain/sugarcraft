<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every production `sugarcraft/*` require is reached from `src/`, or the
 * reason it is not is recorded in the manifest.
 *
 * E453, AND THE FINDING IS NOT THE DEPENDENCY. `sugar-crush` requires
 * `sugarcraft/candy-kit`, nothing in `src/` reaches it, and CI's
 * `path-repo-check` job has been RED on master over it - while the merge
 * checklist ran `check-path-repos.php --no-lib-path-repos`, got 0, and
 * concluded the tree was clean. Those two checks ask different questions, and
 * nothing local asked CI's. **A green local invariant that does not imply a
 * green CI one is worse than no local invariant**, because it is trusted.
 * This test is the missing half: it re-derives the same property CI's
 * `--unused` pass checks for this package, from the same two inputs, so the
 * suite every lane already runs reds at the same moment the build does.
 *
 * WHY NOT SHELL OUT TO THE TOOL. It would be one line and it would be a
 * different test: a subprocess spawned from a suite that guards spawns, whose
 * failure output is another program's, and whose verdict covers all 58 libs
 * rather than this one. What a per-package suite can own is the property of
 * ITS OWN manifest, which is exactly the row CI failed on.
 *
 * WHAT `candy-kit` TURNED OUT TO BE, because the next reader will want the
 * short version and the manifest carries the long one. Not spent: commit
 * ddd9560d0 added it for one named, still-open item - restyling
 * `Cli\Help::screen()` with candy-kit's presentation primitives - and the
 * restyle was costed and deferred rather than forgotten. The obvious fix, the
 * one the tool's own report recommends, was the wrong one; deleting a
 * foundation lib's require because the wiring it was added for has not landed
 * yet is how the wiring stops being possible.
 *
 * A `deferred-wiring` ROW IS A RECORD, NOT AN EXEMPTION. It says a person
 * looked at this require and kept it on purpose. It goes when the wiring
 * lands, never to quiet a check - and `tools/check-path-repos.php --unused`
 * reads the same rows, so the two checks cannot drift again without one of
 * them going red.
 */
final class ManifestDependencyReachTest extends TestCase
{
    /**
     * Production sibling requires that `src/` does not reach and the manifest
     * does not account for.
     *
     * PURE, so both polarities can be pinned against literals rather than
     * against whatever this package happens to require today - which is the
     * whole reason it is not spelled inline in the walk. Its caller asserts a
     * set is EMPTY, and an empty set is what a version of this that always
     * returned `[]` would produce just as convincingly.
     *
     * @param array<string, mixed> $manifest a decoded composer.json
     * @param array<string, bool>  $reached  package name => src/ references it
     * @return list<string>
     */
    private static function unrecordedDeadRequires(array $manifest, array $reached): array
    {
        $recorded = $manifest['extra']['sugarcraft']['deferred-wiring'] ?? [];
        $dead = [];

        /** @var mixed $constraint */
        foreach ((array) ($manifest['require'] ?? []) as $package => $constraint) {
            if (!\is_string($package) || !\str_starts_with($package, 'sugarcraft/')) {
                continue;
            }
            if (($reached[$package] ?? false) === true) {
                continue;
            }

            // An empty reason is not a record. Spelled as a positive test
            // rather than `!empty()` so that a row present with a blank string
            // - the shape a half-finished edit leaves behind - reads as
            // missing, which is the safe direction.
            $reason = \is_array($recorded) ? ($recorded[$package] ?? null) : null;
            if (\is_string($reason) && \trim($reason) !== '') {
                continue;
            }

            $dead[] = $package;
        }

        \sort($dead);

        return $dead;
    }

    /**
     * The classifier, pinned against literals in both directions.
     *
     * No constant return survives this: half the cases demand a name back and
     * half demand nothing.
     */
    public function testTheClassifierReadsBothTheSourceAndTheRecord(): void
    {
        $manifest = [
            'require' => [
                'php' => '^8.3',
                'sugarcraft/used' => '@dev',
                'sugarcraft/deferred' => '@dev',
                'sugarcraft/dead' => '@dev',
                'sugarcraft/blank' => '@dev',
            ],
            'extra' => ['sugarcraft' => ['deferred-wiring' => [
                'sugarcraft/deferred' => 'a measured reason',
                'sugarcraft/blank' => '   ',
            ]]],
        ];

        self::assertSame(
            ['sugarcraft/blank', 'sugarcraft/dead'],
            self::unrecordedDeadRequires($manifest, ['sugarcraft/used' => true]),
            'a require src/ does not reach and the manifest does not account for must be '
                . 'reported - and a row whose reason is blank is not a record, which is the '
                . 'shape a half-finished edit leaves behind.',
        );

        self::assertSame(
            [],
            self::unrecordedDeadRequires(
                ['require' => ['php' => '^8.3', 'sugarcraft/used' => '@dev']],
                ['sugarcraft/used' => true],
            ),
            'and the other polarity: a manifest with nothing wrong in it must report nothing, '
                . 'or the assertion below is satisfied by reporting everything.',
        );
    }

    /** Every sibling require of THIS package, against THIS package's src/. */
    public function testEveryProductionSiblingRequireIsReachedOrRecorded(): void
    {
        $root = \dirname(__DIR__, 2);
        $manifest = \json_decode((string) \file_get_contents($root . '/composer.json'), true);
        self::assertIsArray($manifest, 'sugar-crush/composer.json did not decode to an array.');

        $sources = '';
        /** @var \SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $sources .= (string) \file_get_contents($file->getPathname());
            }
        }

        // A read that returned nothing would make every require look dead,
        // which reds rather than passes - but it would red for the wrong
        // reason and send the reader after the wrong cause.
        self::assertNotSame('', $sources, 'no PHP was read from src/, so nothing can be reached.');

        $reached = [];
        /** @var mixed $constraint */
        foreach ((array) ($manifest['require'] ?? []) as $package => $constraint) {
            if (!\is_string($package) || !\str_starts_with($package, 'sugarcraft/')) {
                continue;
            }

            // The dep's OWN psr-4 prefix, read from its manifest, exactly as
            // tools/check-path-repos.php resolves it - a prefix guessed from
            // the slug would answer a different question from the one CI asks.
            $dep = $root . '/vendor/' . $package . '/composer.json';
            self::assertFileExists($dep, $package . ' is required and is not in vendor/, so the '
                . 'closure this suite is running against is not the one the manifest describes.');

            $decoded = \json_decode((string) \file_get_contents($dep), true);
            self::assertIsArray($decoded, $package . '/composer.json did not decode to an array.');

            foreach (\array_keys((array) ($decoded['autoload']['psr-4'] ?? [])) as $prefix) {
                if (\is_string($prefix) && $prefix !== '' && \str_contains($sources, $prefix)) {
                    $reached[$package] = true;
                }
            }
        }

        // Rule 15: the walk above is what decides the absence below, and a walk
        // that reached nothing would report every require as dead - loudly, but
        // a walk that reached EVERYTHING reports none, silently. candy-core is
        // the floor: this package cannot run without it.
        self::assertArrayHasKey(
            'sugarcraft/candy-core',
            $reached,
            'src/ does not appear to reference candy-core, which is impossible for this package '
                . '- the prefix matching is dead and the absence below is worthless.',
        );

        self::assertSame([], self::unrecordedDeadRequires($manifest, $reached), <<<'TEXT'
            A production `sugarcraft/*` require is not reached from src/ and the
            manifest does not say why.

            THIS IS THE CHECK CI FAILS ON, and it is here because it did not use to
            be: `tools/check-path-repos.php --unused` runs as a hard gate in the
            `path-repo-check` job, and the merge checklist ran a DIFFERENT pass
            (`--no-lib-path-repos`), got a clean 0, and shipped a red build. E453.

            THE RESOLUTIONS, and the report's own recommendation is deliberately
            not first:

              1. WIRE IT. A foundation lib in the require block is more likely to be
                 a wiring nobody finished than a stale entry. Find what it was added
                 for - `git log -S` on this manifest names the commit, and that
                 commit says which plan item wanted it.
              2. RECORD THE DEFERRAL in extra.sugarcraft.deferred-wiring, with the
                 measured reason the wiring has not landed. The same rows are read
                 by the tool, so this test and CI cannot drift apart again.
              3. PRUNE IT, last, and only with evidence that the dependency is
                 genuinely spent. Deleting a foundation lib's require because its
                 wiring has not landed yet is how the wiring stops being possible.
            TEXT);
    }
}
