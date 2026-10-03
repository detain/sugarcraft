<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host;

use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\AgentPoolConfig;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Commands\CommandLoader;
use SugarCraft\Crush\Context\RulesState;
use SugarCraft\Crush\Diagnostics\NoticeSink;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionStore;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Workflows\WorkflowEngineInterface;

/**
 * Everything a session needs from its project root that is not a screen: the
 * non-UI half of {@see Bootstrap::chat()}, built once per root by
 * {@see Bootstrap::workspace()} (roadmap O-2a, Appendix O §4.3).
 *
 * WHY IT EXISTS. `Bootstrap::chat()` built the launch's config, gate, skill
 * registry, command loader, `/rules` set, agent manager, Task pool, memory
 * store, hook chain, workflow engine, side backends and the runtime-notice
 * inbox as locals, and the only thing that ever held them together was the
 * `Chat` they were passed into one by one. A host that runs sessions without a
 * TUI — the server Appendix O designs — needs exactly that bundle and none of
 * the screen. This is that bundle, and it is the first strangler step: `Chat`
 * still receives every collaborator as it did, and additionally holds this, so
 * the `Host\*` services O-2b…O-2h extract can be reached from `Chat` without
 * growing its constructor again.
 *
 * {@see service()} IS HOW THEY ARE REACHED. Each later step registers its
 * service here ({@see withService()}) where the workspace is built, and `Chat`
 * reads it with `$this->workspace?->service(TranscriptStore::class)` — one
 * constructor parameter for the whole extraction rather than one per service,
 * which is the condition the roadmap set on O-2a. A service keyed by a class or
 * interface name is type-checked on registration, so a miswired locator fails
 * at launch rather than at the first call.
 *
 * {@see backendFor()} THREADS THE AGENT MANAGER, ALWAYS. Appendix A's `/model`
 * bug was a provider switch rebuilding its engine from the root and the gate
 * alone, which silently dropped `Task` (no manager) and the session's `/rules`
 * toggles (no set). N-P3a fixed it with a factory closure built inside
 * `Bootstrap::chat()`; that closure now lives here, and a workspace built
 * without one still falls back to a construction that carries this
 * workspace's manager, pool and set — so no host built on this type can
 * inherit the bug.
 *
 * IMMUTABLE AND FLUENT like every value in this package; the collaborators it
 * holds are shared by identity, which is the point — one gate, one manager,
 * one `/rules` set and one notice inbox per root.
 */
final class WorkspaceContext
{
    /**
     * @param array<string, mixed> $userConfig the merged layered config this workspace was built from
     * @param array<string, object> $services see {@see service()}
     */
    private function __construct(
        public readonly ?string $root,
        public readonly array $userConfig,
        public readonly NoticeSink $notices,
        public readonly RulesState $rulesState,
        public readonly SessionStore|EnhancedSessionStore|null $sessionStore,
        public readonly ?SkillRegistry $skills,
        public readonly ?PermissionGate $permissionGate,
        public readonly ?CommandLoader $commandLoader,
        public readonly bool $projectCommandsTrusted,
        public readonly ?AgentManager $agentManager,
        public readonly ?AgentPoolConfig $agentPoolConfig,
        public readonly ?AgentWorkerPool $taskPool,
        public readonly ?Backend $backend,
        public readonly ?MemoryStore $memoryStore,
        public readonly ?HookManager $hooks,
        public readonly ?Backend $titleBackend,
        public readonly ?Backend $summaryBackend,
        public readonly ?float $maxCostUsd,
        public readonly ?WorkflowEngineInterface $workflowEngine,
        public readonly ?BackgroundSupervisor $backgroundSupervisor,
        private readonly ?\Closure $backendFactory,
        private readonly array $services,
    ) {
    }

    /**
     * Build a workspace. Every collaborator is optional, the way every `Chat`
     * collaborator is, so an embedder or a test names only what it has.
     *
     * @param array<string, mixed> $userConfig
     * @param NoticeSink|null $notices this workspace's runtime-notice inbox;
     *        null takes the one {@see RuntimeNoticeSink::current()} names now,
     *        which in a one-session process is the process sink
     * @param \Closure(string): Backend|null $backendFactory how {@see backendFor()}
     *        builds a provider's backend; null builds it from this workspace's
     *        own collaborators
     */
    public static function new(
        ?string $root = null,
        array $userConfig = [],
        ?NoticeSink $notices = null,
        ?RulesState $rulesState = null,
        SessionStore|EnhancedSessionStore|null $sessionStore = null,
        ?SkillRegistry $skills = null,
        ?PermissionGate $permissionGate = null,
        ?CommandLoader $commandLoader = null,
        bool $projectCommandsTrusted = false,
        ?AgentManager $agentManager = null,
        ?AgentPoolConfig $agentPoolConfig = null,
        ?AgentWorkerPool $taskPool = null,
        ?Backend $backend = null,
        ?\Closure $backendFactory = null,
        ?MemoryStore $memoryStore = null,
        ?HookManager $hooks = null,
        ?Backend $titleBackend = null,
        ?Backend $summaryBackend = null,
        ?float $maxCostUsd = null,
        ?WorkflowEngineInterface $workflowEngine = null,
        ?BackgroundSupervisor $backgroundSupervisor = null,
    ): self {
        return new self(
            root: $root,
            userConfig: $userConfig,
            notices: $notices ?? RuntimeNoticeSink::current(),
            rulesState: $rulesState ?? RulesState::new(),
            sessionStore: $sessionStore,
            skills: $skills,
            permissionGate: $permissionGate,
            commandLoader: $commandLoader,
            projectCommandsTrusted: $projectCommandsTrusted,
            agentManager: $agentManager,
            agentPoolConfig: $agentPoolConfig,
            taskPool: $taskPool,
            backend: $backend,
            memoryStore: $memoryStore,
            hooks: $hooks,
            titleBackend: $titleBackend,
            summaryBackend: $summaryBackend,
            maxCostUsd: $maxCostUsd,
            workflowEngine: $workflowEngine,
            backgroundSupervisor: $backgroundSupervisor,
            backendFactory: $backendFactory,
            services: [],
        );
    }

