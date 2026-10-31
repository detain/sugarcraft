<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\Sandbox;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\LaunchContext;
use SugarCraft\Code\Config\LayeredSettings;
use SugarCraft\Code\Config\Settings\SettingsSchema;
use SugarCraft\Code\Tools\Sandbox\Bubblewrap;
use SugarCraft\Code\Tools\Sandbox\SandboxFactory;

/**
 * C-TOOL-4.c: the three hardenings bubblewrap gained on top of the plain jail.
 *
 *  1. `--unshare-user --disable-userns` ({@see Bubblewrap::HARDENED_FLAGS})
 *     by default, so a jailed command cannot create the fresh user namespace
 *     that is the ordinary route back out of a mount jail;
 *  2. the two-stage probe that decides whether THIS host accepts them —
 *     hardened first, plain only on failure, and the plain failure still a
 *     refusal, because falling back DOWN to a weaker jail is allowed and
 *     falling OUT of the jail is not;
 *  3. the `--config` file re-bound read-only when it sits under the writable
 *     root, so a command cannot rewrite the next launch's user-tier policy
 *     from inside the jail that file switches on.
 *
 * NO SPAWNS, ON PURPOSE. Every leg drives {@see Bubblewrap::probe()} with a
 * closure that answers for the exec, so the ladder is exercised on a host that
 * has no bwrap, one that restricts userns, and one whose bwrap predates 0.8 —
 * states a single CI box cannot manufacture. Each leg names a per-process
 * sentinel binary: the probe verdict is memoised by binary, and a shared key
 * would let one leg's synthetic answer decide another's argv. The real-binary
 * arms — which of them this host actually takes — are
 * {@see \SugarCraft\Code\Tests\Tools\BashSandboxTest::testTheRealBubblewrapConfinesOrRefuses()},
 * the only live verification the flag pair gets, because the full suite never
 * runs on macOS and this host carries a modern bwrap.
 */
