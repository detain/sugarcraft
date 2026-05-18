<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Events\ResizeEvent
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Events\ResizeEvent as CanonicalResizeEvent;

class_alias(CanonicalResizeEvent::class, __NAMESPACE__ . '\ResizeEvent');
