<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\State\State
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\State\State as CanonicalState;

class_alias(CanonicalState::class, __NAMESPACE__ . '\State');
