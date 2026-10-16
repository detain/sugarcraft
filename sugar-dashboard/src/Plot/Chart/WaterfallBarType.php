<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Plot\Chart;

enum WaterfallBarType: string
{
    case Positive = 'positive';
    case Negative = 'negative';
    case Total = 'total';
    case Subtotal = 'subtotal';
}