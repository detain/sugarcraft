<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools;

final readonly class ToolResult
{
    public function __construct(
        private string $toolCallId,
        private string $content,
        private bool $isError = false,
        private ?int $durationMs = null,
    ) {}

    public function toolCallId(): string
    {
        return $this->toolCallId;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function isError(): bool
    {
        return $this->isError;
    }

    public function durationMs(): ?int
    {
        return $this->durationMs;
    }

    public function toArray(): array
    {
        return [
            'tool_call_id' => $this->toolCallId,
            'content' => $this->content,
            'is_error' => $this->isError,
            'duration_ms' => $this->durationMs,
        ];
    }
}
