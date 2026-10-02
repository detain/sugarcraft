<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools;

use SugarCraft\Crush\Permissions\DenialKind;
use SugarCraft\Crush\Usage;

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
     *
     * $usage is what the tool itself BILLED on a provider — today only
     * {@see \SugarCraft\Crush\Tools\BuiltIn\TaskTool}, whose sub-agent is a
     * whole agentic run of its own (audit B4). Without a field here that spend
     * died with the tool: a delegated run's dollars reached neither the
     * calling turn's {@see \SugarCraft\Crush\Message::$usage}, nor the
     * session total, nor the mid-turn spend cap. Null means the tool spent
     * nothing it could report — every tool that never talks to a provider.
     *
     * $denial says the call was STOPPED before it ran, and which of the three
     * ways (audit F-P8). It is set by the party that made the refusal — the
     * engine's gate, the TUI's permission prompt — and is the ONLY thing a
     * reader may treat as "this call never ran". The error text cannot be that
     * signal: it is whatever the tool, the shell, or a remote MCP server chose
     * to print, so a `printf 'Permission denied: …'; exit 1` used to be
     * reported as a policy refusal of a command that had in fact executed.
     * The text still opens with {@see DenialKind::reason()}'s prefix for the
     * model and a human; it is just no longer what anything classifies on.
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
        private ?Usage $usage = null,
        private ?DenialKind $denial = null,
    ) {}

    /**
     * A call that was stopped before it ran: the error result whose text is
     * $kind's rendered reason and which CARRIES $kind, so every consumer
     * downstream can tell a refusal from a failure without reading the text.
     */
    public static function denied(string $toolCallId, DenialKind $kind, string $detail): self
    {
        return new self(
            toolCallId: $toolCallId,
            content: $kind->reason($detail),
            isError: true,
            denial: $kind,
        );
    }

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

    /** Provider spend the tool itself incurred (a delegated sub-agent run), or null. */
    public function usage(): ?Usage
    {
        return $this->usage;
    }

    /**
     * Which of the three ways this call was stopped before it ran, or null
     * when it was not stopped — including when it ran and failed with text
     * that merely LOOKS like a refusal (audit F-P8).
     */
    public function denial(): ?DenialKind
    {
        return $this->denial;
    }

    /** The same result marked as stopped-before-it-ran by $denial (null clears it). */
    public function withDenial(?DenialKind $denial): self
    {
        return $this->mutate(['denial' => $denial]);
    }

    /**
     * The same result with its model-visible text replaced and EVERY other
     * field kept — the seam {@see \SugarCraft\Crush\Runtime}'s rewrites
     * (annotate, the UTF-8 scrub) take, so a rebuild cannot drop a field that
     * was added after it was written. Spelled-out positional rebuilds are
     * exactly how $usage would have leaked off a Task result between the tool
     * and the turn's accounting.
     */
    public function withContent(string $content): self
    {
        return $this->mutate(['content' => $content]);
    }

    /** The same result carrying $usage as the tool's own provider spend. */
    public function withUsage(?Usage $usage): self
    {
        return $this->mutate(['usage' => $usage]);
    }

    /**
     * Rebuild with the named fields replaced. get_object_vars() is the field
     * roster because every property is constructor-promoted, so a field added
     * later is carried by every wither without touching them.
     *
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /**
     * The provider wire-shape only -- $diff is deliberately absent, exactly
     * like the image fields: it is renderer-side presentation data, and
     * inlining a whole unified diff here would duplicate it into every
     * chat-completion request's token budget. $usage is absent for the same
     * reason: it is accounting, not something the model reads. So is
     * $denial: the model reads the refusal in the text, which still opens
     * with the kind's prefix, and the structural kind is for the parties
     * that must not trust that text (audit F-P8).
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
