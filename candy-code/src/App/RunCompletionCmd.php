<?php

declare(strict_types=1);

namespace SugarCraft\Code\App;

use SugarCraft\Code\Messages\Message;

/**
 * Command to run completion.
 */
final readonly class RunCompletionCmd implements Cmd
{
    public function __construct(public Message $userMessage) {}
}
