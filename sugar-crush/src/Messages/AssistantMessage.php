<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Messages;

final readonly class AssistantMessage implements Message
{
    public function __construct(
        private string $content,
        private ?array $toolCalls = null,
        private ?string $reasoning = null,
    ) {}

    public function role(): string
    {
        return 'assistant';
    }

    public function content(): string
    {
        return $this->content;
    }

    public function toolCalls(): ?array
    {
        return $this->toolCalls;
    }

    public function reasoning(): ?string
    {
        return $this->reasoning;
    }

    public function toArray(): array
    {
        return [
            'role' => 'assistant',
            'content' => $this->content,
            'tool_calls' => $this->toolCalls,
            'reasoning' => $this->reasoning,
        ];
    }
}
