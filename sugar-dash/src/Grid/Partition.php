<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Plot\Chart\Partition
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Plot\Chart\Partition as CanonicalPartition;

class_alias(CanonicalPartition::class, __NAMESPACE__ . '\Partition');
