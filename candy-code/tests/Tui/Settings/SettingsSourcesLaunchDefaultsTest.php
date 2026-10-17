<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui\Settings;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\Settings\SettingsResolver;
use SugarCraft\Code\Config\Settings\SettingsSchema;
use SugarCraft\Code\Config\Settings\SettingSource;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Tui\Settings\SettingsSources;

/**
 * The settings view explains the TUI launch, so where nothing sets
 * `permissionMode` it shows the TUI's own default (decision D5: `default`),
 * not the schema default the `-p` and daemon paths keep.
 */
final class SettingsSourcesLaunchDefaultsTest extends TestCase
{
    public function testTheViewShowsTheTuiPermissionDefault(): void
    {
        $resolved = SettingsSources::fromLaunch(null, null, null, null, [])->resolver->resolveAll()['permissionMode'];

        self::assertSame(PermissionMode::Default->value, $resolved->value);
        self::assertSame(SettingSource::Default, $resolved->source);
    }

    /** The view's default is the gate's: pinned to the constant the TUI launch uses. */
    public function testTheTuiDefaultIsTheInteractiveGateDefault(): void
    {
        $interactive = (new \ReflectionClassConstant(Bootstrap::class, 'INTERACTIVE_DEFAULT_PERMISSION_MODE'))->getValue();

        self::assertInstanceOf(PermissionMode::class, $interactive);
        self::assertSame($interactive->value, SettingsSources::TUI_DEFAULTS['permissionMode']);
    }

    /** A launch default is only a default: any source that sets the key still wins. */
    public function testASetValueStillOutranksTheLaunchDefault(): void
    {
        $resolved = SettingsSources::fromLaunch(null, null, null, null, ['CANDY_CODE_PERMISSION_MODE' => 'plan'])
            ->resolver->resolveAll()['permissionMode'];

        self::assertSame('plan', $resolved->value);
        self::assertSame(SettingSource::Env, $resolved->source);
    }

    public function testWithoutLaunchDefaultsTheSchemaDefaultStands(): void
    {
        $definition = SettingsSchema::byKey('permissionMode');
        self::assertNotNull($definition);

        self::assertSame($definition->default, SettingsResolver::new()->resolve($definition)->value);
        self::assertSame('x', SettingsResolver::new()->withDefaults(['permissionMode' => 'x'])->resolve($definition)->value);
    }
}
