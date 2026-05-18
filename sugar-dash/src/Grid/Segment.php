<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Foundation\Segment
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Foundation\Segment as CanonicalSegment;

class_alias(CanonicalSegment::class, __NAMESPACE__ . '\Segment');
