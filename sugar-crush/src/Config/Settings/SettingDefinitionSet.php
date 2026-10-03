<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings;

/**
 * One category's settings keys — a file under `Definitions/`, listed on
 * {@see SettingsSchema::DEFINITION_SETS}. Every definition a set returns must be
 * in that set's {@see category()}; the schema refuses one that is not, so a key
 * cannot be filed under one tab and shown under another.
 */
interface SettingDefinitionSet
{
    public static function category(): SettingCategory;

    /** @return list<SettingDefinition> */
    public static function definitions(): array;
}
