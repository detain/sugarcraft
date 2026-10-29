<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchNotices;
use SugarCraft\Code\Config\LayeredSettings;
use SugarCraft\Code\Cli\PermissionConfigException;
use SugarCraft\Code\Permissions\PermissionDecision;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\ToolCall;

/**
 * C-SEC-6.b end to end: the trusted project's shared settings file narrows
 * the gate's rules — deny/ask entries only, loaded FIRST, corruption refuses
 * the launch, untrusted roots are never read at all — and the launch says so
 * exactly once.
 *
 * Every case drives the REAL {@see Bootstrap::permissionGate()} through a
 * sandboxed HOME and a real project directory, the shape
 * {@see \SugarCraft\Code\Tests\Cli\BootstrapToolAndPermissionSettingsTest}
 * established for this seam: rules that "loaded" but never answered are the
 * failure mode this family exists to catch, so the assertions run through
 * `evaluate()` and `rules()`, not just the parsed array.
 *
 * Fails before the fix because `projectNarrowingRules()` did not exist — the
 * trusted project's deny contributed nothing, the user's allow answered alone,
 * and no notice ever said a word.
 */
final class BootstrapProjectNarrowingRulesTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tmpPnr = '';
    private string $homePnr = '';
    private string $configDirPnr = '';
    private string $projectRootPnr = '';
    private string|false $originalPermissionMode = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalPermissionMode = getenv('CANDY_CODE_PERMISSION_MODE');
        putenv('CANDY_CODE_PERMISSION_MODE');

        $this->tmpPnr = sys_get_temp_dir() . '/bootstrap_pnr_' . uniqid('', true);
        $this->homePnr = $this->tmpPnr . '/home';
        $this->configDirPnr = $this->homePnr . '/.candy-code';
        $this->projectRootPnr = $this->tmpPnr . '/repo';

        mkdir($this->configDirPnr, 0o700, true);
        mkdir($this->projectRootPnr . '/' . LayeredSettings::dir(), 0o700, true);

        $this->useHomeSandbox($this->homePnr);
        Bootstrap::useMaxTurns(null);
        LaunchNotices::reset();
        Bootstrap::resetProjectNarrowingRulesForTesting();
    }

    protected function tearDown(): void
    {
        Bootstrap::useProjectRootForSettings(null);
        Bootstrap::useConfigPath(null);
        Bootstrap::useMaxTurns(null);
        $this->restoreHomeSandbox();
        LaunchNotices::reset();
        Bootstrap::resetProjectNarrowingRulesForTesting();

        if (is_string($this->originalPermissionMode)) {
            putenv('CANDY_CODE_PERMISSION_MODE=' . $this->originalPermissionMode);
        }

        $this->purgeTree($this->tmpPnr);

        parent::tearDown();
    }

    /**
     * THE ONE SENTENCE THIS STEP EXISTS FOR: a project `deny` cannot be
     * beaten by a user `allow`, because the project's rules ride BEFORE the
     * user's and the gate answers first-match-wins. Both halves are pinned —
     * the loaded ORDER (the merge) and the DECISION (the consumer) — since a
     * rule that loads in the right place but does not answer is exactly the
     * "looked like it worked" defect.
     */
    public function testAProjectDenyAnswersBeforeAUserAllowOnTheSamePattern(): void
    {
        // One write: the trust grant and the user's rules share `config.json`,
        // and a second write would replace the first file-wide.
        $this->writePolicyFile([
            LayeredSettings::PROJECT_SETTINGS_TRUST_KEY => [realpath($this->projectRootPnr)],
            'permissionRules' => [['pattern' => 'Bash', 'action' => 'allow']],
        ]);
        $this->writeSharedSettingsFile(['permissionRules' => [['pattern' => 'Bash', 'action' => 'deny']]]);
        Bootstrap::useProjectRootForSettings($this->projectRootPnr);

        $gate = Bootstrap::permissionGate();

        self::assertSame(
            [['Bash', 'deny'], ['Bash', 'allow']],
            self::ruleShape($gate->rules()),
        );
        self::assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('Bash', ['command' => 'ls'])));
    }

    /**
     * The trust floor from the other side: the SAME file that supplies rules
     * still cannot set the mode, and a `bypass-permissions` spelled by a
     * checkout must not reach the gate even under the settings grant —
     * `permissionMode` is read from user files alone.
     */
    public function testTheSharedFileThatNarrowsRulesStillCannotSetTheMode(): void
    {
        $this->writeUserSettingsFile(['permissionMode' => 'plan']);
        $this->trustFixtureProject();
        $this->writeSharedSettingsFile([
            // An ignored sibling key is the point: rules YES, mode NO, one
            // byte apart in the same file.
            'permissionMode' => 'bypass-permissions',
            'permissionRules' => [['pattern' => 'Read', 'action' => 'deny']],
        ]);
        Bootstrap::useProjectRootForSettings($this->projectRootPnr);

        $gate = Bootstrap::permissionGate(interactive: true);

        self::assertSame(PermissionMode::Plan, $gate->mode());
        self::assertContains(['Read', 'deny'], self::ruleShape($gate->rules()));
    }

    /**
     * UNTRUSTED IS READ NO AT ALL: no rules, no notice, no exception — the
     * grant gates the door before the file is opened.
     */
    public function testAnUntrustedProjectContributesNoRulesAndSaysNothing(): void
    {
        $this->writeSharedSettingsFile(['permissionRules' => [['pattern' => 'Bash', 'action' => 'deny']]]);
        Bootstrap::useProjectRootForSettings($this->projectRootPnr);

        $gate = Bootstrap::permissionGate();

        self::assertSame([], $gate->rules());
        self::assertSame([], Bootstrap::projectNarrowingRuleSignatures());
        self::notNarrowingNotice();
    }

    /**
     * ONE notice, naming the file, the loaded count and the dropped count —
     * twice through the gate build (the launch calls it more than once in
     * some paths) says it the first time and never again.
     */
    public function testTheNarrowingNoticeNamesTheFileCountsBothSidesAndFiresOnce(): void
    {
        $this->trustFixtureProject();
        $this->writeSharedSettingsFile([
            'permissionRules' => [
                ['pattern' => 'Bash', 'action' => 'deny'],
                ['pattern' => 'Write', 'action' => 'allow'],
            ],
        ]);
        Bootstrap::useProjectRootForSettings($this->projectRootPnr);

        Bootstrap::permissionGate();
        Bootstrap::permissionGate();

        $rows = implode("\n", Bootstrap::launchNotices());
        self::assertSame(1, substr_count($rows, 'narrowed this launch'));
        self::assertStringContainsString($this->sharedSettingsPath(), $rows);
        self::assertStringContainsString('1 deny/ask rule(s) loaded first', $rows);
        self::assertStringContainsString('1 allow rule(s) dropped', $rows);
    }

    /**
     * A project tier that ONLY asked to widen contributes nothing and still
     * says so: zero loaded, one dropped — an empty notice would let the
     * author's assumed grant pass as in force.
     */
    public function testAnAllowOnlyProjectReportsZeroLoadedAndItsDrop(): void
    {
        $this->trustFixtureProject();
        $this->writeSharedSettingsFile(['permissionRules' => [['pattern' => 'Bash', 'action' => 'allow']]]);
        Bootstrap::useProjectRootForSettings($this->projectRootPnr);

        $gate = Bootstrap::permissionGate();

        self::assertSame([], $gate->rules());
        $rows = implode("\n", Bootstrap::launchNotices());
        self::assertSame(1, substr_count($rows, 'narrowed this launch'));
        self::assertStringContainsString('0 deny/ask rule(s) loaded first', $rows);
        self::assertStringContainsString('1 allow rule(s) dropped', $rows);
    }

    /**
     * STRICT, at the user files' bargain: a shared settings file that exists
     * and cannot be used REFUSES the launch under trust, rather than silently
     * narrowing nothing — "absent" and "unparseable" must not wear the same
     * face on the permission path.
     */
    public function testACorruptSharedFileRefusesATrustedLaunch(): void
    {
        $this->trustFixtureProject();
        $this->writeRawShared('{ "permissionRules": [ ');
        Bootstrap::useProjectRootForSettings($this->projectRootPnr);

        $this->expectException(PermissionConfigException::class);

        Bootstrap::permissionGate();
    }

    /**
     * …and the SAME corruption says nothing at all when the project is not
     * trusted — the trust grant decides whether the file is read, and a
     * checkout nobody vouched for cannot hold the launch hostage.
     */
    public function testACorruptSharedFileIsSilentAndHarmlessUntrusted(): void
    {
        $this->writeRawShared('{ "permissionRules": [ ');
        Bootstrap::useProjectRootForSettings($this->projectRootPnr);

        $gate = Bootstrap::permissionGate();

        self::assertSame([], $gate->rules());
        self::notNarrowingNotice();
    }

    /**
     * The `/permissions` tier column's data source: only the rules the
     * project ACTUALLY contributed are signatures, under their exact
     * `action pattern` spelling.
     */
    public function testTheSignatureRosterHoldsOnlyWhatTheProjectContributed(): void
    {
        $this->writePolicyFile([
            LayeredSettings::PROJECT_SETTINGS_TRUST_KEY => [realpath($this->projectRootPnr)],
            'permissionRules' => [['pattern' => 'Bash', 'action' => 'allow']],
        ]);
        $this->writeSharedSettingsFile([
            'permissionRules' => [
                ['pattern' => 'Bash', 'action' => 'deny'],
                ['pattern' => 'Write', 'action' => 'ask'],
                ['pattern' => 'Read', 'action' => 'allow'],
            ],
        ]);
        Bootstrap::useProjectRootForSettings($this->projectRootPnr);

        Bootstrap::permissionGate();

        self::assertSame(['deny Bash', 'ask Write'], Bootstrap::projectNarrowingRuleSignatures());
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /**
     * The rules as `[[pattern, action], …]` — comparing shapes, not object
     * identity: the gate rebuilds every rule, and an identity assert would
     * fail on a correct load.
     *
     * @param list<\SugarCraft\Code\Permissions\PermissionRule> $rules
     *
     * @return list<array{string, string}>
     */
    private static function ruleShape(array $rules): array
    {
        return array_map(
            static fn (\SugarCraft\Code\Permissions\PermissionRule $rule): array => [$rule->pattern, $rule->action->value],
            $rules,
        );
    }

    /** @param array<string, mixed> $data */
    private function writePolicyFile(array $data): void
    {
        file_put_contents($this->configDirPnr . '/config.json', (string) json_encode($data));
        // permissionConfig() refuses a policy file anyone else can write.
        chmod($this->configDirPnr . '/config.json', 0o600);
    }

    /** @param array<string, mixed> $data */
    private function writeUserSettingsFile(array $data): void
    {
        file_put_contents(
            $this->configDirPnr . '/' . LayeredSettings::USER_FILE,
            (string) json_encode($data),
        );
    }

    private function sharedSettingsPath(): string
    {
        return $this->projectRootPnr . '/' . LayeredSettings::SHARED_PATH;
    }

    /** @param array<string, mixed> $data */
    private function writeSharedSettingsFile(array $data): void
    {
        file_put_contents($this->sharedSettingsPath(), (string) json_encode($data));
    }

    private function writeRawShared(string $bytes): void
    {
        file_put_contents($this->sharedSettingsPath(), $bytes);
    }

    /**
     * `realpath()` — a raw temp string would make every trusted case silently
     * untrusted (the trust list compares canonical paths).
     */
    private function trustFixtureProject(): void
    {
        $canonical = realpath($this->projectRootPnr);
        self::assertIsString($canonical);

        $this->writePolicyFile([
            LayeredSettings::PROJECT_SETTINGS_TRUST_KEY => [$canonical],
        ]);
    }

    private function notNarrowingNotice(): void
    {
        $rows = implode("\n", Bootstrap::launchNotices());
        self::assertSame(0, substr_count($rows, 'narrowed this launch'));
    }

    private function purgeTree(string $dir): void
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
            if ($entry->isDir() && !$entry->isLink()) {
                @rmdir($entry->getPathname());
                continue;
            }

            @unlink($entry->getPathname());
        }

        @rmdir($dir);
    }
}
