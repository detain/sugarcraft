<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config\Settings;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\BackgroundColorMsg;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\Settings\SessionSettings;
use SugarCraft\Crush\Config\Settings\SettingsSchema;
use SugarCraft\Crush\Config\Settings\UiSettings;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\SafetyClassifier;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\Tui\TerminalBackground;

/**
 * Roadmap N-P4g remainder: the operator's own knobs that were constants or
 * environment-only became settings keys, each with a real reader — the Auto
 * breaker's limits, the terminal background, session retention, the spend cap,
 * the MCP switch and the `debug.*` flags. Where a variable covered the same
 * ground it still wins.
 */
final class OperatorSettingsTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = sys_get_temp_dir() . '/crush-operator-settings-' . bin2hex(random_bytes(6));
        mkdir($this->home . '/.sugar-crush', 0o700, true);
        $this->useHomeSandbox($this->home);
        SessionSettings::reset();
        UiSettings::forget();
    }

    protected function tearDown(): void
    {
        SessionSettings::reset();
        UiSettings::forget();
        Bootstrap::useConfigPath(null);
        $this->restoreHomeSandbox();
        self::removeTree($this->home);

        parent::tearDown();
    }

    // ── the Auto breaker ────────────────────────────────────────────────

    public function testTheBreakerDefaultsAreTheConstantsItReplaced(): void
    {
        self::assertSame(['strike' => 3, 'total' => 20], PermissionGate::autoBreakerLimits());
        self::assertSame(PermissionGate::STRIKE_THRESHOLD, SettingsSchema::byKey(PermissionGate::STRIKE_LIMIT_SETTING)?->default);
        self::assertSame(PermissionGate::TOTAL_BLOCK_THRESHOLD, SettingsSchema::byKey(PermissionGate::TOTAL_LIMIT_SETTING)?->default);
        self::assertFalse(SettingsSchema::byKey(PermissionGate::STRIKE_LIMIT_SETTING)?->projectSettable, 'a checkout may not buy its commands more attempts');
    }

    public function testTheStrikeLimitIsWhatEvaluateComparesAgainst(): void
    {
        $this->writeConfig([PermissionGate::STRIKE_LIMIT_SETTING => 2]);
        $gate = new PermissionGate(PermissionMode::Auto, [], new SafetyClassifier());

        self::assertSame(PermissionDecision::Deny, $gate->evaluate($this->forcePush(1)));
        self::assertSame(PermissionDecision::Ask, $gate->evaluate($this->forcePush(2)), 'the second block in a row asks');
        self::assertSame(2, $gate->autoBreaker()['strikeThreshold'], 'the read-out reports the enforced number');
    }

    public function testTheTotalLimitIsWhatEvaluateComparesAgainst(): void
    {
        $this->writeConfig([PermissionGate::STRIKE_LIMIT_SETTING => 100, PermissionGate::TOTAL_LIMIT_SETTING => 4]);
        $gate = new PermissionGate(PermissionMode::Auto, [], new SafetyClassifier());

        for ($i = 1; $i <= 3; $i++) {
            self::assertSame(PermissionDecision::Deny, $gate->evaluate($this->forcePush($i)));
        }
        self::assertSame(PermissionDecision::Ask, $gate->evaluate($this->forcePush(4)));
        self::assertSame(4, $gate->autoBreaker()['totalBlockThreshold']);
    }

    public function testAnOutOfRangeLimitReadsAsTheDefaultNeverAsNoBreaker(): void
    {
        $this->writeConfig([PermissionGate::STRIKE_LIMIT_SETTING => 0, PermissionGate::TOTAL_LIMIT_SETTING => 'many']);

        self::assertSame(['strike' => 3, 'total' => 20], PermissionGate::autoBreakerLimits());
    }

    // ── the terminal background ─────────────────────────────────────────

    public function testTheBackgroundSettingAnswersAboveTheTerminalAndTheVariableAboveIt(): void
    {
        TerminalBackground::forget();
        try {
            self::assertNull(TerminalBackground::setting(), 'auto defers to detection');
            self::assertTrue(TerminalBackground::isDark(['COLORFGBG' => '0;0']));

            $this->writeConfig(['terminalBackground' => 'light']);
            self::assertFalse(TerminalBackground::isDark(['COLORFGBG' => '0;0']), 'the setting outranks COLORFGBG');
            TerminalBackground::observe(new BackgroundColorMsg(0, 0, 0));
            self::assertFalse(TerminalBackground::isDark([]), 'and the terminal\'s own answer');
            self::assertSame(15, TerminalBackground::color([])->ansiIndex, 'the chrome resolves against white');
            self::assertTrue(TerminalBackground::isDark(['SUGARCRUSH_BACKGROUND' => 'dark']), 'the variable wins');
            self::assertTrue(TerminalBackground::detect(['COLORFGBG' => '0;0']), 'detect() stays a pure function of the environment');

            $this->writeConfig(['terminalBackground' => 'purple']);
            self::assertTrue(TerminalBackground::isDark([]), 'a value that is not one of the three is auto');
        } finally {
            TerminalBackground::forget();
        }
    }

    // ── session retention ───────────────────────────────────────────────

    public function testRetentionIsTheSettingUnlessTheVariableIsSetAtAll(): void
    {
        $was = getenv('SUGARCRUSH_SESSION_RETENTION_DAYS');
        putenv('SUGARCRUSH_SESSION_RETENTION_DAYS');
        try {
            self::assertSame(0, Bootstrap::sessionRetentionDays(), 'off by default');

            $this->writeConfig(['sessionRetentionDays' => 30]);
            self::assertSame(30, Bootstrap::sessionRetentionDays());

            putenv('SUGARCRUSH_SESSION_RETENTION_DAYS=0');
            self::assertSame(0, Bootstrap::sessionRetentionDays(), 'an explicit 0 keeps everything whatever the file says');
            putenv('SUGARCRUSH_SESSION_RETENTION_DAYS=7');
            self::assertSame(7, Bootstrap::sessionRetentionDays());
            putenv('SUGARCRUSH_SESSION_RETENTION_DAYS=');
            self::assertSame(30, Bootstrap::sessionRetentionDays(), 'an empty variable is unset');

            $this->writeConfig(['sessionRetentionDays' => 99999999]);
            self::assertSame(0, Bootstrap::sessionRetentionDays(), 'out of range is the default, never a guessed cutoff');
        } finally {
            putenv($was === false ? 'SUGARCRUSH_SESSION_RETENTION_DAYS' : 'SUGARCRUSH_SESSION_RETENTION_DAYS=' . $was);
        }
    }

    public function testALaunchPrunesByTheSetting(): void
    {
        $was = getenv('SUGARCRUSH_SESSION_RETENTION_DAYS');
        putenv('SUGARCRUSH_SESSION_RETENTION_DAYS');
        try {
            $store = Bootstrap::sessionStore(prune: false);
            $store->createSession('stale', 'p', 'm');
            $store->createSession('fresh', 'p', 'm');
            (new \PDO('sqlite:' . $this->home . '/.sugar-crush/session.db'))
                ->exec("UPDATE sessions SET updated_at = '2020-01-01 00:00:00' WHERE id = 'stale'");

            $this->writeConfig(['sessionRetentionDays' => 7]);
            $pruned = Bootstrap::sessionStore();

            self::assertNull($pruned->getSession('stale'), 'the stale unnamed session went');
            self::assertNotNull($pruned->getSession('fresh'));
        } finally {
            putenv($was === false ? 'SUGARCRUSH_SESSION_RETENTION_DAYS' : 'SUGARCRUSH_SESSION_RETENTION_DAYS=' . $was);
        }
    }

    // ── the spend cap ───────────────────────────────────────────────────

    public function testTheSpendCapIsTheSettingUnlessTheVariableIsSet(): void
    {
        $was = getenv('SUGARCRUSH_MAX_COST');
        putenv('SUGARCRUSH_MAX_COST');
        try {
            self::assertNull(Bootstrap::maxCostUsd(), 'no cap by default');

            $this->writeConfig(['maxCostUsd' => 5]);
            self::assertSame(5.0, Bootstrap::maxCostUsd());
            self::assertSame(5.0, Bootstrap::workspace($this->home)->maxCostUsd, 'every launch starts with it');

            putenv('SUGARCRUSH_MAX_COST=$2.50');
            self::assertSame(2.5, Bootstrap::maxCostUsd(), 'the variable wins');
        } finally {
            putenv($was === false ? 'SUGARCRUSH_MAX_COST' : 'SUGARCRUSH_MAX_COST=' . $was);
        }
    }

    /** @return array<string, array{0: mixed}> */
    public static function unusableCaps(): array
    {
        return ['zero' => [0], 'negative' => [-5], 'a string' => ['5USD'], 'a list' => [[5]]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableCaps')]
    public function testAPersistedCapThatIsNotACeilingRefusesTheLaunch(mixed $cap): void
    {
        $was = getenv('SUGARCRUSH_MAX_COST');
        putenv('SUGARCRUSH_MAX_COST');
        try {
            $this->writeConfig(['maxCostUsd' => $cap]);
            self::assertNotNull(SettingsSchema::byKey('maxCostUsd')?->validate($cap), 'the settings view refuses to save it');

            $this->expectException(\SugarCraft\Crush\Cli\PermissionConfigException::class);
            $this->expectExceptionMessage('maxCostUsd');
            Bootstrap::maxCostUsd();
        } finally {
            putenv($was === false ? 'SUGARCRUSH_MAX_COST' : 'SUGARCRUSH_MAX_COST=' . $was);
        }
    }

    public function testAProjectFileCannotSetTheSpendCap(): void
    {
        $definition = SettingsSchema::byKey('maxCostUsd');
        self::assertNotNull($definition);
        self::assertFalse($definition->projectSettable);
        self::assertNull($definition->default);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function forcePush(int $n): ToolCall
    {
        return new ToolCall(name: 'Bash', arguments: ['command' => "git push --force origin {$n}"]);
    }

    /** @param array<string, mixed> $values */
    private function writeConfig(array $values): void
    {
        file_put_contents($this->home . '/.sugar-crush/config.json', json_encode($values, JSON_THROW_ON_ERROR));
        UiSettings::forget();
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($dir);
    }
}
