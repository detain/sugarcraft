<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Plot\Chart\Progress
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Plot\Chart\Progress as CanonicalProgress;

class_alias(CanonicalProgress::class, __NAMESPACE__ . '\Progress');
