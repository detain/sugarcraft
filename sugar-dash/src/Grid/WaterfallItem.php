<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Plot\Chart\WaterfallItem
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Plot\Chart\WaterfallItem as CanonicalWaterfallItem;

class_alias(CanonicalWaterfallItem::class, __NAMESPACE__ . '\WaterfallItem');
