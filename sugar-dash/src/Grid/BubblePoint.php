<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Plot\Chart\BubblePoint
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Plot\Chart\BubblePoint as CanonicalBubblePoint;

class_alias(CanonicalBubblePoint::class, __NAMESPACE__ . '\BubblePoint');
