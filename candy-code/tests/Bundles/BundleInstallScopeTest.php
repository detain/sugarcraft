<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Bundles;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SugarCraft\Code\Bundles\BundleDiscovery;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Cli\Subcommands;
use SugarCraft\Code\Tests\Skills\TemporaryDirectoryTrait;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\PathMarketplaceFixtureTrait;

/**
 * PLG-2a: `bundles install --project` lands a bundle in the project tier,
 * `bundles update` keeps each bundle in the tier it occupies, and the scope
 * flags are refused everywhere they do not apply. The bare install keeps its
 * user-root destination, which {@see BundleInstallTest} pins unchanged; this
 * file pins only what the scope flag adds.
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

    public function testProjectAndUserTogetherAreRefusedAndNothingIsWritten(): void
    {
        $source = $this->stagedBundle('demo-src', 'demo');

        self::assertSame(
            NonInteractive::EXIT_CONFIG,
            $this->verb('install', $source, '--project', '--user', '--root', $this->project),
        );

        self::assertDirectoryDoesNotExist($this->project . '/.candy-code');
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

    public function testTheScopeFlagsAreRefusedOnEveryVerbThatIsNotInstall(): void
    {
        self::assertSame(NonInteractive::EXIT_OK, $this->verb('list', '--root', $this->project), 'list answers OK with nothing installed');

        self::assertSame(NonInteractive::EXIT_CONFIG, $this->verb('list', '--project'));
        self::assertSame(NonInteractive::EXIT_CONFIG, $this->verb('update', '--user'));
        self::assertSame(NonInteractive::EXIT_CONFIG, $this->verb('search', 'x', '--project'));
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

    public function testANameInBothTiersIsRefusedByUpdateAndNeitherCopyIsTouched(): void
    {
        $this->plainBundleAt($this->root . '/dup', 'dup');
        $this->plainBundleAt($this->project . '/.candy-code/bundles/dup', 'dup');

        self::assertSame(NonInteractive::EXIT_FAILURE, $this->verb('update', 'dup', '--root', $this->project));

        self::assertFileExists($this->root . '/dup/.claude-plugin/plugin.json');
        self::assertFileExists($this->project . '/.candy-code/bundles/dup/.claude-plugin/plugin.json');
    }

    /** A real bundle directory to install FROM, returned as its path. */
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
