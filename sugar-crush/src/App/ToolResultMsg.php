<?php

declare(strict_types=1);

namespace SugarCraft\Crush\App;

/**
 * Message containing tool execution result.
 */
final readonly class ToolResultMsg implements Msg
{
    public function __construct(
        public string $toolCallId,
        public string $content,
        public bool $isError = false,
    ) {}
}
