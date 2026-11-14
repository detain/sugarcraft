<?php

declare(strict_types=1);

namespace SugarCraft\Readline;

/**
 * The typed outcome of `Readline::run()` (finding 081).
 *
 * Before this carrier existed, run() returned a bare prompt object and the
 * story of WHY it stopped was told only in prose: submitted, aborted and
 * the one-shot stop() latch were all inferable only if the prompt shape
 * happened to expose them, and a stopped run looked identical to an EOF
 * exit. The result states all four outcomes explicitly, plus the final
 * prompt itself for hosts that need the full state machine.
 *
 * `stopped` is honest about the latch: once stop() has fired on an
 * instance, every result it produces reports stopped — the instance is
 * spent, and a fresh Readline is the documented restart.
 */
final readonly class ReadlineResult
{
    public function __construct(
        public bool $submitted,
        public bool $aborted,
        public bool $stopped,
        public mixed $value,
        public object $prompt,
    ) {
    }

    /**
     * Read the outcome off a finished prompt. The value resolution mirrors
     * the per-shape answer accessors shipped by the prompts: value() for
     * text forms, selectedValues() for multi-select, selectedValue() for
     * single selections; null when the prompt exposes none.
     */
    public static function fromPrompt(object $prompt, bool $stopped): self
    {
        $value = null;
        if (\method_exists($prompt, 'value')) {
            $value = $prompt->value();
        } elseif (\method_exists($prompt, 'selectedValues')) {
            $value = $prompt->selectedValues();
        } elseif (\method_exists($prompt, 'selectedValue')) {
            $value = $prompt->selectedValue();
        }

        return new self(
            submitted: $prompt->isSubmitted(),
            aborted: $prompt->isAborted(),
            stopped: $stopped,
            value: $value,
            prompt: $prompt,
        );
    }
}
