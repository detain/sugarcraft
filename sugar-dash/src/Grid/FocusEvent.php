<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Events\FocusEvent
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Events\FocusEvent as CanonicalFocusEvent;

class_alias(CanonicalFocusEvent::class, __NAMESPACE__ . '\FocusEvent');
