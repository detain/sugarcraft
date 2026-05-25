<?php

declare(strict_types=1);

namespace SugarCraft\Forms\TextInput;

/**
 * When validation fires on a TextInput.
 *
 * - {@see None}    — validation runs on every edit (immediate).
 * - {@see Blur}    — validate when the input loses focus.
 * - {@see Change}  — validate on every keystroke.
 * - {@see Submit}  — validate only on Enter keypress.
 */
enum ValidateOn: string
{
    case None    = 'none';
    case Blur    = 'blur';
    case Change  = 'change';
    case Submit  = 'submit';
}
