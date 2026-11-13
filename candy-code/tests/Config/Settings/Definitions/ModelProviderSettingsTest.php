<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config\Settings\Definitions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Config\Settings\Definitions\ModelProviderSettings;
use SugarCraft\Code\Config\Settings\SettingCategory;
use SugarCraft\Code\Config\Settings\SettingDefinition;
use SugarCraft\Code\Config\Settings\SettingDefinitionSet;
use SugarCraft\Code\Config\Settings\SettingsSchema;
use SugarCraft\Code\Config\Settings\SettingType;

/**
 * Pins the model and provider keys that ModelProviderSettings contributes to the schema: the exact
 * key roster (order is the settings-UI order), each row's type, risk class and
 * tiering, and that every row is registered in {@see SettingsSchema}.
 */
final class ModelProviderSettingsTest extends TestCase
{
    /**
     * key => [type, risk class, project-settable, layered]
     *
     * @var array<string, array{string, string, bool, bool}>
     */
    private const ROWS = [
            'provider' => ['String', 'Egress', false, true],
            'models' => ['Map', 'Spend', false, true],
            'providers' => ['Map', 'Egress', false, true],
            'titleModel' => ['String', 'Spend', false, true],
            'summaryModel' => ['String', 'Spend', false, true],
            'advisorModel' => ['String', 'Spend', false, true],
            'maxOutputTokens' => ['Int', 'Spend', false, true],
            'modelPrices' => ['Map', 'Spend', false, true],
            'extraBody' => ['Map', 'Egress', false, true],
            'thinkingBudget' => ['Int', 'Spend', false, true],
            'reasoningEffort' => ['String', 'Spend', false, true],
            'serviceTier' => ['String', 'Spend', false, true],
            'promptCache' => ['Bool', 'Spend', false, true],
    ];

    public function testItIsASettingDefinitionSetForItsCategory(): void
    {
        self::assertInstanceOf(SettingDefinitionSet::class, new class() implements SettingDefinitionSet {
            public static function category(): SettingCategory
            {
                return ModelProviderSettings::category();
            }

            public static function definitions(): array
            {
                return ModelProviderSettings::definitions();
            }
        });
        self::assertTrue(is_subclass_of(ModelProviderSettings::class, SettingDefinitionSet::class));
        self::assertSame(SettingCategory::ModelProvider, ModelProviderSettings::category());
    }

    public function testItOwnsExactlyTheDocumentedKeysInOrder(): void
    {
        $keys = array_map(static fn (SettingDefinition $d): string => $d->key, ModelProviderSettings::definitions());

        self::assertSame(array_keys(self::ROWS), $keys);
        self::assertSame($keys, array_values(array_unique($keys)), 'no duplicate keys');
    }

    public function testEveryRowKeepsItsTypeRiskAndTiering(): void
    {
        foreach (ModelProviderSettings::definitions() as $d) {
            self::assertSame(
                self::ROWS[$d->key],
                [$d->type->name, $d->riskClass->name, $d->projectSettable, $d->layered],
                $d->key,
            );
        }
    }

    public function testEveryRowIsFiledUnderTheSetsCategoryWithResolvedCopy(): void
    {
        foreach (ModelProviderSettings::definitions() as $d) {
            self::assertSame(ModelProviderSettings::category(), $d->category, $d->key);
            self::assertNotSame('', trim($d->label), $d->key . ' label');
            self::assertNotSame('', trim($d->help), $d->key . ' help');
            self::assertStringStartsNotWith('settings.', $d->label, $d->key . ' label is an unresolved lang key');
        }
    }

    public function testEnumRowsDeclareTheirValuesAndDefaultsAreMembers(): void
    {
        $enums = [];
        foreach (ModelProviderSettings::definitions() as $d) {
            if ($d->type !== SettingType::Enum) {
                continue;
            }
            $enums[] = $d->key;
            self::assertNotSame([], $d->enumValues, $d->key . ' is an Enum with no values');
            if ($d->default !== null) {
                self::assertContains($d->default, $d->enumValues, $d->key . ' default');
            }
        }
        self::assertSame([], $enums);
    }

    public function testEveryRowIsRegisteredInTheSchema(): void
    {
        foreach (ModelProviderSettings::definitions() as $d) {
            $registered = SettingsSchema::byKey($d->key);
            self::assertNotNull($registered, $d->key . ' is not in SettingsSchema');
            self::assertSame($d->type, $registered->type, $d->key);
            self::assertSame(ModelProviderSettings::category(), $registered->category, $d->key);
        }
    }
}
