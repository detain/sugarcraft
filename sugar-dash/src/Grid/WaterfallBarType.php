<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

enum WaterfallBarType: string
{
    case Positive = 'positive';
    case Negative = 'negative';
    case Total = 'total';
    case Subtotal = 'subtotal';
}
