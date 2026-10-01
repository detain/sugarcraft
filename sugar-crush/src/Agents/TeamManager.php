<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

use SugarCraft\Core\Util\AtomicJsonFile;
use SugarCraft\Crush\Support\HomeDirectory;

/**
 * Creates and manages Team aggregate roots.
 *
 * Acts as the factory and registry for all active teams in a session.
 * Each team is identified by a unique team ID and scoped to the lead
 * agent that created it. TeamManager persists team metadata to disk so
 * teams can be inspected and resumed across sessions.
 *
 * Team state (tasks, messages) is stored under:
 *     ~/.sugar-crush/teams/{teamId}/
 *
 * while TeamManager's own registry is stored at:
 *     ~/.sugar-crush/teams/registry.json
 */
final class TeamManager
{
    /** @var array<string, Team> */
    private array $teams = [];

    /** @var array<string, TeamConfig> keyed by team ID */
    private array $teamConfigs = [];

    private readonly string $registryPath;

    public function __construct(
        private readonly string $basePath = '~/.sugar-crush/teams',
    ) {
        $this->registryPath = $this->expandPath($this->basePath) . '/registry.json';
        $this->loadRegistry();
    }

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    /**
     * Create a new team and register it.
     *
     * @throws \InvalidArgumentException When a team with the given ID already exists.
     */
    public function createTeam(
        string $teamId,
        string $name,
        string $leadAgentId,
        ?TeamConfig $config = null,
    ): Team {
        if (isset($this->teams[$teamId])) {
            throw new \InvalidArgumentException(sprintf('Team "%s" already exists.', $teamId));
        }

        if (str_contains($teamId, '..') || str_contains($teamId, '/')) {
            throw new \InvalidArgumentException('Team ID must not contain path traversal sequences or slashes.');
        }

        $config ??= new TeamConfig();

        // Ensure the team directory exists before creating Team (TaskList needs it)
        $teamDir = $this->expandPath($this->basePath) . '/' . $teamId;
        if (!is_dir($teamDir)) {
            // 0700, not the pre-audit 0755: a team inbox carries whole tool
            // payloads (audit M2); no other uid has a reason to traverse it.
            // mkdir's mode is umask-filtered, so widen nothing afterwards —
            // the value asked for is the value on a default-umask box, and
            // AtomicJsonFile re-asserts the registry dir's mode itself.
            @mkdir($teamDir, 0700, true);
        }

        $team = new Team(
            id: $teamId,
            name: $name,
            leadAgentId: $leadAgentId,
            createdAt: new \DateTimeImmutable(),
            maxTeammates: $config->maxTeammates,
        );

        $this->teams[$teamId] = $team;
        $this->teamConfigs[$teamId] = $config;
        $this->saveRegistry();

        return $team;
    }

    // -------------------------------------------------------------------------
    // Registry accessors
    // -------------------------------------------------------------------------

    /**
     * Return all registered teams.
     *
     * @return Team[]
     */
    public function getTeams(): array
    {
        return array_values($this->teams);
    }

    /**
     * Fetch a team by its ID.
     */
    public function getTeam(string $teamId): ?Team
    {
        return $this->teams[$teamId] ?? null;
    }

    /**
     * Fetch the config for a given team.
     */
    public function getTeamConfig(string $teamId): ?TeamConfig
    {
        return $this->teamConfigs[$teamId] ?? null;
    }

    /**
     * Return the number of currently registered teams.
     */
    public function teamCount(): int
    {
        return count($this->teams);
    }

    // -------------------------------------------------------------------------
    // Task assignment
    // -------------------------------------------------------------------------

