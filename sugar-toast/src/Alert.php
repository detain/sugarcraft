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
     * @param float|null $expiresAt  Absolute seconds-since-epoch instant the
     *        alert stops rendering. null means INHERIT — Toast::alert(),
     *        progressToast() and push() arm the configured withDuration()
     *        timer when one is set; a truly never-expiring alert is expressed
     *        by $persistent (via withoutExpiry()/withCancelledExpiry() or
     *        Toast::cancelAlert()), not by null alone (lib-reviews-1102 M15).
     * @param float|null $progress  Progress 0.0–1.0 (finite; out-of-range clamps, NAN/INF throw); null = no progress bar
     * @param list<Action> $actions  Clickable action buttons
     * @param Color|null $backgroundColor  Optional box background fill (null = no fill, plain render)
     * @param Color|null $foregroundColor  Optional message-text colour (null = terminal default)
     * @param Color|null $borderColor  Optional box-glyph (border) colour (null = terminal default)
     * @param bool $persistent  Explicit never-expire sentinel. When true, the
     *        queueing doors skip duration inheritance entirely. An explicit
     *        withExpiry()/withExtendedExpiry() clears it (a timer was asked
     *        for); withoutExpiry() sets it. The constructor accepts it
     *        alongside an explicit $expiresAt, but isExpired() short-circuits
     *        on the sentinel — the companion timestamp is stored and unread
     *        while persistence stands (lib-reviews-1102 n24).
     * @param string|null $id  Stable identity for update-in-place. Re-queuing
     *        an alert with the same id through alert()/progressToast()/push()
     *        REPLACES the live alert at its queue position instead of
     *        appending a second box (lib-reviews-1102 C3 — progress toasts
     *        previously had no lifecycle at all). null = anonymous, always
     *        appends.
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
        public readonly bool $persistent = false,
        public readonly ?string $id = null,
    ) {
        if ($progress !== null && !\is_finite($progress)) {
            throw new \InvalidArgumentException(
                'Alert progress must be a finite 0.0-1.0 float (out-of-range values '
                . 'clamp); NAN/INF have no honest bar to render (lib-reviews-1102 minor-6: '
                . 'NAN-as-full-bar was the worst possible fallback)'
            );
        }
    }

    /**
     * Factory mirroring Action::new() / Toast::new() (lib-reviews-1102 n4:
     * Alert had only the bare ctor while its siblings led with ::new()).
     * Arguments and defaults mirror the constructor exactly.
     */
    public static function new(
        ToastType $type,
        ?string $message = null,
        ?float $expiresAt = null,
        ?float $progress = null,
        array $actions = [],
        ?Color $backgroundColor = null,
        ?Color $foregroundColor = null,
        ?Color $borderColor = null,
        bool $persistent = false,
        ?string $id = null,
    ): self {
        return new self($type, $message, $expiresAt, $progress, $actions, $backgroundColor, $foregroundColor, $borderColor, $persistent, $id);
    }

    /**
     * Expiry reads CLOCK_REALTIME (microtime) — an NTP step can shorten or
     * lengthen how long a live alert stays on screen. A monotonic-clock
     * injection seam is a recorded future API candidate (lib-reviews-1102
     * m10); no rate assumption here is load-bearing beyond display timing.
     */
    public function isExpired(): bool
    {
        if ($this->persistent || $this->expiresAt === null) return false;
        return \microtime(true) >= $this->expiresAt;
    }

    /**
     * Alias for {@see withCancelledExpiry()} — makes the alert explicitly
     * persistent (sets the $persistent sentinel, so the queueing doors will
     * not re-inherit a duration). The two spellings are the same door and
     * are documented as aliases (lib-reviews-1102 M15: they used to be
     * undisclosed, and push() re-inherited an expiry precisely because no
     * sentinel distinguished "never" from "not stated").
     */
    public function withoutExpiry(): self
    {
        return $this->withCancelledExpiry();
    }

    public function withCancelledExpiry(): self
    {
        return new self($this->type, $this->message, null, $this->progress, $this->actions, $this->backgroundColor, $this->foregroundColor, $this->borderColor, true, $this->id);
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
            false,
            $this->id,
        );
    }

    public function withExpiry(float $duration): self
    {
        return new self($this->type, $this->message, \microtime(true) + $duration, $this->progress, $this->actions, $this->backgroundColor, $this->foregroundColor, $this->borderColor, false, $this->id);
    }

    /**
     * Attach a progress value (0.0–1.0). Finite out-of-range values are
     * clamped; non-finite values are refused (see the constructor law).
     *
     * @throws \InvalidArgumentException when $progress is NAN or INF
     */
    public function withProgress(float $progress): self
    {
        if (!\is_finite($progress)) {
            throw new \InvalidArgumentException(
                'withProgress() requires a finite 0.0-1.0 float (clamped); NAN/INF are refused'
            );
        }
        $clamped = \max(0.0, \min(1.0, $progress));
        return new self($this->type, $this->message, $this->expiresAt, $clamped, $this->actions, $this->backgroundColor, $this->foregroundColor, $this->borderColor, $this->persistent, $this->id);
    }

    /**
     * Attach action buttons.
     *
     * @param list<Action> $actions
     */
    public function withActions(array $actions): self
    {
        return new self($this->type, $this->message, $this->expiresAt, $this->progress, $actions, $this->backgroundColor, $this->foregroundColor, $this->borderColor, $this->persistent, $this->id);
    }

    /**
     * Set the box background-fill colour (null clears it → plain render).
     *
     * Additive styling consumed by the Toast renderer; when null the alert
     * renders byte-identically to the un-styled path.
     */
    public function withBackgroundColor(?Color $color): self
    {
        return new self($this->type, $this->message, $this->expiresAt, $this->progress, $this->actions, $color, $this->foregroundColor, $this->borderColor, $this->persistent, $this->id);
    }

    /**
     * Set the message-text (foreground) colour (null = terminal default).
     */
    public function withForegroundColor(?Color $color): self
    {
        return new self($this->type, $this->message, $this->expiresAt, $this->progress, $this->actions, $this->backgroundColor, $color, $this->borderColor, $this->persistent, $this->id);
    }

    /**
     * Set the box-glyph (border) colour (null = terminal default).
     */
    public function withBorderColor(?Color $color): self
    {
        return new self($this->type, $this->message, $this->expiresAt, $this->progress, $this->actions, $this->backgroundColor, $this->foregroundColor, $color, $this->persistent, $this->id);
    }
}
