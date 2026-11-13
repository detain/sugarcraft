<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config\Settings;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\Settings\SessionSettings;
use SugarCraft\Code\Config\Settings\SettingSource;
use SugarCraft\Code\Config\Settings\SettingsResolver;
use SugarCraft\Code\Config\Settings\SettingsSchema;
use SugarCraft\Code\Tui\Settings\SettingsDetailPanel;

/**
 * S-O10: the explainer's SettingSource ladder must equal the launch's real
 * layering (`Bootstrap::mergedConfig()`: files < session < managed, then
 * env/flag in the readers), so the settings view never names a loser as the
 * winner for a managed key.
 */
final class SettingSourceOrderTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/source_order_' . uniqid('', true);
        mkdir($this->tmp, 0o700, true);
    }

    protected function tearDown(): void
    {
        Bootstrap::useSystemConfigPath(null);
        SessionSettings::reset();
        array_map('unlink', glob($this->tmp . '/*') ?: []);
        @rmdir($this->tmp);
        parent::tearDown();
    }

    public function testTheDocumentedLadderIsTheCaseOrder(): void
    {
        self::assertSame(
            ['default', 'project', 'project-local', 'user-settings', 'user-config', 'session', 'managed', 'env', 'flag'],
            array_map(static fn (SettingSource $s): string => $s->value, SettingSource::cases()),
        );
        self::assertGreaterThan(SettingSource::Session->precedence(), SettingSource::Managed->precedence());
        self::assertLessThan(SettingSource::Env->precedence(), SettingSource::Managed->precedence());
    }

    public function testResolverWinnerEqualsTheLaunchMergeWhenManagedAndSessionBothSetAKey(): void
    {
        $path = $this->tmp . '/managed.json';
        file_put_contents($path, (string) json_encode(['theme' => 'managed-pin']));
        Bootstrap::useSystemConfigPath($path);
        SessionSettings::apply(['theme' => 'session-pick']);

        $resolved = SettingsResolver::fromFiles(null, false, null, null, Bootstrap::systemConfigPath())
            ->withLayer(SettingSource::Session, SessionSettings::all())
            ->resolve(SettingsSchema::byKey('theme'));

        self::assertSame(Bootstrap::readUserConfig()['theme'] ?? null, $resolved->value);
        self::assertSame('managed-pin', $resolved->value);
        self::assertSame(SettingSource::Managed, $resolved->source);
        self::assertSame($path, $resolved->sourcePath);
        self::assertContains(SettingSource::Session, $resolved->shadowed);
    }

    public function testEnvironmentStillOutranksManaged(): void
    {
        $path = $this->tmp . '/managed.json';
        file_put_contents($path, (string) json_encode(['models' => 'from-managed']));
        $definition = SettingsSchema::byKey('models');
        self::assertNotNull($definition);

        $resolved = SettingsResolver::fromFiles(null, false, null, null, $path)
            ->withEnvironment([(string) $definition->envVar => 'from-env'])
            ->resolve($definition);

        self::assertSame(SettingSource::Env, $resolved->source);
        self::assertContains(SettingSource::Managed, $resolved->shadowed);
    }

    public function testManagedIsOnlyOfferedToLayeredKeysAndIsLabelled(): void
    {
        foreach (SettingsSchema::all() as $definition) {
            self::assertSame(
                $definition->layered,
                \in_array(SettingSource::Managed, $definition->sources(), true),
                $definition->key,
            );
        }

        self::assertSame('set by managed file', SettingsDetailPanel::sourceLabel(SettingSource::Managed));
    }
}
