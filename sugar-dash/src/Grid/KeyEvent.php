<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Events\KeyEvent
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Events\KeyEvent as CanonicalKeyEvent;

class_alias(CanonicalKeyEvent::class, __NAMESPACE__ . '\KeyEvent');