final class BubblewrapHardeningTest extends TestCase
{
    private string $sandboxDir = '';
    private string $root = '';
    private string $home = '';
    private int $leg = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandboxDir = sys_get_temp_dir() . '/bwrap_harden_' . getmypid() . '_' . uniqid('', true);
        $this->root = $this->sandboxDir . '/repo';
        $this->home = $this->sandboxDir . '/home';
        mkdir($this->root, 0o700, true);
        mkdir($this->home . '/.candy-code', 0o700, true);
        $this->root = (string) realpath($this->root);
        $this->home = (string) realpath($this->home);
    }

    protected function tearDown(): void
    {
        LaunchContext::useConfigPath(null);

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->sandboxDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($this->sandboxDir);

        parent::tearDown();
    }

    // ─── 1. the hardened flag pair ───────────────────────────────────────

    public function testTheHardenedFlagsRideThePlainJailByDefault(): void
    {
        $argv = Bubblewrap::new($this->sentinel())->argv($this->root, false, '');

        self::assertSame(Bubblewrap::HARDENED_FLAGS, ['--unshare-user', '--disable-userns']);
        self::assertTrue($this->carriesPair($argv, Bubblewrap::HARDENED_FLAGS), 'a host nobody probed runs the safer set');
        self::assertTrue(Bubblewrap::new($this->sentinel())->hardened());
    }

    public function testOptingInToNestedNamespacesDropsTheFlags(): void
    {
        $argv = Bubblewrap::new($this->sentinel())->withNestedUserns(true)->argv($this->root, false, '');

        self::assertNotContains('--disable-userns', $argv);
        self::assertNotContains('--unshare-user', $argv);
        self::assertContains('--unshare-all', $argv, 'the opt-out is one flag pair, not the jail');

        $ptyArgv = Bubblewrap::new($this->sentinel())->withNestedUserns(true)->argv($this->root, true, '');
        self::assertNotContains('--new-session', $ptyArgv);
        self::assertNotContains('--disable-userns', $ptyArgv, 'the pty form is the same jail minus the detach');
    }

    public function testTheDescriptionOnlyClaimsHostIncapabilityAfterTheProbe(): void
    {
        // Unknown host = no clause: the sentence goes into the tool
        // description, and guessing at a host fact there is a claim the jail
        // has not earned.
        $unprobed = Bubblewrap::new($this->sentinel());
        self::assertStringNotContainsString('nested user namespaces', $unprobed->describe($this->root));

        $unhardened = Bubblewrap::new($this->sentinel())->withNestedUserns(false);
        self::assertNull($unhardened->probe($this->runner(hardened: false, plain: true)));
        self::assertFalse($unhardened->hardened(), 'the memo is what argv() and describe() read');
        $said = $unhardened->describe($this->root);
        self::assertStringContainsString("cannot block nested user namespaces", $said);
        self::assertStringContainsString('Read-only file system', $said, 'the rest of the sentence is the standing one');

        $optedIn = Bubblewrap::new($this->sentinel())->withNestedUserns(true);
        self::assertNull($optedIn->probe($this->runner(hardened: false, plain: true)));
        self::assertStringNotContainsString('cannot block', $optedIn->describe($this->root), 'a jail the operator relaxed must not be described as one the host refused');
    }

    // ─── 2. the two-stage probe ──────────────────────────────────────────

    public function testTheProbeRunsTheHardenedSetFirstAndStopsThere(): void
    {
        $seen = [];
        $jail = Bubblewrap::new($this->sentinel());

        self::assertNull($jail->probe($this->runner(hardened: true, plain: true, sink: $seen)));
        self::assertCount(1, $seen, 'a host that accepts the hardened set costs one spawn');
        self::assertStringContainsString('--disable-userns', $seen[0]);
        self::assertTrue($jail->hardened());
        self::assertTrue($this->carriesPair($jail->argv($this->root, false, ''), Bubblewrap::HARDENED_FLAGS));
    }

    public function testThePlainSetIsTriedOnlyWhenTheHardenedOneFails(): void
    {
        $seen = [];
        $jail = Bubblewrap::new($this->sentinel());

        self::assertNull($jail->probe($this->runner(hardened: false, plain: true, sink: $seen)));
        self::assertCount(2, $seen, 'stage one is the hardened set, and stage two only runs after it');
        self::assertStringContainsString('--disable-userns', $seen[0]);
        self::assertStringNotContainsString('--disable-userns', $seen[1], 'the fallback is the plain flag set');
        self::assertNotContains('--disable-userns', $jail->argv($this->root, false, ''), 'argv follows the host answer');

        // The verdict is memoised: a second probe is no spawn at all.
        $again = [];
        self::assertNull($jail->probe($this->runner(hardened: true, plain: true, sink: $again)));
        self::assertSame([], $again);
    }

    public function testAFailingPlainSetStillRefusesWithItsOwnReason(): void
    {
        $seen = [];
        $jail = Bubblewrap::new($this->sentinel());
        $death = 'bwrap: setting up uid map: Permission denied';

        $reason = $jail->probe($this->runner(hardened: false, plain: false, plainStderr: $death, sink: $seen));

        self::assertSame($death, $reason, 'the refusal names the plain set — that is the set the jail would have run');
        self::assertCount(2, $seen);
        self::assertFalse($jail->hardened());
        self::assertStringContainsString($death, $jail->probe($this->runner(hardened: true, plain: true)));
        // A poisoned-looking host answer stays memoised: the closure above that
        // would have said "starts" is never reached, so the refusal of a host
        // cannot flip mid-process.
    }

    public function testASetThatDiesWithoutStderrStillReportsAnExitCode(): void
    {
        $binary = $this->sentinel();
        $jail = Bubblewrap::new($binary);

        self::assertSame($binary . ' exited 1', $jail->probe($this->runner(hardened: false, plain: false, plainExit: 1)));
    }

    public function testTheMemoIsKeyedByBinaryAndNetworkNotByTheSetting(): void
    {
        $shared = $this->sentinel();
        $host = Bubblewrap::new($shared, true);
        self::assertNull($host->probe($this->runner(hardened: false, plain: true)));

        self::assertFalse(Bubblewrap::new($shared, true)->hardened(), 'the host answer was recorded');
        self::assertTrue(Bubblewrap::new($this->sentinel(), true)->hardened(), 'another binary is another host fact');
        self::assertFalse(Bubblewrap::new($shared, true)->withNestedUserns(true)->hardened(), 'opting out is not a host fact');

        $noSpawn = static fn (string $command): array => throw new \LogicException('the memo must answer without a spawn');
        self::assertNull(Bubblewrap::new($shared, true)->probe($noSpawn));
    }

    // ─── 3. the --config file and the home root ──────────────────────────

    public function testAConfigFileInsideTheRootIsReBoundReadOnly(): void
    {
        $config = $this->root . '/launch.json';
        file_put_contents($config, "{}\n");
        $argv = Bubblewrap::new($this->sentinel())->withProtectedPaths([$config])->argv($this->root, false, '');

        self::assertTrue($this->carriesPair($argv, ['--ro-bind-try', $config, $config]), 'later binds win over the root bind');
        self::assertGreaterThan(
            $this->flagIndex($argv, '--bind', $this->root),
            $this->flagIndex($argv, '--ro-bind-try', $config),
            'the root is bound writable first and the file read-only over it',
        );

        // A file that does not exist yet is no different: --ro-bind-try is the
        // try-variant, and the jail must not die on a launch flag pointing at
        // a path created later.
        $missing = Bubblewrap::new($this->sentinel())
            ->withProtectedPaths([$this->root . '/not-yet.json'])
            ->argv($this->root, false, '');
        self::assertTrue($this->carriesPair($missing, ['--ro-bind-try', $this->root . '/not-yet.json', $this->root . '/not-yet.json']));
    }

    public function testAConfigFileOutsideTheRootIsUntouched(): void
    {
        $outside = $this->sandboxDir . '/elsewhere.json';
        file_put_contents($outside, "{}\n");
        $argv = Bubblewrap::new($this->sentinel())->withProtectedPaths([$outside])->argv($this->root, false, '');

        self::assertNotContains($outside, $argv, 'the path appears nowhere: it is already read-only under --ro-bind / /');
        self::assertFalse($this->carriesPair($argv, ['--ro-bind-try', $outside, $outside]));
    }

    public function testTheRootItselfIsNeverReBoundReadOnly(): void
    {
        $argv = Bubblewrap::new($this->sentinel())->withProtectedPaths([$this->root])->argv($this->root, false, '');

        self::assertFalse($this->carriesPair($argv, ['--ro-bind-try', $this->root, $this->root]), 'that would undo the jail it is a bind of');
        self::assertTrue($this->carriesPair($argv, ['--bind', $this->root, $this->root]));
    }

    public function testAHomeRootProtectsCandyCodeAndNoOtherRootReachesHome(): void
    {
        $inHome = Bubblewrap::new($this->sentinel())->argv($this->home, false, '');
        self::assertTrue(
            $this->carriesPair($inHome, ['--ro-bind-try', $this->home . '/.candy-code', $this->home . '/.candy-code']),
            'with the home as the working root, the settings tree is an escape hatch',
        );

        $inRepo = Bubblewrap::new($this->sentinel())->argv($this->root, false, '');
        foreach ($this->boundWritable($inRepo) as $path) {
            self::assertStringStartsNotWith($this->home, $path, 'a repository root must never make anything under the home writable');
        }
        self::assertTrue($this->carriesPair($inRepo, ['--ro-bind', '/', '/']));
    }

    // ─── 4. the setting and the factory that reads it ────────────────────

    public function testTheSchemaRowIsUserTierOnlyAndDefaultsFalse(): void
    {
        $definition = SettingsSchema::byKey(SandboxFactory::NESTED_USERNS_KEY);

        self::assertNotNull($definition);
        self::assertFalse($definition->default);
        self::assertTrue($definition->layered);
        self::assertFalse($definition->projectSettable, 'true relaxes the jail, so no project may ask for it');
        self::assertContains(SandboxFactory::NESTED_USERNS_KEY, LayeredSettings::LAYERED_KEYS);
        self::assertNotContains(SandboxFactory::NESTED_USERNS_KEY, LayeredSettings::PROJECT_TIER_KEYS);
    }

    public function testTheFactoryHandsTheSettingToTheJailAndOnlyForALiteralTrue(): void
    {
        $key = SandboxFactory::NESTED_USERNS_KEY;

        $on = SandboxFactory::fromSetting('on', 'Linux', [$key => true]);
        self::assertInstanceOf(Bubblewrap::class, $on);
        self::assertTrue($on->nestedUserns);
        self::assertNotContains('--disable-userns', $on->argv($this->root, false, ''));

        foreach ([[], [$key => false], [$key => 'true'], [$key => 1], [$key => null]] as $reading) {
            $jail = SandboxFactory::fromSetting('on', 'Linux', $reading);
            self::assertInstanceOf(Bubblewrap::class, $jail);
            self::assertFalse($jail->nestedUserns, json_encode($reading) . ' must not read as an opt-out of the hardening');
            self::assertTrue($this->carriesPair($jail->argv($this->root, false, ''), Bubblewrap::HARDENED_FLAGS));
        }
    }

    public function testTheFactoryCarriesTheConfigOverrideOntoTheJail(): void
    {
        $config = $this->root . '/launch.json';
        file_put_contents($config, "{}\n");
        LaunchContext::useConfigPath($config);

        $jail = SandboxFactory::fromSetting('on', 'Linux', []);
        self::assertInstanceOf(Bubblewrap::class, $jail);
        self::assertSame([$config], $jail->protectedPaths);
        self::assertTrue($this->carriesPair($jail->argv($this->root, false, ''), ['--ro-bind-try', $config, $config]));

        // An `off` jail is no jail at all, and nothing to carry it on.
        self::assertNull(SandboxFactory::fromSetting('off', 'Linux', []));

        // macOS answers with Seatbelt, which has no user namespaces to block
        // and no bind table to carry the file on (named as a seam in the lane
        // report); the setting must not smuggle a Linux-only shape across.
        $mac = SandboxFactory::fromSetting('on', 'Darwin', [SandboxFactory::NESTED_USERNS_KEY => true]);
        self::assertNotInstanceOf(Bubblewrap::class, $mac);
    }

    public function testAnAbsentConfigOverrideLeavesTheJailWithNothingExtraToProtect(): void
    {
        self::assertNull(LaunchContext::configPathOverride());
        $jail = SandboxFactory::fromSetting('on', 'Linux', []);

        self::assertInstanceOf(Bubblewrap::class, $jail);
        self::assertSame([], $jail->protectedPaths);
    }

    // ─── helpers ─────────────────────────────────────────────────────────

    /**
     * A binary name nobody has a probe verdict for, so each leg starts from
     * the same unknown host. Per-process and per-leg: the memo is static for
     * the life of the PHP process the suite runs in.
     */
    private function sentinel(): string
    {
        return '/usr/bin/bwrap-' . getmypid() . '_' . uniqid('', true) . '-' . ++$this->leg;
    }

    /**
     * The stand-in for the caller's capture path: it answers for the exec
     * rather than performing one, and records the shell strings in order.
     *
     * @param list<string> $sink
     *
     * @return \Closure(string): array{exitCode: int, stderr: string}
     */
    private function runner(
        bool $hardened,
        bool $plain,
        ?array &$sink = null,
        string $plainStderr = '',
        int $plainExit = 0,
    ): \Closure {
        return static function (string $command) use ($hardened, $plain, &$sink, $plainStderr, $plainExit): array {
            $isHardened = str_contains($command, '--disable-userns');
            if ($sink !== null) {
                $sink[] = $command;
            }
            if ($isHardened) {
                return ['exitCode' => $hardened ? 0 : 1, 'stderr' => ''];
            }

            return ['exitCode' => $plain ? 0 : ($plainExit ?: 1), 'stderr' => $plainStderr];
        };
    }

    /**
     * @param list<string> $argv
     * @param list<string> $needle
     */
    private function carriesPair(array $argv, array $needle): bool
    {
        $keys = array_keys($argv, $needle[0], true);
        foreach ($keys as $i) {
            if (array_slice($argv, $i, count($needle)) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $argv
     */
    private function flagIndex(array $argv, string $flag, string $target): int
    {
        foreach ($argv as $i => $value) {
            if ($value === $flag && ($argv[$i + 1] ?? null) === $target) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * Every path a --bind makes WRITABLE, including the temp binds.
     *
     * @param list<string> $argv
     *
     * @return list<string>
     */
    private function boundWritable(array $argv): array
    {
        $paths = [];
        foreach ($argv as $i => $value) {
            if ($value === '--bind') {
                $paths[] = (string) ($argv[$i + 1] ?? '');
            }
        }

        return $paths;
    }
}
