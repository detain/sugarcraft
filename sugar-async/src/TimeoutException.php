<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * Rejection when a time bound is hit: {@see AsyncOps::withTimeout()},
 * {@see AsyncOps::withDeadline()} and {@see AsyncOps::retry()}'s exhausted
 * budget. Carries the bound that was set (and the operation's label, when the
 * caller gave one) as structured fields, so a handler can report or branch on
 * the duration without parsing the human-readable message.
 */
final class TimeoutException extends \RuntimeException
{
    /**
     * The constructor is exempt from signature-compatibility rules in PHP, so
     * the extra structured fields ride after the inherited three (message,
     * code, previous); every existing single-argument construction keeps
     * working unchanged.
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly ?float $seconds = null,
        private readonly ?string $label = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** The bound in seconds that was exceeded, or null when unknown. */
    public function seconds(): ?float
    {
        return $this->seconds;
    }

    /** The operation label passed to the wrapper, or null when unlabelled. */
    public function label(): ?string
    {
        return $this->label;
    }
}
