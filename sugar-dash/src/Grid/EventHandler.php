<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Events\EventHandler
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Events\EventHandler as CanonicalEventHandler;

class_alias(CanonicalEventHandler::class, __NAMESPACE__ . '\EventHandler');
