<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Messages;

use SugarCraft\Crush\Usage;

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
     *
     * $usage carries the tool's OWN provider spend (a Task sub-agent's run,
     * audit B4) the same way, to the one loop that sums a turn's spend and
     * checks the spend cap ({@see \SugarCraft\Crush\Backend\EngineBackend}).
     * It is accounting only: {@see toArray()} — the provider wire — omits it.
     */
    public function __construct(
        private string $toolCallId,
        private string $content,
        private bool $isError = false,
        private ?string $imageBytes = null,
        private ?string $imageProtocol = null,
        private ?Usage $usage = null,
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

    /**
     * The same result answering the call $toolCallId - how
     * {@see HistorySanitizer} keeps a result paired with its call when it
     * renames a call id an older transcript used twice (step 0.2).
     */
    public function withToolCallId(string $toolCallId): self
    {
        return new self(
            $toolCallId,
            $this->content,
            $this->isError,
            $this->imageBytes,
            $this->imageProtocol,
            $this->usage,
        );
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

    /** Provider spend the tool itself incurred, or null. */
    public function usage(): ?Usage
    {
        return $this->usage;
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
