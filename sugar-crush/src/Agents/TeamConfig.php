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
         * Default timeout in seconds for teammate task execution.
         * Teammates exceeding this limit are marked TimedOut.
         */
        public int $defaultTimeoutSeconds = 600,

        /**
         * When true, teammates can send messages to each other directly.
         * When false, all communication must go through the team lead.
         */
        public bool $allowPeerMessaging = true,

        /**
         * When true, unassigned tasks are automatically distributed to
         * available teammates. When false, tasks sit in the inbox until
         * a teammate explicitly claims them.
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
