<?php

declare(strict_types=1);

namespace SugarCraft\Top\Config;

/**
 * Storage class of a config option.
 *
 * Mirrors aristocratos/btop Config::bools / Config::ints / Config::strings —
 * btop keeps one map per type and the map a key lives in decides how its
 * value is tokenised on load and quoted on write.
 */
enum OptionType: string
{
    case Bool = 'bool';
    case Int = 'int';
    case String = 'string';
}
