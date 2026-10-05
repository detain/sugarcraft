<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

use SugarCraft\Core\Util\AtomicJsonFile;
use SugarCraft\Crush\Hooks\HookDispatcher;
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
 *
 * THE REGISTRY ON DISK IS THE TRUTH, not this object (roadmap 4.6-2). The
 * `Team` tool runs in a turn's forked child, a background teammate in its own
 * daemon, and the launch's {@see AgentManager} holds a third instance, so a
 * team one of them creates must be visible to the others. Every lookup that
 * misses, every listing and every write re-reads `registry.json` first, and a
 * write publishes the re-read map plus its own change — so two processes that
 * each create a team keep both, instead of the second save erasing the first.
 * (The re-read and the publish are not one locked step: two writes landing in
 * the same instant can still lose one, which a later create of the same id
 * repairs. Team creation runs at human rate.)
 *
 * CONSTRUCTION DOES NO I/O, and registered teams are built lazily. A `Team`
 * opens its SQLite task list when constructed, and the launch builds this
 * manager in the host process before any turn forks: building every
 * registered team there would hand each forked child an SQLite connection
 * opened by its parent, which SQLite forbids. So the host reads nothing
 * until asked, and a team is constructed in the process that uses it.
 */
final class TeamManager
{
    /** @var array<string, Team> teams already constructed in this process */
    private array $teams = [];

    /**
     * Registered team metadata, keyed by team ID — what the registry holds.
     *
     * @var array<string, array{name: string, leadAgentId: string, createdAt: string}>
     */
    private array $meta = [];

    /** @var array<string, TeamConfig> keyed by team ID */
    private array $teamConfigs = [];

    private readonly string $registryPath;

    /**
     * The launch's hook chain and project root — see {@see useLaunchHooks()}.
     */
    private static ?HookDispatcher $launchHooks = null;

    private static ?string $launchRoot = null;

    /**
     * @param ?HookDispatcher $hooks       the chain every team's task list
     *        raises `TaskCreated`, `TaskCompleted` and `TeammateIdle` through;
     *        null is the launch's ({@see useLaunchHooks()}), when there is one
     * @param ?string         $projectRoot where those hooks run; null is the
     *        launch's root, then the process directory
     */
    public function __construct(
        private readonly string $basePath = '~/.sugar-crush/teams',
        private readonly ?HookDispatcher $hooks = null,
        private readonly ?string $projectRoot = null,
    ) {
        $this->registryPath = $this->expandPath($this->basePath) . '/registry.json';
    }

    /**
     * Make $hooks the chain every team in this process raises its task events
     * through, run in $root (roadmap 4.6-2).
     *
     * {@see \SugarCraft\Crush\Cli\Bootstrap::hooks()} calls this with the launch's
     * own chain — the hook files, built-ins and permission gate a tool call is
     * judged by — so a `TaskCreated` entry in `hooks.yaml` reaches the `Team`
     * tool's task list the same way a `PreToolUse` entry reaches a tool call.
     * A process-wide default rather than a constructor argument threaded
     * through the tool catalog because the team store is opened per call, in
     * whichever process runs it (a turn's forked child inherits this; a
     * background teammate's daemon builds its own launch and calls this
     * itself), and the chain is the launch's, not any one caller's. A manager
     * handed a chain of its own keeps it.
     */
    public static function useLaunchHooks(?HookDispatcher $hooks, ?string $root = null): void
    {
        self::$launchHooks = $hooks;
        self::$launchRoot = $root;
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
        $this->refresh();
        if (isset($this->meta[$teamId])) {
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
            hookDispatcher: $this->hooks ?? self::$launchHooks,
            projectRoot: $this->projectRoot ?? self::$launchRoot,
        );

        $this->teams[$teamId] = $team;
        $this->meta[$teamId] = [
            'name' => $name,
            'leadAgentId' => $leadAgentId,
            'createdAt' => $team->createdAt->format(\DateTimeImmutable::ATOM),
        ];
        $this->teamConfigs[$teamId] = $config;
        $this->saveRegistry();

        return $team;
    }

    // -------------------------------------------------------------------------
    // Registry accessors
    // -------------------------------------------------------------------------

    /**
     * Return all registered teams, as the registry on disk has them now.
     *
     * @return Team[]
     */
    public function getTeams(): array
    {
        $this->refresh();

        $teams = [];
        foreach (array_keys($this->meta) as $teamId) {
            $teams[] = $this->build((string) $teamId);
        }

        return $teams;
    }

    /**
     * Fetch a team by its ID — re-reading the registry when this process has
     * not seen it, so a team another process created is found.
     */
    public function getTeam(string $teamId): ?Team
    {
        if (!isset($this->meta[$teamId])) {
            $this->refresh();
        }

        return isset($this->meta[$teamId]) ? $this->build($teamId) : null;
    }

    /**
     * Fetch the config for a given team.
     */
    public function getTeamConfig(string $teamId): ?TeamConfig
    {
        if (!isset($this->meta[$teamId])) {
            $this->refresh();
        }

        return $this->teamConfigs[$teamId] ?? null;
    }

