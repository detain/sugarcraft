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
        // The highest-precedence source, and a static: leaving it set would
        // pin every later gate in the process to this fixture's mode, which is
        // the same process-wide leak the env-var branch below documents.
        Bootstrap::usePermissionMode(null);
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
    // E57 — a project tier's tool removals must be visible, whatever the glob
    // -------------------------------------------------------------------------

    /**
     * THE FINDING, re-measured in this lane before anything changed: a trusted
     * project's `{"disabledTools": ["[!B]*"]}` leaves exactly `Bash` out of
     * eleven, and said nothing about it. Eight characters that read as almost
     * nothing and mean "everything except Bash".
     *
     * WHAT IS ASSERTED IS THE REPORT, NOT A REFUSAL, and the difference is the
     * fix's whole contract. The capability is unchanged — a project the operator
     * has listed in `trustedProjectSettings` may still reduce the set, and the
     * test below this one pins that it still can. What was broken was the
     * argument the tiering rested on, that a `disabledTools` value "can be seen
     * when you read the file". A pattern-shape restriction cannot make that true
     * again: measured, `["[C-Z]*", "[a-z]*"]` uses no negated class and still
     * leaves only `Bash`. Reporting the EFFECT is closed against every spelling.
     */
    public function testATrustedProjectsToolRemovalsAreReportedWhateverTheGlobLooksLike(): void
    {
        $this->trustTheProject();
        $this->writeProjectSettings(['disabledTools' => ['[!B]*']]);

        $stderr = $this->stderrOfToolSet();

        self::assertStringContainsString(LayeredSettings::SHARED_PATH, $stderr);
        self::assertStringContainsString('disabledTools', $stderr);
        // The tools it actually took, by name — the list the file did not carry.
        self::assertStringContainsString('Read', $stderr);
        self::assertStringContainsString('WebSearch', $stderr);
        // And what the model is left holding, which is the part that says why
        // this matters: everything narrow and reviewable is gone and the one
        // tool that reaches the gate as opaque shell text is not.
        self::assertStringContainsString('Bash', $stderr);
    }

    /**
     * CONTROL, and it is deliberately the SAME fixture as the test above. The
     * fix reports; it does not restrict. A trusted project can still choose the
     * tool set, so anyone reading the report knows they are being told about a
     * grant they made rather than about an attack that was blocked.
     */
    public function testATrustedProjectsGlobStillChoosesTheToolSet(): void
    {
        $this->trustTheProject();
        $this->writeProjectSettings(['disabledTools' => ['[!B]*']]);
        Bootstrap::useProjectRootForSettings($this->projectRoot);

        self::assertSame(['Bash'], $this->toolNames());
    }

    /**
     * THE WHOLE LINE'S WIRING, rendered from the launcher's own formats and
     * matched against a real child launch's stderr.
     *
     * WHAT WAS MISSING AND WHY IT MATTERED (E153).
     * {@see testATrustedProjectsToolRemovalsAreReportedWhateverTheGlobLooksLike()}
     * and its siblings assert only
     * FRAGMENTS — the file name, the word `disabledTools`, three tool names,
     * `leaving: Bash`. Between them they never touch the BODY of
     * {@see Bootstrap::PROJECT_TIER_TOOL_REMOVAL_FORMAT}: the clause that says
     * how many of how many were taken. The launcher could have gone on printing
     * a sentence with the counts silently transposed and no behavioural guard
     * anywhere would have noticed.
     *
     * WHAT THIS TEST PINS, AND WHAT IT DOES NOT — stated exactly, because the
     * first version of this doc-block did not and round 46's review was right
     * to call it. WHAT IT SAID: that the fragments never reach the body, over a
     * measurement of the pre-fix tree, in a paragraph a reader would finish
     * believing this test closed that. WHAT IS TRUE NOW, MEASURED on PHP 8.3.6
     * at round 46, scope = this class under `--filter`: with
     * `PROJECT_TIER_TOOL_REMOVAL_FORMAT` mutated `disabled` → `removed`, this
     * whole class stays green at `OK (57 tests, 136 assertions)`. Of course it
     * does — the expectation below is rendered FROM the constant the child
     * process also renders from, so with respect to the constant's TEXT it is a
     * tautology, the general shape the backlog records for this round. What it
     * DOES pin is the WIRING, and that is the half nothing else covered:
     * transposing `\count($removed)` and `\count($withoutProject)` at the call
     * site takes this class to `Tests: 57, Assertions: 136, Failures: 1`.
     *
     * THE TEXT'S SECOND PARTY IS README.md, VIA
     * {@see \SugarCraft\Crush\Tests\Config\ReadmeSettingsTierClaimTest}, and it
     * is named here so that whoever deletes that file's `assertSame` knows what
     * else they are removing. MEASURED, same mutation, scope = that class:
     * `Tests: 5, Assertions: 30, Failures: 1`. That is the whole reason this
     * test needs no independent literal copy of its own, where
     * {@see testAProjectThatRemovesEveryToolReportsTheNoSurvivorsBranch()} does
     * carry one — there is no README sample for the no-survivors branch. The
     * pair is the pin and neither half is one alone: that file says the
     * constant is THAT SENTENCE, this one says the running program wires THOSE
     * ARGUMENTS into it in that order.
     *
     * RENDERED, NOT RETYPED, for the reason that sibling was repaired for in
     * the same round: a checker that keeps its own copy of the string drifts
     * with it. Every argument here is MEASURED — the census, the removals, the
     * survivors — so a twelfth built-in tool moves the expectation and the
     * launcher's output together instead of reding this.
     *
     * THE ASSERTION IS CONTAINMENT AND THAT IS DELIBERATE, not the `assertSame`
     * its README sibling uses: stderr from a real launch carries the
     * `sugarcrush: ` envelope, a trailing newline and whatever else the launch
     * had to say, so the body is a needle inside it. What containment cannot
     * catch is a mutation that only SHORTENS the needle — see that sibling,
     * where dropping {@see Bootstrap::STDERR_LINE_FORMAT}'s full stop survived
     * a contains() — and the envelope is exactly what is NOT asserted here,
     * because it is asserted exactly there.
     */
    public function testTheReportedLineIsRenderedFromTheLaunchersOwnFormat(): void
    {
        $ceiling = $this->toolNames();

        $this->trustTheProject();
        $this->writeProjectSettings(['disabledTools' => ['[!B]*']]);
        Bootstrap::useProjectRootForSettings($this->projectRoot);

        $survivors = $this->toolNames();
        $removed = array_values(array_diff($ceiling, $survivors));

        self::assertNotSame([], $removed, 'the fixture removed nothing, so there is no line to assert');
        self::assertNotSame([], $survivors, 'this is the survivors branch; the empty one is the test below');

        self::assertStringContainsString(
            sprintf(
                Bootstrap::PROJECT_TIER_TOOL_REMOVAL_FORMAT,
                $this->projectRoot . '/' . LayeredSettings::SHARED_PATH,
                count($removed),
                count($ceiling),
                implode(', ', $removed),
                Bootstrap::PROJECT_TIER_TOOL_REMOVAL_LEAVING . implode(', ', $survivors),
            ),
            $this->stderrOfToolSet(),
            'the launch-report line a real child printed is not what Bootstrap::PROJECT_TIER_TOOL_REMOVAL_FORMAT '
            . 'renders for the measured census — the format and the launcher have come apart',
        );
    }

    /**
     * THE OTHER BRANCH OF THE SAME FIELD, and the reason it needed a test of
     * its own: {@see Bootstrap::PROJECT_TIER_TOOL_REMOVAL_LEAVING_NONE} had no
     * external reader at all.
     *
     * MEASURED before this test existed:
     * `grep -rn 'leaving no tools at all' src/ tests/ docs/ README.md` returned
     * exactly one hit — the constant's own declaration. It was pinned only
     * STRUCTURALLY, by {@see BootstrapLaunchFormatConstantsTest}'s
     * `METHOD_LITERALS` allowlist, which says the emitting method may not hold
     * a second copy but says nothing about what the launcher prints. A constant
     * whose only reader is the test asserting that it exists is circular: the
     * reader has to be the running program.
     *
     * `["*"]` FROM A TRUSTED PROJECT is the fixture because it is the one the
     * code documents — {@see Bootstrap::filterToolSet()} names
     * `disabledTools: ["*"]` as the supported way to ask for a toolless agent,
     * so this is a configuration the launcher must report rather than refuse.
     *
     * DISTINCT FROM THE SIBLING WARNING, and that is asserted rather than
     * assumed. `filterToolSet()` ALSO raises "allowedTools/disabledTools left no
     * tools at all …" for an empty set, and `no tools at all` is a substring of
     * both sentences — so a guard written on that fragment would pass with this
     * branch of the format deleted outright. The constant's full value carries
     * `leaving`, which the sibling does not.
     */
    public function testAProjectThatRemovesEveryToolReportsTheNoSurvivorsBranch(): void
    {
        $ceiling = $this->toolNames();

        $this->trustTheProject();
        $this->writeProjectSettings(['disabledTools' => ['*']]);
        Bootstrap::useProjectRootForSettings($this->projectRoot);

        self::assertSame([], $this->toolNames(), 'the fixture left survivors, so this is not the none branch');

        $stderr = $this->stderrOfToolSet();

        self::assertStringContainsString(
            sprintf(
                Bootstrap::PROJECT_TIER_TOOL_REMOVAL_FORMAT,
                $this->projectRoot . '/' . LayeredSettings::SHARED_PATH,
                count($ceiling),
                count($ceiling),
                implode(', ', $ceiling),
                Bootstrap::PROJECT_TIER_TOOL_REMOVAL_LEAVING_NONE,
            ),
            $stderr,
            'a trusted project that removed every tool did not print the no-survivors branch of '
            . 'Bootstrap::PROJECT_TIER_TOOL_REMOVAL_FORMAT',
        );

        // …and the survivor branch is genuinely absent rather than both being
        // printed, which a report that appended the clause unconditionally
        // would do while satisfying the assertion above.
        self::assertStringNotContainsString(Bootstrap::PROJECT_TIER_TOOL_REMOVAL_LEAVING, $stderr);

        // THE ONE INDEPENDENT COPY OF THIS SENTENCE IN THE TREE, and it is a
        // deliberate second copy rather than the retyping E118 spent a round
        // removing. THE REASON IS A SURVIVED MUTATION OF THIS VERY FIX, which
        // is the only acceptance test a fix gets. Everything above renders its
        // expectation FROM the constant the child process also renders from, so
        // with respect to the constant's TEXT it is a tautology: MEASURED on
        // PHP 8.3.6 with `PROJECT_TIER_TOOL_REMOVAL_LEAVING_NONE` mutated
        // `'leaving no tools at all'` → `'leaving nothing at all'`, this class
        // stayed at `OK (57 tests, 135 assertions)`. What those assertions DO
        // pin is the wiring, and that is not nothing: deleting the branch
        // outright — the ternary replaced by the survivors clause alone — takes
        // the same class to `Tests: 57, Assertions: 134, Failures: 1`.
        //
        // So the pair is the pin, and neither half is one alone. The
        // assertion above says the running program prints THIS CONSTANT; this
        // one says the constant is THAT SENTENCE. The sibling constants need no
        // such copy because README.md holds theirs — see
        // {@see \SugarCraft\Crush\Tests\Config\ReadmeSettingsTierClaimTest},
        // which renders the page's sample from them and kills a text mutation
        // outright. There is no README sample for the no-survivors branch,
        // so until a doc page grows one this line is the second party.
        self::assertSame(
            'leaving no tools at all',
            Bootstrap::PROJECT_TIER_TOOL_REMOVAL_LEAVING_NONE,
            'the no-survivors clause was reworded. Nothing outside src/ reads it except this line, so '
            . 'rewording it silently is exactly what this assertion exists to prevent; if the new wording '
            . 'is wanted, change it here in the same commit',
        );
    }

    /**
     * CONTROL for the report — the trust gate is upstream of everything here,
     * and it is the reason this finding's blast radius is narrower than it was
     * first recorded as. An untrusted project's `disabledTools` never reaches
     * the merge, so there is nothing to report and the launch must stay silent.
     * Without this case a report hardcoded to fire whenever a project settings
     * file merely EXISTS would satisfy the test above.
     */
    public function testAnUntrustedProjectsDisabledToolsIsNeitherAppliedNorReported(): void
    {
        $this->writeProjectSettings(['disabledTools' => ['[!B]*']]);
        Bootstrap::useProjectRootForSettings($this->projectRoot);

        self::assertSame(11, count($this->toolNames()));
        self::assertStringNotContainsString('disabledTools', $this->stderrOfToolSet());
    }

    /**
     * CONTROL — the report is about the PROJECT tier. The user's own
     * `disabledTools` is a choice they made in their own file and is not news to
     * them; reporting it would be the per-launch noise
     * {@see LayeredSettings::projectLayer()} explicitly declines to produce.
     */
    public function testTheUsersOwnDisabledToolsIsNotReportedAsAProjectRemoval(): void
    {
        $this->writeUserSettings(['disabledTools' => ['[!B]*']]);

        $stderr = $this->stderrOfToolSet();

        // The PROJECT ROOT rather than LayeredSettings::SHARED_PATH: the
        // fixture's user file is `<home>/.sugar-crush/settings.json`, of which
        // that constant is a literal substring, so a not-contains on it would
        // be satisfied for the wrong reason the moment anything printed the
        // user's own path.
        self::assertStringNotContainsString($this->projectRoot, $stderr);
        self::assertStringNotContainsString('disabled 10 of', $stderr);
    }

    /**
     * A REAL MITIGATION NOBODY HAD RECORDED, and it is the first thing anyone
     * re-reading E57 should know. `LayeredSettings::merge()` is KEY-LEVEL, not a
     * union: a user who names any `disabledTools` of their own REPLACES a
     * trusted project's list outright rather than adding to it. So the
     * project-tier glob only ever bites an operator who set no `disabledTools`
     * themselves — which narrows the finding a second time, on top of the trust
     * grant it already required.
     *
     * Measured, and the counts are derived rather than written: `["Read"]` from
     * the user against `["[!B]*"]` from a trusted project removes exactly `Read`
     * and leaves everything the project's glob names.
     */
    public function testAUsersOwnDisabledToolsReplacesATrustedProjectsRatherThanUnioningWithIt(): void
    {
        $ceiling = $this->toolNames();

        $this->trustTheProject();
        $this->writeUserSettings(['disabledTools' => ['Read']]);
        $this->writeProjectSettings(['disabledTools' => ['[!B]*']]);
        Bootstrap::useProjectRootForSettings($this->projectRoot);

        $names = $this->toolNames();

        self::assertNotContains('Read', $names);
        // The project's complement glob did nothing: everything it names is
        // still here. Had the two lists unioned, only `Bash` would remain.
        self::assertContains('Edit', $names);
        self::assertContains('WebSearch', $names);
        self::assertCount(count($ceiling) - 1, $names);
    }

    /**
     * THE OVER-REPORT, and it is why the report is a DIFF rather than a
     * re-match of the project's patterns. Given the replacement above, a
     * re-match would announce removals the project did not make, precisely when
     * the operator's own `disabledTools` had already displaced it. Here both
     * files name `WebSearch`; the project's list never applied, nothing it named
     * was taken by it, and nothing is said.
     */
    public function testAToolTheUsersOwnSettingsAlreadyRemovedIsNotReportedAsTheProjectsDoing(): void
    {
        $this->trustTheProject();
        $this->writeUserSettings(['disabledTools' => ['WebSearch']]);
        $this->writeProjectSettings(['disabledTools' => ['WebSearch']]);

        self::assertStringNotContainsString('(disabledTools)', $this->stderrOfToolSet());
    }

    /**
     * ALSO MEASURED IN ROUND 39 AND NOT PREVIOUSLY RECORDED: `disabledTools`
     * has no floor. `["*"]` leaves ZERO tools and nothing noticed.
     *
     * REPORTED, NOT REFUSED, and the reason is a shipped doc-block rather than a
     * judgement call: {@see Bootstrap::filterToolSet()} already names
     * `disabledTools: ["*"]` as the supported way to ask for a toolless agent,
     * in the paragraph explaining why an empty `allowedTools` means "all"
     * instead. Refusing it would break a configuration the code documents as
     * intentional. The direction is fail-safe — a model with no tools can do
     * nothing — so the only defect was the silence.
     */
    public function testATotallyEmptyToolSetIsReportedRatherThanHandedOverSilently(): void
    {
        $this->writeUserSettings(['disabledTools' => ['*']]);

        self::assertSame([], $this->toolNames());
        self::assertStringContainsString('no tools', $this->stderrOfToolSet());
    }

    /**
     * CONTROL for the case above — an ordinary filtered launch must not claim an
     * empty tool set. A report keyed on "disabledTools is set" rather than on
     * the resulting count would pass the test above and fail this one.
     */
    public function testANonEmptyFilteredToolSetIsNotReportedAsEmpty(): void
    {
        $this->writeUserSettings(['disabledTools' => ['WebSearch']]);

        self::assertStringNotContainsString('no tools', $this->stderrOfToolSet());
    }

    /**
     * Everything `Bootstrap::tools()` writes to stderr for this fixture's
     * project root, from a CHILD process — same technique and same reason as
     * {@see stderrOfPermissionGate()}, and the same sentinel so that no
     * `assertStringNotContainsString` over it can pass by the child having died.
     */
    private function stderrOfToolSet(): string
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $script = $this->tmpDir . '/toolset.php';
        $errFile = $this->tmpDir . '/toolset-stderr.txt';
        $root = var_export($this->projectRoot, true);

        file_put_contents(
            $script,
            "<?php\nrequire " . var_export($autoload, true) . ";\n"
            . "\\SugarCraft\\Crush\\Cli\\Bootstrap::useProjectRootForSettings({$root});\n"
            . "\\SugarCraft\\Crush\\Cli\\Bootstrap::tools({$root});\n"
            . "fwrite(STDERR, 'tools-built');\n",
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
            'tools-built',
            $stderr,
            'the child process must have reached the end of tools()',
        );

        return $stderr;
    }

    // -------------------------------------------------------------------------
    // E58 — an EMPTY value in a later layer must not displace an earlier one
    // -------------------------------------------------------------------------

    /**
     * THE BUG, in its narrowest true statement: `""` is how a user spells "I am
     * not setting this here", and writing it in `config.json` used to throw away
     * the `permissionMode` they DID set in `settings.json`.
     *
     * Measured before the fix, with exactly this fixture: `bypass-permissions`,
     * sourced from `the built-in default`.
     *
     * THE ASSERTION IS ABOUT DISPLACEMENT, NOT ABOUT BYPASS. It would be
     * tempting to write this as "must not reach bypass-permissions", because
     * that is what the fail-open looked like from outside. But the mechanism is
     * "an empty key falls through to {@see Bootstrap::DEFAULT_PERMISSION_MODE}",
     * and that default only HAPPENS to be the widest mode today — tighten it and
     * the identical bug would silently lock a user out instead, and a
     * bypass-shaped assertion would go on passing through it. So both halves are
     * asserted: the mode the earlier layer configured, and the file it came
     * from.
     */
    public function testAnEmptyModeInTheWrittenConfigDoesNotDisplaceTheSettingsFile(): void
    {
        $this->writeUserConfigFile(['permissionMode' => '']);
        $this->writeUserSettings(['permissionMode' => 'plan']);

        $gate = Bootstrap::permissionGate();

        self::assertSame(PermissionMode::Plan, $gate->mode());
        self::assertStringContainsString(LayeredSettings::USER_FILE, (string) $gate->modeSource());
    }

    /**
     * The other spelling of the same nothing. `null` and `""` were measured to
     * behave identically before the fix and must behave identically after it —
     * a fix that dropped only the empty string would leave the JSON-native way
     * of writing "no value" still displacing.
     */
    public function testANullModeInTheWrittenConfigDoesNotDisplaceTheSettingsFile(): void
    {
        $this->writeUserConfigFile(['permissionMode' => null]);
        $this->writeUserSettings(['permissionMode' => 'plan']);

        $gate = Bootstrap::permissionGate();

        self::assertSame(PermissionMode::Plan, $gate->mode());
        self::assertStringContainsString(LayeredSettings::USER_FILE, (string) $gate->modeSource());
    }

    /**
     * CONTROL — passes before the fix as well as after, and is here to pin the
     * half of the behaviour that must NOT change. With nothing underneath it,
     * an empty `permissionMode` is still read as absence and the built-in
     * default is still what runs. The fix is "an empty value may not displace an
     * earlier layer", not "an empty value is an error", and without this case
     * the two tests above would also be satisfied by a fix that refused the
     * launch on `""` — a change that would break every config that already
     * carries a blank key.
     */
    public function testAnEmptyModeWithNothingBeneathItIsStillReadAsAbsent(): void
    {
        $this->writeUserConfigFile(['permissionMode' => '']);

        $gate = Bootstrap::permissionGate();

        self::assertSame(PermissionMode::BypassPermissions, $gate->mode());
        self::assertSame('the built-in default', $gate->modeSource());
    }

    /**
     * CONTROL — the emptiness rule is deliberately narrow. `"  "` is not an
     * empty value, it is a value that names no mode, and it went on refusing the
     * launch (loudly, naming the file) both before and after the fix. Widening
     * "empty" to "blank after trimming" would turn that refusal into a silent
     * fallback, which is the direction this whole path exists to avoid.
     */
    public function testAWhitespaceModeInTheWrittenConfigStillRefusesTheLaunch(): void
    {
        $this->writeUserConfigFile(['permissionMode' => '  ']);
        $this->writeUserSettings(['permissionMode' => 'plan']);

        $this->expectException(PermissionConfigException::class);
        Bootstrap::permissionGate();
    }

    /**
     * The SAME SHAPE on the other permission key, and the reason one mechanism
     * covers both: `permissionRules` announced its own emptiness on stderr, but
     * the announcement never said the value it dropped had come from a different
     * file, so a user reading it had no way to know their `deny` rule was gone.
     *
     * Measured before the fix with this fixture: zero rules on the gate.
     */
    public function testEmptyRulesInTheWrittenConfigDoNotDisplaceTheSettingsFilesRules(): void
    {
        $this->writeUserConfigFile(['permissionRules' => null]);
        $this->writeUserSettings([
            'permissionMode' => 'plan',
            'permissionRules' => [['pattern' => 'Bash', 'action' => 'deny']],
        ]);

        self::assertCount(1, Bootstrap::permissionGate()->rules());
    }

    /** The empty-string spelling of the case above. */
    public function testAnEmptyStringRulesKeyInTheWrittenConfigDoesNotDisplaceEither(): void
    {
        $this->writeUserConfigFile(['permissionRules' => '']);
        $this->writeUserSettings([
            'permissionMode' => 'plan',
            'permissionRules' => [['pattern' => 'Bash', 'action' => 'deny']],
        ]);

        self::assertCount(1, Bootstrap::permissionGate()->rules());
    }

    /**
     * CONTROL, and the boundary of the fix. An explicit `[]` is a WELL-FORMED
     * VALUE of the right type, not an empty spelling of "unset", so it still
     * wins over `settings.json` under the documented later-layer-wins
     * precedence. Measured: zero rules, before and after. Recorded as a test
     * rather than left to be rediscovered, because "config.json says no rules"
     * and "config.json says nothing" now differ and that difference is the
     * whole fix.
     */
    public function testAnExplicitlyEmptyRulesListStillOutranksTheSettingsFile(): void
    {
        $this->writeUserConfigFile(['permissionRules' => []]);
        $this->writeUserSettings([
            'permissionMode' => 'plan',
            'permissionRules' => [['pattern' => 'Bash', 'action' => 'deny']],
        ]);

        self::assertSame([], Bootstrap::permissionGate()->rules());
    }

    /**
     * The displacement is not silent. Before the fix the mode case said nothing
     * at all and the rules case said only "no rules were loaded" — neither told
     * the user that a value in a DIFFERENT file had been thrown away, which is
     * the only part they can act on.
     */
    public function testAnIgnoredEmptyOverrideNamesBothFilesOnStderr(): void
    {
        $this->writeUserConfigFile(['permissionMode' => '']);
        $this->writeUserSettings(['permissionMode' => 'plan']);

        $stderr = $this->stderrOfPermissionGate();

        self::assertStringContainsString('permissionMode in ', $stderr);
        self::assertStringContainsString('config.json', $stderr);
        self::assertStringContainsString(LayeredSettings::USER_FILE, $stderr);
    }

    /**
     * CONTROL for the report above — a `config.json` that carries the empty key
     * with nothing beneath it displaced nothing, so it must stay silent. Without
     * this, a warning hardcoded to fire on every empty key would satisfy the
     * test above.
     */
    public function testAnEmptyKeyThatDisplacedNothingIsSilent(): void
    {
        $this->writeUserConfigFile(['permissionMode' => '']);

        self::assertStringNotContainsString('permissionMode in ', $this->stderrOfPermissionGate());
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
    // -------------------------------------------------------------------------
    // Provenance: which source the GATE remembers, for `/permissions`
    // -------------------------------------------------------------------------

    /**
     * `permissionGate()` resolved this precedence chain and then threw away
     * WHICH layer won, one line after knowing it. `/permissions` has to report
     * it, and re-deriving it at display time would be a second copy of the
     * precedence — free to disagree with this one, and re-reading files that
     * may have been edited since the launch. So the winning source rides on the
     * gate.
     *
     * Each case asserts the mode AND the source together. Asserting the source
     * alone would pass on a label hardcoded to the layer under test; asserting
     * the mode alone is what the tests above this block already do.
     */
    public function testTheGateRemembersThatTheFlagSetTheMode(): void
    {
        $this->writeUserSettings(['permissionMode' => 'plan']);
        putenv('SUGARCRUSH_PERMISSION_MODE=auto');
        Bootstrap::usePermissionMode('dont-ask');

        $gate = Bootstrap::permissionGate();

        self::assertSame(PermissionMode::DontAsk, $gate->mode());
        self::assertSame('--permission-mode', $gate->modeSource());
    }

    public function testTheGateRemembersThatTheEnvVarSetTheMode(): void
    {
        $this->writeUserSettings(['permissionMode' => 'plan']);
        putenv('SUGARCRUSH_PERMISSION_MODE=dont-ask');

        $gate = Bootstrap::permissionGate();

        self::assertSame(PermissionMode::DontAsk, $gate->mode());
        self::assertSame('$SUGARCRUSH_PERMISSION_MODE', $gate->modeSource());
    }

    /**
     * The file case names the FILE, and the one it names is the one that
     * actually carried the key — the same provenance the refusal message above
     * had to be fixed to get right. A label hardcoded to `config.json` would
     * pass a test that only looked for "a path".
     */
    public function testTheGateRemembersWhichFileSetTheMode(): void
    {
        $this->writeUserSettings(['permissionMode' => 'plan']);

        $gate = Bootstrap::permissionGate();

        self::assertSame(PermissionMode::Plan, $gate->mode());
        self::assertStringContainsString('permissionMode in ', (string) $gate->modeSource());
        self::assertStringContainsString(LayeredSettings::USER_FILE, (string) $gate->modeSource());
        self::assertStringNotContainsString('config.json', (string) $gate->modeSource());
    }

    public function testTheGateSaysSoWhenNothingConfiguredTheMode(): void
    {
        $gate = Bootstrap::permissionGate();

        self::assertSame(PermissionMode::BypassPermissions, $gate->mode());
        self::assertSame('the built-in default', $gate->modeSource());
    }

    /**
     * The rewrite from a `??` chain to a walked list must not change WHEN a bad
     * value is validated. `??` short-circuits, so a broken env var behind a
     * valid flag was never parsed and never threw; a loop that validated every
     * candidate before choosing would turn that into a refused launch.
     */
    public function testAnUnusableValueBehindAWinningSourceIsStillNeverValidated(): void
    {
        $this->writeUserSettings(['permissionMode' => 'not-a-mode-at-all']);
        putenv('SUGARCRUSH_PERMISSION_MODE=also-not-a-mode');
        Bootstrap::usePermissionMode('plan');

        $gate = Bootstrap::permissionGate();

        self::assertSame(PermissionMode::Plan, $gate->mode());
        self::assertSame('--permission-mode', $gate->modeSource());
    }

    /**
     * …and the converse, so the test above cannot be passing because nothing
     * validates anything any more: with no flag, the bad env var still refuses
     * the launch.
     */
    public function testAnUnusableValueInTheWinningSourceStillRefusesTheLaunch(): void
    {
        putenv('SUGARCRUSH_PERMISSION_MODE=also-not-a-mode');

        $this->expectException(PermissionConfigException::class);
        Bootstrap::permissionGate();
    }

    // -------------------------------------------------------------------------
    // F1 — the E57 report has to be readable from INSIDE the alternate screen
    // -------------------------------------------------------------------------

    /**
     * THE DEFECT F1 NAMES, at the seam that fixes it. Before this, the report
     * existed only as an `fwrite(STDERR, …)`, and on the interactive path that
     * is a surface the operator does not have: measured on a real
     * `bin/sugarcrush` launch under a pty, the line printed 0.47s before
     * `\e[?1049h` and replaying the captured stream through a `candy-vt`
     * `Terminal(120, 40)` found no trace of it on the visible screen.
     *
     * THIS TEST IS NOT THE EVIDENCE FOR THAT, and it should not be read as such.
     * A string reaching a static list is not a user seeing a line; the captured
     * launch in the commit message is the evidence. What this pins is the half a
     * unit test can pin — that the launch RECORDS the sentence for the
     * transcript, in the child process where {@see Bootstrap::tools()} really
     * runs, so a refactor cannot quietly take the transcript half away and leave
     * stderr looking fine.
     */
    public function testATrustedProjectsToolRemovalsAreRecordedForTheTranscriptToo(): void
    {
        $this->trustTheProject();
        $this->writeProjectSettings(['disabledTools' => ['[!B]*']]);

        $notices = $this->launchNoticesOfToolSet();

        self::assertCount(1, $notices);
        self::assertStringContainsString(LayeredSettings::SHARED_PATH, $notices[0]);
        self::assertStringContainsString('disabledTools', $notices[0]);
        self::assertStringContainsString('leaving: Bash', $notices[0]);
    }

    /**
     * BOTH CHANNELS, never one instead of the other. `-p` and the scrollback a
     * user gets back after quitting are real consumers of the stderr line, and
     * ~ten sibling launch warnings still share that channel — so the transcript
     * seam had to be additive. The same child run is asserted on twice here
     * rather than in two tests, because "the same launch said it in both places"
     * is the property, and two launches could each say it in one.
     */
    public function testTheSameLaunchStillSaysItOnStderr(): void
    {
        $this->trustTheProject();
        $this->writeProjectSettings(['disabledTools' => ['[!B]*']]);

        [$stderr, $notices] = $this->toolSetLaunchOutput();

        self::assertStringContainsString('leaving: Bash', $stderr);
        self::assertCount(1, $notices);
    }

    /**
     * CONTROL. A launch with nothing to report seeds nothing — the transcript of
     * an ordinary session must not gain a row for the absence of a warning, and
     * {@see Chat::withLaunchNotices()} must not be handed an empty sentence to
     * render.
     */
    public function testALaunchWithNothingToReportRecordsNoNotice(): void
    {
        $this->writeProjectSettings(['disabledTools' => ['[!B]*']]);

        self::assertSame([], $this->launchNoticesOfToolSet());
    }

    /**
     * SAID ONCE, THOUGH IT IS RAISED TWICE. {@see Bootstrap::app()} builds the
     * tool set a SECOND time for the shell's displayed tool list, after
     * {@see Bootstrap::chat()} has already built it — so the report is raised
     * twice per launch with an identical message. stderr is spared that by
     * {@see Bootstrap::warnPermissionConfigOnce()}'s per-process map; the
     * transcript needs its own guard, because that map is not reset per launch
     * and this list is.
     */
    public function testARepeatedReportIsRecordedOnlyOnce(): void
    {
        $this->trustTheProject();
        $this->writeProjectSettings(['disabledTools' => ['[!B]*']]);

        self::assertCount(1, $this->launchNoticesOfToolSet(buildTwice: true));
    }

    /**
     * The end of the seam a user actually reaches: a real
     * {@see Bootstrap::chat()} in a trusted checkout comes up with the report
     * already in its transcript, as a system row.
     *
     * A CHILD PROCESS, for the reason {@see stderrOfToolSet()} uses one —
     * `chat()` opens a session store and registers a shutdown hook, and doing
     * that in-process would leave both behind for every later test in the file.
     */
    public function testTheBuiltChatComesUpWithTheReportInItsTranscript(): void
    {
        $this->trustTheProject();
        $this->writeProjectSettings(['disabledTools' => ['[!B]*']]);

        $history = $this->chatHistoryOfLaunch();

        self::assertCount(1, $history);
        self::assertSame('system', $history[0]['role']);
        self::assertStringContainsString(LayeredSettings::SHARED_PATH, $history[0]['content']);
        self::assertStringContainsString('leaving: Bash', $history[0]['content']);
    }

    /**
     * CONTROL for the one above, and the one that would catch a seam wired to
     * fire unconditionally: an ordinary launch still opens on an EMPTY
     * transcript, which is what {@see \SugarCraft\Crush\Renderer}'s
     * "(empty conversation …)" placeholder is keyed off.
     */
    public function testAnOrdinaryLaunchStillOpensOnAnEmptyTranscript(): void
    {
        self::assertSame([], $this->chatHistoryOfLaunch());
    }

    /**
     * The keys {@see Bootstrap::permissionSettingsLayer()} may emit are a subset
     * of {@see Bootstrap::PERMISSION_SETTINGS_KEYS} — N3.
     *
     * WHY THIS IS WORTH A TEST WHEN THE METHOD LITERALLY LOOPS OVER THAT
     * CONSTANT. {@see Bootstrap::withoutEmptyPermissionOverrides()} scopes its
     * "an empty value must not displace an earlier layer" rule to the same
     * constant, and states in its doc-block that the scope is not observable
     * today BECAUSE the settings layer carries only those keys. That is an
     * argument about two pieces of code 3,000 lines apart, and nothing enforced
     * it: widen the layer's reader without widening the constant (or the other
     * way round) and a new key silently acquires — or silently loses — the
     * displacing behaviour, with no test going red.
     *
     * Asserted against a file carrying a SUPERSET, so the subset relation is
     * measured rather than restated: the extra keys are real, tolerated
     * `settings.json` keys, not invented ones.
     */
    public function testThePermissionSettingsLayerEmitsNothingOutsideItsWhitelist(): void
    {
        $this->writeUserSettings([
            'permissionMode' => 'plan',
            'permissionRules' => [['pattern' => 'Bash', 'action' => 'allow']],
            'theme' => 'dark',
            'model' => 'gpt-4',
            'disabledTools' => ['Read'],
            LayeredSettings::PROJECT_SETTINGS_TRUST_KEY => ['/anywhere'],
        ]);

        $layer = new \ReflectionMethod(Bootstrap::class, 'permissionSettingsLayer');
        $whitelist = new \ReflectionClassConstant(Bootstrap::class, 'PERMISSION_SETTINGS_KEYS');

        /** @var array<string, mixed> $emitted */
        $emitted = $layer->invoke(null, $this->configDir . '/' . LayeredSettings::USER_FILE);
        /** @var list<string> $keys */
        $keys = $whitelist->getValue();

        self::assertSame([], array_diff(array_keys($emitted), $keys));
        // …and not vacuously: the file above really does carry both of them, so
        // an emptied-out reader could not pass this by emitting nothing.
        self::assertSame($keys, array_keys($emitted));
    }

    /**
     * {@see Bootstrap::launchNotices()} after a child process built the tool
     * set, decoded from the child's stdout.
     *
     * @return list<string>
     */
    private function launchNoticesOfToolSet(bool $buildTwice = false): array
    {
        return $this->toolSetLaunchOutput($buildTwice)[1];
    }

    /**
     * One child launch, reported as `[stderr, launchNotices]` so a single run
     * can be asserted on from both sides.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function toolSetLaunchOutput(bool $buildTwice = false): array
    {
        $root = var_export($this->projectRoot, true);
        $body = "\\SugarCraft\\Crush\\Cli\\Bootstrap::tools({$root});\n";

        $out = $this->runInChildLaunch(
            'toolset',
            "\\SugarCraft\\Crush\\Cli\\Bootstrap::useProjectRootForSettings({$root});\n"
            . $body
            . ($buildTwice ? $body : '')
            . "echo json_encode(\\SugarCraft\\Crush\\Cli\\Bootstrap::launchNotices());\n",
        );

        /** @var list<string> $notices */
        $notices = json_decode($out[0], true, 512, JSON_THROW_ON_ERROR);

        return [$out[1], $notices];
    }

    /**
     * The `role`/`content` of every message a real {@see Bootstrap::chat()}
     * comes up with, from a child process.
     *
     * @return list<array{role: string, content: string}>
     */
    private function chatHistoryOfLaunch(): array
    {
        $root = var_export($this->projectRoot, true);

        $out = $this->runInChildLaunch(
            'chatlaunch',
            "\$chat = \\SugarCraft\\Crush\\Cli\\Bootstrap::chat({$root});\n"
            . "\$rows = [];\n"
            . "foreach (\$chat->history as \$m) { \$rows[] = ['role' => \$m->role->value, 'content' => \$m->content]; }\n"
            . "echo json_encode(\$rows);\n",
        );

        /** @var list<array{role: string, content: string}> $rows */
        $rows = json_decode($out[0], true, 512, JSON_THROW_ON_ERROR);

        return $rows;
    }

    /**
     * Run $body in a child PHP process under this fixture's sandboxed HOME.
     *
     * A CHILD, for {@see stderrOfToolSet()}'s reason and one more: this file's
     * launches have to start from a clean {@see Bootstrap} — the notice list is
     * reset per LAUNCH rather than per process, so an in-process assertion on it
     * would be reading whatever earlier tests in the run had left there.
     *
     * @return array{0: string, 1: string} stdout, stderr
     */
    private function runInChildLaunch(string $name, string $body): array
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $script = $this->tmpDir . '/' . $name . '.php';
        $errFile = $this->tmpDir . '/' . $name . '-stderr.txt';
        $outFile = $this->tmpDir . '/' . $name . '-stdout.txt';

        file_put_contents($script, "<?php\nrequire " . var_export($autoload, true) . ";\n" . $body);

        exec(sprintf(
            'HOME=%s SUGARCRUSH_PERMISSION_MODE= timeout -s KILL 60 %s %s >%s 2>%s',
            escapeshellarg($this->home),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            escapeshellarg($outFile),
            escapeshellarg($errFile),
        ));

        return [
            is_file($outFile) ? (string) file_get_contents($outFile) : '',
            is_file($errFile) ? (string) file_get_contents($errFile) : '',
        ];
    }

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
