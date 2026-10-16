<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\Tree;

/**
 * Visibility modifier for class members.
 */
enum Visibility: string
{
    case Public = '+';
    case Private = '-';
    case Protected = '#';
    case Package = '~';
}
