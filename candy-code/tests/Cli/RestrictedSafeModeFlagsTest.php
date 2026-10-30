<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchNotices;
use SugarCraft\Code\Config\LayeredSettings;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Permissions\ShellSubjectTools;
use SugarCraft\Code\Skills\SkillLoader;
use SugarCraft\Code\Skills\SkillManager;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-SEC-7.b: the two launch-narrowing switches.
 *
 * `--restricted` drops the code-running tools at
 * {@see Bootstrap::filterToolSet()} — the one funnel every launch path's
 * model-facing set passes through — and `--safe-mode` makes the process
 * behave as if nothing had ever been trusted: the single
 * `trustedProject*` parser answers empty, bundles answer empty before the
 * memo, and the foreign skills/commands/presets call sites refuse to walk.
 *
 * The pins below are behavior-first at those chokes, not source scans of
 * them; the two source-scan tests at the end are the deliberate exception and
 * say why. Each test that ARMS a latch restores it in tearDown, because
 * LaunchContext statics are process-wide, and each trust/bundles test gets a
 * fresh HOME/root, because those lists freeze per config path at first read —
 * which is itself the design (a mid-session widening must not take effect),
 * so the honest way to see both verdicts is two launches, as a real user does.
 */
final class RestrictedSafeModeFlagsTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tmpDir;
    private string $home;
    private string $projectRoot;

    protected function setUp(): void
    {
        // Lane-cf law: snapshot the sandbox FIRST, before any env unset, so
        // restoreHomeSandbox() puts HOME back exactly as it found it.
        $this->tmpDir = \sys_get_temp_dir() . '/crush_sec7b_' . \uniqid('', true);
        $this->home = $this->tmpDir . '/home';
        $this->projectRoot = $this->tmpDir . '/project';
        @mkdir($this->configDir(), 0700, true);
        @mkdir($this->projectRoot . '/.candy-code', 0700, true);
        $this->useHomeSandbox($this->home);
        \putenv('CANDY_CODE_PERMISSION_MODE');
    }

    protected function tearDown(): void
    {
        Bootstrap::useRestricted(false);
        Bootstrap::useSafeMode(false);
        Bootstrap::useProjectRootForSettings(null);
        Bootstrap::useConfigPath(null);
        self::resetNoticeShelves();
        $this->restoreHomeSandbox();
        self::removeTree($this->tmpDir);
    }

    // =========================================================================
    // The parser
    // =========================================================================

    public function testBareSwitchesArmAndNothingElseDoes(): void
    {
        $args = ArgvParser::parse(['candy-code', '--restricted', '--safe-mode', '-p', 'hi']);
        self::assertTrue($args->restricted);
        self::assertTrue($args->safeMode);
        self::assertSame([], $args->unknownFlags);

        // Space form: the flags ride anywhere in the pre-`--` band and each
        // arms ONLY its own latch.
        $one = ArgvParser::parse(['candy-code', '-p', 'hi', '--restricted']);
        self::assertTrue($one->restricted);
        self::assertFalse($one->safeMode);

        $plain = ArgvParser::parse(['candy-code', '-p', 'hi']);
        self::assertFalse($plain->restricted);
        self::assertFalse($plain->safeMode);
    }

    public function testTheEqualsFormCarriesExplicitVerdictsBothWays(): void
    {
        // The falsy side is not decoration: a wrapper must be able to
        // outvote an alias carrying the bare flag.
        $cases = [
            ['--restricted=true', true, false],
            ['--restricted=1', true, false],
            ['--restricted=yes', true, false],
            ['--restricted=TRUE', true, false],
            ['--restricted=false', false, false],
            ['--restricted=0', false, false],
            ['--restricted=No', false, false],
            ['--safe-mode=true', false, true],
            ['--safe-mode=YES', false, true],
            ['--safe-mode=false', false, false],
        ];

        foreach ($cases as [$token, $wantRestricted, $wantSafeMode]) {
            $args = ArgvParser::parse(['candy-code', $token]);
            self::assertSame(
                $wantRestricted,
                $args->restricted,
                "{$token} must carry its explicit verdict for --restricted",
            );
            self::assertSame(
                $wantSafeMode,
                $args->safeMode,
                "{$token} must carry its explicit verdict for --safe-mode",
            );
            self::assertSame([], $args->unknownFlags, "{$token} is a recognized form");
        }
    }

    public function testANonsenseSwitchValueIsAnUnrecognizedOptionVerbatim(): void
    {
        // 'on' is deliberately outside the families: EnvBool's house law is
        // opt-in on 1/true/yes ONLY, and an argv narrowing that GUESSED a
        // typo'd verdict either way is the failure these flags exist to end.
        // The WHOLE token lands in $unknownFlags — bin/candy-code turns that
        // into the usage error (exit 2) naming it, never a silent default.
        foreach (['--restricted=maybe', '--safe-mode=on', '--restricted=', '--safe-mode=off'] as $token) {
            $args = ArgvParser::parse(['candy-code', $token]);
            self::assertFalse($args->restricted, $token . ' arms nothing');
            self::assertFalse($args->safeMode, $token . ' arms nothing');
            self::assertSame([$token], $args->unknownFlags, $token . ' must be reported verbatim');
        }
    }

    // =========================================================================
    // --restricted at the tool-set funnel
    // =========================================================================

    public function testTheRestrictedRosterIsTheShellPairPlusTheFourCodeShapers(): void
    {
        // Mirrored against ShellSubjectTools::NAMES by reference, not copy:
        // when the shell pair grows a member, --restricted follows it, and a
        // hand-copied list here would freeze the old pair and go green.
        self::assertSame(
            [...ShellSubjectTools::NAMES, 'Task', 'Workflow', 'ShellStop', 'mcp_call'],
            Bootstrap::RESTRICTED_TOOL_NAMES,
        );
    }

    public function testRestrictedDropsCodeRunningToolsAndKeepsFileTools(): void
    {
        $tools = self::toolSet(['Read', 'Edit', 'Bash', 'PtyRun', 'Task', 'Workflow', 'ShellStop', 'mcp_call']);

        // Control, unarmed: everything the launch built passes through.
        self::assertCount(8, Bootstrap::filterToolSet($tools));

        Bootstrap::useRestricted(true);
        $kept = array_map(static fn (Tool $tool): string => $tool->name(), Bootstrap::filterToolSet($tools));

        self::assertSame(['Read', 'Edit'], $kept, '--restricted must take every code-running tool and nothing else');
    }

    public function testTheDropIsOrderedBeforeTheEmptySetWarning(): void
    {
        // A launch whose WHOLE set is code-running must not go silent: the
        // drop runs BEFORE filterToolSet()'s empty-set warn, so the operator
        // sees cli.warn.no_tools_left. Move the gate after the warn and this
        // test is the one that reddens — the set stays empty either way, but
        // an unexplained empty set is exactly the confusion the warn exists
        // to end.
        Bootstrap::useRestricted(true);

        $kept = Bootstrap::filterToolSet(self::toolSet(['Bash']));

        self::assertSame([], $kept);
        self::assertContains(Lang::t('cli.warn.no_tools_left'), Bootstrap::launchNotices());
    }

    public function testTheRestrictedDropRunsAfterTheProjectTierReport(): void
    {
        // The other half of the order pin: the drop runs AFTER
        // reportProjectTierToolRemovals(), so the launch's attribution row
        // names ONLY what the project's own file took. Move the drop above
        // the report call and this reddens: the report diffs the project-
        // reduced set against what filterToolSet handed it, and with the
        // restricted names already gone it would attribute Task and Bash to
        // the PROJECT file — a wrong attribution on a security seam, which is
        // exactly what the comment in filterToolSet() refuses to print.
        $canonical = realpath($this->projectRoot);
        self::assertIsString($canonical);
        $this->writeUserConfig([
            LayeredSettings::PROJECT_SETTINGS_TRUST_KEY => [$canonical],
        ]);
        file_put_contents(
            $this->projectRoot . '/' . LayeredSettings::SHARED_PATH,
            (string) json_encode(['disabledTools' => ['Grep']]),
        );
        Bootstrap::useProjectRootForSettings($canonical);

        $source = LayeredSettings::projectKeySource($canonical, true, 'disabledTools');
        self::assertIsString($source, 'the fixture must produce an attribution source or both legs are vacuous');
        $expectedRow = sprintf(
            Lang::t('cli.launch.tool_removal'),
            $source,
            1,
            5,
            'Grep',
            Lang::t('cli.launch.tool_removal_leaving') . 'Read, Edit, Task, Bash',
        );

        // Unarmed control: the row exists, byte-exact, and the set keeps the
        // restricted names — this proves the fixture reaches the report at all.
        $unarmed = array_map(
            static fn (Tool $tool): string => $tool->name(),
            Bootstrap::filterToolSet(self::toolSet(['Read', 'Edit', 'Grep', 'Task', 'Bash'])),
        );
        self::assertSame(['Read', 'Edit', 'Task', 'Bash'], $unarmed);
        self::assertContains($expectedRow, Bootstrap::launchNotices());

        self::resetNoticeShelves();
        Bootstrap::useRestricted(true);
        $armed = array_map(
            static fn (Tool $tool): string => $tool->name(),
            Bootstrap::filterToolSet(self::toolSet(['Read', 'Edit', 'Grep', 'Task', 'Bash'])),
        );

        self::assertSame(['Read', 'Edit'], $armed, 'the restricted drop itself still lands');
        self::assertContains(
            $expectedRow,
            Bootstrap::launchNotices(),
            'the project attribution row is byte-identical armed: --restricted drops AFTER it, '
            . 'so its names never leak into the row that blames the project file',
        );
    }

    // =========================================================================
    // The two launch notices
    // =========================================================================

    public function testEachSwitchPrintsItsOneLaunchNoticeAndNothingPrintsUnarmed(): void
    {
        self::resetNoticeShelves();

        // The stderr half of the funnel fires under the hood; swallow it so
        // the gate output stays readable. (The shelf is what the transcript
        // carries, and it is what the pins read.)
        ob_start();
        Bootstrap::useRestricted(true);
        // Twice: the store de-dupes by message identity, so a re-registration
        // in one process is one row, not two.
        Bootstrap::useRestricted(true);
        $afterRestricted = Bootstrap::launchNotices();
        Bootstrap::useSafeMode(true);
        $afterBoth = Bootstrap::launchNotices();
        Bootstrap::useRestricted(false);
        Bootstrap::useSafeMode(false);
        $afterUnarm = Bootstrap::launchNotices();
        ob_end_clean();

        $wantRestricted = Lang::t('appLaunch.notice.restricted');
        $wantSafe = Lang::t('appLaunch.notice.safe_mode');

        self::assertSame(
            1,
            \count(array_filter($afterRestricted, static fn (string $row): bool => $row === $wantRestricted)),
            'the restricted notice is recorded exactly once per process',
        );
        self::assertSame(
            [],
            array_values(array_filter($afterRestricted, static fn (string $row): bool => $row === $wantSafe)),
            'the safe-mode notice is separate from the restricted one and has no business being there',
        );
        self::assertSame(
            1,
            \count(array_filter($afterBoth, static fn (string $row): bool => $row === $wantSafe)),
            'the safe-mode switch prints its own single notice',
        );
        self::assertCount(\count($afterBoth), $afterUnarm, 'disarming records no contrary notice');
    }

    // =========================================================================
    // --safe-mode at the trust and bundle chokes
    // =========================================================================

    public function testWithoutTheSwitchTheGrantingConfigIsHonored(): void
    {
        $this->writeUserConfig([
            'trustedProjectHooks' => [$this->projectRoot],
            'trustedProjectCommands' => [$this->projectRoot],
            'trustedProjectMcp' => [$this->projectRoot],
        ]);

        // Control: the SAME fixture the armed test uses, unarmed, answers
        // trusted. Without this twin, an always-refusing choke would pass the
        // armed test for free.
        self::assertTrue(Bootstrap::projectCommandShellIsTrusted($this->projectRoot));
        self::assertContains(realpath($this->projectRoot), self::invokePrivate('trustedRootsForThisProcess'));
        self::assertTrue(self::invokePrivate('projectMcpIsTrusted', [$this->projectRoot]));
        self::assertTrue(self::invokePrivate('projectHooksAreTrusted', [$this->projectRoot]));
    }

    public function testSafeModeRefusesEveryTrustedProjectList(): void
    {
        // Fresh HOME, fresh config path, fresh memos — and the latch goes on
        // BEFORE the first read, because the freeze is per path and arming
        // after a read would test the freeze rather than the switch. This is
        // the launch as the flag delivers it.
        $this->writeUserConfig([
            'trustedProjectHooks' => [$this->projectRoot],
            'trustedProjectCommands' => [$this->projectRoot],
            'trustedProjectMcp' => [$this->projectRoot],
        ]);

        ob_start();
        Bootstrap::useSafeMode(true);
        ob_end_clean();

        self::assertFalse(Bootstrap::projectCommandShellIsTrusted($this->projectRoot));
        self::assertSame([], self::invokePrivate('trustedRootsForThisProcess'));
        self::assertFalse(self::invokePrivate('projectMcpIsTrusted', [$this->projectRoot]));
        self::assertFalse(self::invokePrivate('projectHooksAreTrusted', [$this->projectRoot]));
    }

    public function testSafeModeLoadsNoBundles(): void
    {
        $bundleDir = $this->tmpDir . '/bundle-src';
        @mkdir($bundleDir . '/.claude-plugin', 0777, true);
        file_put_contents($bundleDir . '/.claude-plugin/plugin.json', json_encode(['name' => 'x']));
        $this->writeUserConfig(['pluginPaths' => [$bundleDir]]);

        ob_start();
        Bootstrap::useSafeMode(true);
        $armed = Bootstrap::bundles($this->projectRoot);
        Bootstrap::useSafeMode(false);
        ob_end_clean();

        self::assertSame([], $armed, '--safe-mode loads no bundle even though pluginPaths names one');

        // The same call, unarmed, finds the fixture: the empty verdict above
        // is the switch's doing, not a bundle that never loaded. Production
        // never re-reads unarmed — the latch is set once at the bin seam
        // before anything can call this — and the gate answers BEFORE the
        // memo, so an armed read neither consults nor seeds one.
        $disarmed = Bootstrap::bundles($this->projectRoot);
        self::assertNotSame([], $disarmed, 'the fixture must be discoverable unarmed, or the armed pin is vacuous');
    }

    public function testSafeModeRefusesTheForeignSkillBandAndKeepsNativeManifests(): void
    {
        // The asymmetry IS the design: --safe-mode silences every foreign
        // convention (the band other CLIs write), while the native built-in /
        // project / user tiers keep loading. Pinned as a real SkillManager
        // walk over fixture trees — project and user foreign legs plus a
        // native project manifest — not a source scan.
        $this->writeSkillFixture($this->projectRoot . '/.claude/skills', 'sec7b-proj-foreign', 'a project tree another CLI wrote');
        $this->writeSkillFixture($this->home . '/.claude/skills', 'sec7b-user-foreign', 'the operator-level foreign tree');
        $this->writeSkillFixture($this->projectRoot . '/.candy-code/skills', 'sec7b-native', 'the repositorys own native manifest');

        // Unarmed control FIRST: all three fixtures load, so every armed
        // verdict below is the switch's doing, not a fixture that never did.
        $control = $this->walkSkillRegistry();
        self::assertNotNull($control->get('sec7b-proj-foreign'), 'the project foreign fixture must load unarmed');
        self::assertNotNull($control->get('sec7b-user-foreign'), 'the user foreign fixture must load unarmed');
        self::assertNotNull($control->get('sec7b-native'), 'the native fixture must load unarmed');

        ob_start();
        Bootstrap::useSafeMode(true);
        ob_end_clean();

        $armed = $this->walkSkillRegistry();
        Bootstrap::useSafeMode(false);

        self::assertNull($armed->get('sec7b-proj-foreign'), 'safe mode must refuse the project foreign leg');
        self::assertNull($armed->get('sec7b-user-foreign'), 'safe mode must refuse the user foreign leg');
        self::assertNotNull(
            $armed->get('sec7b-native'),
            'safe mode keeps the native manifests — refusing them too would turn a '
            . 'trust switch into a skills-less launch, which is not the design',
        );
    }

    // =========================================================================
    // The two deliberate source scans
    // =========================================================================

    public function testTheForeignCallSitesAskTheSafeModeLatchBeforeWalking(): void
    {
        // Defense-in-depth scan, and honest about being one: the foreign
        // skills/commands/presets refusals are thin call-site guards at
        // discovery entries, and building a full foreign-tree fixture per
        // vendor dialect to re-walk them would cost more than the regression
        // it catches. The behavioral guarantees — bundles empty, roots
        // empty — are pinned above; the bundle-fed foreign legs go silent
        // through those.
        $skills = (string) file_get_contents(__DIR__ . '/../../src/Skills/SkillManager.php');
        $commands = (string) file_get_contents(__DIR__ . '/../../src/Commands/CommandLoader.php');

        self::assertStringContainsString('Bootstrap::safeMode()', $skills, 'the skill foreign legs lost their guard');
        self::assertStringContainsString('Bootstrap::safeMode()', $commands, 'the command foreign walk lost its guard');
    }

    public function testTheLaunchSeamRegistersBothFlags(): void
    {
        // Production reachability, and the scan shape this repo already uses
        // for bin pins: a parsed flag nobody registers is a flag that does
        // nothing, and the parser tests above would all stay green. The
        // register seam in bin/candy-code is the ONLY place the launch latches
        // are set from argv (same shape as --incognito's).
        $bin = (string) file_get_contents(__DIR__ . '/../../bin/candy-code');

        self::assertStringContainsString('Bootstrap::useRestricted($args->restricted)', $bin);
        self::assertStringContainsString('Bootstrap::useSafeMode($args->safeMode)', $bin);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function configDir(): string
    {
        return $this->home . '/.candy-code';
    }

    /**
     * @param array<string, mixed> $values
     */
    private function writeUserConfig(array $values): void
    {
        file_put_contents($this->configDir() . '/config.json', json_encode($values));
    }

    private function writeSkillFixture(string $dir, string $name, string $description): void
    {
        $skillDir = $dir . '/' . $name;
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir . '/SKILL.md', "---\ndescription: $description\n---\n\nBody.");
    }

    /**
     * One real SkillManager::loadAll() walk over the fixture trees, returning
     * the registry it filled. A FRESH loader/registry per call: the manager
     * writes into the registry it is handed, and the armed/disarmed verdicts
     * above must not share one.
     */
    private function walkSkillRegistry(): SkillRegistry
    {
        $loader = new SkillLoader(reportSkips: false);
        $registry = new SkillRegistry();
        (new SkillManager($loader, $registry))->loadAll($this->projectRoot);

        return $registry;
    }

    /**
     * @param list<string> $names
     *
     * @return list<Tool>
     */
    private static function toolSet(array $names): array
    {
        return array_map(static fn (string $name): Tool => new class ($name) implements Tool {
            public function __construct(private readonly string $toolName)
            {
            }

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return 'fixture tool ' . $this->toolName;
            }

            /**
             * @return array<string, mixed>
             */
            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: 'ok');
            }
        }, $names);
    }

    /**
     * @param list<mixed> $args
     */
    private static function invokePrivate(string $method, array $args = []): mixed
    {
        $reflector = new ReflectionMethod(Bootstrap::class, $method);
        $reflector->setAccessible(true);

        return $reflector->invoke(null, ...$args);
    }

    private static function resetNoticeShelves(): void
    {
        LaunchNotices::reset();

        // The stderr-once map is process-scoped, not launch-scoped; clearing
        // it keeps the notice pins independent of test order.
        $property = new \ReflectionProperty(LaunchNotices::class, 'reportedPermissionConfigWarnings');
        $property->setAccessible(true);
        $property->setValue(null, []);
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}
