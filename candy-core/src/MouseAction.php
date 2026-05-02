<?php

declare(strict_types=1);

namespace CandyCore\Core;

enum MouseAction: string
{
    case Press   = 'press';
    case Release = 'release';
    case Motion  = 'motion';
}
