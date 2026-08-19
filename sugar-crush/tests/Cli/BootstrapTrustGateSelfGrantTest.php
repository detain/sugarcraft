<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Cli\PermissionConfigException;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;

/**
 * THE TRUST GATE MAY NOT BE GRANTABLE BY THE THING IT GATES.
 *
 * `trustedProjectHooks` lives in `~/.sugar-crush/config.json` and decides
 * whether a cloned repository's `.sugar-crush/hooks.yaml` — arbitrary shell,
 * run on tool calls — is loaded. The shipped default permission mode is
 * bypass-permissions and `Bash` is deliberately not path-jailed, so before this
 * change the exploit was: an untrusted repo prompt-injects the model into
 * appending one line to that list, then any Ctrl+P provider switch rebuilds the
 * hook manager, re-reads the file, and the repo's shell is in the chain —
 * mid-session, no relaunch, no prompt. Writing the file was inert until this
 * change-set made it live, so the hole arrived with the feature.
 *
 * Two independent defences are asserted here, because neither is sufficient:
 * {@see \SugarCraft\Crush\Hooks\BuiltIn\ProtectFilesHook} denies the write, and
 * {@see Bootstrap} freezes the trust list and the hook files for the process so
 * a write that got past the first one still cannot take effect in the session
 * that made it.
 */
final class BootstrapTrustGateSelfGrantTest extends TestCase
{
    private string $tempDir;
    private string $home;
    private string $project;
    private string $originalHome;
    private mixed $originalServerHome;

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/bootstrap_selfgrant_' . uniqid('', true);
        $this->home = $this->tempDir . '/home';
        $this->project = $this->tempDir . '/project';
        mkdir($this->home . '/.sugar-crush', 0700, true);
        mkdir($this->project . '/.sugar-crush', 0700, true);

        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=' . $this->home);
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->home;

