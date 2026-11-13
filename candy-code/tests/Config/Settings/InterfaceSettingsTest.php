<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config\Settings;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Config\Settings\Definitions\InterfaceSettings;
use SugarCraft\Code\Config\Settings\SettingCategory;
use SugarCraft\Code\Config\Settings\SettingDefinition;
use SugarCraft\Code\Config\Settings\SettingDefinitionSet;
use SugarCraft\Code\Config\Settings\SettingsSchema;
use SugarCraft\Code\Config\Settings\SettingType;

/** The Interface category's rows: structural invariants every row of the set must hold. */
final class InterfaceSettingsTest extends TestCase
{
    /** @return list<SettingDefinition> */
    private static function rows(): array
    {
        return InterfaceSettings::definitions();
    }

    public function testCategoryIsInterface(): void
    {
        self::assertSame(SettingCategory::Interface, InterfaceSettings::category());
        self::assertContains(SettingDefinitionSet::class, class_implements(InterfaceSettings::class));
    }

    public function testItHasRowsAndEveryOneIsADefinitionInItsOwnCategory(): void
    {
        $rows = self::rows();

        self::assertNotSame([], $rows);
        foreach ($rows as $row) {
            self::assertInstanceOf(SettingDefinition::class, $row);
            self::assertSame(SettingCategory::Interface, $row->category, $row->key);
        }
    }

    public function testKeysAreUniqueAndNonEmpty(): void
    {
        $keys = array_map(static fn (SettingDefinition $d): string => $d->key, self::rows());

        self::assertSame($keys, array_values(array_unique($keys)));
        foreach ($keys as $key) {
            self::assertNotSame('', $key);
        }
    }

    public function testEveryRowCarriesALabelAndHelp(): void
    {
        foreach (self::rows() as $row) {
            self::assertNotSame('', $row->label, $row->key . ' label');
            self::assertNotSame('', $row->help, $row->key . ' help');
        }
    }

    public function testEnumRowsListTheirValuesAndDefaultToOne(): void
    {
        $enums = array_filter(self::rows(), static fn (SettingDefinition $d): bool => $d->type === SettingType::Enum);

        self::assertNotSame([], $enums, 'theme is an enum row');
        foreach ($enums as $row) {
            self::assertTrue($row->enumValues !== [] || $row->optionsSource !== null, $row->key . ' names its choices statically or via an options source');
            if ($row->default !== null && $row->enumValues !== []) {
                self::assertContains($row->default, $row->enumValues, $row->key . ' default is a member');
            }
        }
    }

    public function testNumericRangesAreOrdered(): void
    {
        foreach (self::rows() as $row) {
            if ($row->min !== null && $row->max !== null) {
                self::assertLessThanOrEqual($row->max, $row->min, $row->key);
            }
            if (is_int($row->default) || is_float($row->default)) {
                if ($row->min !== null) {
                    self::assertGreaterThanOrEqual($row->min, $row->default, $row->key . ' default under min');
                }
                if ($row->max !== null) {
                    self::assertLessThanOrEqual($row->max, $row->default, $row->key . ' default over max');
                }
            }
        }
    }

    public function testTheThemeRowIsPublishedThroughTheSchema(): void
    {
        $theme = SettingsSchema::byKey('theme');

        self::assertNotNull($theme);
        self::assertSame(SettingCategory::Interface, $theme->category);
        self::assertContains('theme', array_map(static fn (SettingDefinition $d): string => $d->key, self::rows()));
    }

    public function testEveryRowIsReachableFromTheAggregateSchema(): void
    {
        foreach (self::rows() as $row) {
            self::assertNotNull(SettingsSchema::byKey($row->key), $row->key);
        }
    }
}
