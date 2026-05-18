<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Events\KeyAction
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Events\KeyAction as CanonicalKeyAction;

class_alias(CanonicalKeyAction::class, __NAMESPACE__ . '\KeyAction');
