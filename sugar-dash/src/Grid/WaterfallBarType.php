<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Plot\Chart\WaterfallBarType
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Plot\Chart\WaterfallBarType as CanonicalWaterfallBarType;

class_alias(CanonicalWaterfallBarType::class, __NAMESPACE__ . '\WaterfallBarType');
