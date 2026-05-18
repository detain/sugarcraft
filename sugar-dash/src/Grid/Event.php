<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Events\Event
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Events\Event as CanonicalEvent;

class_alias(CanonicalEvent::class, __NAMESPACE__ . '\Event');
