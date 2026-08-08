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
        return $this->mutate(['isActive' => $isActive]);
    }

    /**
     * Create a new SessionTab with updated detached state.
     */
    public function withDetached(bool $isDetached): self
    {
        return $this->mutate(['isDetached' => $isDetached]);
    }

    /**
     * Create a new SessionTab with updated agent summary.
     */
    public function withSummary(string $summary): self
    {
        return $this->mutate(['agentSummary' => $summary]);
    }

    /**
     * Create a new SessionTab with updated last activity timestamp.
     */
    public function withLastActivity(DateTimeImmutable $lastActivityAt): self
    {
        return $this->mutate(['lastActivityAt' => $lastActivityAt]);
    }

    /**
     * Create a new instance with the given changes merged in.
     *
     * Mirrors the repo-wide immutable+fluent `mutate()` convention (see
     * candy-sprinkles/src/Style.php, candy-buffer/src/Style.php) so every
     * `with*()` builds through a single point instead of hand-listing all
     * constructor params.
     *
     * @param array<string, mixed> $changes Key-value pairs to change
     */
    private function mutate(array $changes): static
    {
        return new static(...array_merge([
            'id' => $this->id,
            'sessionName' => $this->sessionName,
            'isActive' => $this->isActive,
            'lastActivityAt' => $this->lastActivityAt,
            'agentSummary' => $this->agentSummary,
            'isDetached' => $this->isDetached,
        ], $changes));
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

    /**
     * Return whether the tab is active.
     */
    public function isActive(): bool
    {
        return $this->isActive;
    }
}
