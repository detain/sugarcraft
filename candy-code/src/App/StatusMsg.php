<?php

declare(strict_types=1);

namespace SugarCraft\Code\App;

/**
 * Status update message.
 */
final readonly class StatusMsg implements Msg
{
    public function __construct(public string $message) {}
}