    /**
     * Return the number of currently registered teams.
     */
    public function teamCount(): int
    {
        $this->refresh();

        return count($this->meta);
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
     * The `Team` tool's `claim` with no task named is this method (roadmap
     * 4.6-2), which is what gives it a production caller. $ownerPid is the
     * process the claim is recorded against for crash recovery
     * ({@see TaskList::releaseOrphanedClaims()}); null records this one.
     *
     * A `TeammateIdle` hook that blocks hands the teammate nothing this time;
     * its reason comes back in $refusal (empty when it gave none), so the
     * caller can tell "the hook said wait" from "the board is empty".
     *
     * @param-out ?string $refusal
     * @return string|null The ID of the task just claimed, or null if the team/teammate
     *                      was not found, the hook blocked, or no unblocked task exists.
     */
    public function handleTeammateIdle(string $teamId, string $teammateId, ?int $ownerPid = null, ?string &$refusal = null): ?string
    {
        $refusal = null;
        $team = $this->getTeam($teamId);
        if ($team === null) {
            return null;
        }

        $taskList = $team->getTaskList();

        $hookResult = $taskList->dispatchTeammateIdle($teamId, $teammateId);
        if ($hookResult->isBlock()) {
            $refusal = $hookResult->message;

            return null;
        }

        foreach ($taskList->getUnblockedTasks($teammateId) as $task) {
            if ($taskList->claimTask($task->id, $teammateId, null, $ownerPid)) {
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
        if (!isset($this->meta[$teamId])) {
            $this->refresh();
        }

        return isset($this->meta[$teamId]);
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
        $this->refresh();
        if (!isset($this->meta[$teamId])) {
            return null;
        }

        $team = $this->build($teamId);
        unset($this->teams[$teamId], $this->meta[$teamId], $this->teamConfigs[$teamId]);
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
        $this->refresh();
        if (!isset($this->meta[$teamId])) {
            return null;
        }

        // A fresh instance over the on-disk state, not the one this process
        // may already hold.
        unset($this->teams[$teamId]);

        return $this->build($teamId);
    }

    // -------------------------------------------------------------------------
    // Persistence
    // -------------------------------------------------------------------------

    /**
     * Re-read the registry from disk and make it this manager's view.
     *
     * Teams no longer registered are forgotten; teams this process already
     * constructed and still registered keep their instance (and its in-memory
     * teammate roster). Entries missing a required field are skipped.
     */
    private function refresh(): void
    {
        $meta = [];
        $configs = [];
        foreach ($this->loadRegistryData() as $teamId => $entry) {
            if (!\is_array($entry) || !isset($entry['name'], $entry['leadAgentId'], $entry['createdAt'])) {
                continue;
            }

            $teamId = (string) $teamId;
            $meta[$teamId] = [
                'name' => (string) $entry['name'],
                'leadAgentId' => (string) $entry['leadAgentId'],
                'createdAt' => (string) $entry['createdAt'],
            ];
            $config = \is_array($entry['config'] ?? null) ? $entry['config'] : null;
            $configs[$teamId] = $config === null
                ? new TeamConfig()
                : new TeamConfig(
                    maxTeammates: (int) ($config['maxTeammates'] ?? 5),
                    defaultTimeoutSeconds: (int) ($config['defaultTimeoutSeconds'] ?? 600),
                    allowPeerMessaging: (bool) ($config['allowPeerMessaging'] ?? true),
                    autoAssignTasks: (bool) ($config['autoAssignTasks'] ?? true),
                    inboxPath: (string) ($config['inboxPath'] ?? '~/.sugar-crush/teams/'),
                );
        }

        $this->meta = $meta;
        $this->teamConfigs = $configs;
        $this->teams = array_intersect_key($this->teams, $meta);
    }

    /**
     * The Team for a registered ID, constructed on first use in this process.
     */
    private function build(string $teamId): Team
    {
        if (isset($this->teams[$teamId])) {
            return $this->teams[$teamId];
        }

        $meta = $this->meta[$teamId];
        $config = $this->teamConfigs[$teamId] ?? new TeamConfig();

        return $this->teams[$teamId] = new Team(
            id: $teamId,
            name: $meta['name'],
            leadAgentId: $meta['leadAgentId'],
            createdAt: new \DateTimeImmutable($meta['createdAt']),
            maxTeammates: $config->maxTeammates,
            hookDispatcher: $this->hooks ?? self::$launchHooks,
            projectRoot: $this->projectRoot ?? self::$launchRoot,
        );
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
        // is_file, not file_exists: a directory squatting on the path is no
        // registry, and reading one only raises a notice.
        if (!is_file($this->registryPath)) {
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
     * Writes the registered-team map to registry.json, creating the parent
     * directory if it does not already exist. Callers {@see refresh()} first,
     * so the map written is the disk's plus their own change.
     *
     * @throws \RuntimeException When the file cannot be written.
     */
    private function saveRegistry(): void
    {
        // No manual mkdir here: AtomicJsonFile::write() creates the parent
        // itself, at 0700 derived from the requested file mode (the pre-M2
        // 0755 came from this line).
        $data = [];
        foreach ($this->meta as $teamId => $meta) {
            $config = $this->teamConfigs[$teamId] ?? new TeamConfig();
            $data[$teamId] = [
                'name' => $meta['name'],
                'leadAgentId' => $meta['leadAgentId'],
                'createdAt' => $meta['createdAt'],
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
