<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Events\MouseEvent
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Events\MouseEvent as CanonicalMouseEvent;

class_alias(CanonicalMouseEvent::class, __NAMESPACE__ . '\MouseEvent');
