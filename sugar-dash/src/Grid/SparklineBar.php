<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Plot\Chart\SparklineBar
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Plot\Chart\SparklineBar as CanonicalSparklineBar;

class_alias(CanonicalSparklineBar::class, __NAMESPACE__ . '\SparklineBar');
