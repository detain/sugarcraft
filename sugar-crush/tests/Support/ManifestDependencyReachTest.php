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
 * `--unused` pass checks for this package, from the same inputs and in the
 * same order, so the suite every lane already runs reds at the same moment the
 * build does. "In the same order" is load-bearing and was not true at first -
 * see {@see unrecordedDeadRequires()} on `AMBIGUOUS_NO_PSR4`.
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
 *
 * WHAT THAT PARAGRAPH LEFT OUT, and an attack found it rather than a reading.
 * WHAT IT SAID, and still says above: the row "goes when the wiring lands".
 * WHAT WAS TRUE: nothing enforced the going. The record was consulted only for
 * a package already decided dead, so a row for a dependency `src/` reaches on
 * every page, and a row for a package nobody requires at all, both sat in the
 * manifest with this file GREEN and `--unused` silent. WHY THE PARAGRAPH STILL
 * EARNS ITS PLACE: it states the contract correctly, and
 * {@see idleDeferrals()} is now the thing that holds anybody to it.
 *
 * ONE SHAPE STILL DIVERGES FROM CI AND IT IS NOT THIS TEST'S. The tool's own
 * `deferred-wiring` lookup has the same never-expires property for the other
 * 57 libs; only `sugar-crush`'s rows are pinned here. Recorded rather than
 * fixed: `tools/` belongs to no lane this round.
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
     * A DEP WITH NO `autoload.psr-4` IS REPORTED WHATEVER THE MANIFEST SAYS,
     * and the order is copied from the tool rather than chosen. E453's whole
     * complaint is a local check that is greener than CI's, and this test
     * shipped with one shape of exactly that left in it: `--unused` classifies
     * a dep whose namespace it cannot resolve as `AMBIGUOUS_NO_PSR4` and counts
     * it as a finding BEFORE it ever looks at `deferred-wiring`, so a psr-4-less
     * dep with a deferral row was CI-red and locally green. Every sibling
     * declares psr-4 today (measured, this tree), so the divergence was latent -
     * which is the only reason it is a correction rather than a failure.
     *
     * @param array<string, mixed> $manifest  a decoded composer.json
     * @param array<string, bool>  $reached   package name => src/ references it
     * @param array<string, bool>  $ambiguous package name => declares no autoload.psr-4
     * @return list<string>
     */
    private static function unrecordedDeadRequires(array $manifest, array $reached, array $ambiguous = []): array
    {
        $recorded = $manifest['extra']['sugarcraft']['deferred-wiring'] ?? [];
        $dead = [];

        /** @var mixed $constraint */
        foreach ((array) ($manifest['require'] ?? []) as $package => $constraint) {
            if (!\is_string($package) || !\str_starts_with($package, 'sugarcraft/')) {
                continue;
            }
            if (($ambiguous[$package] ?? false) === true) {
                // Deliberately ahead of both the reach test and the record: a
                // namespace the tool cannot resolve is a finding it cannot
                // clear, so neither can this.
                $dead[] = $package;

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
     * `deferred-wiring` rows that are not suppressing anything.
     *
     * MAJOR, AND AN ATTACK FOUND IT RATHER THAN A READING. The record was
     * consulted only for a package this test had already decided was dead, and
     * `tools/check-path-repos.php`'s own lookup does the same - so neither ever
     * asked whether a row still had work to do. Adding rows for
     * `sugarcraft/candy-core` (reached from `src/` on every page) and for
     * `sugarcraft/package-that-does-not-exist` (not even a require) left this
     * file GREEN at 2 tests / 26 assertions, and `--unused` said nothing about
     * either.
     *
     * That is the shape the doc-block above already rejects in words - "a
     * `deferred-wiring` ROW IS A RECORD, NOT AN EXEMPTION ... It goes when the
     * wiring lands" - with nothing enforcing the going. A row for a dependency
     * that is already wired, or for a package nobody requires, is an exemption
     * with no defect behind it, and that is exactly where the next real one
     * hides.
     *
     * A ROW IS DOING WORK when it names a production `sugarcraft/*` require of
     * THIS manifest that `src/` does not reach and whose namespace resolves.
     * Anything else is idle and is reported with which of the three it failed.
     *
     * @param array<string, mixed> $manifest  a decoded composer.json
     * @param array<string, bool>  $reached   package name => src/ references it
     * @param array<string, bool>  $ambiguous package name => declares no autoload.psr-4
     * @return list<string> `<package>: <why the row is idle>`
     */
    private static function idleDeferrals(array $manifest, array $reached, array $ambiguous = []): array
    {
        $rows = $manifest['extra']['sugarcraft']['deferred-wiring'] ?? null;
        if (!\is_array($rows)) {
            return [];
        }

        $requires = [];
        /** @var mixed $constraint */
        foreach ((array) ($manifest['require'] ?? []) as $package => $constraint) {
            if (\is_string($package)) {
                $requires[$package] = true;
            }
        }

        $idle = [];
        /** @var mixed $reason */
        foreach ($rows as $package => $reason) {
            if (!\is_string($package)) {
                continue;
            }

            if (!isset($requires[$package])) {
                $idle[] = $package . ': not a production require of this manifest at all';

                continue;
            }
            if (!\str_starts_with($package, 'sugarcraft/')) {
                $idle[] = $package . ': not a sugarcraft/* package, so no reach check ever reads it';

                continue;
            }
            if (($ambiguous[$package] ?? false) === true) {
                $idle[] = $package . ': declares no autoload.psr-4, so CI reports it as '
                    . 'AMBIGUOUS_NO_PSR4 before it reads this row and the row suppresses nothing';

                continue;
            }
            if (($reached[$package] ?? false) === true) {
                $idle[] = $package . ': src/ reaches it, so the wiring has landed';
            }
        }

        \sort($idle);

        return $idle;
    }

    /**
     * The idle-row classifier, pinned against literals in both directions.
     *
     * Half the cases demand a row back and half demand none, so no constant
     * return passes - and the second half is the one that matters: a version
     * that reported every row would red a manifest whose deferral is doing
     * exactly the job it was written for.
     */
    public function testTheIdleRowClassifierReadsBothDirections(): void
    {
        $manifest = [
            'require' => [
                'php' => '^8.3',
                'sugarcraft/wired' => '@dev',
                'sugarcraft/deferred' => '@dev',
                'sugarcraft/opaque' => '@dev',
                'vendor/other' => '^1.0',
            ],
            'extra' => ['sugarcraft' => ['deferred-wiring' => [
                'sugarcraft/wired' => 'a reason for a dep src/ already reaches',
                'sugarcraft/deferred' => 'a measured reason the wiring has not landed',
                'sugarcraft/opaque' => 'a reason for a dep whose namespace does not resolve',
                'sugarcraft/never-required' => 'a reason for a package nobody requires',
                'vendor/other' => 'a reason for a package no reach check looks at',
            ]]],
        ];

        self::assertSame(
            [
                'sugarcraft/never-required: not a production require of this manifest at all',
                'sugarcraft/opaque: declares no autoload.psr-4, so CI reports it as '
                    . 'AMBIGUOUS_NO_PSR4 before it reads this row and the row suppresses nothing',
                'sugarcraft/wired: src/ reaches it, so the wiring has landed',
                'vendor/other: not a sugarcraft/* package, so no reach check ever reads it',
            ],
            self::idleDeferrals(
                $manifest,
                ['sugarcraft/wired' => true],
                ['sugarcraft/opaque' => true],
            ),
            'every row that is not suppressing a real finding must be reported, and each of the '
                . 'four ways a row goes idle must be reported for its own reason - a row that '
                . 'names a package nobody requires cannot be told from a wired one by the count.',
        );

        self::assertSame(
            [],
            self::idleDeferrals([
                'require' => ['php' => '^8.3', 'sugarcraft/deferred' => '@dev'],
                'extra' => ['sugarcraft' => ['deferred-wiring' => [
                    'sugarcraft/deferred' => 'a measured reason the wiring has not landed',
                ]]],
            ], [], []),
            'and the other polarity, which is the one that keeps this usable: a row that IS '
                . 'suppressing a real finding is reported by nothing. Without it the assertion '
                . 'in the live arm is satisfied by a classifier that reports everything, which '
                . 'would red the moment anybody records a deferral at all.',
        );

        self::assertSame(
            [],
            self::idleDeferrals(['require' => ['sugarcraft/x' => '@dev']], ['sugarcraft/x' => true]),
            'a manifest with no deferred-wiring block has no idle rows - not "every require is '
                . 'idle", which is what reading a missing block as an empty-ish something would '
                . 'produce.',
        );
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

        // THE ORDERING CI USES, pinned so it cannot drift back. A dep with no
        // psr-4 is a finding the tool cannot clear, so a deferral row must not
        // clear it here either - and the reached flag must not either, since
        // "reached" was decided by matching a prefix that does not exist.
        self::assertSame(
            ['sugarcraft/deferred', 'sugarcraft/used'],
            self::unrecordedDeadRequires(
                [
                    'require' => [
                        'sugarcraft/used' => '@dev',
                        'sugarcraft/deferred' => '@dev',
                    ],
                    'extra' => ['sugarcraft' => ['deferred-wiring' => [
                        'sugarcraft/deferred' => 'a measured reason',
                    ]]],
                ],
                ['sugarcraft/used' => true],
                ['sugarcraft/used' => true, 'sugarcraft/deferred' => true],
            ),
            'a dep declaring no autoload.psr-4 is AMBIGUOUS_NO_PSR4 to the tool, counted before '
                . 'it reads either the source or the record. A local check that clears it is '
                . 'greener than CI, which is the entire defect E453 is about.',
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
        $ambiguous = [];
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

            $prefixes = \array_keys((array) ($decoded['autoload']['psr-4'] ?? []));
            $usable = \array_filter($prefixes, static fn ($p): bool => \is_string($p) && $p !== '');
            if ($usable === []) {
                // Exactly what `--unused` calls AMBIGUOUS_NO_PSR4: there is no
                // namespace to grep for, so "not reached" is not a conclusion.
                $ambiguous[$package] = true;
            }

            foreach ($usable as $prefix) {
                if (\str_contains($sources, $prefix)) {
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

        // Rule 25, and it is this arm's own hole rather than the one below's:
        // `[]` is what idleDeferrals() returns when it is working AND when it
        // is dead, and the fixture that proves otherwise runs against a
        // synthetic manifest. This pushes THIS manifest through the same call
        // with one input flipped - every recorded row pretended to be reached -
        // so the known positive is measured on the real rows the assertion
        // below is about.
        $recorded = (array) ($manifest['extra']['sugarcraft']['deferred-wiring'] ?? []);
        self::assertNotSame(
            [],
            $recorded,
            'this package records no deferrals at all, so the control below proves nothing and '
                . 'the assertion after it is vacuous. Retire both, or find out why the row went.',
        );
        self::assertSame(
            \array_map(
                static fn (string $p): string => $p . ': src/ reaches it, so the wiring has landed',
                \array_keys($recorded),
            ),
            self::idleDeferrals(
                $manifest,
                $reached + \array_fill_keys(\array_keys($recorded), true),
                $ambiguous,
            ),
            'told that every recorded deferral is reached from src/, the classifier must report '
                . 'every one of them. It does not, so it cannot see this manifest\'s rows at all '
                . 'and the empty result asserted next is what a dead instrument returns.',
        );

        // A ROW THAT IS SUPPRESSING NOTHING IS AN EXEMPTION, NOT A RECORD, and
        // it is checked BEFORE the finding below so that the reader who added
        // a row to quiet this test is told so directly rather than watching it
        // go green. Asserted here rather than only in the fixture because the
        // fixture cannot see this manifest.
        self::assertSame([], self::idleDeferrals($manifest, $reached, $ambiguous), <<<'TEXT'
            An extra.sugarcraft.deferred-wiring row is not suppressing anything.

            The row records that somebody looked at a require src/ does not reach
            and kept it ON PURPOSE. It stops being that the moment the require is
            wired, renamed or dropped, and what is left is a standing exemption for
            a defect that no longer exists - which is where the next real one hides.

            THE RESOLUTION IS TO DELETE THE ROW. If the wiring landed, that is the
            row's whole job done. If the package is no longer required, the row
            outlived its require. Neither is a reason to keep it: with the row gone,
            a future regression reds the arm below and gets argued again.
            TEXT);

        self::assertSame([], self::unrecordedDeadRequires($manifest, $reached, $ambiguous), <<<'TEXT'
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
