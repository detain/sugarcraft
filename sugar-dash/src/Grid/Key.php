<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Events\Key
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Events\Key as CanonicalKey;

class_alias(CanonicalKey::class, __NAMESPACE__ . '\Key');
