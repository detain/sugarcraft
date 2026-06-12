<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Alerts;

use SugarCraft\Toast\Position;
use SugarCraft\Toast\Toast;
use SugarCraft\Toast\ToastType;

/**
 * Sends alert notifications via SugarCraft\Toast\Toast.
 *
 * Wraps a Toast factory callable so this class degrades gracefully
 * when sugar-toast is unavailable (e.g. in non-TUI contexts).
 * All notify*() methods return new instances for immutability.
 *
 * Usage:
 *   $notifier = AlertNotifier::new()->withMuted(false);
 *   $notifier = $notifier->notifyWarning('High memory usage');
 *   $composed = $toast->View($background);
 */
final class AlertNotifier implements AlertNotifierInterface
{
    /**
     * @param ?\Closure():Toast $toastFactory  Factory that produces Toast instances
     * @param bool $muted  When true, all notifications are suppressed
     * @param Toast|null $toast  Current toast state (for chaining)
     */
    private function __construct(
        private readonly ?\Closure $toastFactory = null,
        private readonly bool $muted = true,
        private readonly ?Toast $toast = null,
    ) {}

    /**
     * Create a new notifier with no factory (mute-safe, no-op until factory set).
     * Returns a muted notifier by default - use withMuted(false) to enable.
     */
    public static function new(?\Closure $toastFactory = null): self
    {
        // When no factory is provided, mute by default for safety
        $muted = $toastFactory === null;
        return new self(toastFactory: $toastFactory, muted: $muted);
    }

    /**
     * Create a notifier with a default Toast factory using standard position/duration.
     *
     * @param bool $muted  When true, notifications are suppressed (default: true)
     */
    public static function withDefaults(?\Closure $toastFactory = null, Position $position = Position::TopRight, ?float $duration = 5.0, bool $muted = true): self
    {
        $factory = $toastFactory ?? static fn(): Toast => Toast::new(50)
            ->withPosition($position)
            ->withDuration($duration);

        return new self(toastFactory: $factory, muted: $muted);
    }

    /**
     * Notify with a pre-built Alert.
     *
     * Returns a new AlertNotifier with the toast updated to include the alert.
     * If muted or no factory is available, returns $this unchanged.
     */
    public function notify(Alert $alert): self
    {
        if ($this->muted || $this->toastFactory === null) {
            return $this;
        }

        $toast = ($this->toastFactory)();
        $toastType = $alert->severity->toToastType();

        $newToast = ($this->toast ?? $toast)
            ->alert($toastType, $alert->toToastMessage());

        return new self(
            toastFactory: $this->toastFactory,
            muted: $this->muted,
            toast: $newToast,
        );
    }

    /**
     * Send a warning notification.
     */
    public function notifyWarning(string $message): self
    {
        return $this->notify(Alert::warning('system', $message, 0.0, 0.0));
    }

    /**
     * Send a critical notification.
     */
    public function notifyCritical(string $message): self
    {
        return $this->notify(Alert::critical('system', $message, 0.0, 0.0));
    }

    /**
     * Send an error notification.
     *
     * Note: Error maps to ToastType::Error which is equivalent to Critical.
     */
    public function notifyError(string $message): self
    {
        return $this->notify(Alert::critical('system', $message, 0.0, 0.0));
    }

    /**
     * Send an info notification.
     */
    public function notifyInfo(string $message): self
    {
        return $this->notify(Alert::info('system', $message, 0.0, 0.0));
    }

    /**
     * True when notifications are muted.
     */
    public function isMuted(): bool
    {
        return $this->muted;
    }

    /**
     * Return a new notifier with the given mute state.
     */
    public function withMuted(bool $muted): self
    {
        return new self(
            toastFactory: $this->toastFactory,
            muted: $muted,
            toast: $this->toast,
        );
    }

    /**
     * Return a new notifier with an updated toast factory.
     *
     * Useful for late-binding the factory when it wasn't available at construction.
     */
    public function withToastFactory(\Closure $factory): self
    {
        return new self(
            toastFactory: $factory,
            muted: $this->muted,
            toast: $this->toast,
        );
    }

    /**
     * Compose the current toast state over a background viewport string.
     *
     * @param string $background  The underlying viewport content
     * @param int $viewportWidth  Viewport width in cells (default 80)
     * @param int $viewportHeight Viewport height in lines (default 24)
     * @return string  The composited output with toast overlaid
     */
    public function view(string $background, int $viewportWidth = 80, int $viewportHeight = 24): string
    {
        if ($this->toast === null) {
            return $background;
        }

        return $this->toast->View($background, $viewportWidth, $viewportHeight);
    }

    /**
     * Get the underlying Toast instance for direct manipulation.
     *
     * Returns null if no toast has been created yet (e.g. no alerts sent).
     */
    public function toast(): ?Toast
    {
        return $this->toast;
    }

    /**
     * True if any alerts have been queued.
     */
    public function hasActiveAlert(): bool
    {
        return $this->toast?->hasActiveAlert() ?? false;
    }
}
