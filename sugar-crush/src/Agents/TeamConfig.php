<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

/**
 * Configuration for team behavior in sugar-crush.
 *
 * Controls team size limits, task assignment strategy, messaging between
 * teammates, and where team communication files are stored. All values
 * are immutable after construction — use with*() methods to produce derived
 * instances.
 */
final readonly class TeamConfig
{
    public function __construct(
        /**
         * Maximum number of teammates allowed in a team (excluding the lead).
         * Defaults to 5.
         */
        public int $maxTeammates = 5,

        /**
         * How long, in seconds, a teammate may hold a claimed task before
         * the claim reads as overdue ({@see TeamManager::overdueSeconds()}).
         * Nothing is failed or stopped automatically: the Team tool's `list`
         * marks the claim overdue, and the lead may `release` it. `0` never
         * marks one.
         */
        public int $defaultTimeoutSeconds = 600,

        /**
         * When true, teammates can send messages to each other directly.
         * When false, all communication must go through the team lead.
         */
        public bool $allowPeerMessaging = true,

        /**
         * When true, an idle teammate is handed the next unblocked task on
         * the board. When false, tasks stay pending on the board until a
         * teammate claims one by name.
         */
        public bool $autoAssignTasks = true,

        /**
         * Directory path where team inbox and message files are stored.
         * Expandable via FileSystem::expandPath().
         */
        public string $inboxPath = '~/.sugar-crush/teams/',
    ) {}

    /**
     * Create a new config with a different maxTeammates value.
     */
    public function withMaxTeammates(int $maxTeammates): self
    {
        return new self(
            maxTeammates: $maxTeammates,
            defaultTimeoutSeconds: $this->defaultTimeoutSeconds,
            allowPeerMessaging: $this->allowPeerMessaging,
            autoAssignTasks: $this->autoAssignTasks,
            inboxPath: $this->inboxPath,
        );
    }

    /**
     * Create a new config with a different defaultTimeoutSeconds value.
     */
    public function withDefaultTimeoutSeconds(int $defaultTimeoutSeconds): self
    {
        return new self(
            maxTeammates: $this->maxTeammates,
            defaultTimeoutSeconds: $defaultTimeoutSeconds,
            allowPeerMessaging: $this->allowPeerMessaging,
            autoAssignTasks: $this->autoAssignTasks,
            inboxPath: $this->inboxPath,
        );
    }

    /**
     * Create a new config with a different allowPeerMessaging value.
     */
    public function withAllowPeerMessaging(bool $allowPeerMessaging): self
    {
        return new self(
            maxTeammates: $this->maxTeammates,
            defaultTimeoutSeconds: $this->defaultTimeoutSeconds,
            allowPeerMessaging: $allowPeerMessaging,
            autoAssignTasks: $this->autoAssignTasks,
            inboxPath: $this->inboxPath,
        );
    }

    /**
     * Create a new config with a different autoAssignTasks value.
     */
    public function withAutoAssignTasks(bool $autoAssignTasks): self
    {
        return new self(
            maxTeammates: $this->maxTeammates,
            defaultTimeoutSeconds: $this->defaultTimeoutSeconds,
            allowPeerMessaging: $this->allowPeerMessaging,
            autoAssignTasks: $autoAssignTasks,
            inboxPath: $this->inboxPath,
        );
    }

    /**
     * Create a new config with a different inboxPath value.
     */
    public function withInboxPath(string $inboxPath): self
    {
        return new self(
            maxTeammates: $this->maxTeammates,
            defaultTimeoutSeconds: $this->defaultTimeoutSeconds,
            allowPeerMessaging: $this->allowPeerMessaging,
            autoAssignTasks: $this->autoAssignTasks,
            inboxPath: $inboxPath,
        );
    }
}
