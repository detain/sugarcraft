<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Events\KeyMap
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Events\KeyMap as CanonicalKeyMap;

class_alias(CanonicalKeyMap::class, __NAMESPACE__ . '\KeyMap');
