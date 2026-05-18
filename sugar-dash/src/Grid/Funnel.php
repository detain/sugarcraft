<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Plot\Chart\Funnel
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Plot\Chart\Funnel as CanonicalFunnel;

class_alias(CanonicalFunnel::class, __NAMESPACE__ . '\Funnel');
