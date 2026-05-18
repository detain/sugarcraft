<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Plot\Chart\OHLCPoint
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Plot\Chart\OHLCPoint as CanonicalOHLCPoint;

class_alias(CanonicalOHLCPoint::class, __NAMESPACE__ . '\OHLCPoint');
