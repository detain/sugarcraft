<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\Tree;

enum FlowchartNodeType: string
{
    case Process = 'process';
    case Decision = 'decision';
    case StartEnd = 'startend';
    case InputOutput = 'inputoutput';
    case Connector = 'connector';
    case Data = 'data';
}