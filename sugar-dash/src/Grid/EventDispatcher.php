<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Events\EventDispatcher
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Events\EventDispatcher as CanonicalEventDispatcher;

class_alias(CanonicalEventDispatcher::class, __NAMESPACE__ . '\EventDispatcher');
