<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Bundles;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SugarCraft\Code\Bundles\BundleDiscovery;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Cli\Subcommands;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Support\ProjectRoot;
use SugarCraft\Code\Tests\Skills\TemporaryDirectoryTrait;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\PathMarketplaceFixtureTrait;

/**
 * PLG-2a: `bundles install --project` lands a bundle in the project tier,
 * `bundles update` keeps each bundle in the tier it occupies, `bundles
 * recommend` does not offer back what the project already holds, and the
 * scope flags are refused everywhere they do not apply. The bare install keeps
 * its user-root destination, which {@see BundleInstallTest} pins unchanged;
 * this file pins only what the scope flag adds.
 *
 * The refusal lines are written to the process's STDERR, which an in-process
 * `ob_start()` cannot capture, so those cases run the real `bin/candy-code`
 * and read its stderr. Every other case dispatches in-process.
 */
final class BundleInstallScopeTest extends TestCase
{
    use TemporaryDirectoryTrait;
    use HomeSandboxTrait;
    use PathMarketplaceFixtureTrait;

    private string $tempDir;

    private string $home;

    private string $root;

    private string $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/candy-code-bundle-scope-test-' . uniqid((string) getmypid(), true);
        mkdir($this->tempDir, 0777, true);
        $this->home = $this->useHomeSandbox($this->tempDir . '/home');
        $this->root = $this->home . '/.candy-code/bundles';
        $this->project = $this->tempDir . '/project';
        mkdir($this->project, 0777, true);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Subcommands::class, 'bundlesInstallSpawn'))->setValue(null, null);
        $this->restoreHomeSandbox();
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testABareInstallStillLandsInTheUserRootAndNeverTouchesTheProject(): void
    {
        $source = $this->stagedBundle('demo-src', 'demo');

        self::assertSame(NonInteractive::EXIT_OK, $this->verb('install', $source, '--root', $this->project));

        self::assertFileExists($this->root . '/demo/.claude-plugin/plugin.json');
        self::assertDirectoryDoesNotExist($this->project . '/.candy-code', 'the default scope writes no project tier');
    }

    public function testProjectInstallLandsUnderTheNamedRootAndNotInTheUserRoot(): void
    {
        $source = $this->stagedBundle('demo-src', 'demo');

        self::assertSame(NonInteractive::EXIT_OK, $this->verb('install', $source, '--project', '--root', $this->project));

        self::assertFileExists($this->project . '/.candy-code/bundles/demo/.claude-plugin/plugin.json');
        self::assertFileExists($this->project . '/.candy-code/bundles/demo/commands/hello.md');
        self::assertDirectoryDoesNotExist($this->root . '/demo', 'the project scope must not also write the user root');
    }

    public function testProjectInstallDefaultsToTheWorkingDirectoryWhenNoRootIsGiven(): void
    {
        $source = $this->stagedBundle('demo-src', 'demo');
        $before = getcwd();
        self::assertNotFalse($before);

        chdir($this->project);

        try {
            self::assertSame(NonInteractive::EXIT_OK, $this->verb('install', $source, '--project'));
        } finally {
            chdir($before);
        }

        self::assertFileExists($this->project . '/.candy-code/bundles/demo/.claude-plugin/plugin.json');
    }

    public function testBundlesListWithoutRootShowsAProjectInstallMadeWithoutRoot(): void
    {
        $source = $this->stagedBundle('demo-src', 'demo');
        $before = getcwd();
        self::assertNotFalse($before);

        chdir($this->project);

        try {
            self::assertSame(NonInteractive::EXIT_OK, $this->verb('install', $source, '--project'));
            [$exit, $out] = $this->verbCapture('list');
        } finally {
            chdir($before);
        }

        self::assertSame(NonInteractive::EXIT_OK, $exit);
        self::assertMatchesRegularExpression('/^demo\s.*\bproject\b/m', $out, 'list defaults to the working directory the install wrote to');
    }

    public function testAProjectInstallIsDiscoveredInTheProjectTier(): void
    {
        $source = $this->stagedBundle('demo-src', 'demo');
        self::assertSame(NonInteractive::EXIT_OK, $this->verb('install', $source, '--project', '--root', $this->project));

        $found = [];
        foreach ((new BundleDiscovery($this->project))->discover() as $bundle) {
            $found[$bundle->name] = $bundle->tier;
        }

        self::assertSame(['demo' => 'project'], $found, 'the bundle is listed in the project tier and nowhere else');
    }

    public function testAUserBundleIsDiscoveredOnceInTheUserTierWhenTheHomeDirectoryIsTheProjectRoot(): void
    {
        $this->plainBundleAt($this->root . '/demo', 'demo');

        $found = [];
        foreach ((new BundleDiscovery(ProjectRoot::resolve($this->home)))->discover() as $bundle) {
            $found[] = [$bundle->name, $bundle->tier];
        }

        self::assertSame(
            [['demo', 'user']],
            $found,
            'home is both the project root and the user root: the bundle is one row, in the user tier',
        );
    }

    public function testBundlesListFromTheHomeDirectoryShowsAUserBundleOnce(): void
    {
        $this->plainBundleAt($this->root . '/demo', 'demo');

        $before = getcwd();
        self::assertNotFalse($before);

        chdir($this->home);

        try {
            [$exit, $out] = $this->verbCapture('list');
        } finally {
            chdir($before);
        }

        self::assertSame(NonInteractive::EXIT_OK, $exit);
        self::assertSame(1, preg_match_all('/^demo\s/m', $out), 'list shows one row for the bundle, not one per tier');
        self::assertMatchesRegularExpression('/^demo\s.*\buser\b/m', $out, 'the one row is the user-tier copy');
    }

    public function testProjectAndUserTogetherAreRefusedWithTheirOwnLineAndNothingIsWritten(): void
    {
        $source = $this->stagedBundle('demo-src', 'demo');

        [$exit, , $stderr] = $this->runCli($this->project, 'bundles', 'install', $source, '--project', '--user', '--root', $this->project);

        self::assertSame(NonInteractive::EXIT_CONFIG, $exit);
        self::assertStringContainsString(
            Lang::t('cli.bundles.install.project_user'),
            $stderr,
            'the refusal must name the conflict, not be the generic unknown-flag exit',
        );
        self::assertDirectoryDoesNotExist($this->project . '/.candy-code/bundles');
        self::assertDirectoryDoesNotExist($this->root . '/demo');
    }

    public function testAProjectRootReachedThroughASymlinkIsRefusedAndTheLinkTargetIsUntouched(): void
    {
        $source = $this->stagedBundle('demo-src', 'demo');
        $outside = $this->tempDir . '/outside';
        mkdir($outside, 0777, true);
        symlink($outside, $this->project . '/.candy-code');

        self::assertSame(
            NonInteractive::EXIT_FAILURE,
            $this->verb('install', $source, '--project', '--root', $this->project),
        );

        self::assertSame([], $this->listed($outside), 'a checkout must not steer the install out of its tree');
        self::assertDirectoryDoesNotExist($this->root . '/demo');
    }

    public function testAProjectRootThatIsNotADirectoryIsRefusedWithoutCreatingAnything(): void
    {
        $source = $this->stagedBundle('demo-src', 'demo');
        $missing = $this->tempDir . '/no-such-project';

        self::assertSame(
            NonInteractive::EXIT_FAILURE,
            $this->verb('install', $source, '--project', '--root', $missing),
        );

        self::assertDirectoryDoesNotExist($missing);
    }

    public function testTheScopeFlagsAreRefusedWithTheirOwnLineOnEveryVerbThatIsNotInstall(): void
    {
        self::assertSame(NonInteractive::EXIT_OK, $this->verb('list', '--root', $this->project), 'list answers OK with nothing installed');

        foreach ([
            ['list', '--project'],
            ['update', '--user'],
            ['search', 'x', '--project'],
            ['recommend', '--user'],
        ] as $operands) {
            [$exit, , $stderr] = $this->runCli($this->project, 'bundles', ...$operands);

            self::assertSame(NonInteractive::EXIT_CONFIG, $exit, 'refused as usage: ' . implode(' ', $operands));
            self::assertStringContainsString(
                Lang::t('cli.bundles.scope_not_install', ['verb' => $operands[0]]),
                $stderr,
                'the refusal names the scope flag and the verb: ' . implode(' ', $operands),
            );
        }

        self::assertDirectoryDoesNotExist($this->project . '/.candy-code/bundles', 'a refused verb writes nothing');
    }

    public function testUpdateKeepsAProjectTierBundleInItsTier(): void
    {
        $source = $this->addPathMarketplace('demo', 'alpha');
        self::assertSame(
            NonInteractive::EXIT_OK,
            $this->verb('install', 'alpha@demo', '--project', '--root', $this->project),
        );
        self::assertSame('v1 for alpha', file_get_contents($this->project . '/.candy-code/bundles/alpha/UPSTREAM.txt'));

        file_put_contents($source . '/plugins/alpha/UPSTREAM.txt', 'v2 for alpha');

        self::assertSame(NonInteractive::EXIT_OK, $this->verb('update', 'alpha', '--root', $this->project));

        self::assertSame(
            'v2 for alpha',
            file_get_contents($this->project . '/.candy-code/bundles/alpha/UPSTREAM.txt'),
            'the refresh lands in the project tier, where the bundle was installed',
        );
        self::assertDirectoryDoesNotExist($this->root . '/alpha', 'an update must not move a project bundle into the user root');
        self::assertSame(['alpha'], $this->listed($this->project . '/.candy-code/bundles'), 'no .swap- or .install- residue');
    }

    public function testUpdateOfAProjectBundleWithNoOwnedHomeIsRefusedByNameAndLeftWhereItIs(): void
    {
        $source = $this->addPathMarketplace('demo', 'alpha');
        self::assertSame(
            NonInteractive::EXIT_OK,
            $this->verb('install', 'alpha@demo', '--project', '--root', $this->project),
        );
        file_put_contents($source . '/plugins/alpha/UPSTREAM.txt', 'v2 for alpha');

        [$exit, , $stderr] = $this->runCliAs('relative-home', $this->project, 'bundles', 'update', 'alpha', '--root', $this->project);

        self::assertSame(
            NonInteractive::EXIT_FAILURE,
            $exit,
            'the pins live in the user registry, which an unowned home cannot read: a named update refuses, it never re-stages from the record',
        );
        self::assertStringContainsString(
            Lang::t('cli.bundles.update.no_home', ['name' => 'alpha']),
            $stderr,
            'the refusal names the bundle it refused, on the update verb',
        );
        self::assertStringNotContainsString('bundles install:', $stderr, 'an update refusal must not borrow the install verb');
        self::assertSame(
            'v1 for alpha',
            file_get_contents($this->project . '/.candy-code/bundles/alpha/UPSTREAM.txt'),
            'the refused update left the project tree exactly as it was',
        );
        self::assertSame(['alpha'], $this->listed($this->project . '/.candy-code/bundles'), 'no .swap- or .install- residue');
        self::assertDirectoryDoesNotExist($this->root . '/alpha', 'a refused project update must not create a user-tier copy');
    }

    public function testAnUnnamedUpdateWithNoOwnedHomeWalksTheProjectTierAndSkipsItWithoutFailing(): void
    {
        $source = $this->addPathMarketplace('demo', 'alpha');
        self::assertSame(
            NonInteractive::EXIT_OK,
            $this->verb('install', 'alpha@demo', '--project', '--root', $this->project),
        );
        file_put_contents($source . '/plugins/alpha/UPSTREAM.txt', 'v2 for alpha');

        [$exit, , $stderr] = $this->runCliAs('relative-home', $this->project, 'bundles', 'update', '--root', $this->project);

        self::assertSame(
            NonInteractive::EXIT_OK,
            $exit,
            'a scanned root reports and skips a bundle it cannot re-resolve, as it does for a gone marketplace; the project root is still walked, so the old whole-verb refusal is gone',
        );
        self::assertStringContainsString(
            Lang::t('cli.bundles.update.no_home', ['name' => 'alpha']),
            $stderr,
            'the walk still refuses each project bundle by name, so the operator can see which one was skipped',
        );
        self::assertSame('v1 for alpha', file_get_contents($this->project . '/.candy-code/bundles/alpha/UPSTREAM.txt'));
    }

    public function testUpdateWithNoOwnedHomeAndNoProjectRootStillRefusesWithTheHomeLine(): void
    {
        $this->unownHome();

        self::assertSame(NonInteractive::EXIT_FAILURE, $this->verb('update', '--root', $this->project));
        self::assertDirectoryDoesNotExist($this->project . '/.candy-code', 'an update never creates a bundle root');
    }

    public function testAnUnnamedUpdateRefreshesEachBundleInTheTierItOccupies(): void
    {
        $alphaSource = $this->addPathMarketplace('demo', 'alpha');
        $betaSource = $this->addPathMarketplace('other', 'beta');
        self::assertSame(NonInteractive::EXIT_OK, $this->verb('install', 'alpha@demo'));
        self::assertSame(
            NonInteractive::EXIT_OK,
            $this->verb('install', 'beta@other', '--project', '--root', $this->project),
        );

        file_put_contents($alphaSource . '/plugins/alpha/UPSTREAM.txt', 'v2 for alpha');
        file_put_contents($betaSource . '/plugins/beta/UPSTREAM.txt', 'v2 for beta');

        [$exit, $out] = $this->verbCapture('update', '--root', $this->project);

        self::assertSame(NonInteractive::EXIT_OK, $exit);
        self::assertSame(1, substr_count($out, 'Updated bundle alpha from'), 'alpha is refreshed once, in the user root');
        self::assertSame(1, substr_count($out, 'Updated bundle beta from'), 'beta is refreshed once, in the project root');
        self::assertSame('v2 for alpha', file_get_contents($this->root . '/alpha/UPSTREAM.txt'));
        self::assertSame('v2 for beta', file_get_contents($this->project . '/.candy-code/bundles/beta/UPSTREAM.txt'));
        self::assertDirectoryDoesNotExist($this->root . '/beta', 'the project bundle is not moved to the user root');
        self::assertDirectoryDoesNotExist($this->project . '/.candy-code/bundles/alpha', 'the user bundle is not copied into the project');
    }

    public function testAnUnnamedUpdateFromTheHomeDirectoryWalksTheUserRootOnce(): void
    {
        $source = $this->addPathMarketplace('demo', 'alpha');
        self::assertSame(NonInteractive::EXIT_OK, $this->verb('install', 'alpha@demo'));
        file_put_contents($source . '/plugins/alpha/UPSTREAM.txt', 'v2 for alpha');

        // The working directory IS the home directory, so the project root
        // (`<cwd>/.candy-code/bundles`) names the user root a second time.
        $before = getcwd();
        self::assertNotFalse($before);
        chdir($this->home);

        try {
            [$exit, $out] = $this->verbCapture('update');
        } finally {
            chdir($before);
        }

        self::assertSame(NonInteractive::EXIT_OK, $exit);
        self::assertSame(1, substr_count($out, 'Updated bundle alpha from'), 'one root, walked once: one refresh line');
        self::assertSame('v2 for alpha', file_get_contents($this->root . '/alpha/UPSTREAM.txt'));
    }

    public function testANameInBothTiersIsRefusedByUpdateAndNeitherCopyIsTouched(): void
    {
        $this->plainBundleAt($this->root . '/dup', 'dup');
        $this->plainBundleAt($this->project . '/.candy-code/bundles/dup', 'dup');

        [$exit, , $stderr] = $this->runCli($this->project, 'bundles', 'update', 'dup');

        self::assertSame(NonInteractive::EXIT_FAILURE, $exit);
        self::assertStringContainsString(
            Lang::t('cli.bundles.update.ambiguous', ['name' => 'dup']),
            $stderr,
            'update must refuse to choose between the two tiers, not re-stage one of them',
        );
        self::assertFileExists($this->root . '/dup/.claude-plugin/plugin.json');
        self::assertFileExists($this->project . '/.candy-code/bundles/dup/.claude-plugin/plugin.json');
    }

    public function testAProjectInstallIsRefusedWhenTheUserTierAlreadyHoldsTheName(): void
    {
        $this->plainBundleAt($this->root . '/alpha', 'alpha');
        $source = $this->stagedBundle('alpha-src', 'alpha');

        [$exit, , $stderr] = $this->runCli($this->project, 'bundles', 'install', $source, '--project', '--root', $this->project);

        self::assertSame(NonInteractive::EXIT_FAILURE, $exit);
        self::assertStringContainsString(
            'bundles install: a bundle named alpha is already installed at',
            $stderr,
            'the install must refuse the cross-tier duplicate with its own line',
        );
        self::assertDirectoryDoesNotExist($this->project . '/.candy-code/bundles/alpha', 'a refused install writes nothing');
        self::assertFileExists($this->root . '/alpha/.claude-plugin/plugin.json', 'the user-tier copy is untouched');
    }

    public function testAUserInstallIsRefusedWhenTheProjectTierAlreadyHoldsTheName(): void
    {
        $this->plainBundleAt($this->project . '/.candy-code/bundles/alpha', 'alpha');
        $source = $this->stagedBundle('alpha-src', 'alpha');

        [$exit, , $stderr] = $this->runCli($this->project, 'bundles', 'install', $source);

        self::assertSame(NonInteractive::EXIT_FAILURE, $exit);
        self::assertStringContainsString(
            'bundles install: a bundle named alpha is already installed at',
            $stderr,
            'the bare install must refuse a name the project tier already holds',
        );
        self::assertDirectoryDoesNotExist($this->root . '/alpha', 'a refused install writes nothing');
        self::assertFileExists($this->project . '/.candy-code/bundles/alpha/.claude-plugin/plugin.json', 'the project-tier copy is untouched');
    }

    public function testAProjectInstallFromTheHomeDirectoryIsRefusedInsteadOfLandingInTheUserTier(): void
    {
        mkdir($this->home . '/.candy-code', 0777, true);
        $source = $this->stagedBundle('demo-src', 'demo');

        [$exit, , $stderr] = $this->runCli($this->home, 'bundles', 'install', $source, '--project');

        self::assertSame(NonInteractive::EXIT_FAILURE, $exit);
        self::assertStringContainsString(
            'bundles install: --project resolves to your home directory',
            $stderr,
            'the home directory is the user tier, so --project there is refused, not collapsed',
        );
        self::assertDirectoryDoesNotExist($this->root . '/demo', 'nothing lands in the user root under the project scope');
    }

    public function testRecommendDoesNotOfferBackABundleTheProjectAlreadyInstalledWithProject(): void
    {
        // composer.json is a manifest signal whose vocabulary includes `php`,
        // so a keyword-`php` catalog entry is a recommendation for this project.
        file_put_contents($this->project . '/composer.json', '{}');
        $this->addPathMarketplace('demo', 'alpha', keywords: ['php']);

        self::assertContains('alpha', $this->recommendedNames(), 'before the install the entry is offered');

        self::assertSame(
            NonInteractive::EXIT_OK,
            $this->verb('install', 'alpha@demo', '--project', '--root', $this->project),
        );

        self::assertNotContains(
            'alpha',
            $this->recommendedNames(),
            'an install in the project tier counts as installed for recommend in that project',
        );
    }

    public function testTheProjectBundleRootIsCreatedAtTheDeclaredModeBeforeTheUmask(): void
    {
        $source = $this->stagedBundle('demo-src', 'demo');

        self::assertSame(NonInteractive::EXIT_OK, $this->verb('install', $source, '--project', '--root', $this->project));

        $expected = decoct(0755 & ~umask());
        self::assertSame(
            $expected,
            decoct(fileperms($this->project . '/.candy-code/bundles') & 0777),
            'the project bundle root is created 0755, less the umask',
        );
        self::assertSame(
            $expected,
            decoct(fileperms($this->project . '/.candy-code') & 0777),
            'the .candy-code segment the install creates takes the same mode',
        );
    }

    public function testAProjectTierReachedThroughASymlinkIsNotReadByUpdate(): void
    {
        $source = $this->addPathMarketplace('demo', 'alpha');
        $staging = $this->tempDir . '/staging';
        mkdir($staging, 0777, true);
        self::assertSame(NonInteractive::EXIT_OK, $this->verb('install', 'alpha@demo', '--project', '--root', $staging));

        // The install was real; the checkout then swaps its dot directory for a
        // link into a tree outside the project, which update must not walk.
        $linked = $this->tempDir . '/linked-tier';
        mkdir($linked, 0777, true);
        rename($staging . '/.candy-code', $linked . '/.candy-code');
        symlink($linked . '/.candy-code', $this->project . '/.candy-code');

        file_put_contents($source . '/plugins/alpha/UPSTREAM.txt', 'v2 for alpha');

        [$exit, $out] = $this->verbCapture('update', '--root', $this->project);

        self::assertSame(NonInteractive::EXIT_OK, $exit);
        self::assertStringNotContainsString('Updated bundle alpha', $out, 'the linked tier is not a root update walks');
        self::assertSame(
            'v1 for alpha',
            file_get_contents($linked . '/.candy-code/bundles/alpha/UPSTREAM.txt'),
            'the bytes behind the symlink are untouched',
        );

        self::assertSame(
            NonInteractive::EXIT_FAILURE,
            $this->verb('update', 'alpha', '--root', $this->project),
            'a named update does not find alpha behind the link either',
        );
        self::assertSame('v1 for alpha', file_get_contents($linked . '/.candy-code/bundles/alpha/UPSTREAM.txt'));
    }

    public function testAProjectTierReachedThroughASymlinkIsNotReadByRecommend(): void
    {
        // The project's composer.json offers the `php`-keyword entry; the install
        // sits behind a symlinked dot directory, so it is not counted as installed.
        file_put_contents($this->project . '/composer.json', '{}');
        $this->addPathMarketplace('demo', 'alpha', keywords: ['php']);
        $linked = $this->tempDir . '/linked-tier';
        $this->plainBundleAt($linked . '/.candy-code/bundles/alpha', 'alpha');
        symlink($linked . '/.candy-code', $this->project . '/.candy-code');

        self::assertContains(
            'alpha',
            $this->recommendedNames(),
            'discovery refuses a symlinked project floor, so recommend does not read it either',
        );
    }

    /** A real bundle directory to install FROM, returned as its path. */
    public function testAProjectInstallFromASubdirectoryLandsInTheRepositoryRootAndWritesNoMarkerBelowIt(): void
    {
        $repo = $this->gitRepo('repo');
        $source = $this->stagedBundle('demo-src', 'demo');
        $src = $repo . '/src';
        mkdir($src, 0777, true);

        $this->inDirectory($src, function () use ($source): void {
            self::assertSame(NonInteractive::EXIT_OK, $this->verb('install', $source, '--project'));
        });

        self::assertFileExists($repo . '/.candy-code/bundles/demo/.claude-plugin/plugin.json');
        self::assertDirectoryDoesNotExist($src . '/.candy-code', 'an install must not make the subdirectory its own project root');
        ProjectRoot::forget();
        self::assertSame($repo, ProjectRoot::resolve($src), 'settings keep resolving to the repository, not the subdirectory');
    }

    public function testTheProjectRootFlagNamingASubdirectoryInstallsIntoTheRepositoryRoot(): void
    {
        $repo = $this->gitRepo('repo');
        $source = $this->stagedBundle('demo-src', 'demo');
        $src = $repo . '/src';
        mkdir($src, 0777, true);

        self::assertSame(NonInteractive::EXIT_OK, $this->verb('install', $source, '--project', '--root', $src));

        self::assertFileExists($repo . '/.candy-code/bundles/demo/.claude-plugin/plugin.json');
        self::assertDirectoryDoesNotExist($src . '/.candy-code');
    }

    public function testBundlesListFromASubdirectoryShowsTheRepositoryProjectInstall(): void
    {
        $repo = $this->gitRepo('repo');
        $source = $this->stagedBundle('demo-src', 'demo');
        $src = $repo . '/src';
        mkdir($src, 0777, true);

        $this->inDirectory($repo, function () use ($source): void {
            self::assertSame(NonInteractive::EXIT_OK, $this->verb('install', $source, '--project'));
        });

        [$exit, $out] = $this->inDirectory($src, fn (): array => $this->verbCapture('list'));

        self::assertSame(NonInteractive::EXIT_OK, $exit);
        self::assertMatchesRegularExpression('/^demo\s.*\bproject\b/m', $out, 'list from a subdirectory reads the repository project tier');
    }

    public function testTheLaunchDiscoversTheRepositoryProjectTierFromASubdirectory(): void
    {
        $repo = $this->gitRepo('repo');
        $source = $this->stagedBundle('demo-src', 'demo');
        $src = $repo . '/src';
        mkdir($src, 0777, true);

        $this->inDirectory($repo, function () use ($source): void {
            self::assertSame(NonInteractive::EXIT_OK, $this->verb('install', $source, '--project'));
        });

        $found = [];
        foreach (Bootstrap::bundles($src) as $bundle) {
            $found[$bundle->name] = $bundle->tier;
        }

        self::assertSame(['demo' => 'project'], $found, 'a launch from a subdirectory sees the bundle the repository install placed');
    }

    private function gitRepo(string $name): string
    {
        $dir = $this->tempDir . '/' . $name;
        mkdir($dir, 0777, true);
        exec('git -C ' . escapeshellarg($dir) . ' init -q 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));

        return (string) realpath($dir);
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function inDirectory(string $dir, callable $work): mixed
    {
        $before = getcwd();
        self::assertNotFalse($before);

        chdir($dir);

        try {
            return $work();
        } finally {
            chdir($before);
        }
    }

    private function stagedBundle(string $folder, string $name): string
    {
        $dir = $this->tempDir . '/' . $folder;
        $this->plainBundleAt($dir, $name);
        mkdir($dir . '/commands', 0777, true);
        file_put_contents($dir . '/commands/hello.md', "---\ndescription: say hi\n---\nbody\n");

        return $dir;
    }

    private function plainBundleAt(string $dir, string $name): void
    {
        mkdir($dir . '/.claude-plugin', 0777, true);
        file_put_contents(
            $dir . '/.claude-plugin/plugin.json',
            json_encode(['name' => $name, 'version' => '1.0.0']),
        );
    }

    /**
     * A relative HOME is never an owned home ({@see \SugarCraft\Code\Support\HomeDirectory::owned()}
     * refuses a non-absolute path), so the user root and the user registry read as absent. The
     * sandbox restore in tearDown puts the real HOME back.
     */
    private function unownHome(): void
    {
        putenv('HOME=relative-home');
        $_SERVER['HOME'] = 'relative-home';
    }

    /** @return list<string> the names `bundles recommend` offers for the project, in its JSON form */
    private function recommendedNames(): array
    {
        [, $out] = $this->verbCapture('recommend', '--root', $this->project, '--output-format', 'json');
        $decoded = json_decode($out, true);
        self::assertIsArray($decoded, 'recommend --output-format json prints one JSON document');

        return array_values(array_map(
            static fn (array $row): string => (string) $row['name'],
            (array) ($decoded['recommendations'] ?? []),
        ));
    }

    private function verb(string ...$operands): int
    {
        $output = ob_start();

        try {
            return Subcommands::dispatch(ArgvParser::parse(array_merge(['candy-code', 'bundles'], $operands)));
        } finally {
            if ($output !== false) {
                ob_end_clean();
            }
        }
    }

    /** @return array{0: int, 1: string} the exit code and the stdout of one in-process dispatch */
    private function verbCapture(string ...$operands): array
    {
        ob_start();

        try {
            $exit = Subcommands::dispatch(ArgvParser::parse(array_merge(['candy-code', 'bundles'], $operands)));
        } finally {
            $captured = (string) ob_get_clean();
        }

        return [$exit, $captured];
    }

    /**
     * One real `bin/candy-code bundles ...` process, with the sandboxed home and
     * a fixed locale, so its stderr is the refusal line a person would see.
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runCli(string $cwd, string ...$operands): array
    {
        return $this->runCliAs($this->home, $cwd, ...$operands);
    }

    /**
     * {@see runCli()} with an explicit HOME. A value HomeDirectory::owned()
     * rejects (`relative-home`) drives the owner-less path in a real process,
     * where the in-process unownHome() would print to the test runner's stderr.
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runCliAs(string $home, string $cwd, string ...$operands): array
    {
        $bin = dirname(__DIR__, 2) . '/bin/candy-code';
        $process = proc_open(
            [PHP_BINARY, $bin, ...$operands],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            ['HOME' => $home, 'PATH' => (string) getenv('PATH'), 'LC_ALL' => 'C', 'LANG' => 'C'],
        );

        if (!is_resource($process)) {
            self::fail('could not start candy-code for: ' . implode(' ', $operands));
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /** @return list<string> every entry directly under a directory, dot-named ones included */
    private function listed(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $names = [];
        foreach ((array) scandir($dir) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $names[] = $entry;
            }
        }

        sort($names);

        return $names;
    }
}
