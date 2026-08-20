<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Cli\PermissionConfigException;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tools\Tool;

/**
 * crush_code.md Phase 6 items 3 and 4: `allowedTools`/`disabledTools` filtering
 * {@see Bootstrap::tools()}, and `permissionMode`/`permissionRules` reachable
 * from the user's hand-authored `~/.sugar-crush/settings.json`.
 *
 * Every case here fails against the build before them, because neither key set
 * had a reader: `grep -n 'allowedTools' src/` returned nothing and
 * `permissionGate()` opened `config.json` and no other file.
 */
final class BootstrapToolAndPermissionSettingsTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tmpDir = '';
    private string $home = '';
    private string $configDir = '';
    private string $projectRoot = '';
    private string|false $originalPermissionMode = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalPermissionMode = getenv('SUGARCRUSH_PERMISSION_MODE');
        putenv('SUGARCRUSH_PERMISSION_MODE');

        $this->tmpDir = sys_get_temp_dir() . '/bootstrap_toolfilter_' . uniqid('', true);
        $this->home = $this->tmpDir . '/home';
        $this->configDir = $this->home . '/.sugar-crush';
        $this->projectRoot = $this->tmpDir . '/repo';

        mkdir($this->configDir, 0o700, true);
        mkdir($this->projectRoot . '/' . LayeredSettings::dir(), 0o700, true);

        $this->useHomeSandbox($this->home);
    }

    protected function tearDown(): void
    {
        Bootstrap::useProjectRootForSettings(null);
        Bootstrap::useConfigPath(null);
        $this->restoreHomeSandbox();

        // BOTH BRANCHES, and the else is the one that matters. This fixture
        // SETS the env var (unlike BootstrapLayeredSettingsTest, whose
        // setUp/tearDown pair this was copied from and which only ever clears
        // it), so a restore-if-it-was-set leaks `dont-ask` into every later test
        // in the process. Measured: it turned six passing tests in
        // McpToolWiringTest, BinSugarcrushWiringTest and
        // CustomCommandShellAndFileFormsTest red in the full run while each of
        // those files stayed green on its own.
        if (is_string($this->originalPermissionMode)) {
            putenv('SUGARCRUSH_PERMISSION_MODE=' . $this->originalPermissionMode);
        } else {
            putenv('SUGARCRUSH_PERMISSION_MODE');
        }

        $this->removeTree($this->tmpDir);

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Item 3 — the tool filter
    // -------------------------------------------------------------------------

    /**
     * The baseline, measured rather than written as a literal: whatever
     * {@see Bootstrap::tools()} builds with no settings is the ceiling every
     * assertion below is relative to. A hardcoded count here would be the
     * decayed-figure defect — the built-in set has already gone from ten to
     * eleven once.
     *
     * @return list<string>
     */
    private function toolNames(): array
    {
        return array_map(
            static function (Tool $tool): string {
                return $tool->name();
            },
            Bootstrap::tools($this->projectRoot),
        );
    }

    public function testWithNoSettingsTheToolSetIsUnfiltered(): void
    {
        $names = $this->toolNames();

        self::assertContains('Bash', $names);
        self::assertContains('Read', $names);
        self::assertGreaterThan(1, count($names));
    }

    public function testDisabledToolsRemovesNamedToolsAndLeavesTheRest(): void
    {
        $before = $this->toolNames();
        self::assertContains('WebSearch', $before);

        $this->writeUserSettings(['disabledTools' => ['WebSearch', 'doctor']]);

        $after = $this->toolNames();
        self::assertNotContains('WebSearch', $after);
        self::assertNotContains('doctor', $after);
        self::assertContains('Bash', $after);
        self::assertCount(count($before) - 2, $after);
    }

    /**
     * The glob dialect is {@see \SugarCraft\Crush\Permissions\PermissionRule}'s,
     * so `Web*` here means what `Web*` means in a permission rule.
     */
    public function testDisabledToolsAcceptsTheSameGlobDialectAsAPermissionRule(): void
    {
        $this->writeUserSettings(['disabledTools' => ['Web*']]);

        $names = $this->toolNames();
        self::assertNotContains('WebSearch', $names);
        self::assertNotContains('WebFetch', $names);
        self::assertContains('Read', $names);
    }

    public function testAllowedToolsIsAWhitelistAndDropsEverythingElse(): void
    {
        $this->writeUserSettings(['allowedTools' => ['Read', 'Grep']]);

        self::assertSame(['Read', 'Grep'], $this->toolNames());
    }

    /**
     * EMPTY MEANS ALL, following
     * {@see \SugarCraft\Crush\MCP\McpRouter::resolveAllowedTools()}. The other
     * reading turns `"allowedTools": []` into a silently toolless agent.
     */
    public function testAnEmptyAllowListMeansEveryToolRatherThanNone(): void
    {
        $before = $this->toolNames();
        $this->writeUserSettings(['allowedTools' => []]);

        self::assertSame($before, $this->toolNames());
    }

    /**
     * The ordering property {@see LayeredSettings::PROJECT_TIER_KEYS} relies on:
     * deny runs after allow, so nothing can re-admit what the whitelist left
     * out. Both keys in one file here; the tier gate is the next test.
     */
    public function testDenyCannotReadmitWhatTheAllowListExcluded(): void
    {
        $this->writeUserSettings([
            'allowedTools' => ['Read'],
            'disabledTools' => ['Read'],
        ]);

        self::assertSame([], $this->toolNames());
    }

    /**
     * A non-list value is ignored rather than coerced to a one-element list —
     * guessing on a key that decides what the model can do is how a config typo
     * becomes a capability change.
     */
    public function testANonListValueIsIgnoredRatherThanCoerced(): void
    {
        $before = $this->toolNames();
        $this->writeUserSettings(['disabledTools' => 'Bash', 'allowedTools' => 'Read']);

        self::assertSame($before, $this->toolNames());
    }

    // -------------------------------------------------------------------------
    // Item 3 — the tier gate
    // -------------------------------------------------------------------------

    public function testATrustedProjectMayDisableAToolButMayNotSetTheWhitelist(): void
    {
        $this->trustTheProject();
        $this->writeProjectSettings([
            'disabledTools' => ['WebSearch'],
            'allowedTools' => ['Bash'],
        ]);
        Bootstrap::useProjectRootForSettings($this->projectRoot);

        $names = $this->toolNames();

        // The deny half landed.
        self::assertNotContains('WebSearch', $names);
        // The whitelist half did NOT: had it landed, `Bash` would be the only
        // entry and every narrow, reviewable tool would be gone.
        self::assertContains('Read', $names);
        self::assertContains('Edit', $names);
    }

    public function testAnUntrustedProjectCannotEvenDisableATool(): void
    {
        $this->writeProjectSettings(['disabledTools' => ['WebSearch']]);
        Bootstrap::useProjectRootForSettings($this->projectRoot);

        self::assertContains('WebSearch', $this->toolNames());
    }

    /**
     * The whitelist is user-tier, and it stays user-tier even when the project
     * is trusted AND the user has said nothing — the case where "fall back to
     * the project's value" would be the tempting implementation.
     */
    public function testTheUsersWhitelistOutranksATrustedProjectsAttemptAtOne(): void
    {
        $this->trustTheProject();
        $this->writeUserSettings(['allowedTools' => ['Read', 'Grep']]);
        $this->writeProjectSettings(['allowedTools' => ['Bash']]);
        Bootstrap::useProjectRootForSettings($this->projectRoot);

        self::assertSame(['Read', 'Grep'], $this->toolNames());
    }

    // -------------------------------------------------------------------------
    // Item 4 — the permission settings block
    // -------------------------------------------------------------------------

    public function testPermissionModeIsReadableFromTheUsersSettingsFile(): void
    {
        $this->writeUserSettings(['permissionMode' => 'plan']);

        self::assertSame(PermissionMode::Plan, Bootstrap::permissionGate()->mode());
    }

    /**
     * `config.json` outranks `settings.json` for {@see Bootstrap::readUserConfig()}'s
     * reason — it is the file the CLI writes, so it must be the file that decides.
     */
    public function testTheWrittenConfigOutranksTheSettingsFileForTheMode(): void
    {
        $this->writeUserConfigFile(['permissionMode' => 'dont-ask']);
        $this->writeUserSettings(['permissionMode' => 'plan']);

        self::assertSame(PermissionMode::DontAsk, Bootstrap::permissionGate()->mode());
    }

    /**
     * The env var is still the per-invocation override, above both files.
     */
    public function testTheEnvVarStillOutranksTheSettingsFile(): void
    {
        $this->writeUserSettings(['permissionMode' => 'plan']);
        putenv('SUGARCRUSH_PERMISSION_MODE=dont-ask');

        self::assertSame(PermissionMode::DontAsk, Bootstrap::permissionGate()->mode());
    }

    /**
     * Rules too, and they reach the gate as real DECISIONS rather than as a
     * parsed array nobody consults — asserted through `evaluate()` so the test
     * cannot pass on a rule that loaded and did nothing.
     */
    public function testPermissionRulesFromTheSettingsFileDecideARealCall(): void
    {
        $this->writeUserSettings([
            'permissionMode' => 'bypass-permissions',
            'permissionRules' => [['pattern' => 'Bash(rm -rf *)', 'action' => 'deny']],
        ]);

        self::assertSame(
            \SugarCraft\Crush\Permissions\PermissionDecision::Deny,
            Bootstrap::permissionGate()->evaluate(
                new \SugarCraft\Crush\ToolCall('Bash', ['command' => 'rm -rf /tmp/x']),
            ),
        );
    }

    /**
     * NO PROJECT TIER, at any trust level: `bypass-permissions` from a file that
     * arrived with a clone would be a sandbox escape delivered by `git clone`.
     * The project here is TRUSTED and still cannot do it, which is the point —
     * a test with an untrusted project would pass for the wrong reason.
     */
    public function testATrustedProjectCannotSetThePermissionModeAtAll(): void
    {
        $this->trustTheProject();
        $this->writeProjectSettings(['permissionMode' => 'bypass-permissions']);
        $this->writeUserSettings(['permissionMode' => 'plan']);
        Bootstrap::useProjectRootForSettings($this->projectRoot);

        self::assertSame(PermissionMode::Plan, Bootstrap::permissionGate()->mode());
    }

    public function testATrustedProjectCannotContributeAPermissionRule(): void
    {
        $this->trustTheProject();
        $this->writeProjectSettings([
            'permissionRules' => [['pattern' => 'Read', 'action' => 'allow']],
        ]);
        Bootstrap::useProjectRootForSettings($this->projectRoot);

        self::assertSame(PermissionMode::BypassPermissions, Bootstrap::permissionGate()->mode());
        // And the rule itself is absent: under DontAsk an `Allow Read` would be
        // indistinguishable from the mode's own read-only allowance, so the
        // observable is that a `Deny`-shaped project rule cannot appear either.
        $this->writeProjectSettings([
            'permissionRules' => [['pattern' => 'Read', 'action' => 'deny']],
        ]);
        self::assertSame(
            \SugarCraft\Crush\Permissions\PermissionDecision::Allow,
            Bootstrap::permissionGate()->evaluate(
                new \SugarCraft\Crush\ToolCall('Read', ['file_path' => './x']),
            ),
        );
    }

    /**
     * The strictness that is the whole reason these keys do not go through
     * {@see LayeredSettings}: a settings file that exists and cannot be parsed
     * refuses the launch rather than silently becoming the permissive default.
     */
    public function testAnUnparseableSettingsFileRefusesTheLaunch(): void
    {
        file_put_contents($this->configDir . '/' . LayeredSettings::USER_FILE, '{ not json at all');

        $this->expectException(PermissionConfigException::class);
        Bootstrap::permissionGate();
    }

    /**
     * ...and the tolerant reader over the SAME file still tolerates it, because
     * a theme is not a policy. Both halves asserted together so the split is
     * pinned rather than described.
     */
    public function testTheTolerantReaderStillToleratesTheSameUnparseableFile(): void
    {
        file_put_contents($this->configDir . '/' . LayeredSettings::USER_FILE, '{ not json at all');

        self::assertSame([], Bootstrap::readUserConfig());
    }

    /**
     * `settings.json` may not smuggle in the grants that make a repository's
     * files policy in the first place — those belong to the file the CLI writes.
     */
    public function testTheSettingsFileCannotSelfGrantProjectTrust(): void
    {
        $canonical = realpath($this->projectRoot);
        self::assertIsString($canonical);

        $this->writeUserSettings([
            LayeredSettings::PROJECT_SETTINGS_TRUST_KEY => [$canonical],
        ]);
        $this->writeProjectSettings(['disabledTools' => ['WebSearch']]);
        Bootstrap::useProjectRootForSettings($this->projectRoot);

        // The trust key came from settings.json, so the project stays untrusted
        // and its tool list is ignored.
        self::assertContains('WebSearch', $this->toolNames());
    }

    // -------------------------------------------------------------------------
    // Provenance: which file the refusal names
    // -------------------------------------------------------------------------

    /**
     * B4 — AN INVALID MODE IN `settings.json` REFUSED THE LAUNCH NAMING
     * `config.json`, a file that in this fixture does not exist.
     *
     * `permissionGate()` built the source label as a literal
     * `userConfigPath()`, hardcoded to `config.json`, from the moment
     * `settings.json` became a second source of the key. Measured before the
     * fix, with only a `settings.json` present:
     *
     *     permissionMode in …/.sugar-crush/config.json is 'nope', which is not …
     *
     * {@see Bootstrap::readPolicyFile()}'s doc-block claims which file refused
     * the launch is always in the error. For this branch it was not, and an
     * error that sends an operator to edit the wrong file is worse than a vague
     * one. Both directions are asserted, in both tests, because "names
     * settings.json" would also be satisfied by a label that always says
     * settings.json.
     */
    public function testAnInvalidModeInTheSettingsFileNamesTheSettingsFile(): void
    {
        $this->writeUserSettings(['permissionMode' => 'nope']);

        try {
            Bootstrap::permissionGate();
            self::fail('an unusable permissionMode must refuse the launch');
        } catch (PermissionConfigException $e) {
            self::assertStringContainsString(LayeredSettings::USER_FILE, $e->getMessage());
            self::assertStringNotContainsString('config.json', $e->getMessage());
        }
    }

    /** The other direction, so the label is provenance and not a new constant. */
    public function testAnInvalidModeInTheWrittenConfigStillNamesTheWrittenConfig(): void
    {
        $this->writeUserConfigFile(['permissionMode' => 'nope']);
        $this->writeUserSettings(['permissionMode' => 'plan']);

        try {
            Bootstrap::permissionGate();
            self::fail('an unusable permissionMode must refuse the launch');
        } catch (PermissionConfigException $e) {
            self::assertStringContainsString('config.json', $e->getMessage());
            self::assertStringNotContainsString(LayeredSettings::USER_FILE, $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // A present-but-null rules key
    // -------------------------------------------------------------------------

    /**
     * S3 — THE RATIONALE FOR `array_key_exists` DID NOT EXIST UNTIL THIS TEST'S
     * SUBJECT WAS BUILT.
     *
     * `permissionSettingsLayer()` copies the whitelisted keys with
     * `array_key_exists` rather than `?? null`, and its comment claimed that an
     * explicit `"permissionRules": null` "has to reach `permissionRules()`' own
     * handling". Measured: `permissionRules()` opened with
     * `$config[KEY] ?? null; if ($raw === null) return [];`, so explicit-null and
     * absent were IDENTICAL and the two spellings of the filter were exactly
     * equivalent — a mutation swapping them survived both permission test files
     * and this one. The claim is now true because the behaviour was built: a
     * present-but-null rules key is REPORTED, since a user who typed the key
     * believes they configured rules.
     *
     * Asserted through a CHILD PROCESS because the warning goes to `STDERR`,
     * which cannot be intercepted in-process — the same technique
     * {@see BootstrapHookFileTest} uses for its warning assertions.
     */
    public function testAPresentButNullRulesKeyInTheSettingsFileIsReported(): void
    {
        $this->writeUserSettings(['permissionMode' => 'plan', 'permissionRules' => null]);

        $stderr = $this->stderrOfPermissionGate();

        self::assertStringContainsString('permissionRules is present but null', $stderr);
    }

    /**
     * ABSENCE IS NOT AN ERROR — the control that stops the test above from being
     * satisfied by a warning that fires on every launch. A fresh install has no
     * `permissionRules` key at all and must say nothing.
     */
    public function testAnAbsentRulesKeyIsSilent(): void
    {
        $this->writeUserSettings(['permissionMode' => 'plan']);

        self::assertStringNotContainsString('permissionRules', $this->stderrOfPermissionGate());
    }

    /**
     * The same for `config.json`, so the report belongs to `permissionRules()`
     * (which reads the MERGED array) rather than to one file's reader.
     */
    public function testAPresentButNullRulesKeyInTheWrittenConfigIsAlsoReported(): void
    {
        $this->writeUserConfigFile(['permissionRules' => null]);

        self::assertStringContainsString(
            'permissionRules is present but null',
            $this->stderrOfPermissionGate(),
        );
    }

    /**
     * A rejected PATTERN is reported with the reason THE GRAMMAR gave, not with
     * a hardcoded "unbalanced parenthesis" — which was false for two of the four
     * shapes `isWellFormedPattern()` refuses (`""` has no parenthesis, `"(rm *)"`
     * has a balanced pair; both lack a tool-name half).
     */
    public function testARejectedPatternIsReportedWithTheReasonTheGrammarGave(): void
    {
        $this->writeUserSettings([
            'permissionMode' => 'plan',
            'permissionRules' => [
                ['pattern' => '(rm -rf *)', 'action' => 'deny'],
                ['pattern' => 'Bash(rm -rf', 'action' => 'deny'],
            ],
        ]);

        $stderr = $this->stderrOfPermissionGate();

        self::assertStringContainsString('no tool-name half', $stderr);
        self::assertStringContainsString('unterminated', $stderr);
        self::assertStringNotContainsString('unbalanced', $stderr);
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /**
     * Everything `Bootstrap::permissionGate()` writes to stderr, from a CHILD
     * process with this fixture's sandbox home — `fwrite(STDERR, …)` targets fd
     * 2 directly and no in-process buffer can see it.
     */
    private function stderrOfPermissionGate(): string
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $script = $this->tmpDir . '/gate.php';
        $errFile = $this->tmpDir . '/stderr.txt';

        file_put_contents(
            $script,
            "<?php\nrequire " . var_export($autoload, true) . ";\n"
            . "\\SugarCraft\\Crush\\Cli\\Bootstrap::permissionGate();\n"
            // THE SENTINEL, and it is not decoration: without it every
            // `assertStringNotContainsString` over this helper's output would
            // pass for free if the child failed to start at all — a vacuous
            // assertion dressed as a control. Asserted below on every call.
            . "fwrite(STDERR, 'gate-built');\n",
        );

        exec(sprintf(
            'HOME=%s SUGARCRUSH_PERMISSION_MODE= timeout -s KILL 60 %s %s >/dev/null 2>%s',
            escapeshellarg($this->home),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            escapeshellarg($errFile),
        ));

        $stderr = is_file($errFile) ? (string) file_get_contents($errFile) : '';

        self::assertStringContainsString(
            'gate-built',
            $stderr,
            'the child process must have reached the end of permissionGate()',
        );

        return $stderr;
    }

    /** @param array<string, mixed> $data */
    private function writeUserConfigFile(array $data): void
    {
        file_put_contents($this->configDir . '/config.json', (string) json_encode($data));
        chmod($this->configDir . '/config.json', 0o600);
    }

    /** @param array<string, mixed> $data */
    private function writeUserSettings(array $data): void
    {
        $path = $this->configDir . '/' . LayeredSettings::USER_FILE;
        file_put_contents($path, (string) json_encode($data));
        // It is a policy file now — permissionSettingsLayer() refuses one anyone
        // else can write, exactly as it refuses such a config.json.
        chmod($path, 0o600);
    }

    /** @param array<string, mixed> $data */
    private function writeProjectSettings(array $data): void
    {
        file_put_contents(
            $this->projectRoot . '/' . LayeredSettings::SHARED_PATH,
            (string) json_encode($data),
        );
    }

    private function trustTheProject(): void
    {
        $canonical = realpath($this->projectRoot);
        self::assertIsString($canonical);

        $this->writeUserConfigFile([
            LayeredSettings::PROJECT_SETTINGS_TRUST_KEY => [$canonical],
        ]);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $entry */
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($dir);
    }
}
