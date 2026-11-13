<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config\Settings\Definitions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Config\Settings\Definitions\ToolSettings;
use SugarCraft\Code\Config\Settings\SettingCategory;
use SugarCraft\Code\Config\Settings\SettingDefinition;
use SugarCraft\Code\Config\Settings\SettingDefinitionSet;
use SugarCraft\Code\Config\Settings\SettingsSchema;
use SugarCraft\Code\Config\Settings\SettingType;

/**
 * Pins the tool keys that ToolSettings contributes to the schema: the exact
 * key roster (order is the settings-UI order), each row's type, risk class and
 * tiering, and that every row is registered in {@see SettingsSchema}.
 */
final class ToolSettingsTest extends TestCase
{
    /**
     * key => [type, risk class, project-settable, layered]
     *
     * @var array<string, array{string, string, bool, bool}>
     */
    private const ROWS = [
            'allowedTools' => ['StringList', 'Security', false, true],
            'disabledTools' => ['StringList', 'Narrowing', true, true],
            'references' => ['Json', 'Security', false, true],
            'bashSandbox' => ['Enum', 'Security', false, true],
            'bashSandboxNestedUserns' => ['Bool', 'Security', false, true],
            'sandboxScope' => ['StringList', 'Security', false, true],
            'sandboxAllowedDomains' => ['StringList', 'Security', false, true],
            'testCommand' => ['String', 'Exec', false, true],
            'autoTest' => ['Bool', 'Exec', false, true],
            'toolOutputCapBytes' => ['Int', 'Spend', false, true],
            'mcpResultCapBytes' => ['Int', 'Spend', false, true],
            'readMaxBytes' => ['Int', 'Spend', false, true],
            'readPageLines' => ['Int', 'Spend', false, true],
            'readPageBytes' => ['Int', 'Spend', false, true],
            'toolSpillWindowPercent' => ['Int', 'Spend', false, true],
            'toolInstructionCapBytes' => ['Int', 'Spend', false, true],
            'toolSpillCaptureBytes' => ['Int', 'Tuning', true, true],
            'toolSpillMinCapBytes' => ['Int', 'Tuning', true, true],
            'globMaxMatches' => ['Int', 'Tuning', true, true],
            'webFetchMaxBytes' => ['Int', 'Tuning', true, true],
            'webFetchTimeoutSeconds' => ['Int', 'Tuning', true, true],
            'webSearchMaxResults' => ['Int', 'Tuning', true, true],
            'webSearchTimeoutSeconds' => ['Int', 'Tuning', true, true],
            'webSearchEndpoint' => ['Url', 'Egress', false, true],
            'webSearchBackend' => ['Enum', 'Egress', false, true],
            'bashInteractiveIdleSeconds' => ['Float', 'Tuning', true, true],
            'bashTimeoutSeconds' => ['Int', 'Tuning', true, true],
            'bashMaxTimeoutSeconds' => ['Int', 'Tuning', true, true],
            'chatToolTimeoutSeconds' => ['Int', 'Tuning', true, true],
            'grepTimeoutSeconds' => ['Int', 'Tuning', true, true],
            'requireReadBeforeWrite' => ['Bool', 'Narrowing', false, true],
    ];

    public function testItIsASettingDefinitionSetForItsCategory(): void
    {
        self::assertInstanceOf(SettingDefinitionSet::class, new class() implements SettingDefinitionSet {
            public static function category(): SettingCategory
            {
                return ToolSettings::category();
            }

            public static function definitions(): array
            {
                return ToolSettings::definitions();
            }
        });
        self::assertTrue(is_subclass_of(ToolSettings::class, SettingDefinitionSet::class));
        self::assertSame(SettingCategory::Tools, ToolSettings::category());
    }

    public function testItOwnsExactlyTheDocumentedKeysInOrder(): void
    {
        $keys = array_map(static fn (SettingDefinition $d): string => $d->key, ToolSettings::definitions());

        self::assertSame(array_keys(self::ROWS), $keys);
        self::assertSame($keys, array_values(array_unique($keys)), 'no duplicate keys');
    }

    public function testEveryRowKeepsItsTypeRiskAndTiering(): void
    {
        foreach (ToolSettings::definitions() as $d) {
            self::assertSame(
                self::ROWS[$d->key],
                [$d->type->name, $d->riskClass->name, $d->projectSettable, $d->layered],
                $d->key,
            );
        }
    }

    public function testEveryRowIsFiledUnderTheSetsCategoryWithResolvedCopy(): void
    {
        foreach (ToolSettings::definitions() as $d) {
            self::assertSame(ToolSettings::category(), $d->category, $d->key);
            self::assertNotSame('', trim($d->label), $d->key . ' label');
            self::assertNotSame('', trim($d->help), $d->key . ' help');
            self::assertStringStartsNotWith('settings.', $d->label, $d->key . ' label is an unresolved lang key');
        }
    }

    public function testEnumRowsDeclareTheirValuesAndDefaultsAreMembers(): void
    {
        $enums = [];
        foreach (ToolSettings::definitions() as $d) {
            if ($d->type !== SettingType::Enum) {
                continue;
            }
            $enums[] = $d->key;
            self::assertNotSame([], $d->enumValues, $d->key . ' is an Enum with no values');
            if ($d->default !== null) {
                self::assertContains($d->default, $d->enumValues, $d->key . ' default');
            }
        }
        self::assertSame(['bashSandbox', 'webSearchBackend'], $enums);
    }

    public function testEveryRowIsRegisteredInTheSchema(): void
    {
        foreach (ToolSettings::definitions() as $d) {
            $registered = SettingsSchema::byKey($d->key);
            self::assertNotNull($registered, $d->key . ' is not in SettingsSchema');
            self::assertSame($d->type, $registered->type, $d->key);
            self::assertSame(ToolSettings::category(), $registered->category, $d->key);
        }
    }
}
