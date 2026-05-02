<?php

declare(strict_types=1);

namespace CandyCore\Bits\TextInput;

/**
 * How a {@see TextInput} renders its value.
 *
 * - {@see Normal}   — show the actual characters.
 * - {@see Password} — replace each character with `$echoChar`.
 * - {@see None}     — render an empty string regardless of value.
 */
enum EchoMode: string
{
    case Normal   = 'normal';
    case Password = 'password';
    case None     = 'none';
}