    /**
     * Real consumer for the TaskList::dispatchTeammateIdle() signal.
     *
     * TaskList::dispatchTeammateIdle() previously had no caller anywhere in
     * the codebase — a teammate going idle produced a hook dispatch that
     * nothing ever triggered. This gives it a listener: when a teammate goes
     * idle, dispatch the TeammateIdle hook and, if it isn't blocked, hand the
     * teammate the next unblocked task in its team's queue (if any).
     *
     * @return string|null The ID of the task just claimed, or null if the team/teammate
     *                      was not found, the hook blocked, or no unblocked task exists.
     */
    public function handleTeammateIdle(string $teamId, string $teammateId): ?string
    {
        $team = $this->teams[$teamId] ?? null;
        if ($team === null) {
            return null;
        }

        $taskList = $team->getTaskList();

        $hookResult = $taskList->dispatchTeammateIdle($teamId, $teammateId);
        if ($hookResult->isBlock()) {
            return null;
        }

        foreach ($taskList->getUnblockedTasks($teammateId) as $task) {
            if ($taskList->claimTask($task->id, $teammateId)) {
                return $task->id;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    /**
     * Check whether a team exists (is registered).
     */
    public function hasTeam(string $teamId): bool
    {
        return isset($this->teams[$teamId]);
    }

    /**
     * Unregister and return a team by ID.
     *
     * This does NOT delete the team's persisted data (tasks, messages).
     * Use this when a session ends but team history should be preserved.
     *
     * Returns null if the team does not exist.
     */
    public function removeTeam(string $teamId): ?Team
    {
        if (!isset($this->teams[$teamId])) {
            return null;
        }

        $team = $this->teams[$teamId];
        unset($this->teams[$teamId], $this->teamConfigs[$teamId]);
        $this->saveRegistry();

        return $team;
    }

    /**
     * Re-hydrate a previously registered team into memory.
     *
     * Useful when resuming a session — the registry is loaded but Team
     * instances need to be reconstructed from their on-disk state.
     *
     * @return Team|null The re-hydrated team, or null if not found on disk.
     */
    public function reloadTeam(string $teamId): ?Team
    {
        $registry = $this->loadRegistryData();
        if (!isset($registry[$teamId])) {
            return null;
        }

        $meta = $registry[$teamId];

        $config = isset($meta['config'])
            ? new TeamConfig(
                maxTeammates: $meta['config']['maxTeammates'] ?? 5,
                defaultTimeoutSeconds: $meta['config']['defaultTimeoutSeconds'] ?? 600,
                allowPeerMessaging: $meta['config']['allowPeerMessaging'] ?? true,
                autoAssignTasks: $meta['config']['autoAssignTasks'] ?? true,
                inboxPath: $meta['config']['inboxPath'] ?? '~/.sugar-crush/teams/',
            )
            : new TeamConfig();

        $team = new Team(
            id: $teamId,
            name: $meta['name'],
            leadAgentId: $meta['leadAgentId'],
            createdAt: new \DateTimeImmutable($meta['createdAt']),
            maxTeammates: $config->maxTeammates,
        );

        $this->teams[$teamId] = $team;
        $this->teamConfigs[$teamId] = $config;

        return $team;
    }

    // -------------------------------------------------------------------------
    // Persistence
    // -------------------------------------------------------------------------

    /**
     * Load the registry of team metadata from disk.
     *
     * Re-hydrates all previously persisted teams into memory so they can be
     * inspected and resumed without re-creating their on-disk state.
     */
    private function loadRegistry(): void
    {
        $registry = $this->loadRegistryData();
        foreach ($registry as $teamId => $meta) {
            if (!isset($meta['name'], $meta['leadAgentId'], $meta['createdAt'])) {
                continue;
            }

            $config = isset($meta['config'])
                ? new TeamConfig(
                    maxTeammates: $meta['config']['maxTeammates'] ?? 5,
                    defaultTimeoutSeconds: $meta['config']['defaultTimeoutSeconds'] ?? 600,
                    allowPeerMessaging: $meta['config']['allowPeerMessaging'] ?? true,
                    autoAssignTasks: $meta['config']['autoAssignTasks'] ?? true,
                    inboxPath: $meta['config']['inboxPath'] ?? '~/.sugar-crush/teams/',
                )
                : new TeamConfig();

            // Re-hydrate without re-creating task/mailbox storage
            $team = new Team(
                id: $teamId,
                name: $meta['name'],
                leadAgentId: $meta['leadAgentId'],
                createdAt: new \DateTimeImmutable($meta['createdAt']),
                maxTeammates: $config->maxTeammates,
            );

            $this->teams[$teamId] = $team;
            $this->teamConfigs[$teamId] = $config;
        }
    }

    /**
     * Read and decode the raw registry JSON from disk.
     *
     * Returns an empty array if the file does not exist, is empty,
     * or contains malformed JSON. Decoding still fails safe to a reset —
     * the manager cannot trust a file it cannot read — but audit M2's
     * "silent loss" half is closed two ways: atomic publishing (see
     * {@see saveRegistry()}) means a decode failure can no longer come from
     * OUR torn write, and a foreign-corrupted file is now QUARANTINED to
     * `registry.json.corrupt-<epoch>` instead of being left in place for the
     * next save to overwrite, so the bytes stay recoverable by hand.
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadRegistryData(): array
    {
        if (!file_exists($this->registryPath)) {
            return [];
        }

        $content = file_get_contents($this->registryPath);
        if ($content === false || $content === '') {
            return [];
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->quarantineCorruptRegistry();

            return [];
        }

        if (!is_array($data)) {
            $this->quarantineCorruptRegistry();

            return [];
        }

        return $data;
    }

    /**
     * Move an undecodable registry aside instead of leaving it underfoot.
     *
     * Best-effort: if the rename itself fails (read-only dir), the reset
     * proceeds anyway — keeping the process alive is worth more here than
     * guaranteeing the forensics, and the save path will loudly throw if the
     * directory truly is unwritable.
     */
    private function quarantineCorruptRegistry(): void
    {
        $stamp = date('Ymd-His');
        $target = $this->registryPath . '.corrupt-' . $stamp;
        // Suffix on collision: two loads inside one second (a reload loop)
        // must not have the second quarantine silently overwrite the first.
        if (file_exists($target)) {
            $target .= '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        }

        @rename($this->registryPath, $target);
    }

    /**
     * Persist the current team registry to disk.
     *
     * Writes the full in-memory team map to registry.json, creating
     * the parent directory if it does not already exist.
     *
     * @throws \RuntimeException When the file cannot be written.
     */
    private function saveRegistry(): void
    {
        // No manual mkdir here: AtomicJsonFile::write() creates the parent
        // itself, at 0700 derived from the requested file mode (the pre-M2
        // 0755 came from this line).
        $data = [];
        foreach ($this->teams as $teamId => $team) {
            $config = $this->teamConfigs[$teamId] ?? new TeamConfig();
            $data[$teamId] = [
                'name' => $team->name,
                'leadAgentId' => $team->leadAgentId,
                'createdAt' => $team->createdAt->format(\DateTimeImmutable::ATOM),
                'config' => [
                    'maxTeammates' => $config->maxTeammates,
                    'defaultTimeoutSeconds' => $config->defaultTimeoutSeconds,
                    'allowPeerMessaging' => $config->allowPeerMessaging,
                    'autoAssignTasks' => $config->autoAssignTasks,
                    'inboxPath' => $config->inboxPath,
                ],
            ];
        }

        // Audit M2: the pre-fix write was a direct file_put_contents(LOCK_EX)
        // onto the live path — a crash mid-write tore the registry, and the
        // loader read any torn file as a FULL reset, silently losing every
        // team. candy-core's AtomicJsonFile is this package's canonical answer
        // (adopted at Session.php for exactly this reason): full payload into
        // a same-dir temp, mode settled before the first byte, one rename to
        // publish. 0600 because the registry names teams, leads and inbox
        // paths — nothing a co-tenant should read.
        try {
            AtomicJsonFile::new($this->registryPath)
                ->withPermissions(0600)
                ->write($data);
        } catch (\Throwable $e) {
            // Re-thrown with the registry's own vocabulary (the message is
            // pinned by TeamManagerTest): the underlying failure names a temp
            // the caller has never seen.
            throw new \RuntimeException(
                sprintf('Failed to write registry to "%s": %s', $this->registryPath, $e->getMessage()),
                0,
                $e,
            );
        }
    }

    /**
     * Expand ~ to the server's HOME directory and validate the path.
     *
     * @param string $path A path that may begin with ~ (will be expanded).
     * @return string The expanded, absolute path.
     * @throws \InvalidArgumentException When the path contains "..".
     */
    private function expandPath(string $path): string
    {
        if (str_contains($path, '..')) {
            throw new \InvalidArgumentException(
                sprintf('Path must not contain "..": %s', $path),
            );
        }

        if (str_starts_with($path, '~/')) {
            $home = HomeDirectory::path();
            $path = $home . '/' . substr($path, 2);
        }

        return $path;
    }
}
