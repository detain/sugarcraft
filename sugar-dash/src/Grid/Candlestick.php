<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Plot\Chart\Candlestick
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Plot\Chart\Candlestick as CanonicalCandlestick;

class_alias(CanonicalCandlestick::class, __NAMESPACE__ . '\Candlestick');
