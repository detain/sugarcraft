<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Sessions;

/**
 * Optional companion to {@see SessionNotificationInterface} for listeners that
 * want to tell a user-requested stop apart from a failure (audit BG-1).
 *
 * A separate interface rather than a sixth method on the original contract:
 * adding a method there would break every existing implementer at load time.
 * {@see BackgroundSupervisor::onSessionStopped()} forwards here when the
 * listener implements it and to `onSessionFailed()` otherwise — a stopped
 * session did not complete, and its `status` (Stopped) says why.
 */
interface SessionStopNotificationInterface
{
    /**
     * Called when a background session was stopped before it settled.
     */
    public function onSessionStopped(BackgroundSession $session): void;
}
