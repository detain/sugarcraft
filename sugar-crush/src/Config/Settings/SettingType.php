<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings;

/**
 * The value shape a {@see SettingDefinition} carries, as the settings editor and
 * the generated key table need to know it.
 *
 * DESCRIPTIVE, NOT A PARSER. Every key keeps the reader it already has (the
 * `readerSymbol` on its definition), and those readers stay the authority on
 * what a value means: `maxOutputTokens` truncating `2047.9` to 2047 is
 * {@see \SugarCraft\Crush\Backend\EngineBackend}'s rule, not this enum's. What
 * this answers is which form field edits the key and how the doc table spells
 * its type — a question no reader could answer, because each reads only its own.
 */
enum SettingType: string
{
    case Bool = 'bool';
    case Int = 'int';
    case Float = 'float';
    case Enum = 'enum';
    case String = 'string';
    case Secret = 'secret';
    case Path = 'path';
    case Url = 'url';
    case StringList = 'list';
    case Map = 'map';
    case Json = 'json';

    /** How the generated `docs/SETTINGS.md` table names the type. */
    public function label(): string
    {
        return match ($this) {
            self::Bool => 'bool',
            self::Int => 'int',
            self::Float => 'number',
            self::Enum => 'enum',
            self::String => 'string',
            self::Secret => 'secret',
            self::Path => 'path',
            self::Url => 'URL',
            self::StringList => 'list',
            self::Map => 'object',
            self::Json => 'JSON',
        };
    }
}
