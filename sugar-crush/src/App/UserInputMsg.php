<?php

declare(strict_types=1);

namespace SugarCraft\Crush\App;

/**
 * Message from user input.
 */
final readonly class UserInputMsg implements Msg
{
    public function __construct(public string $content) {}
}
