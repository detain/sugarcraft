<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Context\RepoMapBlock;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;

/**
 * @see RepoMapBlock
 *
 * Fixture-driven rather than run against this checkout: a test that captures
 * the real repository asserts whatever the tree happens to hold today, so it
 * goes red on an unrelated `composer.json` edit and green on a scanner that
 * quietly stopped finding half of them. Every assertion below is against a
 * workspace this file built, so the expected value is known exactly.
 */
final class RepoMapBlockTest extends TestCase
{
    /** @var list<string> */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $dir) {
            self::rmrf($dir);
        }

        $this->temp = [];
    }

    // =========================================================================
    // capture() — the sub-package scan
    // =========================================================================

    public function testItFindsEachImmediateSubdirectoryThatCarriesANamedComposerManifest(): void
    {
        $root = $this->workspace([
            'alpha/composer.json' => ['name' => 'acme/alpha', 'description' => 'The alpha package.', 'autoload' => ['psr-4' => ['Acme\\Alpha\\' => 'src/']]],
            'beta/composer.json' => ['name' => 'acme/beta', 'description' => 'The beta package.', 'autoload' => ['psr-4' => ['Acme\\Beta\\' => 'lib/']]],
        ]);

        $this->assertSame(
            [
                ['dir' => 'alpha', 'name' => 'acme/alpha', 'namespace' => 'Acme\\Alpha\\', 'description' => 'The alpha package.'],
                ['dir' => 'beta', 'name' => 'acme/beta', 'namespace' => 'Acme\\Beta\\', 'description' => 'The beta package.'],
            ],
            RepoMapBlock::capture($root)->packages(),
        );
    }

    public function testTheRootsOwnManifestIsTheSubjectOfTheMapAndNotAMemberOfIt(): void
    {
        $root = $this->workspace([
            'composer.json' => ['name' => 'acme/workspace', 'description' => 'The root itself.'],
            'alpha/composer.json' => ['name' => 'acme/alpha', 'description' => 'A member.'],
        ]);

        $this->assertSame(
            ['alpha'],
            array_column(RepoMapBlock::capture($root)->packages(), 'dir'),
        );
    }

    public function testAPackageWithoutPsr4AutoloadIsListedWithANullNamespaceRatherThanDropped(): void
    {
        $root = $this->workspace([
            'legacy/composer.json' => ['name' => 'acme/legacy', 'description' => 'No autoload at all.'],
        ]);

        $packages = RepoMapBlock::capture($root)->packages();

        $this->assertCount(1, $packages);
        $this->assertNull($packages[0]['namespace']);
        $this->assertStringNotContainsString('->', RepoMapBlock::capture($root)->render());
    }

    public function testADirectoryWithNoManifestAndAManifestWithNoNameAreBothSkipped(): void
    {
        $root = $this->workspace([
            'plain/keep.txt' => 'not a package',
            'unnamed/composer.json' => ['description' => 'a manifest with no name'],
            'blank/composer.json' => ['name' => '', 'description' => 'a manifest with an empty name'],
            'real/composer.json' => ['name' => 'acme/real', 'description' => 'the only package here'],
        ]);

        $this->assertSame(
            ['real'],
            array_column(RepoMapBlock::capture($root)->packages(), 'dir'),
        );
    }

    public function testAManifestThatIsNotValidJsonDropsOnlyItsOwnEntry(): void
    {
        $root = $this->workspace([
            'good/composer.json' => ['name' => 'acme/good', 'description' => 'fine'],
        ]);
        mkdir($root . '/broken');
        file_put_contents($root . '/broken/composer.json', '{ "name": "acme/broken", ');

        $this->assertSame(
            ['good'],
            array_column(RepoMapBlock::capture($root)->packages(), 'dir'),
        );
    }

    public function testAManifestHoldingAJsonScalarRatherThanAnObjectIsAlsoDropped(): void
    {
        $root = $this->workspace([]);
        mkdir($root . '/scalar');
        file_put_contents($root . '/scalar/composer.json', '"acme/scalar"');

        $this->assertSame([], RepoMapBlock::capture($root)->packages());
    }

    public function testVendorNodeModulesAndDotDirectoriesAreNeverScannedForPackages(): void
    {
        $root = $this->workspace([
            'vendor/acme/composer.json' => ['name' => 'acme/vendored', 'description' => 'installed dependency'],
            'node_modules/composer.json' => ['name' => 'acme/node', 'description' => 'js tree'],
            '.hidden/composer.json' => ['name' => 'acme/hidden', 'description' => 'dot directory'],
            'real/composer.json' => ['name' => 'acme/real', 'description' => 'the only one'],
        ]);

        $this->assertSame(
            ['real'],
            array_column(RepoMapBlock::capture($root)->packages(), 'dir'),
        );
    }

    public function testAMultiLineDescriptionIsCollapsedToOneLineSoTheListStaysOneEntryPerLine(): void
    {
        $root = $this->workspace([
            'alpha/composer.json' => ['name' => 'acme/alpha', 'description' => "first line\n\n  second\tline  "],
        ]);

        $this->assertSame(
            'first line second line',
            RepoMapBlock::capture($root)->packages()[0]['description'],
        );
    }

    public function testTheScanStopsAtMaxPackagesManifests(): void
    {
        $root = $this->workspace([]);

        for ($i = 0; $i < RepoMapBlock::MAX_PACKAGES + 5; ++$i) {
            $dir = sprintf('%s/pkg-%04d', $root, $i);
            mkdir($dir);
            file_put_contents($dir . '/composer.json', json_encode(['name' => 'acme/pkg-' . $i, 'description' => 'p']));
        }

        $packages = RepoMapBlock::capture($root)->packages();

        $this->assertCount(RepoMapBlock::MAX_PACKAGES, $packages);
        // scandir() order, so the cap takes the alphabetical prefix; naming the
        // last one kept proves the cut is at the constant and not one either
        // side of it.
        $this->assertSame(sprintf('pkg-%04d', RepoMapBlock::MAX_PACKAGES - 1), end($packages)['dir']);
    }

    // =========================================================================
    // capture() — the PSR-4 source scan
    // =========================================================================

    public function testEachDirectoryHoldingPhpFilesIsPairedWithTheNamespacePsr4MapsItTo(): void
    {
        $root = $this->workspace([
            'composer.json' => ['name' => 'acme/lib', 'autoload' => ['psr-4' => ['Acme\\Lib\\' => 'src/']]],
            'src/One.php' => '<?php',
            'src/Two.php' => '<?php',
            'src/Deep/Three.php' => '<?php',
            'src/Deep/Deeper/Four.php' => '<?php',
        ]);

        $this->assertSame(
            [
                ['path' => 'src', 'namespace' => 'Acme\\Lib\\', 'files' => 2],
                ['path' => 'src/Deep', 'namespace' => 'Acme\\Lib\\Deep\\', 'files' => 1],
                ['path' => 'src/Deep/Deeper', 'namespace' => 'Acme\\Lib\\Deep\\Deeper\\', 'files' => 1],
            ],
            RepoMapBlock::capture($root)->sourceDirectories(),
        );
    }

    public function testTheFileCountIsDirectChildrenOnlySoSummingTheColumnCountsTheTreeExactlyOnce(): void
    {
        $root = $this->workspace([
            'composer.json' => ['name' => 'acme/lib', 'autoload' => ['psr-4' => ['Acme\\Lib\\' => 'src/']]],
            'src/One.php' => '<?php',
            'src/Deep/Two.php' => '<?php',
            'src/Deep/Three.php' => '<?php',
            'src/README.md' => 'not php',
        ]);

        $dirs = RepoMapBlock::capture($root)->sourceDirectories();

        $this->assertSame([1, 2], array_column($dirs, 'files'));
        $this->assertSame(3, array_sum(array_column($dirs, 'files')));
    }

    public function testADirectoryWithNoPhpFilesOfItsOwnDoesNotAppearEvenWhenItsChildrenDo(): void
    {
        $root = $this->workspace([
            'composer.json' => ['name' => 'acme/lib', 'autoload' => ['psr-4' => ['Acme\\Lib\\' => 'src/']]],
            'src/Empty/Nested/One.php' => '<?php',
        ]);

        $this->assertSame(
            ['src/Empty/Nested'],
            array_column(RepoMapBlock::capture($root)->sourceDirectories(), 'path'),
        );
    }

    public function testTwoPrefixesMappedToOneDirectoryAreBothListedWithoutDoubleCountingItsFiles(): void
    {
        $root = $this->workspace([
            'composer.json' => ['name' => 'acme/lib', 'autoload' => ['psr-4' => ['Acme\\A\\' => 'src/', 'Acme\\B\\' => 'src/']]],
            'src/One.php' => '<?php',
            'src/Two.php' => '<?php',
        ]);

        $this->assertSame(
            [
                ['path' => 'src', 'namespace' => 'Acme\\A\\', 'files' => 2],
                ['path' => 'src', 'namespace' => 'Acme\\B\\', 'files' => 2],
            ],
            RepoMapBlock::capture($root)->sourceDirectories(),
        );
    }

    public function testOnePrefixMappedToSeveralDirectoriesYieldsAnEntryForEach(): void
    {
        $root = $this->workspace([
            'composer.json' => ['name' => 'acme/lib', 'autoload' => ['psr-4' => ['Acme\\Lib\\' => ['src/', 'extra/']]]],
            'src/One.php' => '<?php',
            'extra/Two.php' => '<?php',
        ]);

        $this->assertSame(
            [
                ['path' => 'extra', 'namespace' => 'Acme\\Lib\\', 'files' => 1],
                ['path' => 'src', 'namespace' => 'Acme\\Lib\\', 'files' => 1],
            ],
            RepoMapBlock::capture($root)->sourceDirectories(),
        );
    }

    public function testAnEmptyPrefixMapsDirectoriesIntoTheGlobalNamespaceWithoutALeadingSeparator(): void
    {
        $root = $this->workspace([
            'composer.json' => ['name' => 'acme/lib', 'autoload' => ['psr-4' => ['' => 'src/']]],
            'src/One.php' => '<?php',
            'src/Deep/Two.php' => '<?php',
        ]);

        $this->assertSame(
            [
                ['path' => 'src', 'namespace' => '', 'files' => 1],
                ['path' => 'src/Deep', 'namespace' => 'Deep\\', 'files' => 1],
            ],
            RepoMapBlock::capture($root)->sourceDirectories(),
        );
    }

    public function testAPsr4EntryPointingAtADirectoryThatDoesNotExistIsSkippedRatherThanThrowing(): void
    {
        $root = $this->workspace([
            'composer.json' => ['name' => 'acme/lib', 'autoload' => ['psr-4' => ['Acme\\Gone\\' => 'gone/', 'Acme\\Lib\\' => 'src/']]],
            'src/One.php' => '<?php',
        ]);

        $this->assertSame(
            ['src'],
            array_column(RepoMapBlock::capture($root)->sourceDirectories(), 'path'),
        );
    }

    public function testAutoloadDevIsDeliberatelyNotScannedSoTestRootsStayOutOfTheMap(): void
    {
        $root = $this->workspace([
            'composer.json' => [
                'name' => 'acme/lib',
                'autoload' => ['psr-4' => ['Acme\\Lib\\' => 'src/']],
                'autoload-dev' => ['psr-4' => ['Acme\\Lib\\Tests\\' => 'tests/']],
            ],
            'src/One.php' => '<?php',
            'tests/OneTest.php' => '<?php',
        ]);

        $this->assertSame(
            ['src'],
            array_column(RepoMapBlock::capture($root)->sourceDirectories(), 'path'),
        );
    }

    public function testVendorAndNodeModulesAreNotDescendedIntoWhileWalkingASourceRoot(): void
    {
        $root = $this->workspace([
            'composer.json' => ['name' => 'acme/lib', 'autoload' => ['psr-4' => ['Acme\\Lib\\' => 'src/']]],
            'src/One.php' => '<?php',
            'src/vendor/Dep.php' => '<?php',
            'src/node_modules/Mod.php' => '<?php',
            'src/.cache/Cached.php' => '<?php',
        ]);

        $this->assertSame(
            ['src'],
            array_column(RepoMapBlock::capture($root)->sourceDirectories(), 'path'),
        );
    }

    public function testAPsr4EntryEscapingTheRootWithDotDotIsRefusedBeforeTheWalkOpensIt(): void
    {
        $parent = $this->workspace([
            'composer.json' => ['name' => 'acme/parent', 'autoload' => ['psr-4' => ['Acme\\Escape\\' => 'target/']]],
            'target/Secret.php' => '<?php',
            'inner/composer.json' => ['name' => 'acme/inner', 'autoload' => ['psr-4' => [
                'Acme\\Escape\\' => '../target',
                'Acme\\Lib\\' => 'src/',
            ]]],
            'inner/src/One.php' => '<?php',
        ]);

        $block = RepoMapBlock::capture($parent . '/inner');

        $this->assertSame(['src'], array_column($block->sourceDirectories(), 'path'));
        $this->assertStringNotContainsString('target', $block->render());
        // The control: the SAME directory is mapped when the root really does
        // contain it, so the assertion above is about the gate and not about a
        // scanner that fails to find `target/` either way.
        $this->assertSame(
            ['target'],
            array_column(RepoMapBlock::capture($parent)->sourceDirectories(), 'path'),
        );
    }

    public function testAPsr4EntrySymlinkedOutOfTheRootIsRefusedToo(): void
    {
        $outside = $this->workspace(['Secret.php' => '<?php']);
        $root = $this->workspace([
            'composer.json' => ['name' => 'acme/lib', 'autoload' => ['psr-4' => ['Acme\\Escape\\' => 'linked/']]],
        ]);

        if (!@symlink($outside, $root . '/linked')) {
            $this->markTestSkipped('this filesystem does not support symlinks');
        }

        $this->assertSame([], RepoMapBlock::capture($root)->sourceDirectories());
        $this->assertSame('', RepoMapBlock::capture($root)->render());
    }

    public function testAPrefixMappedOntoTheRootItselfIsAllowedRatherThanRefusedAsNotStrictlyInside(): void
    {
        // `within()` and not `below()`: the empty relative path resolves ONTO
        // the boundary, which a strict compare would reject and which is a
        // legal PSR-4 spelling for a package whose sources sit at its root.
        $root = $this->workspace([
            'composer.json' => ['name' => 'acme/lib', 'autoload' => ['psr-4' => ['Acme\\Lib\\' => '']]],
            'One.php' => '<?php',
        ]);

        $this->assertSame(
            [['path' => '.', 'namespace' => 'Acme\\Lib\\', 'files' => 1]],
            RepoMapBlock::capture($root)->sourceDirectories(),
        );
    }

    public function testTheFileVisitBudgetIsSpentAcrossEveryRootRatherThanRefilledPerRoot(): void
    {
        $root = $this->workspace([
            'src/One.php' => '<?php',
            'src/Two.php' => '<?php',
            'src/Three.php' => '<?php',
        ]);

        // Reaching the private walker directly is the only way to observe the
        // bound: MAX_SOURCE_FILES is 20,000, and building a fixture that large
        // would trade a precise assertion for a slow, approximate one. The
        // by-reference counter is what makes the budget global rather than
        // per-root, so it is asserted at the exact boundary.
        $walk = \Closure::bind(
            static fn(string $base, int &$visited): array => RepoMapBlock::phpFileDirectories($base, $visited),
            null,
            RepoMapBlock::class,
        );

        $exhausted = RepoMapBlock::MAX_SOURCE_FILES;
        $this->assertSame([], $walk($root . '/src', $exhausted));
        $this->assertSame(RepoMapBlock::MAX_SOURCE_FILES, $exhausted);

        $oneLeft = RepoMapBlock::MAX_SOURCE_FILES - 1;
        $this->assertSame(['' => 1], $walk($root . '/src', $oneLeft));
        $this->assertSame(RepoMapBlock::MAX_SOURCE_FILES, $oneLeft);

        $fresh = 0;
        $this->assertSame(['' => 3], $walk($root . '/src', $fresh));
        $this->assertSame(3, $fresh);
    }

    // =========================================================================
    // capture() — inputs that are not there at all
    // =========================================================================

    public function testARootThatDoesNotExistCapturesNothingRatherThanThrowing(): void
    {
        $block = RepoMapBlock::capture('/no/such/workspace/' . bin2hex(random_bytes(4)));

        $this->assertSame([], $block->packages());
        $this->assertSame([], $block->sourceDirectories());
        $this->assertSame('', $block->render());
    }

    public function testAnEmptyRootStringIsRefusedRatherThanResolvedToTheFilesystemRoot(): void
    {
        // '/' rtrims to '', which is the one path a map of "everything" could
        // be built from; both spellings must capture nothing.
        $this->assertSame([], RepoMapBlock::capture('')->packages());
        $this->assertSame([], RepoMapBlock::capture('/')->packages());
        $this->assertSame([], RepoMapBlock::capture('/')->sourceDirectories());
    }

    public function testATrailingSlashOnTheRootDoesNotChangeWhatIsCaptured(): void
    {
        $root = $this->workspace([
            'composer.json' => ['name' => 'acme/lib', 'autoload' => ['psr-4' => ['Acme\\Lib\\' => 'src/']]],
            'alpha/composer.json' => ['name' => 'acme/alpha', 'description' => 'a member'],
            'src/One.php' => '<?php',
        ]);

        $this->assertSame(
            RepoMapBlock::capture($root)->render(),
            RepoMapBlock::capture($root . '/')->render(),
        );
    }

    public function testEmptyCapturesNothingAndRendersNothing(): void
    {
        $block = RepoMapBlock::empty();

        $this->assertSame([], $block->packages());
        $this->assertSame([], $block->sourceDirectories());
        $this->assertSame('', $block->render());
    }

    public function testARootWithNoManifestAnywhereRendersTheEmptyStringNotAnEmptyFence(): void
    {
        $root = $this->workspace(['README.md' => 'a repository with no composer.json']);

        $this->assertSame('', RepoMapBlock::capture($root)->render());
    }

    // =========================================================================
    // render()
    // =========================================================================

    public function testTheRenderedBlockIsByteExactForAKnownWorkspace(): void
    {
        $root = $this->workspace([
            'composer.json' => ['name' => 'acme/lib', 'autoload' => ['psr-4' => ['Acme\\Lib\\' => 'src/']]],
            'alpha/composer.json' => ['name' => 'acme/alpha', 'description' => 'The alpha package.', 'autoload' => ['psr-4' => ['Acme\\Alpha\\' => 'src/']]],
            'src/One.php' => '<?php',
            'src/Deep/Two.php' => '<?php',
        ]);

        $rendered = RepoMapBlock::capture($root)->render();

        // The header sentences are asserted by shape below, not here; what this
        // pins is the ENTRY grammar, which is the part a reading model parses.
        $this->assertStringContainsString(
            "\n- alpha/  ->  Acme\\Alpha\\  The alpha package.\n",
            $rendered . "\n",
        );
        $this->assertStringContainsString(
            "\n- src/  ->  Acme\\Lib\\  (1 files)\n- src/Deep/  ->  Acme\\Lib\\Deep\\  (1 files)\n",
            $rendered . "\n",
        );
        $this->assertStringStartsWith("<repo-map>\n", $rendered);
        $this->assertStringEndsWith("\n</repo-map>", $rendered);
    }

    public function testBothSectionsRenderWhenTheRootIsAPackageAndAWorkspaceAtOnce(): void
    {
        $root = $this->workspace([
            'composer.json' => ['name' => 'acme/lib', 'autoload' => ['psr-4' => ['Acme\\Lib\\' => 'src/']]],
            'alpha/composer.json' => ['name' => 'acme/alpha', 'description' => 'a member'],
            'src/One.php' => '<?php',
        ]);

        $rendered = RepoMapBlock::capture($root)->render();

        $packagesAt = strpos($rendered, 'Packages in the immediate subdirectories');
        $sourcesAt = strpos($rendered, "Directories under this package's own PSR-4");

        $this->assertIsInt($packagesAt);
        $this->assertIsInt($sourcesAt);
        $this->assertLessThan($sourcesAt, $packagesAt, 'the package list is meant to precede the source map');
    }

    public function testOnlyThePackageSectionRendersForAWorkspaceRootWithNoAutoloadOfItsOwn(): void
    {
        $root = $this->workspace([
            'composer.json' => ['name' => 'acme/workspace', 'type' => 'metapackage'],
            'alpha/composer.json' => ['name' => 'acme/alpha', 'description' => 'a member'],
        ]);

        $rendered = RepoMapBlock::capture($root)->render();

        $this->assertStringContainsString('Packages in the immediate subdirectories', $rendered);
        $this->assertStringNotContainsString("Directories under this package's own PSR-4", $rendered);
    }

    public function testOnlyTheSourceSectionRendersForALeafPackageWithNoSubPackages(): void
    {
        $root = $this->workspace([
            'composer.json' => ['name' => 'acme/lib', 'autoload' => ['psr-4' => ['Acme\\Lib\\' => 'src/']]],
            'src/One.php' => '<?php',
        ]);

        $rendered = RepoMapBlock::capture($root)->render();

        $this->assertStringNotContainsString('Packages in the immediate subdirectories', $rendered);
        $this->assertStringContainsString("Directories under this package's own PSR-4", $rendered);
    }

    public function testTheHeaderQuotesTheLimitsFromTheConstantsThatEnforceThem(): void
    {
        $root = $this->workspace([
            'alpha/composer.json' => ['name' => 'acme/alpha', 'description' => 'a member'],
        ]);

        $rendered = RepoMapBlock::capture($root)->render();

        $this->assertStringContainsString(
            sprintf('at most %d bytes of entries', RepoMapBlock::MAX_SECTION_BYTES),
            $rendered,
        );
        $this->assertStringContainsString(
            sprintf('clips any single entry to %d bytes', RepoMapBlock::MAX_ENTRY_BYTES),
            $rendered,
        );
    }

    public function testALineOverTheEntryCapIsCutToExactlyThatCapMarkerIncluded(): void
    {
        $root = $this->workspace([
            'alpha/composer.json' => ['name' => 'acme/alpha', 'description' => str_repeat('x', 500), 'autoload' => ['psr-4' => ['Acme\\Alpha\\' => 'src/']]],
        ]);

        $line = $this->entryLines(RepoMapBlock::capture($root)->render())[0];

        $this->assertSame(RepoMapBlock::MAX_ENTRY_BYTES, strlen($line));
        $this->assertStringEndsWith('[…truncated]', $line);
        // The marker is paid for OUT of the budget: a cap that admitted
        // marker-on-top would leave a line longer than the constant.
        $this->assertStringStartsWith('- alpha/  ->  ', $line);
    }

    public function testALineExactlyAtTheEntryCapIsLeftAlone(): void
    {
        $prefix = '- alpha/  ';
        $root = $this->workspace([
            'alpha/composer.json' => ['name' => 'acme/alpha', 'description' => str_repeat('x', RepoMapBlock::MAX_ENTRY_BYTES - strlen($prefix))],
        ]);

        $line = $this->entryLines(RepoMapBlock::capture($root)->render())[0];

        $this->assertSame(RepoMapBlock::MAX_ENTRY_BYTES, strlen($line));
        $this->assertStringNotContainsString('truncated', $line);
    }

    public function testAClipLandingInsideAMultiByteCharacterStillLeavesValidUtf8(): void
    {
        // The multi-byte run has to STRADDLE the cut, and every offset around
        // it has to be tried: only one residue splits a three-byte em dash, and
        // the first version of this test padded the dashes past the cut
        // entirely — it passed against a plain substr(), which is the exact
        // defect it exists to catch. json_encode() is the real consumer: it
        // REFUSES invalid UTF-8, which fails the whole provider request rather
        // than mangling a line.
        for ($pad = 0; $pad < 8; ++$pad) {
            $root = $this->workspace([
                'alpha/composer.json' => ['name' => 'acme/alpha', 'description' => str_repeat('x', $pad) . str_repeat('—', 60)],
            ]);

            $line = $this->entryLines(RepoMapBlock::capture($root)->render())[0];

            $this->assertTrue(mb_check_encoding($line, 'UTF-8'), "pad {$pad} split a character");
            $this->assertLessThanOrEqual(RepoMapBlock::MAX_ENTRY_BYTES, strlen($line));
            $this->assertNotFalse(json_encode($line), "pad {$pad} produced bytes json_encode refuses");
        }
    }

    public function testTheSectionStopsAtTheByteBudgetAndSaysHowManyEntriesItDropped(): void
    {
        $root = $this->workspace([]);
        $total = 120;

        for ($i = 0; $i < $total; ++$i) {
            $dir = sprintf('%s/pkg-%03d', $root, $i);
            mkdir($dir);
            file_put_contents($dir . '/composer.json', json_encode([
                'name' => 'acme/pkg-' . $i,
                'description' => str_repeat('y', 300),
            ]));
        }

        $rendered = RepoMapBlock::capture($root)->render();
        $lines = $this->entryLines($rendered);

        $kept = array_values(array_filter($lines, static fn(string $l): bool => !str_starts_with($l, '- (')));
        $bytes = array_sum(array_map('strlen', $kept));

        $this->assertGreaterThan(0, count($kept));
        $this->assertLessThanOrEqual(RepoMapBlock::MAX_SECTION_BYTES, $bytes);
        // One more line of the same length would have breached the budget --
        // this is what separates "capped" from "capped far too early".
        $this->assertGreaterThan(RepoMapBlock::MAX_SECTION_BYTES - RepoMapBlock::MAX_ENTRY_BYTES, $bytes);
        $this->assertStringContainsString(
            sprintf('- (%d further entries omitted by the size limit)', $total - count($kept)),
            $rendered,
        );
    }

    public function testTheOmissionNoticeIsSingularForExactlyOneDroppedEntry(): void
    {
        // Sized so the budget admits every package but one: each line is padded
        // to the entry cap, so the count that fits is exactly the ratio.
        $fits = intdiv(RepoMapBlock::MAX_SECTION_BYTES, RepoMapBlock::MAX_ENTRY_BYTES);
        $root = $this->workspace([]);

        for ($i = 0; $i <= $fits; ++$i) {
            $dir = sprintf('%s/pkg-%03d', $root, $i);
            mkdir($dir);
            file_put_contents($dir . '/composer.json', json_encode([
                'name' => 'acme/pkg-' . $i,
                'description' => str_repeat('y', 300),
            ]));
        }

        $this->assertStringContainsString(
            '- (1 further entry omitted by the size limit)',
            RepoMapBlock::capture($root)->render(),
        );
    }

    public function testNothingIsOmittedWhenEverythingFitsSoTheNoticeStaysAbsent(): void
    {
        $root = $this->workspace([
            'alpha/composer.json' => ['name' => 'acme/alpha', 'description' => 'short'],
            'beta/composer.json' => ['name' => 'acme/beta', 'description' => 'short'],
        ]);

        $this->assertStringNotContainsString('omitted by the size limit', RepoMapBlock::capture($root)->render());
    }

    public function testRenderTouchesNoFilesystemSoTheMapIsFrozenAtCapture(): void
    {
        $root = $this->workspace([
            'alpha/composer.json' => ['name' => 'acme/alpha', 'description' => 'the only package'],
        ]);

        $block = RepoMapBlock::capture($root);
        $before = $block->render();

        mkdir($root . '/beta');
        file_put_contents($root . '/beta/composer.json', json_encode(['name' => 'acme/beta', 'description' => 'added after capture']));

        $this->assertSame($before, $block->render());
        $this->assertStringNotContainsString('beta', $block->render());
        // ...and a fresh capture DOES see it, so the assertion above is about
        // freezing rather than about a scanner that stopped working.
        $this->assertStringContainsString('beta', RepoMapBlock::capture($root)->render());
    }

    // =========================================================================
    // Runtime wiring
    // =========================================================================

    public function testTheBlockReachesTheSystemPromptBetweenEnvAndTheProjectInstructions(): void
    {
        $root = $this->workspace([
            'composer.json' => ['name' => 'acme/lib', 'autoload' => ['psr-4' => ['Acme\\Lib\\' => 'src/']]],
            'src/One.php' => '<?php',
        ]);

        $prompt = $this->systemPrompt($root);

        $envEnd = strpos($prompt, '</env>');
        $mapAt = strpos($prompt, '<repo-map>');

        $this->assertIsInt($envEnd);
        $this->assertIsInt($mapAt, 'the repo map never reached the prompt');
        $this->assertGreaterThan($envEnd, $mapAt, 'the map must follow the block that names the cwd its paths are relative to');
        $this->assertStringContainsString('- src/  ->  Acme\\Lib\\  (1 files)', $prompt);
    }

    public function testAWorkspaceWithNothingToMapAddsNothingToTheSystemPrompt(): void
    {
        $root = $this->workspace(['README.md' => 'no manifests here']);

        $this->assertStringNotContainsString('repo-map', $this->systemPrompt($root));
    }

    public function testTheMapIsCapturedAtTheConfiguredRootAndNotAtTheProcessDirectory(): void
    {
        $root = $this->workspace([
            'only-here/composer.json' => ['name' => 'acme/only-here', 'description' => 'unique to the configured root'],
        ]);

        $prompt = $this->systemPrompt($root);

        $this->assertStringContainsString('unique to the configured root', $prompt);
        // getcwd() during the suite is sugar-crush/, whose own map would list
        // src/ directories instead; naming one proves the capture was rooted.
        $this->assertStringNotContainsString('SugarCraft\\Crush\\Agents\\', $prompt);
    }

    public function testTheSnapshotIsMemoizedSoARepositoryChangedMidTurnDoesNotAlterAlaterStep(): void
    {
        $root = $this->workspace([
            'alpha/composer.json' => ['name' => 'acme/alpha', 'description' => 'present at capture'],
        ]);

        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test-provider');
        $runtime = new Runtime($provider, new HookManager(new HookRegistry()));
        $app = App::new($provider, 'test-model')->withRoot($root);

        $build = new \ReflectionMethod($runtime, 'buildSystemPrompt');
        $build->setAccessible(true);

        $first = (string) $build->invoke($runtime, $app);

        mkdir($root . '/beta');
        file_put_contents($root . '/beta/composer.json', json_encode(['name' => 'acme/beta', 'description' => 'added between steps']));

        $second = (string) $build->invoke($runtime, $app);

        $this->assertStringContainsString('present at capture', $first);
        $this->assertStringNotContainsString('added between steps', $second);
        // A fresh Runtime is where the change is meant to land, which is the
        // difference between "memoized" and "never scanned twice by anyone".
        $fresh = new Runtime($provider, new HookManager(new HookRegistry()));
        $freshBuild = new \ReflectionMethod($fresh, 'buildSystemPrompt');
        $freshBuild->setAccessible(true);
        $this->assertStringContainsString('added between steps', (string) $freshBuild->invoke($fresh, $app));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function systemPrompt(string $root): string
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test-provider');

        $runtime = new Runtime($provider, new HookManager(new HookRegistry()));
        $method = new \ReflectionMethod($runtime, 'buildSystemPrompt');
        $method->setAccessible(true);

        return (string) $method->invoke($runtime, App::new($provider, 'test-model')->withRoot($root));
    }

    /**
     * Every `- ` entry line of a rendered block, headers and fence excluded.
     *
     * @return list<string>
     */
    private function entryLines(string $rendered): array
    {
        return array_values(array_filter(
            explode("\n", $rendered),
            static fn(string $line): bool => str_starts_with($line, '- '),
        ));
    }

    /**
     * Build a throwaway workspace. Array values are written as JSON, string
     * values verbatim; parent directories are created as needed.
     *
     * @param array<string, array<string, mixed>|string> $files
     */
    private function workspace(array $files): string
    {
        $root = sys_get_temp_dir() . '/crush_repomap_' . bin2hex(random_bytes(8));
        mkdir($root, 0o777, true);
        $this->temp[] = $root;

        foreach ($files as $relative => $contents) {
            $path = $root . '/' . $relative;
            $dir = \dirname($path);

            if (!is_dir($dir)) {
                mkdir($dir, 0o777, true);
            }

            file_put_contents($path, is_array($contents) ? (string) json_encode($contents) : $contents);
        }

        return $root;
    }

    private static function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }

        @rmdir($dir);
    }
}
