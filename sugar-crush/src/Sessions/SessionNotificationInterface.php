<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Sessions;

/**
 * Contract for receiving background session notifications.
 *
 * The BackgroundSupervisor sends notifications through this interface when
 * sessions complete, fail, or need attention. The TUI implements this
 * to surface notifications in the status bar.
 */
interface SessionNotificationInterface
{
    /**
     * Called when a background session completes successfully.
     */
    public function onSessionCompleted(BackgroundSession $session): void;

    /**
     * Called when a background session fails or errors.
     */
    public function onSessionFailed(BackgroundSession $session): void;

    /**
     * Called when a background session has been stalled (heartbeats stopped).
     */
    public function onSessionStalled(BackgroundSession $session): void;

    /**
     * Called when a stalled session resumes (sends a new heartbeat).
     */
    public function onSessionResumed(BackgroundSession $session): void;

    /**
     * Called when a background session starts streaming output.
     */
    public function onSessionStreaming(BackgroundSession $session, string $chunk): void;
}
