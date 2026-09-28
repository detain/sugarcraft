<?php

declare(strict_types=1);

namespace SugarCraft\Crush\App;

use SugarCraft\Crush\Messages\Message;

/**
 * Command to run completion.
 */
final readonly class RunCompletionCmd implements Cmd
{
    public function __construct(public Message $userMessage) {}
}
