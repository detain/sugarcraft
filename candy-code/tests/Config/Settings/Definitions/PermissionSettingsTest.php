<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config\Settings\Definitions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Config\Settings\Definitions\PermissionSettings;
use SugarCraft\Code\Config\Settings\SettingCategory;
use SugarCraft\Code\Config\Settings\SettingDefinition;
use SugarCraft\Code\Config\Settings\SettingDefinitionSet;
use SugarCraft\Code\Config\Settings\SettingsSchema;
use SugarCraft\Code\Config\Settings\SettingType;

/**
 * Pins the permission-engine keys that PermissionSettings contributes to the schema: the exact
 * key roster (order is the settings-UI order), each row's type, risk class and
 * tiering, and that every row is registered in {@see SettingsSchema}.
 */
final class PermissionSettingsTest extends TestCase
{
    /**
     * key => [type, risk class, project-settable, layered]
     *
     * @var array<string, array{string, string, bool, bool}>
     */
    private const ROWS = [
            'permissionMode' => ['Enum', 'Security', false, false],
            'permissionRules' => ['Json', 'Security', false, false],
            'autoReview' => ['Bool', 'Spend', false, true],
            'autoReviewScope' => ['Enum', 'Spend', false, true],
            'autoReviewModel' => ['String', 'Spend', false, true],
            'permissions.autoStrikeLimit' => ['Int', 'Security', false, true],
            'permissions.autoTotalLimit' => ['Int', 'Security', false, true],
            'permissions.autoAllowReadOnly' => ['Bool', 'Narrowing', true, true],
            'redactToolOutput' => ['Bool', 'Security', false, true],
            'secretEnvAllowlist' => ['StringList', 'Security', false, true],
            'trustedProjectHooks' => ['StringList', 'Security', false, false],
            'trustedProjectMcp' => ['StringList', 'Security', false, false],
            'trustedProjectCommands' => ['StringList', 'Security', false, false],
            'trustedProjectSettings' => ['StringList', 'Security', false, false],
            'trustedBundleCommands' => ['StringList', 'Security', false, false],
            'trustedBundleChannels' => ['StringList', 'Security', false, false],
            'mcpPromptShell' => ['StringList', 'Security', false, false],
    ];

    public function testItIsASettingDefinitionSetForItsCategory(): void
    {
        self::assertInstanceOf(SettingDefinitionSet::class, new class() implements SettingDefinitionSet {
            public static function category(): SettingCategory
            {
                return PermissionSettings::category();
            }

            public static function definitions(): array
            {
                return PermissionSettings::definitions();
            }
        });
        self::assertTrue(is_subclass_of(PermissionSettings::class, SettingDefinitionSet::class));
        self::assertSame(SettingCategory::Permissions, PermissionSettings::category());
    }

    public function testItOwnsExactlyTheDocumentedKeysInOrder(): void
    {
        $keys = array_map(static fn (SettingDefinition $d): string => $d->key, PermissionSettings::definitions());

        self::assertSame(array_keys(self::ROWS), $keys);
        self::assertSame($keys, array_values(array_unique($keys)), 'no duplicate keys');
    }

    public function testEveryRowKeepsItsTypeRiskAndTiering(): void
    {
        foreach (PermissionSettings::definitions() as $d) {
            self::assertSame(
                self::ROWS[$d->key],
                [$d->type->name, $d->riskClass->name, $d->projectSettable, $d->layered],
                $d->key,
            );
        }
    }

    public function testEveryRowIsFiledUnderTheSetsCategoryWithResolvedCopy(): void
    {
        foreach (PermissionSettings::definitions() as $d) {
            self::assertSame(PermissionSettings::category(), $d->category, $d->key);
            self::assertNotSame('', trim($d->label), $d->key . ' label');
            self::assertNotSame('', trim($d->help), $d->key . ' help');
            self::assertStringStartsNotWith('settings.', $d->label, $d->key . ' label is an unresolved lang key');
        }
    }

    public function testEnumRowsDeclareTheirValuesAndDefaultsAreMembers(): void
    {
        $enums = [];
        foreach (PermissionSettings::definitions() as $d) {
            if ($d->type !== SettingType::Enum) {
                continue;
            }
            $enums[] = $d->key;
            self::assertNotSame([], $d->enumValues, $d->key . ' is an Enum with no values');
            if ($d->default !== null) {
                self::assertContains($d->default, $d->enumValues, $d->key . ' default');
            }
        }
        self::assertSame(['permissionMode', 'autoReviewScope'], $enums);
    }

    public function testEveryRowIsRegisteredInTheSchema(): void
    {
        foreach (PermissionSettings::definitions() as $d) {
            $registered = SettingsSchema::byKey($d->key);
            self::assertNotNull($registered, $d->key . ' is not in SettingsSchema');
            self::assertSame($d->type, $registered->type, $d->key);
            self::assertSame(PermissionSettings::category(), $registered->category, $d->key);
        }
    }
}
