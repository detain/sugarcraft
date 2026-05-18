<?php

declare(strict_types=1);

// Re-export for backward compatibility — canonical class moved to Plot\Chart\WaterfallBarType.
namespace SugarCraft\Dash\Grid;

class_alias(\SugarCraft\Dash\Plot\Chart\WaterfallBarType::class, __NAMESPACE__ . '\WaterfallBarType');
