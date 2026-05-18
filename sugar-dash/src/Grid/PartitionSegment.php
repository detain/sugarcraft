<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Plot\Chart\PartitionSegment
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Plot\Chart\PartitionSegment as CanonicalPartitionSegment;

class_alias(CanonicalPartitionSegment::class, __NAMESPACE__ . '\PartitionSegment');
