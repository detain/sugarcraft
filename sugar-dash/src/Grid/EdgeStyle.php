<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Foundation\EdgeStyle
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Foundation\EdgeStyle as CanonicalEdgeStyle;

class_alias(CanonicalEdgeStyle::class, __NAMESPACE__ . '\EdgeStyle');
