<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Sessions;

use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Util\IdGenerator;

/**
 * Manages all background sessions as a supervisor process.
 *
 * The supervisor monitors session health via heartbeats, marks stalled
 * sessions, and routes messages between the TUI and each session. Health
 * monitoring reuses the same heartbeat contract as Phase 1's ProcessExecutor:
 * each session reports a heartbeat on a fixed interval, and the supervisor
 * marks it stalled if heartbeats stop arriving.
 *
 * Mirrors charmbracelet/charmcrush background session supervisor design.
 */
final class BackgroundSupervisor implements SessionNotificationInterface
{
    /**
     * Heartbeat timeout in seconds — matching Phase 1's ProcessExecutor.
     */
    public const HEARTBEAT_TIMEOUT_SECS = 15;

    /** @var array<string, BackgroundSession> Sessions indexed by session ID */
    private array $sessions = [];

    /** @var SessionNotificationInterface|null Optional listener for session events */
    private ?SessionNotificationInterface $listener = null;

    /** @var bool Whether IPC reconnect has been performed for this supervisor cycle */
    private bool $reconnected = false;

    public function __construct(?SessionNotificationInterface $listener = null)
    {
        $this->listener = $listener;
    }

    // =========================================================================
    // Session Management
    // =========================================================================

    /**
     * Add a new background session to the supervisor.
     */
    public function addSession(BackgroundSession $session): void
    {
        $this->sessions[$session->id] = $session;
    }

    /**
     * Remove a session from the supervisor by ID.
     */
    public function removeSession(string $sessionId): void
    {
        unset($this->sessions[$sessionId]);
    }

    /**
     * Get a session by ID, or null if not found.
     */
    public function getSession(string $sessionId): ?BackgroundSession
    {
        return $this->sessions[$sessionId] ?? null;
    }

    /**
     * Return all active (non-terminal) sessions.
     *
     * @return array<string, BackgroundSession>
     */
    public function getActiveSessions(): array
    {
        $active = [];
        foreach ($this->sessions as $id => $session) {
            if ($session->isActive()) {
                $active[$id] = $session;
            }
        }
        return $active;
    }

    /**
     * Return true when there is at least one active session.
     */
    public function hasActiveSessions(): bool
    {
        foreach ($this->sessions as $session) {
            if ($session->isActive()) {
                return true;
            }
        }
        return false;
    }

    // =========================================================================
    // Health Monitoring (Heartbeat Ticker)
    // =========================================================================

    /**
     * Tick the supervisor — call this periodically to check session health.
     *
     * Each active session is checked for stalled heartbeat. A session that
     * was previously not stalled but now exceeds the heartbeat timeout is
     * marked Stalled and the listener is notified. Sessions that resume
     * heartbeating after being stalled are also notified.
     *
     * @param int|null $now Unix timestamp for testing injection; defaults to time()
     */
    public function tick(?int $now = null): void
    {
        $now = $now ?? time();

        foreach ($this->sessions as $session) {
            if (!$session->isActive()) {
                continue;
            }

            $wasStalled = $session->status === BackgroundSessionStatus::Stalled;
            $isStalled = $session->isStalled(self::HEARTBEAT_TIMEOUT_SECS);

            if ($isStalled && !$wasStalled) {
                // Transition running → stalled
                $session->status = BackgroundSessionStatus::Stalled;
                $this->onSessionStalled($session);
            } elseif (!$isStalled && $wasStalled) {
                // Transition stalled → running (resumed)
                $session->status = BackgroundSessionStatus::Running;
                $this->onSessionResumed($session);
            }
        }
    }

    // =========================================================================
    // IPC Reconnect (TUI Reopen)
    // =========================================================================

    /**
     * Reconnect to existing sessions over IPC when the TUI reopens.
     *
     * This restores state for all sessions that were running when the TUI
     * closed, allowing the user to see partial output and continue interacting.
     *
     * @return array<string, BackgroundSession> Sessions that were reconnected
     */
    public function reconnect(): array
    {
        if ($this->reconnected) {
            return [];
        }

        $reconnected = [];
        foreach ($this->sessions as $id => $session) {
            if ($session->isActive()) {
                // In a real implementation this would use IPC to restore session state
                // from the supervisor process. Here we mark the session as reconnected.
                $reconnected[$id] = $session;
            }
        }

        $this->reconnected = true;
        return $reconnected;
    }

    /**
     * Reset the reconnect flag — useful when supervisor restarts fresh.
     */
    public function resetReconnected(): void
    {
        $this->reconnected = false;
    }

    // =========================================================================
    // SessionNotificationInterface Implementation
    // =========================================================================

    public function onSessionCompleted(BackgroundSession $session): void
    {
        if ($this->listener !== null) {
            $this->listener->onSessionCompleted($session);
        }
    }

    public function onSessionFailed(BackgroundSession $session): void
    {
        if ($this->listener !== null) {
            $this->listener->onSessionFailed($session);
        }
    }

    public function onSessionStalled(BackgroundSession $session): void
    {
        if ($this->listener !== null) {
            $this->listener->onSessionStalled($session);
        }
    }

    public function onSessionResumed(BackgroundSession $session): void
    {
        if ($this->listener !== null) {
            $this->listener->onSessionResumed($session);
        }
    }

    public function onSessionStreaming(BackgroundSession $session, string $chunk): void
    {
        $session->output .= $chunk;

        if ($this->listener !== null) {
            $this->listener->onSessionStreaming($session, $chunk);
        }
    }

    // =========================================================================
    // Mutation (Immutable + Fluent Pattern)
    // =========================================================================

    /**
     * Create a new supervisor with a different listener.
     */
    public function withListener(?SessionNotificationInterface $listener): self
    {
        $clone = clone $this;
        $clone->listener = $listener;
        return $clone;
    }
}
