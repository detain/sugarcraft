<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Events\Focus
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Events\Focus as CanonicalFocus;

class_alias(CanonicalFocus::class, __NAMESPACE__ . '\Focus');