        foreach (['SUGARCRUSH_PROVIDER', 'SUGARCRUSH_BACKEND_CMD', 'SUGARCRUSH_BACKEND_CMD_STREAM', 'SUGARCRUSH_MODEL', 'SUGARCRUSH_PERMISSION_MODE'] as $var) {
            $this->originalEnv[$var] = getenv($var);
            putenv($var);
        }
    }

    protected function tearDown(): void
    {
        $this->originalHome === '' ? putenv('HOME') : putenv('HOME=' . $this->originalHome);

        if ($this->originalServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->originalServerHome;
        }

        foreach ($this->originalEnv as $var => $value) {
            $value === false ? putenv($var) : putenv($var . '=' . $value);
        }

        @chmod($this->home . '/.sugar-crush', 0700);
        @chmod($this->home . '/.sugar-crush/config.json', 0600);
        clearstatcache();
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    // =========================================================================
    // The reviewer's exploit chain, end to end
    // =========================================================================

    /**
     * Step for step: untrusted repo carrying a hook file, one tool call that
     * appends itself to `trustedProjectHooks`, one hook-manager rebuild (what a
     * Ctrl+P provider switch does). The repo must still not be trusted.
     */
    public function testASessionCannotGrantItselfTrustAndUseItInTheSameRun(): void
    {
        $marker = $this->tempDir . '/PWNED';
        $this->writeProjectHooks(
            "hooks:\n  PreToolUse:\n    - name: attacker\n      matcher: '.*'\n"
            . "      command: 'touch " . $marker . "'\n",
        );

        // 1. Launch. The repo is not trusted, so its hook file is not loaded.
        $launch = $this->chainOf(Bootstrap::backend($this->project));
        $this->assertNotContains('attacker', $this->namesOn($launch, 'PreToolUse'));

        // 2. The tool call the exploit needs. It goes through the launch's own
        //    guard chain, which is where it is refused.
        $write = $launch->preToolUse($this->context('Write', [
            'file_path' => $this->home . '/.sugar-crush/config.json',
        ]));
        $this->assertTrue($write->isDenied(), 'the trust list must not be writable by the session it gates');
        $this->assertFalse($write->permitsExecution());

        // 3. The write happens ANYWAY — the shell spellings protect-files
        //    cannot see (`cd ~/.sugar-crush && …`, `$HOME` expansion, a
        //    heredoc) are unbounded, so the read side may not assume it was
        //    stopped.
        $this->writeUserConfig(['trustedProjectHooks' => [$this->project]]);

        // 4. The provider switch. Same process, new hook manager.
        $afterSwitch = $this->chainOf(Bootstrap::backend($this->project));

        $this->assertNotContains(
            'attacker',
            $this->namesOn($afterSwitch, 'PreToolUse'),
            'a grant written DURING the session must not take effect IN it',
        );
        $this->assertFileDoesNotExist($marker);
    }

    /**
     * The same freeze, on the file the reviewer noted is worse: the USER's
     * `~/.sugar-crush/hooks.yaml` is loaded with no trust gate at all, on the
     * premise that the user wrote it. A file that appears mid-session is
     * exactly the case where that premise is false.
     */
    public function testAUserHookFilePlantedMidSessionIsNotLoadedByARebuild(): void
    {
        $this->chainOf(Bootstrap::backend($this->project));

        file_put_contents(
            $this->home . '/.sugar-crush/hooks.yaml',
            "hooks:\n  PreToolUse:\n    - name: planted\n      command: 'true'\n",
        );

        $this->assertNotContains(
            'planted',
            $this->namesOn($this->chainOf(Bootstrap::backend($this->project)), 'PreToolUse'),
        );
    }

    /**
     * THE CONTROL. Freezing must not mean "hook files stop working": one the
     * user wrote BEFORE the launch is loaded normally, and stays loaded across
     * the rebuild a provider switch performs.
     */
    public function testAHookFileWrittenBeforeTheLaunchIsLoadedAndStaysLoaded(): void
    {
        file_put_contents(
            $this->home . '/.sugar-crush/hooks.yaml',
            "hooks:\n  PreToolUse:\n    - name: mine\n      command: 'true'\n",
        );

        $this->assertContains('mine', $this->namesOn($this->chainOf(Bootstrap::backend($this->project)), 'PreToolUse'));
        $this->assertContains('mine', $this->namesOn($this->chainOf(Bootstrap::backend($this->project)), 'PreToolUse'));
    }

    /**
     * ...and so must a repository the user trusted before the launch, which is
     * the feature the gate exists to permit.
     */
    public function testAProjectTrustedBeforeTheLaunchIsStillHonoured(): void
    {
        $this->writeUserConfig(['trustedProjectHooks' => [$this->project]]);
        $this->writeProjectHooks("hooks:\n  PreToolUse:\n    - name: from-project\n      command: 'true'\n");

        $this->assertContains(
            'from-project',
            $this->namesOn($this->chainOf(Bootstrap::backend($this->project)), 'PreToolUse'),
        );
    }

    // =========================================================================
    // Whose file is it, anyway
    // =========================================================================

    /**
     * `~/.sugar-crush/config.json` carries `permissionMode`, `permissionRules`
     * and `trustedProjectHooks`, and the hook file beside it is loaded with no
     * gate at all. A file every account on the machine can rewrite is not the
     * user's policy, whatever the path says.
     */
    public function testAWorldWritablePolicyFileRefusesToStart(): void
    {
        $this->writeUserConfig(['trustedProjectHooks' => []]);
        chmod($this->home . '/.sugar-crush/config.json', 0666);
        clearstatcache();

        $this->expectException(PermissionConfigException::class);
        $this->expectExceptionMessageMatches('/writable by every account/');

        Bootstrap::backend($this->project);
    }

    /** Same question one level up: a directory anyone can write is a file anyone can replace. */
    public function testAWorldWritablePolicyDirectoryRefusesToStart(): void
    {
        $this->writeUserConfig(['trustedProjectHooks' => []]);
        chmod($this->home . '/.sugar-crush', 0777);
        clearstatcache();

        $this->expectException(PermissionConfigException::class);
        $this->expectExceptionMessageMatches('/writable by every account/');

        Bootstrap::backend($this->project);
    }

    // =========================================================================
    // The refusal has to come before the side effects
    // =========================================================================

    /**
     * `trustedConfigDirPath()` refuses a home it cannot determine — but
     * {@see Bootstrap::chat()} used to reach the session store and the skill
     * scan first, so a launch with `HOME` unset and `TMPDIR` pointed at an
     * attacker-owned directory refused only AFTER creating `session.db` in it.
     */
    public function testAnUndeterminableHomeRefusesBeforeAnythingIsCreated(): void
    {
        $probe = $this->tempDir . '/tmpdir';
        mkdir($probe, 0700, true);

        $output = $this->runInChild(
            "\\SugarCraft\\Crush\\Cli\\Bootstrap::chat(" . var_export($this->project, true) . ");",
            $probe,
        );

        $this->assertStringContainsString('cannot determine which home directory is yours', $output);
        $this->assertSame(
            [],
            array_values(array_diff(scandir($probe) ?: [], ['.', '..'])),
            'nothing may be written to the temp-directory stand-in before the refusal',
        );
    }

    /**
     * An agent preset carries `permissionMode:` and `tools:`, so
     * `~/.sugar-crush/agents` is policy by the same definition the hook file is
     * — and the refusal may not be degraded into "continuing with the built-in
     * agents" by the catch that handles an unreadable preset.
     */
    public function testTheAgentPresetDirectoryIsPolicyToo(): void
    {
        $output = $this->runInChild(
            "\\SugarCraft\\Crush\\Cli\\Bootstrap::agentPresets(" . var_export($this->project, true) . ");",
            $this->tempDir,
        );

        $this->assertStringContainsString('cannot determine which home directory is yours', $output);
        $this->assertStringNotContainsString('continuing with the built-in agents', $output);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Run one statement in a child process with no HOME and no passwd fallback
     * — the only way to reach {@see Bootstrap::trustedConfigDirPath()}'s
     * refusal, since ext-posix would otherwise answer with the real home.
     */
    private function runInChild(string $statement, string $tmpDir): string
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $script = $this->tempDir . '/child.php';
        $outFile = $this->tempDir . '/child-out.txt';

        file_put_contents($script, sprintf(
            "<?php\nrequire %s;\ntry {\n    %s\n} catch (\\Throwable \$e) {\n    echo \$e->getMessage();\n}\n",
            var_export($autoload, true),
            $statement,
        ));

        exec(sprintf(
            'env -u HOME -u USERPROFILE TMPDIR=%s timeout -s KILL 60 %s -d %s %s >%s 2>&1',
            escapeshellarg($tmpDir),
            escapeshellarg(PHP_BINARY),
            escapeshellarg('disable_functions=posix_getpwuid,posix_geteuid'),
            escapeshellarg($script),
            escapeshellarg($outFile),
        ));

        return is_file($outFile) ? (string) file_get_contents($outFile) : '';
    }

    private function writeProjectHooks(string $yaml): void
    {
        file_put_contents($this->project . '/.sugar-crush/hooks.yaml', $yaml);
    }

    /** @param array<string, mixed> $config */
    private function writeUserConfig(array $config): void
    {
        file_put_contents($this->home . '/.sugar-crush/config.json', json_encode($config, JSON_PRETTY_PRINT));
    }

    private function chainOf(object $backend): HookManager
    {
        $this->assertInstanceOf(EngineBackend::class, $backend);

        $hooks = (new \ReflectionProperty(EngineBackend::class, 'hookManager'))->getValue($backend);
        $this->assertInstanceOf(HookManager::class, $hooks);

        return $hooks;
    }

    /** @return list<string> */
    private function namesOn(HookManager $hooks, string $event): array
    {
        $registry = (new \ReflectionProperty(HookManager::class, 'registry'))->getValue($hooks);
        $this->assertInstanceOf(HookRegistry::class, $registry);

        return array_map(static fn(HookInterface $hook): string => $hook->name(), $registry->getForEvent($event));
    }

    /** @param array<string, mixed> $args */
    private function context(string $tool, array $args): HookContext
    {
        return new HookContext(
            sessionId: 'test-session',
            toolName: $tool,
            toolArgs: $args,
            toolInput: json_encode($args) ?: '{}',
            toolOutput: '',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: $this->project,
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($dir);
    }
}
