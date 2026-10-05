<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config\Settings;

use PHPUnit\Framework\TestCase;
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
