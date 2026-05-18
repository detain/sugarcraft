<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Registry;

/**
 * Type alias for module constructors.
 *
 * A Constructor is a callable that takes a configuration array
 * and returns a Module instance.
 *
 * @see Registry::register()
 *
 * @note PHP 8.3: use callable. This interface serves as documentation.
 */
interface Constructor
{
}
