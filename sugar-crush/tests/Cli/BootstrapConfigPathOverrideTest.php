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
}
