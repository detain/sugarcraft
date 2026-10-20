<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Bundles;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Cli\Subcommands;
use SugarCraft\Code\Tests\Skills\TemporaryDirectoryTrait;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * PLG-1 commit 6: `bundles install` writes in exactly one place — the user
 * bundle root under a home this process can confirm it owns — and every
 * refusal is louder than the write. The clone leg runs through the
 * `$bundlesInstallSpawn` seam (the `$loginSpawn` idiom): these tests pin
 * WHERE the clone lands and WHAT the installer does with the answer, not
 * the network.
 */
final class BundleInstallTest extends TestCase
{
    use TemporaryDirectoryTrait;
    use HomeSandboxTrait;

    private string $tempDir;

    private string $home;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/candy-code-bundle-install-test-' . uniqid((string) getmypid(), true);
        mkdir($this->tempDir, 0777, true);
        $this->home = $this->useHomeSandbox($this->tempDir . '/home');
        $this->root = $this->home . '/.candy-code/bundles';
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Subcommands::class, 'bundlesInstallSpawn'))->setValue(null, null);
        $this->restoreHomeSandbox();
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testADirectorySourceLandsInTheOwnedBundleRootWithoutItsGitHistory(): void
    {
        $source = $this->stagedBundle('demo-src', name: 'demo-src', withGitDir: true);

        self::assertSame(NonInteractive::EXIT_OK, $this->verb('install', $source));
        self::assertFileExists($this->root . '/demo-src/.claude-plugin/plugin.json');
        self::assertFileExists($this->root . '/demo-src/commands/hello.md');
        self::assertDirectoryDoesNotExist($this->root . '/demo-src/.git');
    }

    public function testTheManifestNameBeatsTheSourceDirectoryName(): void
    {
        $source = $this->stagedBundle('downloaded-folder', name: 'fancy');

        self::assertSame(NonInteractive::EXIT_OK, $this->verb('install', $source));
        self::assertDirectoryExists($this->root . '/fancy');
    }

    public function testAnExistingInstallIsRefusedAndLeftUntouched(): void
    {
        $source = $this->stagedBundle('keeper');
        self::assertSame(NonInteractive::EXIT_OK, $this->verb('install', $source));
        file_put_contents($this->root . '/keeper/MINE.txt', 'kept');

        self::assertSame(NonInteractive::EXIT_FAILURE, $this->verb('install', $source));
        self::assertSame('kept', file_get_contents($this->root . '/keeper/MINE.txt'));
    }

    public function testADirectoryWithoutAManifestInstallsNothingAndLeaksNoScratch(): void
    {
        $source = $this->tempDir . '/plain';
        mkdir($source, 0777, true);
        file_put_contents($source . '/notes.txt', 'not a bundle');

        self::assertSame(NonInteractive::EXIT_FAILURE, $this->verb('install', $source));
        self::assertSame([], $this->entriesUnderRoot());
    }

    public function testAStringThatIsNeitherDirectoryNorGitShapeIsRefused(): void
    {
        self::assertSame(NonInteractive::EXIT_FAILURE, $this->verb('install', 'no-such-place-at-all'));
        self::assertSame([], $this->entriesUnderRoot());
    }

    public function testAGitUrlClonesIntoScratchInsideTheOwnedRootAndRenamesOnceValidated(): void
    {
        $recorded = [];
        $this->armFakeClone(static function (string $url, string $scratch) use (&$recorded): array {
            $recorded[] = ['url' => $url, 'scratch' => $scratch];
            mkdir($scratch . '/.claude-plugin', 0777, true);
            file_put_contents($scratch . '/.claude-plugin/plugin.json', json_encode(['name' => 'clonely']));

            return ['ok' => true, 'detail' => ''];
        });

        self::assertSame(NonInteractive::EXIT_OK, $this->verb('install', 'https://example.invalid/clonely.git'));

        self::assertCount(1, $recorded);
        self::assertSame('https://example.invalid/clonely.git', $recorded[0]['url']);
        self::assertStringStartsWith($this->root . '/.install-', $recorded[0]['scratch']);
        self::assertFileExists($this->root . '/clonely/.claude-plugin/plugin.json');
        self::assertSame(['clonely'], $this->entriesUnderRoot());
    }

    public function testAFailedCloneIsReportedAndLeavesNeitherInstallNorScratch(): void
    {
        $this->armFakeClone(static fn (): array => ['ok' => false, 'detail' => 'repository not found']);

        self::assertSame(NonInteractive::EXIT_FAILURE, $this->verb('install', 'https://example.invalid/gone.git'));
        self::assertSame([], $this->entriesUnderRoot());
    }

    public function testAManifestNamingSomethingOtherThanOneSafeSegmentRefusesTheWrite(): void
    {
        $source = $this->tempDir . '/evil';
        mkdir($source . '/.claude-plugin', 0777, true);
        file_put_contents($source . '/.claude-plugin/plugin.json', json_encode(['name' => '../../escape']));

        self::assertSame(NonInteractive::EXIT_FAILURE, $this->verb('install', $source));
        self::assertSame([], $this->entriesUnderRoot());
        self::assertDirectoryDoesNotExist($this->home . '/escape');
    }

    public function testTheVerbWithoutAnActionStillAnswersUsageWithExitTwo(): void
    {
        self::assertSame(NonInteractive::EXIT_CONFIG, $this->verb('frobnicate'));
    }

    /**
     * @param callable(string, string): array{ok: bool, detail: string} $fn
     */
    private function armFakeClone(callable $fn): void
    {
        (new ReflectionProperty(Subcommands::class, 'bundlesInstallSpawn'))->setValue(null, \Closure::fromCallable($fn));
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

    /** @return list<string> names directly under the bundle root */
    private function entriesUnderRoot(): array
    {
        if (!is_dir($this->root)) {
            return [];
        }

        $names = [];
        foreach ((array) scandir($this->root) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $names[] = $entry;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * INT-M1: `looksLikeGitUrl` admits any `.git`-suffixed string, so the
     * remote-helper shape `ext::sh -c …x.git` reaches the clone. The defence
     * is the argv itself pinning `-c protocol.ext.allow=never`, so the refusal
     * belongs to this spawn and not to the git version. A source-slice pin
     * (not a live `git clone`) is what discriminates: modern git refuses `ext`
     * by default too, so a real-clone test would stay green with the belt
     * removed. The exact token order is asserted so dropping or reordering the
     * `-c` leg reddens this.
     */
    public function testTheDefaultCloneArgvPinsTheExtTransportOffBeforeTheCloneToken(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Cli/Subcommands.php');

        self::assertSame(
            1,
            substr_count($source, "\"git\", \"-c\", \"protocol.ext.allow=never\", \"clone\"")
                + substr_count($source, "'git', '-c', 'protocol.ext.allow=never', 'clone'"),
            'bundlesCloneDefault() lost its -c protocol.ext.allow=never belt ahead of the clone token',
        );
    }

    /**
     * INT-M1: an `ext::sh` helper URL (which `looksLikeGitUrl` admits because
     * it ends in `.git`) is refused by the real clone leg and never executes
     * the helper — the canary `touch` never lands on disk. Robust whether or
     * not git is on PATH: a missing git is its own refusal with no spawn.
     */
    public function testAnExtHelperShapedSourceIsRefusedWithoutSpawningTheHelper(): void
    {
        $canary = $this->tempDir . '/ext-transport-canary.git';
        // A single token ending in `.git` so looksLikeGitUrl routes to clone;
        // if the ext helper ever ran, `touch` would create $canary.
        $source = 'ext::sh -c touch ' . $canary;

        self::assertSame(NonInteractive::EXIT_FAILURE, $this->verb('install', $source));
        self::assertFileDoesNotExist($canary, 'the ext:: remote helper executed — the transport belt is open');
        self::assertSame([], $this->entriesUnderRoot());
    }

    /** A real bundle directory to install FROM, returned as its path. */
    private function stagedBundle(string $folder, ?string $name = null, bool $withGitDir = false): string
    {
        $dir = $this->tempDir . '/' . $folder;
        mkdir($dir . '/.claude-plugin', 0777, true);
        mkdir($dir . '/commands', 0777, true);
        file_put_contents(
            $dir . '/.claude-plugin/plugin.json',
            json_encode(['name' => $name ?? $folder, 'version' => '1.0.0']),
        );
        file_put_contents($dir . '/commands/hello.md', "---\ndescription: say hi\n---\nbody\n");

        if ($withGitDir) {
            mkdir($dir . '/.git', 0777, true);
            file_put_contents($dir . '/.git/config', "[core]\n");
        }

        return $dir;
    }
}
