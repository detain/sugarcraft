<?php

declare(strict_types=1);

namespace SugarCraft\Toast;

/**
 * A single alert entry in the queue.
 */
final class Alert
{
    /**
     * @param ToastType $type  Alert severity
     * @param string $message  User-visible message text
     * @param float|null $expiresAt  Seconds since epoch; null = never expires
     * @param float|null $progress  Progress 0.0–1.0; null = no progress bar
     * @param list<Action> $actions  Clickable action buttons
     */
    public function __construct(
        public readonly ToastType $type,
        public readonly string $message,
        public readonly ?float $expiresAt = null,
        public readonly ?float $progress = null,
        public readonly array $actions = [],
    ) {}

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) return false;
        return \microtime(true) >= $this->expiresAt;
    }

    /**
     * Alias for withCancelledExpiry — makes the alert never expire.
     */
    public function withoutExpiry(): self
    {
        return $this->withCancelledExpiry();
    }

    public function withCancelledExpiry(): self
    {
        return new self($this->type, $this->message, null, $this->progress, $this->actions);
    }

    /**
     * Extend the expiry by $additionalSeconds from now.
     */
    public function withExtendedExpiry(float $additionalSeconds): self
    {
        return new self(
            $this->type,
            $this->message,
            \microtime(true) + $additionalSeconds,
            $this->progress,
            $this->actions,
        );
    }

    public function withExpiry(float $duration): self
    {
        return new self($this->type, $this->message, \microtime(true) + $duration, $this->progress, $this->actions);
    }

    /**
     * Attach a progress value (0.0–1.0). Values outside range are clamped.
     */
    public function withProgress(float $progress): self
    {
        $clamped = \max(0.0, \min(1.0, $progress));
        return new self($this->type, $this->message, $this->expiresAt, $clamped, $this->actions);
    }

    /**
     * Attach action buttons.
     *
     * @param list<Action> $actions
     */
    public function withActions(array $actions): self
    {
        return new self($this->type, $this->message, $this->expiresAt, $this->progress, $actions);
    }
}
