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
use SugarCraft\Crush\Tests\Prompt\PromptFixture;

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

    /** The fixture backing {@see systemPrompt()} for the runtime-wiring tests. */
    private ?PromptFixture $fixture = null;

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

    /**
     * THE ESCAPE THE FIRST REVISION SAID COULD NOT HAPPEN, in the exact shape a
     * `git clone` produces it: git stores a symlink as a tree entry of mode
     * `120000` and a clone materialises it, so this needs no local tampering by
     * anybody. The doc-block reasoned that a `scandir()` entry cannot contain a
     * separator and concluded the walk could not leave the root — true of the
     * NAME, false of the directory it names.
     *
     * What crossed was not a file listing: it was the outside manifest's
     * `description`, an unbounded string its author wrote, rendered into every
     * system prompt of the session.
     */
    public function testASubPackageDirectorySymlinkedOutOfTheRootIsRefused(): void
    {
        $outside = $this->workspace([
            'composer.json' => [
                'name' => 'victim/private',
                'description' => 'INTERNAL ONLY - acme-bank settlement client, staging creds in .env',
                'autoload' => ['psr-4' => ['Victim\\Private\\' => 'src/']],
            ],
            'src/Client.php' => '<?php',
        ]);
        $root = $this->workspace(['composer.json' => ['name' => 'acme/root', 'type' => 'metapackage']]);

        if (!@symlink($outside, $root . '/peek')) {
            $this->markTestSkipped('this filesystem does not support symlinks');
        }

        $this->assertSame([], RepoMapBlock::capture($root)->packages());
        $this->assertSame('', RepoMapBlock::capture($root)->render());
        // The control: the same manifest IS mapped when it really is inside.
        rename($outside, $root . '/inside');
        $this->assertSame(
            ['inside'],
            array_column(RepoMapBlock::capture($root)->packages(), 'dir'),
        );
    }

    /**
     * The same attack one level down, which is why the compare lives inside
     * `readManifest()` rather than beside the directory scan: the directory is
     * real and inside the root, and only the `composer.json` in it is a link.
     */
    public function testASubPackageManifestSymlinkedOutOfTheRootIsRefused(): void
    {
        $outside = $this->workspace([
            'composer.json' => ['name' => 'victim/private', 'description' => 'staging creds in .env'],
        ]);
        $root = $this->workspace(['alpha/.keep' => '']);

        if (!@symlink($outside . '/composer.json', $root . '/alpha/composer.json')) {
            $this->markTestSkipped('this filesystem does not support symlinks');
        }

        $this->assertSame([], RepoMapBlock::capture($root)->packages());
    }

    /**
     * And the root's OWN manifest, which is the read `scanSourceDirectories()`
     * starts from. Nothing outside can be walked through it — the `psr-4` gate
     * sees to that — but its declared PREFIXES would still have reached the
     * prompt for any directory name that happened to exist inside the root.
     */
    public function testTheRootsOwnManifestSymlinkedOutOfTheRootIsRefused(): void
    {
        $outside = $this->workspace([
            'composer.json' => ['name' => 'victim/private', 'autoload' => ['psr-4' => ['Victim\\Private\\' => 'src/']]],
        ]);
        $root = $this->workspace(['src/One.php' => '<?php']);

        if (!@symlink($outside . '/composer.json', $root . '/composer.json')) {
            $this->markTestSkipped('this filesystem does not support symlinks');
        }

        $this->assertSame([], RepoMapBlock::capture($root)->sourceDirectories());
        $this->assertSame('', RepoMapBlock::capture($root)->render());
    }

    /**
     * `below()` and not `within()` on a candidate directory, which is the whole
     * difference between the two predicates: a sub-package cannot BE the root.
     * `within()` accepts a path resolving ONTO the boundary, so `peek -> .`
     * would have listed the root as a member of itself under a second name —
     * contradicting {@see testTheRootsOwnManifestIsTheSubjectOfTheMapAndNotAMemberOfIt()}
     * one line at a time.
     */
    public function testADirectorySymlinkedOntoTheRootItselfIsNotListedAsASubPackage(): void
    {
        $root = $this->workspace([
            'composer.json' => ['name' => 'acme/root', 'description' => 'the subject of the map'],
        ]);

        if (!@symlink('.', $root . '/peek')) {
            $this->markTestSkipped('this filesystem does not support symlinks');
        }

        $this->assertSame([], RepoMapBlock::capture($root)->packages());
    }

    // =========================================================================
    // capture() — path repositories, the `packages/*` half
    // =========================================================================

    public function testAPackagesGlobDeclaredAsAPathRepositoryIsMapped(): void
    {
        $root = $this->workspace([
            'composer.json' => ['name' => 'acme/mono', 'repositories' => [['type' => 'path', 'url' => 'packages/*']]],
            'packages/alpha/composer.json' => ['name' => 'acme/alpha', 'description' => 'The alpha package.', 'autoload' => ['psr-4' => ['Acme\\Alpha\\' => 'src/']]],
            'packages/beta/composer.json' => ['name' => 'acme/beta', 'description' => 'The beta package.', 'autoload' => ['psr-4' => ['Acme\\Beta\\' => 'src/']]],
            // An immediate child as well, sorting AFTER the globbed pair: the
            // two candidate sources are merged and sorted as one list, so the
            // rendered order stays the alphabetical one the header claims and
            // is not "children first, then whatever the glob returned".
            'zeta/composer.json' => ['name' => 'acme/zeta', 'description' => 'an immediate child'],
        ]);

        $this->assertSame(
            ['packages/alpha', 'packages/beta', 'zeta'],
            array_column(RepoMapBlock::capture($root)->packages(), 'dir'),
        );
        $this->assertStringContainsString(
            "\n- packages/alpha/  ->  Acme\\Alpha\\  The alpha package.\n",
            RepoMapBlock::capture($root)->render() . "\n",
        );
    }

    public function testAPathRepositoryNamingOneDirectoryWithoutAGlobIsMappedToo(): void
    {
        $root = $this->workspace([
            'composer.json' => ['name' => 'acme/mono', 'repositories' => [['type' => 'path', 'url' => 'libs/one']]],
            'libs/one/composer.json' => ['name' => 'acme/one', 'description' => 'a member'],
            'libs/two/composer.json' => ['name' => 'acme/two', 'description' => 'not declared, so not found'],
        ]);

        $this->assertSame(
            ['libs/one'],
            array_column(RepoMapBlock::capture($root)->packages(), 'dir'),
        );
    }

    public function testAPathRepositoryPointingOutsideTheRootFindsNothing(): void
    {
        $parent = $this->workspace([
            'sibling/composer.json' => ['name' => 'acme/sibling', 'description' => 'outside the root'],
            'root/composer.json' => ['name' => 'acme/root', 'repositories' => [
                ['type' => 'path', 'url' => '../*'],
                ['type' => 'path', 'url' => '../sibling'],
            ]],
        ]);

        $block = RepoMapBlock::capture($parent . '/root');

        $this->assertSame([], $block->packages());
        $this->assertStringNotContainsString('sibling', $block->render());
    }

    public function testANonPathRepositoryEntryIsIgnoredRatherThanGlobbed(): void
    {
        $root = $this->workspace([
            'composer.json' => ['name' => 'acme/mono', 'repositories' => [
                ['type' => 'vcs', 'url' => 'https://example.invalid/acme/alpha'],
                'not-even-an-array',
            ]],
            'packages/alpha/composer.json' => ['name' => 'acme/alpha', 'description' => 'a member'],
        ]);

        $this->assertSame([], RepoMapBlock::capture($root)->packages());
    }

    public function testADirectoryNamedByBothAGlobAndTheChildScanIsListedOnce(): void
    {
        $root = $this->workspace([
            'composer.json' => ['name' => 'acme/mono', 'repositories' => [['type' => 'path', 'url' => '*']]],
            'alpha/composer.json' => ['name' => 'acme/alpha', 'description' => 'a member'],
        ]);

        $this->assertSame(
            ['alpha'],
            array_column(RepoMapBlock::capture($root)->packages(), 'dir'),
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

    /**
     * MANIFESTS OPENED, not packages FOUND — which is what the constant's
     * doc-block says it bounds and what the first revision did not do. It broke
     * on `count($packages) >= MAX_PACKAGES`, so the directories below (none of
     * which yields a package) cost a `composer.json` open each and none of them
     * counted, leaving the I/O bound unenforced in exactly the "somebody
     * pointed it at their home directory" case it is written for.
     */
    public function testTheBoundIsOnManifestsOpenedAndNotOnPackagesFound(): void
    {
        $root = $this->workspace([]);

        for ($i = 0; $i < RepoMapBlock::MAX_PACKAGES; ++$i) {
            mkdir(sprintf('%s/dir-%04d', $root, $i));
        }

        // Sorts last, so it is only reached if the empty directories above did
        // not spend the budget.
        mkdir($root . '/zzz-package');
        file_put_contents($root . '/zzz-package/composer.json', (string) json_encode(['name' => 'acme/zzz']));

        $this->assertSame([], RepoMapBlock::capture($root)->packages());
    }

    /**
     * The FIRST prefix a manifest declares, which was undocumented and untested
     * — a mutation to `end($prefixes)` survived the whole suite.
     */
    public function testAPackageDeclaringSeveralPsr4PrefixesShowsTheFirstOneItDeclares(): void
    {
        $root = $this->workspace([
            'alpha/composer.json' => ['name' => 'acme/alpha', 'autoload' => ['psr-4' => [
                'Zeta\\Alpha\\' => 'src/',
                'Acme\\Alpha\\' => 'lib/',
            ]]],
        ]);

        $this->assertSame('Zeta\\Alpha\\', RepoMapBlock::capture($root)->packages()[0]['namespace']);
    }

    /**
     * `is_array()` alone was the test behind a doc-block promising "not a JSON
     * object", and `json_decode('[1,2,3]', true)` is an array. The existing
     * coverage was a SCALAR, the one shape that check did reject.
     */
    public function testAManifestHoldingAJsonArrayRatherThanAnObjectIsAlsoDropped(): void
    {
        $root = $this->workspace([
            'alpha/composer.json' => '[1,2,3]',
            'beta/composer.json' => '[{"name":"acme/beta"}]',
            'gamma/composer.json' => ['name' => 'acme/gamma'],
        ]);

        $this->assertSame(
            ['gamma'],
            array_column(RepoMapBlock::capture($root)->packages(), 'dir'),
        );
    }

    /**
     * `readManifest()`'s contract asserted where it is WRITTEN, by reflection,
     * because it is not observable from the public surface: a JSON array has no
     * `name`, no `autoload` and no `repositories` key, so every caller drops it
     * either way. That is exactly why the doc-block could promise null "when it
     * is … not a JSON object" while the code tested `is_array($decoded)` — and
     * `json_decode('[1,2,3]', true)` is an array — for a whole round without a
     * red test. The behaviour-level test above covers the same shapes through
     * the public surface; this one covers the sentence.
     */
    public function testReadManifestRefusesAJsonArrayExactlyAsItsDocBlockPromises(): void
    {
        $root = $this->workspace([]);
        $path = $root . '/composer.json';

        $read = new \ReflectionMethod(RepoMapBlock::class, 'readManifest');
        $read->setAccessible(true);
        $call = function (string $raw) use ($read, $path, $root): ?array {
            file_put_contents($path, $raw);

            /** @var ?array<string, mixed> */
            return $read->invoke(null, $path, $root);
        };

        $this->assertNull($call('[1,2,3]'), 'a JSON array is not a JSON object');
        $this->assertNull($call('[{"name":"acme/x"}]'));
        $this->assertNull($call('"acme/scalar"'));
        $this->assertNull($call('{ "name": '));
        $this->assertSame([], $call('{}'), 'an empty object is an object');
        $this->assertSame(['name' => 'acme/x'], $call('{"name":"acme/x"}'));
    }

    /**
     * `{}` decodes to `[]`, which `array_is_list()` calls a list — so the array
     * refusal above has to make room for it. It is an OBJECT with no keys, and
     * it drops one line later on the missing `name` exactly as `{"foo":1}` does,
     * which is what this pins: the two must not diverge.
     */
    public function testAnEmptyJsonObjectIsTreatedAsAManifestWithNoNameRatherThanAsAnArray(): void
    {
        $root = $this->workspace([
            'composer.json' => '{}',
            'src/One.php' => '<?php',
            'alpha/composer.json' => '{}',
            'beta/composer.json' => '{"foo":1}',
        ]);

        $block = RepoMapBlock::capture($root);

        $this->assertSame([], $block->packages());
        $this->assertSame([], $block->sourceDirectories());
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

    /**
     * `.PHP` counts. The extension test is `strtolower(...) === 'php'`, and
     * dropping the `strtolower()` survived the whole suite: on a case-sensitive
     * filesystem an uppercase-extension file simply stopped counting, and on a
     * case-INSENSITIVE one (macOS, Windows) it would stop counting files PHP
     * itself will happily `require`.
     */
    public function testAPhpFileWithAnUppercaseExtensionIsCountedLikeAnyOther(): void
    {
        $root = $this->workspace([
            'composer.json' => ['name' => 'acme/lib', 'autoload' => ['psr-4' => ['Acme\\Lib\\' => 'src/']]],
            'src/One.PHP' => '<?php',
            'src/Two.PhP' => '<?php',
            'src/Three.php' => '<?php',
            'src/notes.txt' => 'not code',
        ]);

        $this->assertSame(
            [['path' => 'src', 'namespace' => 'Acme\\Lib\\', 'files' => 3]],
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

    /**
     * The `src/`-shaped fixture this started as could not see the `rtrim()` it
     * exists to pin: with a psr-4 value of `src`, the doubled separator lands
     * INSIDE the joined path (`<root>//src`) and every later offset is taken
     * from that same string, so removing the `rtrim()` changed nothing and the
     * mutation survived. The load-bearing shape is a prefix mapped to `""`,
     * where the walk's base IS the root and the relative path is cut at
     * `strlen($base) + 1` — one byte too far when the base carries a trailing
     * slash, which silently renames `sub/` to `ub/`.
     */
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

        $atRoot = $this->workspace([
            'composer.json' => ['name' => 'acme/lib', 'autoload' => ['psr-4' => ['Acme\\Lib\\' => '']]],
            'One.php' => '<?php',
            'sub/Two.php' => '<?php',
        ]);

        $this->assertSame(
            ['.', 'sub'],
            array_column(RepoMapBlock::capture($atRoot . '/')->sourceDirectories(), 'path'),
        );
        $this->assertSame(
            RepoMapBlock::capture($atRoot)->render(),
            RepoMapBlock::capture($atRoot . '/')->render(),
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

        $packagesAt = strpos($rendered, 'Packages in this workspace');
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

        $this->assertStringContainsString('Packages in this workspace', $rendered);
        $this->assertStringNotContainsString("Directories under this package's own PSR-4", $rendered);
    }

    public function testOnlyTheSourceSectionRendersForALeafPackageWithNoSubPackages(): void
    {
        $root = $this->workspace([
            'composer.json' => ['name' => 'acme/lib', 'autoload' => ['psr-4' => ['Acme\\Lib\\' => 'src/']]],
            'src/One.php' => '<?php',
        ]);

        $rendered = RepoMapBlock::capture($root)->render();

        $this->assertStringNotContainsString('Packages in this workspace', $rendered);
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

    /**
     * The two bounds pinned as LITERALS, which is the coverage the cap tests
     * cannot give: every one of them derives its expectation from the constant,
     * so a mutation of the constant moves the expectation with it and survives.
     * Measured: `MAX_ENTRY_BYTES 120 -> 119` and `MAX_PACKAGES 256 -> 255` both
     * survived the suite as it shipped.
     *
     * `MAX_ENTRY_BYTES` is the one that matters, and the first review of this
     * file defended the `MAX_PACKAGES` survivor with an argument that fits only
     * this constant: a bound whose DOC-BLOCK REASONS ABOUT ITS VALUE has to be
     * pinned, because deriving the test from the value asserts nothing about
     * the measurement the value came from. 120 is exactly that — it is chosen
     * against 140 and 160 on a measured trade (+947 B to un-clip four more
     * lines, +1,836 B for seven and over the section cap), and the whole
     * paragraph is void if the figure moves. `MAX_PACKAGES`'s doc-block argues
     * only that a bound EXISTS, so its literal is pinned here for symmetry
     * rather than because 256 carries an argument.
     */
    public function testTheTwoMeasuredBoundsAreTheLiteralsTheirDocBlocksArgueFor(): void
    {
        $this->assertSame(120, RepoMapBlock::MAX_ENTRY_BYTES);
        $this->assertSame(256, RepoMapBlock::MAX_PACKAGES);
        $this->assertSame(8192, RepoMapBlock::MAX_SECTION_BYTES);
        $this->assertSame(20000, RepoMapBlock::MAX_SOURCE_FILES);
    }

    /**
     * The boundary the section budget's doc-block argues about — "a ceiling
     * rather than a ceiling-plus-one-entry" — asserted at the byte where `>`
     * and `>=` differ, which nothing did before: 128 lines of exactly 64 B land
     * on {@see RepoMapBlock::MAX_SECTION_BYTES} to the byte, and the last one
     * must be KEPT.
     */
    public function testASectionEndingExactlyOnTheByteBudgetKeepsItsLastEntry(): void
    {
        // `- pkg-000/` is 10 B, the two-space description join is 2 B.
        $files = [];
        $lines = intdiv(RepoMapBlock::MAX_SECTION_BYTES, 64);

        for ($i = 0; $i < $lines; ++$i) {
            $files[sprintf('pkg-%03d/composer.json', $i)] = [
                'name' => 'acme/pkg',
                'description' => str_repeat('d', 52),
            ];
        }

        $entries = $this->entryLines(RepoMapBlock::capture($this->workspace($files))->render());

        $this->assertCount($lines, $entries, 'the last entry lands exactly on the budget and must survive it');
        $this->assertSame(64, strlen($entries[0]), 'the fixture must be 64 B per line for this boundary to bite');
        $this->assertSame(
            RepoMapBlock::MAX_SECTION_BYTES,
            array_sum(array_map(strlen(...), $entries)),
        );
        $this->assertStringNotContainsString('omitted by the size limit', implode("\n", $entries));
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

    /**
     * The `rtrim()` before the marker, which survived being deleted because
     * every clip fixture in this file cut mid-word. A line whose cut lands
     * exactly after a space renders `…d […truncated]` without it — a stray
     * space the reader has no way to distinguish from part of the description.
     * The whole string is asserted, not its length, because the un-rtrimmed
     * form is still under the cap and a length assertion cannot see it.
     */
    public function testTheClipDropsTrailingSpaceRatherThanCarryingItIntoTheMarker(): void
    {
        // Room before the marker is MAX_ENTRY_BYTES - 13; the `- alpha/  `
        // prefix is 10 B, so the description's 97th byte is the cut point.
        $room = RepoMapBlock::MAX_ENTRY_BYTES - strlen(' […truncated]');
        $keep = $room - strlen('- alpha/  ') - 1;

        $root = $this->workspace([
            'alpha/composer.json' => [
                'name' => 'acme/alpha',
                'description' => str_repeat('a', $keep) . ' ' . str_repeat('b', 40),
            ],
        ]);

        $this->assertSame(
            '- alpha/  ' . str_repeat('a', $keep) . ' […truncated]',
            $this->entryLines(RepoMapBlock::capture($root)->render())[0],
        );
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
        // P3.S1 inverted this pin, deliberately, with the env block's move to
        // the END of the assembly (stable layers first, volatile <env> last —
        // prompt_expand.md §9.2): the repo map now precedes the environment
        // block instead of following it. An inverted assertion still pins an
        // order — a reorder that put <env> back ahead of the map reds this.
        $this->fixture = new PromptFixture();
        $this->temp[] = $this->fixture->root();
        $root = $this->fixture->root();
        $this->fixture->writeJson('composer.json', ['name' => 'acme/lib', 'autoload' => ['psr-4' => ['Acme\\Lib\\' => 'src/']]]);
        $this->fixture->write('src/One.php', '<?php');

        $prompt = $this->systemPrompt($root);

        $envEnd = strpos($prompt, '</env>');
        $mapAt = strpos($prompt, '<repo-map>');

        $this->assertIsInt($envEnd);
        $this->assertIsInt($mapAt, 'the repo map never reached the prompt');
        $this->assertLessThan($envEnd, $mapAt, 'the map must precede the block that names the cwd its paths are relative to');
        $this->assertStringContainsString('- src/  ->  Acme\\Lib\\  (1 files)', $prompt);
    }

    public function testAWorkspaceWithNothingToMapAddsNothingToTheSystemPrompt(): void
    {
        $this->fixture = new PromptFixture();
        $this->temp[] = $this->fixture->root();
        $root = $this->fixture->root();
        $this->fixture->write('README.md', 'no manifests here');

        $this->assertStringNotContainsString('repo-map', $this->systemPrompt($root));
    }

    public function testTheMapIsCapturedAtTheConfiguredRootAndNotAtTheProcessDirectory(): void
    {
        $this->fixture = new PromptFixture();
        $this->temp[] = $this->fixture->root();
        $root = $this->fixture->root();
        $this->fixture->writeJson('only-here/composer.json', ['name' => 'acme/only-here', 'description' => 'unique to the configured root']);

        $prompt = $this->systemPrompt($root);

        $this->assertStringContainsString('unique to the configured root', $prompt);
        // getcwd() during the suite is sugar-crush/, whose own map would list
        // src/ directories instead; naming one proves the capture was rooted.
        $this->assertStringNotContainsString('SugarCraft\\Crush\\Agents\\', $prompt);
    }

    public function testTheSnapshotIsMemoizedSoARepositoryChangedMidTurnDoesNotAlterAlaterStep(): void
    {
        $fixture = new PromptFixture();
        $fixture->writeJson('alpha/composer.json', ['name' => 'acme/alpha', 'description' => 'present at capture']);
        $this->temp[] = $fixture->root();

        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test-provider');
        $runtime = new Runtime($provider, new HookManager(new HookRegistry()));
        $app = App::new($provider, 'test-model')->withRoot($fixture->root());

        $first = $fixture->systemPrompt($app, $runtime);

        mkdir($fixture->root() . '/beta');
        file_put_contents($fixture->root() . '/beta/composer.json', json_encode(['name' => 'acme/beta', 'description' => 'added between steps']));

        $second = $fixture->systemPrompt($app, $runtime);

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

    /**
     * **E529 — the monorepo half of P8.8, measured instead of counted.**
     *
     * {@see RepoMapBlock}'s design note used to close with "Measured on this
     * checkout it finds 58 packages and their namespaces without reading either
     * markdown file". Two of that file's three restated cardinalities WERE
     * asserted against their derivation, by a census test that has since been
     * retired along with both restatements — a figure derived from `src/`'s
     * file count reds on any honest addition and moves for nothing else, so
     * what is checked now is the ARGUMENT each figure was supporting, by
     * {@see \SugarCraft\Crush\Tests\Tools\BuiltInToolCorpusTest::testTheTwoDesignArgumentsRepoMapBlockMakesAboutThisTreeStillHold()},
     * with their continued ABSENCE from the file asserted by
     * {@see \SugarCraft\Crush\Tests\Tools\BuiltInToolCorpusTest::testRepoMapBlockNoLongerRestatesTheSourceCensus()}.
     * This third one was asserted by nothing, and it wrapped across a doc-block
     * line, so it was invisible to a line-oriented search for the digits beside
     * the noun.
     *
     * The number is not what the paragraph is arguing. The argument is that the
     * monorepo half is derived from `composer.json` manifests and NOT from
     * `docs/MATCHUPS.md` and `PROJECT_NAMES.md` — the two hand-maintained
     * documents the item asked for a parser for. So that is what is measured,
     * and the digits are gone from the prose rather than corrected: a
     * hand-updated census is the same defect one round later (rule 18).
     *
     * FIXTURE, NOT THIS CHECKOUT, per this class's own opening note: a count
     * taken against the real repository goes red on an unrelated manifest edit
     * and green on a scanner that quietly stopped finding half of them.
     *
     * BOTH POLARITIES (rule 15). "The render contains no sentinel" is an
     * absence, and an empty render satisfies it perfectly — so the same
     * assertion block also requires that the manifest-derived facts DID arrive,
     * and that the sentinels really are on disk to be found. Without the last
     * of those, a fixture whose markdown failed to write would prove the point
     * by not existing.
     */
    public function testTheMonorepoHalfIsDerivedFromManifestsAndNeverFromTheTwoMarkdownFiles(): void
    {
        // Built by concatenation so that a future textual sweep for either
        // document name cannot rewrite the fixture that proves they are unread.
        $matchupsSentinel = 'SENTINEL-FROM-' . 'MATCHUPS-MD';
        $namesSentinel = 'SENTINEL-FROM-' . 'PROJECT-NAMES-MD';

        $root = $this->workspace([
            'composer.json' => ['name' => 'acme/monorepo'],
            'docs/MATCHUPS.md' => "# Upstream map\n\n| upstream | port |\n| x | {$matchupsSentinel} |\n",
            'PROJECT_NAMES.md' => "# Naming\n\nCandyThing -> {$namesSentinel}\n",
            'candy-alpha/composer.json' => [
                'name' => 'acme/candy-alpha',
                'description' => 'The alpha package.',
                'autoload' => ['psr-4' => ['Acme\\Alpha\\' => 'src/']],
            ],
            'candy-alpha/src/.keep' => '',
            'sugar-beta/composer.json' => [
                'name' => 'acme/sugar-beta',
                'description' => 'The beta package.',
                'autoload' => ['psr-4' => ['Acme\\Beta\\' => 'src/']],
            ],
            'sugar-beta/src/.keep' => '',
        ]);

        // The sentinels exist on disk. Without this the absence assertions
        // below are satisfied by a fixture that never wrote the two files.
        foreach ([
            '/docs/MATCHUPS.md' => $matchupsSentinel,
            '/PROJECT_NAMES.md' => $namesSentinel,
        ] as $relative => $sentinel) {
            $this->assertStringContainsString(
                $sentinel,
                (string) file_get_contents($root . $relative),
                "the fixture never wrote {$relative}, so the absence assertions below would be "
                    . 'true for the wrong reason',
            );
        }

        $block = RepoMapBlock::capture($root);
        $rendered = $block->render();

        // THE POSITIVE HALF: every fact in the block came out of a manifest.
        foreach (['candy-alpha', 'sugar-beta', 'Acme\\Alpha\\', 'Acme\\Beta\\', 'The alpha package.', 'The beta package.'] as $fromManifest) {
            $this->assertStringContainsString(
                $fromManifest,
                $rendered,
                'the manifest-derived scan stopped finding what it is supposed to find, so the '
                    . 'absence assertions below measure an empty block',
            );
        }

        // THE WINDOW, AND IT IS THE HALF THE FIRST REVISION GOT WRONG. render()
        // clips every line at MAX_ENTRY_BYTES, and this repository's own
        // docblock records that most real package lines already clip - so a
        // markdown read whose bytes land past the clip is INVISIBLE to an
        // absence assertion taken on the render. MEASURED: with the sentinel
        // appended to each description rather than prepended, so it falls off
        // the end of the line, the render-only version of this test was GREEN.
        // packages() and sourceDirectories() are the same facts uncapped and
        // unclipped, so the absence is asserted over those as well.
        $unclipped = $rendered . "\n" . var_export($block->packages(), true)
            . "\n" . var_export($block->sourceDirectories(), true);

        // ... and the window itself must not be empty for the wrong reason
        // (rule 15): the uncapped view has to still carry the manifest facts.
        $this->assertStringContainsString('The alpha package.', $unclipped);

        // THE ARGUMENT ITSELF: nothing came out of either markdown document.
        foreach ([$matchupsSentinel, $namesSentinel] as $sentinel) {
            $this->assertStringNotContainsString(
                $sentinel,
                $unclipped,
                'RepoMapBlock read one of the two hand-maintained markdown documents. The '
                    . 'monorepo half of P8.8 is implemented generically from composer manifests '
                    . 'precisely so it works on repositories that are not this one; a parser for '
                    . "these two files binds a shipped feature to their formatting.\n"
                    . $unclipped,
            );
        }
    }

    private function systemPrompt(string $root): string
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test-provider');

        return $this->fixture->systemPrompt(App::new($provider, 'test-model')->withRoot($root));
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
