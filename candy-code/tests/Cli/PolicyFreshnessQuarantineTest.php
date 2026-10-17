<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\PolicySnapshot;
use SugarCraft\Code\Hooks\HookManager;

/**
 * rv-sec-3 (fix 8), behaviour side: the launch that FINDS a changed policy
 * must say so through the same channel `/notices` reads, and must not let
 * project hooks that nobody has seen run on that launch. The launch after —
 * with the change announced — honours them.
 *
 * Each "launch" is a statics reset plus one call to `Bootstrap::hooks()`
 * (which funnels through `policyQuarantineActive()` →
 * `ensurePolicyFreshnessChecked()` exactly as a real launch does). The file
 * shapes — user hooks.yaml, project hooks.yaml, trustedProjectHooks — are
 * the ones `BootstrapHookFileTest` already proves load through the real
 * loader, so what is under test here is only the freshness decision and the
 * entry filter.
 */
final class PolicyFreshnessQuarantineTest extends TestCase
{
    private string $tempDir = '';
    private string $home = '';
    private string $project = '';
    private string $originalHome = '';
    private mixed $originalServerHome = null;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/policy_freshness_' . uniqid('', true);
        $this->home = $this->tempDir . '/home';
        $this->project = $this->tempDir . '/project';
        mkdir($this->home . '/.candy-code', 0700, true);
        mkdir($this->project . '/.candy-code', 0700, true);

        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=' . $this->home);
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->home;

        foreach (['CANDY_CODE_PROVIDER', 'CANDY_CODE_BACKEND_CMD', 'CANDY_CODE_MODEL', 'CANDY_CODE_PERMISSION_MODE'] as $var) {
            putenv($var);
        }

