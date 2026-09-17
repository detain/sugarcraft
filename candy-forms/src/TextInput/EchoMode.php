<?php

declare(strict_types=1);

namespace SugarCraft\Forms\TextInput;

/**
 * How a {@see TextInput} renders its value.
 *
 * - {@see Normal}   — show the actual characters.
 * - {@see Password} — replace each character with `$echoChar`.
 * - {@see Mask}     — replace only the characters the `maskPattern` matches
 *                     with `$echoChar`; everything else renders verbatim
 *                     (E736 5.5: `4111 **** **** 1111` credit-card style).
 * - {@see None}     — render an empty string regardless of value.
 */
enum EchoMode: string
{
    case Normal   = 'normal';
    case Password = 'password';
    case Mask     = 'mask';
    case None     = 'none';
}
