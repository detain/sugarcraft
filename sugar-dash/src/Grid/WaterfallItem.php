<?php

declare(strict_types=1);

// Re-export for backward compatibility — canonical class moved to Plot\Chart\WaterfallItem.
namespace SugarCraft\Dash\Grid;

class_alias(\SugarCraft\Dash\Plot\Chart\WaterfallItem::class, __NAMESPACE__ . '\WaterfallItem');
