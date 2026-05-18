<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Plot\Chart\SparklineArea
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Plot\Chart\SparklineArea as CanonicalSparklineArea;

class_alias(CanonicalSparklineArea::class, __NAMESPACE__ . '\SparklineArea');
