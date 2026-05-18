<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Plot\Chart\SparkArea
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Plot\Chart\SparkArea as CanonicalSparkArea;

class_alias(CanonicalSparkArea::class, __NAMESPACE__ . '\SparkArea');
