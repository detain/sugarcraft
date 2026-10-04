<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Config\Settings\SettingsSchema;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tools\BuiltIn\Bash;
use SugarCraft\Crush\Tools\Sandbox\Bubblewrap;

/**
 * Roadmap 5.12: the optional bubblewrap write-jail under `Bash`.
 *
 * NO SKIPS, ON PURPOSE. Whether a real `bwrap` can start is a property of the
 * host (Ubuntu 24.04's AppArmor userns restriction makes an installed one die
 * at "setting up uid map"), and the suite's skip roster reds on an unrostered
 * skip. So the real-binary test asserts BOTH arms — a working jail confines,
 * a non-working one refuses with nothing run — and everything else drives a
 * shim that stands in for bwrap by exec'ing what follows its `--`, which
 * exercises the wrap, the probe and the refusal on every host.
 */
final class BashSandboxTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tmpDir = '';
    private string $root = '';
    private string $configDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/bash_sandbox_' . getmypid() . '_' . uniqid('', true);
        $this->root = $this->tmpDir . '/repo';
        $home = $this->tmpDir . '/home';
        $this->configDir = $home . '/.sugar-crush';
        mkdir($this->root, 0o700, true);
        mkdir($this->configDir, 0o700, true);
        $this->root = (string) realpath($this->root);

        $this->useHomeSandbox($home);
    }

    protected function tearDown(): void
    {
        Bootstrap::useConfigPath(null);
        Bootstrap::useProjectRootForSettings(null);
        $this->restoreHomeSandbox();

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($this->tmpDir);

        parent::tearDown();
    }

    // ─── the setting ─────────────────────────────────────────────────────

    public function testTheSettingVocabulary(): void
    {
        self::assertNull(Bubblewrap::fromSetting(null));
        self::assertNull(Bubblewrap::fromSetting('off'));
        self::assertNull(Bubblewrap::fromSetting(false));
        self::assertSame('on', Bubblewrap::fromSetting('on')?->mode());
        self::assertSame('on', Bubblewrap::fromSetting(true)?->mode());
        self::assertSame('no-network', Bubblewrap::fromSetting('no-network')?->mode());
    }

    public function testAnUnrecognisedValueIsTheStrictestModeNeverOff(): void
    {
        foreach (['no-netwrok', 'ON', 1, ['on']] as $typo) {
            self::assertSame('no-network', Bubblewrap::fromSetting($typo)?->mode(), json_encode($typo) . ' must not read as off');
        }
    }

    public function testTheSchemaRowIsUserTierOnlyAndDefaultsOff(): void
    {
        $definition = SettingsSchema::byKey('bashSandbox');

        self::assertNotNull($definition);
        self::assertSame('off', $definition->default);
        self::assertSame(Bubblewrap::MODES, $definition->enumValues);
        self::assertTrue($definition->layered);
        self::assertFalse($definition->projectSettable, 'off widens, so no project may set it');
        self::assertContains('bashSandbox', LayeredSettings::LAYERED_KEYS);
        self::assertNotContains('bashSandbox', LayeredSettings::PROJECT_TIER_KEYS);
    }

    public function testTheSettingReachesTheWiredBash(): void
    {
        self::assertNull($this->wiredSandbox(), 'unset is off');

        $this->writeUserSettings(['bashSandbox' => 'no-network']);
        self::assertSame('no-network', $this->wiredSandbox()?->mode());

        $this->writeUserSettings(['bashSandbox' => 'on']);
        self::assertSame('on', $this->wiredSandbox()?->mode());
    }

    public function testATrustedProjectCannotSwitchTheSandboxOff(): void
    {
        $this->writeUserSettings(['bashSandbox' => 'on']);
        file_put_contents($this->configDir . '/config.json', (string) json_encode([
            LayeredSettings::PROJECT_SETTINGS_TRUST_KEY => [$this->root],
        ]));
        chmod($this->configDir . '/config.json', 0o600);
        mkdir($this->root . '/' . LayeredSettings::dir(), 0o700, true);
        file_put_contents($this->root . '/' . LayeredSettings::SHARED_PATH, (string) json_encode(['bashSandbox' => 'off']));
        Bootstrap::useProjectRootForSettings($this->root);

        self::assertSame('on', $this->wiredSandbox()?->mode());
    }

    // ─── the argv ────────────────────────────────────────────────────────

    public function testTheJailIsReadOnlyEverywhereButTheRoot(): void
    {
        $argv = Bubblewrap::new('/usr/bin/bwrap')->argv($this->root, false, '');

        self::assertSame('/usr/bin/bwrap', $argv[0]);
        self::assertSame('--', $argv[array_key_last($argv)]);
        self::assertTrue($this->hasSequence($argv, ['--ro-bind', '/', '/']));
        self::assertTrue($this->hasSequence($argv, ['--bind', $this->root, $this->root]));
        self::assertTrue($this->hasSequence($argv, ['--tmpfs', '/tmp']));
        self::assertTrue($this->hasSequence($argv, ['--chdir', $this->root]));
        self::assertContains('--unshare-all', $argv);
        self::assertContains('--die-with-parent', $argv);
        self::assertContains('--share-net', $argv);
        self::assertContains('--new-session', $argv);

        // The root bind precedes the read-only re-binds: in bwrap later binds win.
        $rootBind = array_search($this->root, $argv, true);
        foreach (Bubblewrap::PROTECTED_PATHS as $relative) {
            $path = $this->root . '/' . $relative;
            self::assertTrue($this->hasSequence($argv, ['--ro-bind-try', $path, $path]), "{$relative} is not re-bound read-only");
            self::assertGreaterThan($rootBind, array_search($path, $argv, true));
        }
    }

    public function testAnOrdinaryCheckoutKeepsItsGitEscapeHatchesReadOnly(): void
    {
        mkdir($this->root . '/.git');
        $argv = Bubblewrap::new('bwrap')->argv($this->root, false, '');

        foreach (Bubblewrap::PROTECTED_GIT_PATHS as $leaf) {
            $path = $this->root . '/.git/' . $leaf;
            self::assertTrue($this->hasSequence($argv, ['--ro-bind-try', $path, $path]), "{$leaf} is writable");
        }
        self::assertFalse($this->hasSequence($argv, ['--bind', $this->root . '/.git', $this->root . '/.git']), 'already inside the root bind');
    }

    public function testAPlantedGitdirFileBindsNothingWritable(): void
    {
        // A checkout committing `.git` as `gitdir: <somewhere of yours>`: no
        // back-pointer from there names this root, so nothing is bound.
        $victim = $this->tmpDir . '/victim';
        mkdir($victim);
        file_put_contents($this->root . '/.git', "gitdir: {$victim}\n");

        $argv = Bubblewrap::new('bwrap')->argv($this->root, false, '');

        self::assertNotContains((string) realpath($victim), $argv);
    }

    public function testNoNetworkDropsShareNetAndInteractiveKeepsTheTerminal(): void
    {
        self::assertNotContains('--share-net', Bubblewrap::new('bwrap', false)->argv($this->root, false, ''));
        self::assertNotContains('--new-session', Bubblewrap::new('bwrap')->argv($this->root, true, ''));
    }

    public function testTmpdirSurvivesThePrivateTmp(): void
    {
        self::assertTrue($this->hasSequence(
            Bubblewrap::new('bwrap')->argv($this->root, false, '/tmp/cr-some-dir'),
            ['--dir', '/tmp/cr-some-dir'],
        ));

        $outside = $this->tmpDir . '/elsewhere';
        mkdir($outside);
        $outside = (string) realpath($outside);
        if (!str_starts_with($outside, '/tmp/')) {
            self::assertTrue($this->hasSequence(
                Bubblewrap::new('bwrap')->argv($this->root, false, $outside),
                ['--bind', $outside, $outside],
            ));
        }
    }

    public function testALinkedWorktreeBindsItsGitdirAndCommonDirWritableWithTheEscapesProtected(): void
    {
        $common = $this->tmpDir . '/main/.git';
        $gitDir = $common . '/worktrees/wt';
        mkdir($gitDir, 0o700, true);
        file_put_contents($common . '/HEAD', "ref: refs/heads/main\n");
        file_put_contents($gitDir . '/commondir', "../..\n");
        file_put_contents($gitDir . '/gitdir', $this->root . "/.git\n");
        file_put_contents($this->root . '/.git', "gitdir: {$gitDir}\n");
        $gitDir = (string) realpath($gitDir);
        $common = (string) realpath($common);

        $argv = Bubblewrap::new('bwrap')->argv($this->root, false, '');

        foreach ([$gitDir, $common] as $dir) {
            self::assertTrue($this->hasSequence($argv, ['--bind', $dir, $dir]), "{$dir} is not writable");
            foreach (Bubblewrap::PROTECTED_GIT_PATHS as $leaf) {
                self::assertTrue($this->hasSequence($argv, ['--ro-bind-try', "{$dir}/{$leaf}", "{$dir}/{$leaf}"]));
            }
        }
    }

    // ─── the tool, through a shim ────────────────────────────────────────

    public function testTheCommandRunsThroughTheJailPrefix(): void
    {
        $log = $this->tmpDir . '/shim.log';
        $bash = new Bash($this->root, sandbox: Bubblewrap::new($this->shim($log)));

        $result = $bash->execute(['command' => 'pwd; touch made-here', 'description' => 'probe']);

        self::assertFalse($result->isError(), $result->content());
        self::assertStringContainsString($this->root, $result->content());
        self::assertFileExists($this->root . '/made-here');

        $argv = file($log, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($argv);
        self::assertTrue($this->hasSequence($argv, ['--bind', $this->root, $this->root]));
        self::assertTrue($this->hasSequence($argv, ['--', 'bash', '-c']), 'the command follows the jail argv');
    }

    public function testTheWorktreeJailRootIsTheWritableRootNotTheProjectRoot(): void
    {
        $worktree = $this->tmpDir . '/worktree';
        mkdir($worktree);
        $worktree = (string) realpath($worktree);
        $log = $this->tmpDir . '/shim.log';

        $bash = (new Bash($this->root, sandbox: Bubblewrap::new($this->shim($log))))
            ->withWorktreeJail(new \SugarCraft\Crush\Agents\PathJail($worktree, new \SugarCraft\Crush\Agents\PathJailConfig()));
        $bash->execute(['command' => 'true', 'description' => 'probe']);

        $argv = file($log, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($argv);
        self::assertTrue($this->hasSequence($argv, ['--bind', $worktree, $worktree]));
        self::assertFalse($this->hasSequence($argv, ['--bind', $this->root, $this->root]));
        self::assertNotNull($bash->sandbox(), 'the jail rebind keeps the sandbox');
    }

    public function testASandboxThatCannotStartRefusesAndRunsNothing(): void
    {
        $broken = $this->tmpDir . '/broken-bwrap';
        file_put_contents($broken, "#!/bin/sh\necho 'bwrap: setting up uid map: Permission denied' >&2\nexit 1\n");
        chmod($broken, 0o755);

        $result = (new Bash($this->root, sandbox: Bubblewrap::new($broken)))
            ->execute(['command' => 'touch should-not-exist', 'description' => 'probe']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('Not run:', $result->content());
        self::assertStringContainsString('setting up uid map: Permission denied', $result->content());
        self::assertStringContainsString('Nothing ran', $result->content());
        self::assertFileDoesNotExist($this->root . '/should-not-exist');
    }

    public function testAMissingBinaryRefuses(): void
    {
        $result = (new Bash($this->root, sandbox: Bubblewrap::new($this->tmpDir . '/no-such-bwrap')))
            ->execute(['command' => 'touch should-not-exist', 'description' => 'probe']);

        self::assertTrue($result->isError());
        self::assertFileDoesNotExist($this->root . '/should-not-exist');
    }

    public function testTheDescriptionNamesTheJailOnlyWhileItIsOn(): void
    {
        $off = new Bash($this->root);
        $on = $off->withSandbox(Bubblewrap::new('bwrap', false));

        self::assertStringNotContainsString('bubblewrap', $off->description());
        self::assertStringContainsString('bubblewrap sandbox', $on->description());
        self::assertStringContainsString($this->root, $on->description());
        self::assertStringContainsString('NO network', $on->description());
        self::assertStringStartsWith($off->description(), $on->description());
    }

    // ─── the real binary: both arms asserted, never skipped ──────────────

    public function testTheRealBubblewrapConfinesOrRefuses(): void
    {
        $sandbox = Bubblewrap::new();
        $outside = $this->tmpDir . '/outside-marker';
        $bash = new Bash($this->root, sandbox: $sandbox);

        $result = $bash->execute([
            'command' => 'touch inside-marker; touch ' . escapeshellarg($outside) . ' 2>/dev/null; echo ran',
            'description' => 'probe',
        ]);

        // Never written on the host, whichever arm this host takes: a working
        // jail keeps the write in its private /tmp or refuses it read-only.
        self::assertFileDoesNotExist($outside);

        if (str_contains($result->content(), 'Not run:')) {
            self::assertTrue($result->isError());
            self::assertFileDoesNotExist($this->root . '/inside-marker', 'a refused command must not have run');

            return;
        }

        self::assertStringContainsString('ran', $result->content());
        self::assertFileExists($this->root . '/inside-marker', 'the working root is writable inside the jail');
    }

    // ─── helpers ─────────────────────────────────────────────────────────

    /**
     * A stand-in for bwrap: logs its argv one per line, then execs whatever
     * follows the `--` — the contract the real binary keeps.
     */
    private function shim(string $log): string
    {
        $path = $this->tmpDir . '/fake-bwrap-' . getmypid() . '_' . uniqid('', true);
        file_put_contents($path, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > " . escapeshellarg($log) . "\n"
            . "while [ \"\$#\" -gt 0 ] && [ \"\$1\" != \"--\" ]; do shift; done\nshift\nexec \"\$@\"\n");
        chmod($path, 0o755);

        return $path;
    }

    /**
     * @param list<string> $haystack
     * @param list<string> $needle
     */
    private function hasSequence(array $haystack, array $needle): bool
    {
        $count = count($needle);
        for ($i = 0, $n = count($haystack) - $count; $i <= $n; $i++) {
            if (array_slice($haystack, $i, $count) === $needle) {
                return true;
            }
        }

        return false;
    }

    /** The sandbox of the one Bash the launch's tool feed wires. */
    private function wiredSandbox(): ?Bubblewrap
    {
        foreach (Bootstrap::tools($this->root) as $tool) {
            if ($tool instanceof Bash) {
                return $tool->sandbox();
            }
        }
        self::fail('Bootstrap::tools() wired no Bash');
    }

    /** @param array<string, mixed> $data */
    private function writeUserSettings(array $data): void
    {
        $path = $this->configDir . '/' . LayeredSettings::USER_FILE;
        file_put_contents($path, (string) json_encode($data));
        chmod($path, 0o600);
    }
}
