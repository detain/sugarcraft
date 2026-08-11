<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Messages;

final readonly class ToolResultMessage implements Message
{
    /**
     * $imageBytes/$imageProtocol carry an image-bearing {@see
     * \SugarCraft\Crush\Tools\ToolResult} (e.g. {@see
     * \SugarCraft\Crush\Tools\BuiltIn\Doctor}'s capability swatch) across
     * the {@see \SugarCraft\Crush\Runtime} agentic loop so {@see
     * \SugarCraft\Crush\Backend\EngineBackend::complete()} can thread it
     * onto the final root {@see \SugarCraft\Crush\Message} (W1.G2
     * reachability fix) instead of dropping it here.
     */
    public function __construct(
        private string $toolCallId,
        private string $content,
        private bool $isError = false,
        private ?string $imageBytes = null,
        private ?string $imageProtocol = null,
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

    public function imageBytes(): ?string
    {
        return $this->imageBytes;
    }

    public function imageProtocol(): ?string
    {
        return $this->imageProtocol;
    }

    public function hasImage(): bool
    {
        return $this->imageBytes !== null;
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
