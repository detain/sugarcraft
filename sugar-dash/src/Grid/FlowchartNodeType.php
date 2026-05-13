<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

enum FlowchartNodeType: string
{
    case Process = 'process';
    case Decision = 'decision';
    case StartEnd = 'startend';
    case InputOutput = 'inputoutput';
    case Connector = 'connector';
    case Data = 'data';
}
