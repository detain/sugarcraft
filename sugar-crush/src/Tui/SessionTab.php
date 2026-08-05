<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use DateTimeImmutable;

/**
 * Immutable value object representing a single session tab.
 *
 * Each tab tracks its own session metadata including identity,
 * active state, detach/reattach lifecycle, and agent summary text.
 *
 * Mirrors charmbracelet/crush Tab.
 */
final class SessionTab
{
    /**
     * @param string                 $id            Unique tab identifier.
     * @param string                 $sessionName   Human-readable session name.
     * @param bool                   $isActive      Whether this tab currently has input focus.
     * @param DateTimeImmutable      $lastActivityAt Timestamp of last user or agent activity.
     * @param string|null            $agentSummary  Current agent summary text or null.
     * @param bool                   $isDetached    Whether the session is running in background.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $sessionName,
        public readonly bool $isActive = false,
        public readonly DateTimeImmutable $lastActivityAt = new DateTimeImmutable(),
        public readonly ?string $agentSummary = null,
        public readonly bool $isDetached = false,
    ) {
    }

    /**
     * Create a new SessionTab with updated active state.
     */
    public function withActive(bool $isActive): self
    {
        return new self(
            $this->id,
            $this->sessionName,
            $isActive,
            $this->lastActivityAt,
            $this->agentSummary,
            $this->isDetached,
        );
    }

    /**
     * Create a new SessionTab with updated detached state.
     */
    public function withDetached(bool $isDetached): self
    {
        return new self(
            $this->id,
            $this->sessionName,
            $this->isActive,
            $this->lastActivityAt,
            $this->agentSummary,
            $isDetached,
        );
    }

    /**
     * Create a new SessionTab with updated agent summary.
     */
    public function withSummary(string $summary): self
    {
        return new self(
            $this->id,
            $this->sessionName,
            $this->isActive,
            $this->lastActivityAt,
            $summary,
            $this->isDetached,
        );
    }

    /**
     * Create a new SessionTab with updated last activity timestamp.
     */
    public function withLastActivity(DateTimeImmutable $lastActivityAt): self
    {
        return new self(
            $this->id,
            $this->sessionName,
            $this->isActive,
            $lastActivityAt,
            $this->agentSummary,
            $this->isDetached,
        );
    }

    /**
     * Return the tab id.
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Return whether the tab is detached.
     */
    public function isDetached(): bool
    {
        return $this->isDetached;
    }

    /**
     * Return the agent summary.
     */
    public function agentSummary(): ?string
    {
        return $this->agentSummary;
    }

    /**
     * Return the session name.
     */
    public function sessionName(): string
    {
        return $this->sessionName;
    }
}
