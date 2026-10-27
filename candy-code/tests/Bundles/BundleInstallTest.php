<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Bundles;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
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
     * INT-M1 + PLG-1: `looksLikeGitUrl` admits any `.git`-suffixed string, so
     * the remote-helper shape `ext::sh -c …x.git` reaches the clone. The
     * defence is the argv itself pinning `-c protocol.ext.allow=never`, so the
     * refusal belongs to this spawn and not to the git version — and, since
     * PLG-1, a second belt `-c core.symlinks=false` right beside it, so git
     * checks symlinks out as plain files and the scratch this leg mints never
     * holds a live link for a cleanup or loader to follow. A source-slice pin
     * (not a live `git clone`) is what discriminates: modern git refuses `ext`
     * by default too, so a real-clone test would stay green with the belt
     * removed. The exact token order is asserted so dropping or reordering
     * either `-c` leg reddens this.
     */
    public function testTheDefaultCloneArgvPinsBothBeltsAheadOfTheCloneToken(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Cli/Subcommands.php');

        self::assertSame(
            1,
            substr_count($source, "\"git\", \"-c\", \"protocol.ext.allow=never\", \"-c\", \"core.symlinks=false\", \"clone\"")
                + substr_count($source, "'git', '-c', 'protocol.ext.allow=never', '-c', 'core.symlinks=false', 'clone'"),
            'bundlesCloneDefault() lost its -c belt pair ahead of the clone token — the symlink leg matters as much as the ext-transport leg',
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

    /**
     * PLG-1-P1: the clone seam builds a scratch carrying `link -> <outside
     * sentinel>`, and each failure exit below that hands the scratch to
     * removeTree(). is_dir() follows links, so the pre-fix cleanup descended
     * through the link and emptied the sentinel — a clone holding
     * `x -> ../../..` deleted $HOME on every refusal. The sentinel must
     * survive byte-intact and the scratch must still be gone.
     *
     * @return array<string, array{string}>
     */
    public static function scratchLinkCaseProvider(): array
    {
        return [
            'no manifest' => ['no-manifest'],
            'invalid name' => ['invalid-name'],
            'existing name' => ['existing-name'],
        ];
    }

    #[DataProvider('scratchLinkCaseProvider')]
    public function testAFailureExitNeverFollowsAScratchLinkOutsideTheRoot(string $case): void
    {
        $outside = $this->outsideSentinel();

        if ($case === 'existing-name') {
            $this->armFakeClone(static function (string $url, string $scratch): array {
                mkdir($scratch . '/.claude-plugin', 0777, true);
                file_put_contents(
                    $scratch . '/.claude-plugin/plugin.json',
                    json_encode(['name' => 'keeper']),
                );

                return ['ok' => true, 'detail' => ''];
            });
            self::assertSame(NonInteractive::EXIT_OK, $this->verb('install', 'https://example.invalid/keeper.git'));
        }

        // 'no-manifest' refuses at the manifest gate, 'invalid-name' at the
        // name gate, 'existing-name' at the overwrite gate — three different
        // removeTree() calls, all reached with the same linked scratch.
        $name = $case === 'invalid-name' ? 'bad name!' : 'keeper';
        $this->armFakeClone(static function (string $url, string $scratch) use ($case, $name, $outside): array {
            mkdir($scratch, 0777, true);
            if ($case !== 'no-manifest') {
                mkdir($scratch . '/.claude-plugin', 0777, true);
                file_put_contents(
                    $scratch . '/.claude-plugin/plugin.json',
                    json_encode(['name' => $name]),
                );
            }
            symlink($outside, $scratch . '/link');

            return ['ok' => true, 'detail' => ''];
        });

        self::assertSame(
            NonInteractive::EXIT_FAILURE,
            $this->verb('install', 'https://example.invalid/' . $case . '.git'),
        );

        self::assertSame(
            'do not touch',
            @file_get_contents($outside . '/canary.txt'),
            $case . ': the failure exit followed the scratch link and ate the canary',
        );
        self::assertSame('still here', @file_get_contents($outside . '/nested/keep.txt'));
        // The scratch itself is still removed; only the pre-install of the
        // existing-name case remains under the root.
        self::assertSame($case === 'existing-name' ? ['keeper'] : [], $this->entriesUnderRoot());
    }

    /**
     * PLG-1-P3: a scratch holding a link that points AT the scratch recursed
     * in the pre-fix removeTree() until the kernel's ELOOP ceiling stopped
     * the descent. The refusal now unlinks the link as a link and never
     * descends, and the verb terminates with its scratch gone.
     */
    public function testACyclicScratchLinkTerminatesInsteadOfRecursingForever(): void
    {
        $this->armFakeClone(static function (string $url, string $scratch): array {
            mkdir($scratch, 0777, true);
            file_put_contents($scratch . '/notes.txt', 'no manifest');
            symlink($scratch, $scratch . '/loop');

            return ['ok' => true, 'detail' => ''];
        });

        self::assertSame(NonInteractive::EXIT_FAILURE, $this->verb('install', 'https://example.invalid/loop.git'));
        self::assertSame([], $this->entriesUnderRoot());
    }

    /**
     * PLG-1-P3: a path source holding an outside link is refused — the copy
     * never follows it into the sentinel, no scratch leaks, and the source
     * tree is left as found.
     */
    public function testAPathSourceHoldingAnOutsideLinkIsRefusedAndNothingLeaks(): void
    {
        $outside = $this->outsideSentinel();
        $source = $this->stagedBundle('linked-src', name: 'linked-src');
        symlink($outside, $source . '/aaa-escape');

        self::assertSame(NonInteractive::EXIT_FAILURE, $this->verb('install', $source));
        self::assertSame([], $this->entriesUnderRoot());
        self::assertSame('do not touch', file_get_contents($outside . '/canary.txt'));
        self::assertTrue(is_link($source . '/aaa-escape'), 'the refusal must not consume the source');
    }

    /**
     * PLG-1-P3: the refusal NAMES the first link the walk meets — the string
     * that reaches `cli.bundles.install.link_refused` as {link}. Reached
     * through the copy itself (reflection on the private method), since the
     * message line is written to the verb's stderr and not to the transcript.
     */
    public function testTheCopyRefusalNamesTheFirstLinkItMeets(): void
    {
        $source = $this->stagedBundle('two-links-src', name: 'two-links');
        symlink($this->tempDir . '/no-such-place', $source . '/aaa-first');
        symlink($this->tempDir . '/no-such-place', $source . '/zzz-second');

        $result = (new ReflectionMethod(Subcommands::class, 'copyBundleTree'))
            ->invoke(null, $source, $this->tempDir . '/copy-out');

        self::assertIsArray($result);
        self::assertFalse($result['ok']);
        self::assertSame(
            $source . '/aaa-first',
            $result['link'],
            'the copy must refuse and name the first symlink the walk meets',
        );
    }

    /**
     * A directory OUTSIDE the bundle root holding a canary file and a nested
     * directory: the thing a link-following cleanup wrongly eats.
     */
    private function outsideSentinel(): string
    {
        $dir = $this->tempDir . '/outside-sentinel';
        mkdir($dir . '/nested', 0777, true);
        file_put_contents($dir . '/canary.txt', 'do not touch');
        file_put_contents($dir . '/nested/keep.txt', 'still here');

        return $dir;
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
