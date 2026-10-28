<?php

declare(strict_types=1);

namespace SugarCraft\Code\Messages;

use SugarCraft\Code\Usage;

final readonly class ToolResultMessage implements Message
{
    /**
     * $imageBytes/$imageProtocol carry an image-bearing {@see
     * \SugarCraft\Code\Tools\ToolResult} (e.g. {@see
     * \SugarCraft\Code\Tools\BuiltIn\Doctor}'s capability swatch) across
     * the {@see \SugarCraft\Code\Runtime} agentic loop so {@see
     * \SugarCraft\Code\Backend\EngineBackend::complete()} can thread it
     * onto the final root {@see \SugarCraft\Code\Message} (W1.G2
     * reachability fix) instead of dropping it here.
     *
     * $usage carries the tool's OWN provider spend (a Task sub-agent's run,
     * audit B4) the same way, to the one loop that sums a turn's spend and
     * checks the spend cap ({@see \SugarCraft\Code\Backend\EngineBackend}).
     * It is accounting only: {@see toArray()} — the provider wire — omits it.
     *
     * $documentBytes/$documentName carry an in-budget PDF {@see
     * \SugarCraft\Code\Tools\BuiltIn\Read} returned when no host text
     * extractor answered it (C-TOOL-2, GAP-TL-20). Same in-turn doctrine as
     * the image pair: the wire leg reads them through typed accessors while
     * the bytes live in the child-side loop, and a parent-side history
     * replay rebuilds these rows text-only — neither media ever survives a
     * reload as bytes, which is why {@see toTypedMessages()}-era replay
     * sites construct this type positionally without them.
     */
    public function __construct(
        private string $toolCallId,
        private string $content,
        private bool $isError = false,
        private ?string $imageBytes = null,
        private ?string $imageProtocol = null,
        private ?Usage $usage = null,
        private ?string $documentBytes = null,
        private ?string $documentName = null,
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
            $this->documentBytes,
            $this->documentName,
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

    public function documentBytes(): ?string
    {
        return $this->documentBytes;
    }

    public function documentName(): ?string
    {
        return $this->documentName;
    }

    public function hasDocument(): bool
    {
        return $this->documentBytes !== null;
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