        $this->writeUserConfig(['trustedProjectHooks' => [$this->project]]);
        $this->resetFreshnessStatics();
    }

    protected function tearDown(): void
    {
        $this->resetFreshnessStatics();

        putenv('HOME=' . $this->originalHome);
        if ($this->originalServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->originalServerHome;
        }

        $this->removeTree($this->tempDir);
    }

    public function testTheFirstLaunchIsABaselineAndTheDriftedLaunchQuarantinesTheNewHookForOneLaunchOnly(): void
    {
        $this->writeUserHooks("hooks:\n  PreToolUse:\n    - name: from-home\n      command: 'true'\n");
        $this->writeProjectHooks("hooks:\n  PreToolUse:\n    - name: old-guard\n      command: 'true'\n");

        // ---- launch 1: nothing has a history; every hook loads, no notice.
        $names1 = $this->launchHookChain();
        $this->assertContains('old-guard', $names1);
        $this->assertStringNotContainsString(
            'changed since the last launch',
            implode("\n", Bootstrap::launchNotices()),
            'a baseline launch must not cry policy drift at an untouched box'
        );
        $this->assertFileExists(PolicySnapshot::path(static fn (): string => self::configDir()));

        // ---- the operator edits both policy files between launches: the
        // user's own hook changes, AND the project gains a brand-new entry.
        $this->writeUserHooks("hooks:\n  PreToolUse:\n    - name: from-home\n      command: 'true'\n    - name: home-new\n      command: 'true'\n");
        $this->writeProjectHooks(
            "hooks:\n  PreToolUse:\n"
            . "    - name: old-guard\n      command: 'true'\n"
            . "    - name: repo-new\n      command: 'true'\n"
        );

        // ---- launch 2: loud notice, old entries honoured, the unseen one
        // quarantined — while the user's OWN new hook loads (consent there
        // is the notice).
        $this->resetFreshnessStatics();
        $before = \count(Bootstrap::launchNotices());
        $names2 = $this->launchHookChain();
        $notices = \array_slice(Bootstrap::launchNotices(), $before);

        $this->assertNotSame([], $notices);
        $this->assertStringContainsString(
            'changed since the last launch',
            implode("\n", $notices),
            'the drift notice must ride the launch-notice channel /notices reads'
        );
        $this->assertContains('old-guard', $names2, 'a project hook the last launch already ran is not the new thing');
        $this->assertContains('home-new', $names2, 'the user tier is never entry-quarantined — the operator owns that file');
        $this->assertNotContains('repo-new', $names2, 'the unseen project hook waits one launch');

        // ---- launch 3: the change has been seen and stated; it loads.
        $this->resetFreshnessStatics();
        $names3 = $this->launchHookChain();
        $this->assertContains('repo-new', $names3, 'quarantine is one launch, not a veto — permanence would brick legitimate edits');
    }

    public function testAnAbsentPriorSnapshotNeverQuarantines(): void
    {
        $this->writeProjectHooks("hooks:\n  PreToolUse:\n    - name: day-one\n      command: 'true'\n");

        $names = $this->launchHookChain();

        $this->assertContains('day-one', $names, 'first launch after install has no baseline to be surprised by');
    }

    public function testAProjectOnlyDriftArmsTheQuarantineTheUserFilesCannotSee(): void
    {
        // ISS-090(a): the git-pull shape. Both user files sit untouched —
        // their fingerprints cannot arm anything — while the trusted repo's
        // hooks.yaml gains an entry nobody has seen. Before the fix the new
        // hook ran on the launch that first discovered it.
        $this->writeUserHooks("hooks:\n  PreToolUse:\n    - name: from-home\n      command: 'true'\n");
        $this->writeProjectHooks("hooks:\n  PreToolUse:\n    - name: old-guard\n      command: 'true'\n");
        $this->launchHookChain();

        $this->writeProjectHooks(
            "hooks:\n  PreToolUse:\n"
            . "    - name: old-guard\n      command: 'true'\n"
            . "    - name: pulled-in\n      command: 'true'\n"
        );

        $this->resetFreshnessStatics();
        $before = \count(Bootstrap::launchNotices());
        $names = $this->launchHookChain();
        $notices = \array_slice(Bootstrap::launchNotices(), $before);

        $this->assertNotSame([], $notices, 'project-only drift must still speak through the launch-notice channel');
        $this->assertStringContainsString(
            'carries entries the last launch never saw',
            implode("\n", $notices),
            'the notice must name the file the operator reviews — the repo copy, not ~/.candy-code'
        );
        $this->assertContains('old-guard', $names, 'the seen entry is not the new thing');
        $this->assertNotContains('pulled-in', $names, 'the unseen entry waits one launch — the whole point');

        // And the next launch honours it: one-launch quarantine, not a veto.
        $this->resetFreshnessStatics();
        $this->assertContains('pulled-in', $this->launchHookChain());
    }

    public function testAnUnchangedSecondLaunchStaysSilentAboutProjectHooks(): void
    {
        // The negative half: identity comparison that re-arms on every launch
        // would quarantine (and announce) the same hook forever.
        $this->writeProjectHooks("hooks:\n  PreToolUse:\n    - name: steady\n      command: 'true'\n");
        $this->launchHookChain();

        $this->resetFreshnessStatics();
        $before = \count(Bootstrap::launchNotices());
        $names = $this->launchHookChain();

        $this->assertSame([], \array_slice(Bootstrap::launchNotices(), $before), 'an untouched box must not cry drift');
        $this->assertContains('steady', $names);
    }

    public function testTheSnapshotRecordsTheEntryIdentitiesTheNextFilterNeeds(): void
    {
        $this->writeProjectHooks("hooks:\n  PreToolUse:\n    - name: old-guard\n      command: 'true'\n");
        $this->launchHookChain();

        $record = PolicySnapshot::read(static fn (): string => self::configDir());

        $this->assertIsArray($record);
        $this->assertArrayHasKey($this->project, $record['projectHooks']);
        $this->assertSame(
            ['old-guard|PreToolUse|true'],
            $record['projectHooks'][$this->project],
            'the identity triple the loader compares is name|event|command'
        );
    }

    // =========================================================================
    // Harness
    // =========================================================================

    /** One fake launch: fresh statics already reset; return PreToolUse names. */
    private function launchHookChain(): array
    {
        $hooks = (new \ReflectionMethod(Bootstrap::class, 'hooks'))->invoke(null, null, $this->project);
        $this->assertInstanceOf(HookManager::class, $hooks);

        $registry = (new \ReflectionProperty(HookManager::class, 'registry'));
        $registry->setAccessible(true);
        /** @var \SugarCraft\Code\Hooks\HookRegistry $reg */
        $reg = $registry->getValue($hooks);
        $getFor = new \ReflectionMethod($reg, 'getForEvent');
        $getFor->setAccessible(true);

        return array_map(
            static fn (object $hook): string => $hook->name(),
            $getFor->invoke($reg, 'PreToolUse')
        );
    }

    private static function configDir(): string
    {
        $method = new \ReflectionMethod(Bootstrap::class, 'trustedConfigDirPath');
        $method->setAccessible(true);

        return $method->invoke(null);
    }

    /**
     * A "new process" as far as the freshness guard is concerned: the
     * once-per-process flag, the decision, both quarantine inputs, the
     * per-path entries memo (its files change under a fixed name between
     * launches, which no real process ever does), and the notice store.
     */
    private function resetFreshnessStatics(): void
    {
        foreach ([
            'policyFreshnessChecked' => false,
            'policyChanged' => false,
            'policyPreviousProjectHooks' => [],
            'policyPreviousTrustedRoots' => [],
            'hookFileEntries' => [],
            'launchNotices' => [],
            'launchNoticesDropped' => [],
        ] as $name => $value) {
            $property = new \ReflectionProperty(Bootstrap::class, $name);
            $property->setAccessible(true);
            $property->setValue(null, $value);
        }
    }

    private function writeUserConfig(array $config): void
    {
        file_put_contents($this->home . '/.candy-code/config.json', (string) json_encode($config));
    }

    private function writeUserHooks(string $yaml): void
    {
        file_put_contents($this->home . '/.candy-code/hooks.yaml', $yaml);
        clearstatcache();
    }

    private function writeProjectHooks(string $yaml): void
    {
        file_put_contents($this->project . '/.candy-code/hooks.yaml', $yaml);
        clearstatcache();
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? @chmod($path, 0700) : null;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
