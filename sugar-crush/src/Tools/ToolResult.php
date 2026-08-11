<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools;

final readonly class ToolResult
{
    /**
     * $imageBytes/$imagePath/$imageProtocol bridge this type to {@see
     * \SugarCraft\Crush\ToolResult}'s image-carrying fields (W1.G2 E1/E2):
     * this is the {@see Tool}-interface result type the LIVE
     * {@see \SugarCraft\Crush\Runtime}/{@see
     * \SugarCraft\Crush\Backend\EngineBackend} agentic loop actually
     * produces and consumes, so an image-bearing tool (e.g. {@see
     * \SugarCraft\Crush\Tools\BuiltIn\Doctor}) needs a real place to put
     * its bytes on THIS type, not only on the parallel `Crush\ToolResult`
     * that only Chat's own (production-unreachable) registerTool()
     * dispatch consumes.
     *
     * $diff carries a raw unified diff (crush_feat.md §1 recommendation E3)
     * for edit-shaped tools, kept OFF $content so a renderer can hand it
     * straight to `sugar-stash\DiffViewer::fromRawDiff()` instead of
     * string-scanning a free-text summary for a `--- a/` line.
     */
    public function __construct(
        private string $toolCallId,
        private string $content,
        private bool $isError = false,
        private ?int $durationMs = null,
        private ?string $imageBytes = null,
        private ?string $imagePath = null,
        private ?string $imageProtocol = null,
        private ?string $diff = null,
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

    public function imageBytes(): ?string
    {
        return $this->imageBytes;
    }

    public function imagePath(): ?string
    {
        return $this->imagePath;
    }

    public function imageProtocol(): ?string
    {
        return $this->imageProtocol;
    }

    public function hasImage(): bool
    {
        return $this->imageBytes !== null;
    }

    /** Raw unified diff produced by an edit-shaped tool, or null. */
    public function diff(): ?string
    {
        return $this->diff;
    }

    public function hasDiff(): bool
    {
        return $this->diff !== null;
    }

    /**
     * The provider wire-shape only -- $diff is deliberately absent, exactly
     * like the image fields: it is renderer-side presentation data, and
     * inlining a whole unified diff here would duplicate it into every
     * chat-completion request's token budget.
     */
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