    /**
     * The backend a provider switch (`/model <provider>`, Ctrl+P "Switch
     * model") runs on, built from this workspace's collaborators.
     *
     * With a factory — {@see Bootstrap::workspace()} always passes one — the
     * factory decides. Without one, {@see Bootstrap::backendFor()} is handed the
     * root, the skill registry, the gate, the `/rules` set, the agent manager
     * and the Task pool, and the engine it returns reads that same set: the
     * N-P3a contract, kept on the embedder path too.
     *
     * @throws \Throwable whatever the provider construction throws; a caller
     *                    that named a provider explicitly should see why it
     *                    could not have it
     */
    public function backendFor(string $provider): Backend
    {
        if ($this->backendFactory !== null) {
            return ($this->backendFactory)($provider);
        }

        $backend = Bootstrap::backendFor(
            $provider,
            $this->root,
            $this->skills,
            $this->permissionGate,
            rulesState: $this->rulesState,
            taskManager: $this->agentManager,
            taskPool: $this->taskPool,
        );

        return $backend instanceof EngineBackend ? $backend->withRulesState($this->rulesState) : $backend;
    }

    /**
     * `\Closure(string $provider): Backend` over {@see backendFor()} — the shape
     * `Chat`'s N-P3a `backendFactory` parameter takes.
     */
    public function backendFactory(): \Closure
    {
        return $this->backendFor(...);
    }

    /**
     * A copy with `$service` registered under `$id`.
     *
     * `$id` is normally the service's class or interface name, and then the
     * service must be an instance of it — a locator that hands back the wrong
     * type fails here, at the composition root, instead of at whichever call
     * site first trusts the type. Any other string is an opaque key.
     *
     * @throws \InvalidArgumentException for an empty id, or a service that is
     *                                   not an instance of the class it is
     *                                   registered as
     */
    public function withService(string $id, object $service): self
    {
        if ($id === '') {
            throw new \InvalidArgumentException('A workspace service needs a non-empty id; use its class name.');
        }

        if ((class_exists($id) || interface_exists($id)) && !$service instanceof $id) {
            throw new \InvalidArgumentException(sprintf(
                'Workspace service "%s" must be an instance of it; got %s.',
                $id,
                $service::class,
            ));
        }

        return $this->mutate(['services' => [...$this->services, $id => $service]]);
    }

    /**
     * The service registered under `$id`, or null when none is — the locator
     * the O-2b…O-2h extractions use so `Chat` gains no constructor state per
     * service. Null rather than a throw because every `Chat` collaborator is
     * optional and degrades politely; a caller that cannot work without one
     * says so in its own words.
     *
     * @template T of object
     *
     * @param class-string<T>|string $id
     *
     * @return ($id is class-string<T> ? T|null : object|null)
     */
    public function service(string $id): ?object
    {
        return $this->services[$id] ?? null;
    }

    /** Whether a service is registered under `$id`. */
    public function hasService(string $id): bool
    {
        return isset($this->services[$id]);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): self
    {
        return new self(...array_merge([
            'root' => $this->root,
            'userConfig' => $this->userConfig,
            'notices' => $this->notices,
            'rulesState' => $this->rulesState,
            'sessionStore' => $this->sessionStore,
            'skills' => $this->skills,
            'permissionGate' => $this->permissionGate,
            'commandLoader' => $this->commandLoader,
            'projectCommandsTrusted' => $this->projectCommandsTrusted,
            'agentManager' => $this->agentManager,
            'agentPoolConfig' => $this->agentPoolConfig,
            'taskPool' => $this->taskPool,
            'backend' => $this->backend,
            'memoryStore' => $this->memoryStore,
            'hooks' => $this->hooks,
            'titleBackend' => $this->titleBackend,
            'summaryBackend' => $this->summaryBackend,
            'maxCostUsd' => $this->maxCostUsd,
            'workflowEngine' => $this->workflowEngine,
            'backgroundSupervisor' => $this->backgroundSupervisor,
            'backendFactory' => $this->backendFactory,
            'services' => $this->services,
        ], $changes));
    }
}
