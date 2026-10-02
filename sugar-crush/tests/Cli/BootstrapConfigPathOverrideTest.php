<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Cli\PermissionConfigException;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\ToolCall;

/**
 * The Bootstrap half of `--config <path>` (crush_code.md Phase 4 item 6):
 * {@see Bootstrap::useConfigPath()} redirects every reader of the per-user
 * `config.json` at a file the caller named, and `bin/sugarcrush` sets it once
 * after {@see \SugarCraft\Crush\Cli\ArgvParser::configError()} has proved the
 * file readable.
 *
 * HOME is sandboxed for the whole class even though most cases below never
 * look at the discovered path: the two that DO assert "the discovered file was
 * not touched" have to be able to say that about a directory that is not the
 * developer's own, and `useConfigPath(null)` in tearDown puts the process back
 * where it started for every other test in the suite.
 */
final class BootstrapConfigPathOverrideTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tempDir = '';

    private string|false $originalPermissionMode = false;

    protected function setUp(): void
    {
        parent::setUp();

        // permissionGate() lets $SUGARCRUSH_PERMISSION_MODE override the file,
        // so the one case below that asserts on the MODE would read the
        // developer's exported value instead of the fixture.
        $this->originalPermissionMode = \getenv('SUGARCRUSH_PERMISSION_MODE');
        \putenv('SUGARCRUSH_PERMISSION_MODE');

        $this->tempDir = \sys_get_temp_dir() . '/bootstrap_config_override_' . \uniqid('', true);
        \mkdir($this->tempDir . '/elsewhere', 0700, true);
        $this->useHomeSandbox($this->tempDir . '/home');
    }

    protected function tearDown(): void
    {
        // A process-wide static: leaving it set would point the REST of the
        // suite at a temp file this tearDown is about to delete.
        Bootstrap::useConfigPath(null);
        // One case below makes the sandbox home world-writable to trip the
        // ownership gate; put it back before the recursive delete walks it.
        @\chmod($this->tempDir . '/home', 0700);
        $this->restoreHomeSandbox();

        if (\is_string($this->originalPermissionMode)) {
            \putenv('SUGARCRUSH_PERMISSION_MODE=' . $this->originalPermissionMode);
        }

        if ($this->tempDir !== '' && \is_dir($this->tempDir)) {
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($entries as $entry) {
                /** @var \SplFileInfo $entry */
                $entry->isDir() ? @\rmdir($entry->getPathname()) : @\unlink($entry->getPathname());
            }
            @\rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    /** @param array<string, mixed> $config */
    private function writeOverrideFile(array $config): string
    {
        $path = $this->tempDir . '/elsewhere/crush.json';
        \file_put_contents($path, (string) \json_encode($config));

        return $path;
    }

    public function testTheDiscoveredPathIsUsedWhenNoOverrideIsSet(): void
    {
        $this->assertSame(
            $this->tempDir . '/home/.sugar-crush/config.json',
            Bootstrap::userConfigPath(),
        );
    }

    public function testUseConfigPathRedirectsUserConfigPath(): void
    {
        $path = $this->writeOverrideFile([]);
        Bootstrap::useConfigPath($path);

        $this->assertSame($path, Bootstrap::userConfigPath());
    }

    public function testPassingNullRestoresDiscovery(): void
    {
        Bootstrap::useConfigPath($this->writeOverrideFile([]));
        Bootstrap::useConfigPath(null);

        $this->assertSame(
            $this->tempDir . '/home/.sugar-crush/config.json',
            Bootstrap::userConfigPath(),
        );
    }

    /**
     * The point of the flag: the settings a run uses come out of the named
     * file, not out of ~/.sugar-crush.
     */
    public function testReadUserConfigReadsTheOverrideFile(): void
    {
        Bootstrap::useConfigPath($this->writeOverrideFile(['theme' => 'tokyonight']));

        $this->assertSame('tokyonight', Bootstrap::readUserConfig()['theme'] ?? null);
    }

    /**
     * The discovered file is not consulted at all while an override is in
     * force — a merge of the two would make "which policy am I running"
     * unanswerable from the command line alone.
     */
    public function testTheDiscoveredFileIsNotMergedInUnderAnOverride(): void
    {
        \mkdir($this->tempDir . '/home/.sugar-crush', 0700, true);
        \file_put_contents(
            $this->tempDir . '/home/.sugar-crush/config.json',
            (string) \json_encode(['theme' => 'discovered', 'provider' => 'openai']),
        );

        Bootstrap::useConfigPath($this->writeOverrideFile(['theme' => 'named']));

        $config = Bootstrap::readUserConfig();
        $this->assertSame('named', $config['theme'] ?? null);
        $this->assertArrayNotHasKey('provider', $config);
    }

    /**
     * `writeUserConfig()` stages a temp file next to the target and renames it
     * over it, and used to take that directory from `configDirPath()` rather
     * than from the target. MEASURED with the old line restored: the persist
     * still LANDS here, because /tmp and the sandbox HOME are one filesystem
     * and rename() across two directories on one filesystem works — so an
     * assertion on the file contents alone proves nothing. What it does do is
     * stage through ~/.sugar-crush, which is a cross-mount rename failure
     * (a silently lost `/theme` or Ctrl+P choice) on any host where they are
     * not, and creates that directory on a run told to stay out of it. The
     * directory assertion is the one that kills the mutation.
     */
    public function testWriteUserConfigPersistsIntoTheOverrideFile(): void
    {
        $path = $this->writeOverrideFile(['theme' => 'dark']);
        Bootstrap::useConfigPath($path);

        Bootstrap::writeUserConfig(['provider' => 'anthropic']);

        /** @var array<string, mixed> $onDisk */
        $onDisk = \json_decode((string) \file_get_contents($path), true);
        $this->assertSame('anthropic', $onDisk['provider'] ?? null);
        $this->assertSame('dark', $onDisk['theme'] ?? null, 'the merge lost a key that was already there');
        $this->assertFileDoesNotExist(
            $this->tempDir . '/home/.sugar-crush/config.json',
            'the persist landed in the discovered file instead of the named one',
        );
        $this->assertDirectoryDoesNotExist(
            $this->tempDir . '/home/.sugar-crush',
            'the write staged its temp file through the discovered config dir',
        );
    }

    /**
     * The permission mode and rules are the reason `--config` naming an
     * unreadable file is a hard usage error rather than a fallback: this is
     * the policy that moves with the flag.
     */
    public function testThePermissionGateIsBuiltFromTheOverrideFile(): void
    {
        Bootstrap::useConfigPath($this->writeOverrideFile([
            'permissionMode' => 'plan',
            'permissionRules' => [['pattern' => 'Bash', 'action' => 'deny']],
        ]));

        $gate = Bootstrap::permissionGate();

        $this->assertSame(PermissionMode::Plan, $gate->mode());
        $this->assertSame(
            PermissionDecision::Deny,
            $gate->evaluate(new ToolCall('Bash', ['command' => 'ls'])),
        );
    }

    /**
     * `--config` names the POLICY FILE; it does not vouch for the home
     * directory. {@see Bootstrap::permissionConfig()} therefore calls
     * `trustedConfigDirPath()` on its own line — for the THROW — before
     * honouring the override, because ~/.sugar-crush is still where this
     * process goes on to read hooks, agent presets and workflows from, and a
     * home it cannot establish as this user's makes all of those somebody
     * else's.
     *
     * Pinned because the claim was previously made only in a comment: with
     * `trustedConfigDirPath()` swapped for `configDirPath()` the whole
     * override suite, the ownership suite and the permission-gate suite all
     * stayed green while `--config` quietly disarmed the home gate.
     *
     * DOMAIN: the world-writable arm of that gate. The unresolvable-$HOME arm
     * is the same call and is covered in BootstrapTrustedHomeOwnershipTest.
     */
    public function testTheHomeOwnershipGateStillFiresWhileAnOverrideIsInForce(): void
    {
        $path = $this->writeOverrideFile(['permissionMode' => 'plan']);
        Bootstrap::useConfigPath($path);

        // Exactly the /tmp-style planted home the gate exists for: any local
        // account can pre-create ~/.sugar-crush inside a 1777 directory.
        \chmod($this->tempDir . '/home', 0o1777);

        $this->expectException(PermissionConfigException::class);
        $this->expectExceptionMessageMatches('/cannot be established as yours/');

        Bootstrap::permissionGate();
    }

    /**
     * A dotfiles setup — `~/.sugar-crush/config.json` a symlink into a
     * checkout — must keep its link across a persist (audit 15d-15). The
     * rename used to land on the LINK PATH, replacing the link with a private
     * 0600 copy: every later edit to the real file, `permissionMode` and
     * `permissionRules` included, then silently stopped applying. Both halves
     * are asserted: the link is still a link to the same place, and the write
     * (merged, not a one-key file) is in the file it points at.
     */
    public function testWriteUserConfigPreservesASymlinkedConfig(): void
    {
        [$link, $real] = $this->symlinkedConfig(['permissionMode' => 'plan']);

        Bootstrap::writeUserConfig(['theme' => 'dracula']);

        \clearstatcache();
        $this->assertTrue(\is_link($link), 'the persist replaced the symlinked config with a regular file');
        $this->assertSame($real, \readlink($link));

        /** @var array<string, mixed> $onDisk */
        $onDisk = \json_decode((string) \file_get_contents($real), true);
        $this->assertSame(['permissionMode' => 'plan', 'theme' => 'dracula'], $onDisk);

        // The temp file is staged beside the RESOLVED file and renamed onto it,
        // so nothing may be left behind in either directory.
        $this->assertSame(['config.json'], $this->entriesOf(\dirname($real)));
        $this->assertSame(['.config.json.lock', 'config.json'], $this->entriesOf(\dirname($link)));

        // And the link is what the launch reads: an edit to the real file is
        // live, which is the property the regular-file copy destroyed.
        \file_put_contents($real, (string) \json_encode(['theme' => 'edited-in-dotfiles']));
        $this->assertSame('edited-in-dotfiles', Bootstrap::readUserConfig()['theme'] ?? null);
    }

    /**
     * A dangling link is refused, not "repaired": creating its target would
     * be guessing which file the user meant, and replacing the link would be
     * the 15d-15 defect again. The launch refuses this state too, so losing
     * the setting is the honest outcome.
     */
    public function testWriteUserConfigRefusesToWriteThroughADanglingSymlink(): void
    {
        $dir = $this->tempDir . '/home/.sugar-crush';
        \mkdir($dir, 0700, true);
        $missing = $this->tempDir . '/dotfiles/config.json';
        \symlink($missing, $dir . '/config.json');

        Bootstrap::writeUserConfig(['theme' => 'dracula']);

        \clearstatcache();
        $this->assertTrue(\is_link($dir . '/config.json'), 'the dangling link was replaced');
        $this->assertSame($missing, \readlink($dir . '/config.json'));
        $this->assertFileDoesNotExist($missing, 'the write invented the link\'s target');
    }

    /**
     * Following a link is only safe to a file the launch would itself accept
     * as this account's policy ({@see Bootstrap::requirePrivatePolicyFile()}).
     * A link into a world-writable file must neither be written through —
     * that would put the user's policy in a file anyone can rewrite — nor
     * replaced by a private copy.
     */
    public function testWriteUserConfigRefusesToWriteThroughALinkToAWorldWritableFile(): void
    {
        [$link, $real] = $this->symlinkedConfig(['theme' => 'before']);
        \chmod($real, 0o666);
        $before = (string) \file_get_contents($real);

        Bootstrap::writeUserConfig(['theme' => 'after']);

        \clearstatcache();
        $this->assertTrue(\is_link($link), 'the link to an unacceptable target was replaced');
        $this->assertSame($before, \file_get_contents($real), 'the write went through a link into a world-writable file');
    }

    /**
     * A config that is present but unparsable — an editor mid-save, a hand
     * edit with a typo, a top-level list — is read as `{}` by the forgiving
     * read path, and the write used to merge onto THAT: the user's whole
     * file replaced by the one key a theme switch named. The write now
     * refuses, leaving the bytes exactly as they were.
     *
     * @dataProvider unparsableConfigs
     */
    public function testWriteUserConfigRefusesToOverwriteAnUnparsableConfig(string $contents): void
    {
        $path = $this->tempDir . '/elsewhere/crush.json';
        \file_put_contents($path, $contents);
        Bootstrap::useConfigPath($path);

        Bootstrap::writeUserConfig(['theme' => 'dracula']);

        $this->assertSame($contents, \file_get_contents($path), 'an unparsable config was overwritten with the patch');
    }

    /** @return array<string, array{string}> */
    public static function unparsableConfigs(): array
    {
        return [
            'truncated mid-save' => ["{\n  \"permissionMode\": \"plan\",\n  \"permissionRu"],
            'hand-edit typo' => ['{"permissionMode": "plan",}'],
            'top-level list' => ['[{"permissionMode": "plan"}]'],
            'empty list' => ['[]'],
        ];
    }

    /**
     * The refusal is for files that HOLD something: a zero-byte or
     * whitespace-only config is "nothing configured" to the launch, and
     * refusing to write over it would make the state an older build or a
     * full disk left behind permanently unpersistable.
     */
    public function testWriteUserConfigStillWritesOverAnEmptyConfig(): void
    {
        $path = $this->tempDir . '/elsewhere/crush.json';
        \file_put_contents($path, " \n");
        Bootstrap::useConfigPath($path);

        Bootstrap::writeUserConfig(['theme' => 'dracula']);

        $this->assertSame(['theme' => 'dracula'], \json_decode((string) \file_get_contents($path), true));
    }

    /**
     * The read-merge-write is serialised: a second session persisting a
     * DIFFERENT key while this one is mid-update must not lose either key.
     *
     * The test plays the first session by hand — it takes the sidecar lock,
     * reads its merge base, and only writes after giving a real child process
     * (the second session, persisting `theme`) ample time to run. Without the
     * lock the child's write lands inside that window and the hand-played
     * write, merged from the stale base, erases it. With the lock the child
     * waits, then re-reads and merges onto the hand-played write.
     */
    public function testConcurrentWritersDoNotLoseEachOthersKeys(): void
    {
        $path = $this->writeOverrideFile(['keep' => 'me']);
        $lockPath = \dirname($path) . '/.' . \basename($path) . '.lock';

        $lock = \fopen($lockPath, 'c');
        $this->assertIsResource($lock);
        $this->assertTrue(\flock($lock, \LOCK_EX));

        $child = null;

        try {
            /** @var array<string, mixed> $base */
            $base = \json_decode((string) \file_get_contents($path), true);
            $child = $this->spawnWriter($path, ['theme' => 'from-the-other-session']);

            // Long enough for an UNLOCKED child to boot and finish its write
            // many times over; ends early once one has landed, since that is
            // already the failure the assertion below reports.
            $deadline = \microtime(true) + 1.5;
            while (\microtime(true) < $deadline && \file_get_contents($path) === (string) \json_encode(['keep' => 'me'])) {
                \usleep(20_000);
            }

            \file_put_contents($path, (string) \json_encode($base + ['provider' => 'from-this-session']));
        } finally {
            \flock($lock, \LOCK_UN);
            \fclose($lock);
        }

        $this->assertSame(0, $this->reap($child), 'the second session\'s writer did not exit cleanly');

        /** @var array<string, mixed> $onDisk */
        $onDisk = \json_decode((string) \file_get_contents($path), true);
        $this->assertSame('me', $onDisk['keep'] ?? null);
        $this->assertSame('from-this-session', $onDisk['provider'] ?? null);
        $this->assertSame(
            'from-the-other-session',
            $onDisk['theme'] ?? null,
            'a concurrent persist was lost: the write did not wait for the config lock',
        );
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{string, string} the link path and the file it points at
     */
    private function symlinkedConfig(array $config): array
    {
        $dotfiles = $this->tempDir . '/dotfiles';
        \mkdir($dotfiles, 0700, true);
        $real = $dotfiles . '/config.json';
        \file_put_contents($real, (string) \json_encode($config));

        $dir = $this->tempDir . '/home/.sugar-crush';
        \mkdir($dir, 0700, true);
        $link = $dir . '/config.json';
        \symlink($real, $link);

        return [$link, $real];
    }

    /**
     * scandir(), not glob('*'): the temp file's name starts with a dot.
     *
     * @return list<string>
     */
    private function entriesOf(string $dir): array
    {
        $entries = \array_values(\array_diff(\scandir($dir) ?: [], ['.', '..']));
        \sort($entries);

        return $entries;
    }

    /**
     * Run `writeUserConfig($patch)` against $path in a separate PHP process.
     *
     * @param array<string, mixed> $patch
     *
     * @return resource
     */
    private function spawnWriter(string $path, array $patch)
    {
        $script = $this->tempDir . '/writer.php';
        \file_put_contents($script, <<<'PHP'
            <?php
            declare(strict_types=1);
            require $argv[1];
            \SugarCraft\Crush\Cli\Bootstrap::useConfigPath($argv[2]);
            \SugarCraft\Crush\Cli\Bootstrap::writeUserConfig((array) \json_decode($argv[3], true));
            PHP);

        $process = \proc_open(
            [\PHP_BINARY, $script, \dirname(__DIR__, 2) . '/vendor/autoload.php', $path, (string) \json_encode($patch)],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->tempDir . '/writer.out', 'w'], 2 => ['file', $this->tempDir . '/writer.err', 'w']],
            $pipes,
            null,
            ['HOME' => $this->tempDir . '/home', 'PATH' => (string) \getenv('PATH')],
        );
        $this->assertIsResource($process);

        return $process;
    }

    /** @param resource|null $process */
    private function reap($process): int
    {
        if ($process === null) {
            return -1;
        }

        // Bounded: the child's own lock wait is five seconds.
        $deadline = \microtime(true) + 20.0;
        while (\microtime(true) < $deadline) {
            $status = \proc_get_status($process);
            if (!$status['running']) {
                \proc_close($process);

                return $status['exitcode'];
            }
            \usleep(20_000);
        }

        \proc_terminate($process, 9);
        \proc_close($process);

        return -1;
    }
}
