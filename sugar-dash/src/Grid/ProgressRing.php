<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Plot\Chart\ProgressRing
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Plot\Chart\ProgressRing as CanonicalProgressRing;

class_alias(CanonicalProgressRing::class, __NAMESPACE__ . '\ProgressRing');
