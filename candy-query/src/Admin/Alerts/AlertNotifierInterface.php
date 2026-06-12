<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Alerts;

use SugarCraft\Toast\Toast;

/**
 * Contract for dispatching alert notifications.
 *
 * Extracted so consumers (AlertManager, DashboardPage) depend on the
 * behaviour, not the concrete Toast-backed AlertNotifier. That keeps the
 * dispatch path testable — a counting double can stand in for the real
 * notifier to assert dedup behaviour without driving sugar-toast.
 *
 * Implementations are immutable: every notify and with call returns a new
 * instance. The static factories (new, withDefaults) stay on the concrete.
 */
interface AlertNotifierInterface
{
    /** Dispatch a pre-built Alert; returns a new notifier carrying it. */
    public function notify(Alert $alert): self;

    public function notifyWarning(string $message): self;

    public function notifyCritical(string $message): self;

    public function notifyError(string $message): self;

    public function notifyInfo(string $message): self;

    /** True when notifications are suppressed. */
    public function isMuted(): bool;

    public function withMuted(bool $muted): self;

    /** Compose the current toast state over a background viewport string. */
    public function view(string $background, int $viewportWidth = 80, int $viewportHeight = 24): string;

    /** The underlying Toast, or null if none has been created. */
    public function toast(): ?Toast;

    /** True if any alert is currently queued. */
    public function hasActiveAlert(): bool;
}
