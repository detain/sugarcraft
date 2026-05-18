<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Events\PasteEvent
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Events\PasteEvent as CanonicalPasteEvent;

class_alias(CanonicalPasteEvent::class, __NAMESPACE__ . '\PasteEvent');
