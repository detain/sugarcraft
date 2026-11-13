<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config\Settings\Definitions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Config\Settings\Definitions\MemoryRuleSettings;
use SugarCraft\Code\Config\Settings\SettingCategory;
use SugarCraft\Code\Config\Settings\SettingDefinition;
use SugarCraft\Code\Config\Settings\SettingDefinitionSet;
use SugarCraft\Code\Config\Settings\SettingsSchema;
use SugarCraft\Code\Config\Settings\SettingType;

/**
 * Pins the memory and rule-file keys that MemoryRuleSettings contributes to the schema: the exact
 * key roster (order is the settings-UI order), each row's type, risk class and
 * tiering, and that every row is registered in {@see SettingsSchema}.
 */
final class MemoryRuleSettingsTest extends TestCase
{
    /**
     * key => [type, risk class, project-settable, layered]
     *
     * @var array<string, array{string, string, bool, bool}>
     */
    private const ROWS = [
            'rules.standingMaxBytes' => ['Int', 'Prompt', false, false],
            'skills.pathNudges' => ['Bool', 'Narrowing', true, true],
            'memory.projectNoteMaxBytes' => ['Int', 'Tuning', false, false],
            'memory.promptMaxEntries' => ['Int', 'Prompt', false, false],
            'memory.promptMaxBytes' => ['Int', 'Prompt', false, false],
            'memory.entryMaxBytes' => ['Int', 'Prompt', false, false],
            'memory.userMaxEntries' => ['Int', 'Prompt', false, false],
            'memory.userMaxBytes' => ['Int', 'Prompt', false, false],
            'memory.autoConsolidate' => ['Bool', 'Spend', false, false],
            'memory.dreamIntervalSeconds' => ['Int', 'Spend', false, false],
            'memory.dreamProposeSkills' => ['Bool', 'Prompt', false, false],
            'instructions' => ['StringList', 'Prompt', false, true],
            'instructions.walkToRoot' => ['Bool', 'Prompt', false, true],
            'instructionsExclude' => ['StringList', 'Prompt', false, true],
            'disabledRules' => ['StringList', 'Prompt', false, true],
            'embeddingModel' => ['String', 'Spend', false, true],
    ];

    public function testItIsASettingDefinitionSetForItsCategory(): void
    {
        self::assertInstanceOf(SettingDefinitionSet::class, new class() implements SettingDefinitionSet {
            public static function category(): SettingCategory
            {
                return MemoryRuleSettings::category();
            }

            public static function definitions(): array
            {
                return MemoryRuleSettings::definitions();
            }
        });
        self::assertTrue(is_subclass_of(MemoryRuleSettings::class, SettingDefinitionSet::class));
        self::assertSame(SettingCategory::MemoryRules, MemoryRuleSettings::category());
    }

    public function testItOwnsExactlyTheDocumentedKeysInOrder(): void
    {
        $keys = array_map(static fn (SettingDefinition $d): string => $d->key, MemoryRuleSettings::definitions());

        self::assertSame(array_keys(self::ROWS), $keys);
        self::assertSame($keys, array_values(array_unique($keys)), 'no duplicate keys');
    }

    public function testEveryRowKeepsItsTypeRiskAndTiering(): void
    {
        foreach (MemoryRuleSettings::definitions() as $d) {
            self::assertSame(
                self::ROWS[$d->key],
                [$d->type->name, $d->riskClass->name, $d->projectSettable, $d->layered],
                $d->key,
            );
        }
    }

    public function testEveryRowIsFiledUnderTheSetsCategoryWithResolvedCopy(): void
    {
        foreach (MemoryRuleSettings::definitions() as $d) {
            self::assertSame(MemoryRuleSettings::category(), $d->category, $d->key);
            self::assertNotSame('', trim($d->label), $d->key . ' label');
            self::assertNotSame('', trim($d->help), $d->key . ' help');
            self::assertStringStartsNotWith('settings.', $d->label, $d->key . ' label is an unresolved lang key');
        }
    }

    public function testEnumRowsDeclareTheirValuesAndDefaultsAreMembers(): void
    {
        $enums = [];
        foreach (MemoryRuleSettings::definitions() as $d) {
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
        foreach (MemoryRuleSettings::definitions() as $d) {
            $registered = SettingsSchema::byKey($d->key);
            self::assertNotNull($registered, $d->key . ' is not in SettingsSchema');
            self::assertSame($d->type, $registered->type, $d->key);
            self::assertSame(MemoryRuleSettings::category(), $registered->category, $d->key);
        }
    }
}
