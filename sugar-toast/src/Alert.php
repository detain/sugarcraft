<?php

declare(strict_types=1);

namespace SugarCraft\Toast;

use SugarCraft\Core\Util\Color;

/**
 * A single alert entry in the queue.
 */
final class Alert
{
    /**
     * @param ToastType $type  Alert severity
     * @param string|null $message  User-visible message text (null renders as empty)
     * @param float|null $expiresAt  Seconds since epoch; null = never expires
     * @param float|null $progress  Progress 0.0–1.0; null = no progress bar
     * @param list<Action> $actions  Clickable action buttons
     * @param Color|null $backgroundColor  Optional box background fill (null = no fill, plain render)
     * @param Color|null $foregroundColor  Optional message-text colour (null = terminal default)
     * @param Color|null $borderColor  Optional box-glyph (border) colour (null = terminal default)
     */
    public function __construct(
        public readonly ToastType $type,
        public readonly ?string $message = null,
        public readonly ?float $expiresAt = null,
        public readonly ?float $progress = null,
        public readonly array $actions = [],
        public readonly ?Color $backgroundColor = null,
        public readonly ?Color $foregroundColor = null,
        public readonly ?Color $borderColor = null,
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
        return new self($this->type, $this->message, null, $this->progress, $this->actions, $this->backgroundColor, $this->foregroundColor, $this->borderColor);
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
            $this->backgroundColor,
            $this->foregroundColor,
            $this->borderColor,
        );
    }

    public function withExpiry(float $duration): self
    {
        return new self($this->type, $this->message, \microtime(true) + $duration, $this->progress, $this->actions, $this->backgroundColor, $this->foregroundColor, $this->borderColor);
    }

    /**
     * Attach a progress value (0.0–1.0). Values outside range are clamped.
     */
    public function withProgress(float $progress): self
    {
        $clamped = \max(0.0, \min(1.0, $progress));
        return new self($this->type, $this->message, $this->expiresAt, $clamped, $this->actions, $this->backgroundColor, $this->foregroundColor, $this->borderColor);
    }

    /**
     * Attach action buttons.
     *
     * @param list<Action> $actions
     */
    public function withActions(array $actions): self
    {
        return new self($this->type, $this->message, $this->expiresAt, $this->progress, $actions, $this->backgroundColor, $this->foregroundColor, $this->borderColor);
    }

    /**
     * Set the box background-fill colour (null clears it → plain render).
     *
     * Additive styling consumed by the Toast renderer; when null the alert
     * renders byte-identically to the un-styled path.
     */
    public function withBackgroundColor(?Color $color): self
    {
        return new self($this->type, $this->message, $this->expiresAt, $this->progress, $this->actions, $color, $this->foregroundColor, $this->borderColor);
    }

    /**
     * Set the message-text (foreground) colour (null = terminal default).
     */
    public function withForegroundColor(?Color $color): self
    {
        return new self($this->type, $this->message, $this->expiresAt, $this->progress, $this->actions, $this->backgroundColor, $color, $this->borderColor);
    }

    /**
     * Set the box-glyph (border) colour (null = terminal default).
     */
    public function withBorderColor(?Color $color): self
    {
        return new self($this->type, $this->message, $this->expiresAt, $this->progress, $this->actions, $this->backgroundColor, $this->foregroundColor, $color);
    }
}
