<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Messages;

final readonly class ToolResultMessage implements Message
{
    public function __construct(
        private string $toolCallId,
        private string $content,
        private bool $isError = false,
    ) {}

    public function role(): string
    {
        return 'tool';
    }

    public function content(): string
    {
        return $this->content;
    }

    public function toolCallId(): string
    {
        return $this->toolCallId;
    }

    public function isError(): bool
    {
        return $this->isError;
    }

    public function toArray(): array
    {
        return [
            'role' => 'tool',
            'tool_call_id' => $this->toolCallId,
            'content' => $this->content,
            'is_error' => $this->isError,
        ];
    }
}
