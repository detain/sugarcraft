<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Messages;

use SugarCraft\Crush\Usage;

final readonly class AssistantMessage implements Message
{
    public function __construct(
        private string $content,
        private ?array $toolCalls = null,
        private ?string $reasoning = null,
        /**
         * What this ONE provider call cost, as the provider counted it, or
         * null when it reported nothing (crush_code.md Phase 5 item 7).
         * {@see \SugarCraft\Crush\Runtime} sets it from
         * {@see \SugarCraft\Crush\Providers\CompleteResponse}; until it did,
         * the count and cost every provider already returns were dropped here
         * and no caller could see them at all - the same silent conversion
         * loss {@see \SugarCraft\Crush\Message::$reasoning} was added to fix
         * one seam further out.
         *
         * Per CALL, not per turn: {@see \SugarCraft\Crush\Backend\EngineBackend::complete()}
         * runs up to `$maxSteps` of these and is where they are summed.
         */
        private ?Usage $usage = null,
    ) {}

    /**
     * This call's provider-counted usage, or null when none was reported - see
     * the constructor's $usage docblock and {@see Usage} on why null and zero
     * are deliberately different answers.
     */
    public function usage(): ?Usage
    {
        return $this->usage;
    }

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

    /**
     * The wire shape a provider is sent. Deliberately carries NO `usage` key:
     * this is what goes back INTO the next request, and a turn's cost is
     * engine-side accounting rather than conversation content. Adding it here
     * would put the previous call's bill into the next call's prompt.
     */
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
