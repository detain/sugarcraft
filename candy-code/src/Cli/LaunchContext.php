<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

use SugarCraft\Code\Background\BackgroundShellRegistry;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Config\LayeredSettings;
use SugarCraft\Code\Bundles\Bundle;
use SugarCraft\Code\LSP\LspClient;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpToolShape;
use SugarCraft\Code\Tools\ReadLedger;
use SugarCraft\Code\Session\PromptHistory;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Background\BackgroundSupervisor;
use SugarCraft\Code\Context\InstructionFileLoader;
use SugarCraft\Code\Context\RulesState;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Context\RuleLoader;
use SugarCraft\Code\Providers\EchoProvider;
use SugarCraft\Code\Providers\ProviderFactory;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Support\ProjectRoot;
use SugarCraft\Code\Support\ToolIpcFiles;
use SugarCraft\Code\Memory\MemoryStore;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Tui\Pane;
use SugarCraft\Code\App\PaneAutoShow;
use SugarCraft\Constraints\Dock\DockLayout;
use SugarCraft\Code\Context\RulePathNudge;
use SugarCraft\Code\Skills\SkillPathNudge;
use SugarCraft\Code\Support\ProcessContainment;
use SugarCraft\Code\Tools\BuiltIn\Bash;
use SugarCraft\Code\Tools\BuiltIn\TaskTool;
use SugarCraft\Code\Tools\Tool;

/**
 * The launch-context shelf: the process-wide stores that the launch wiring
 * fills — what `bin/candy-code` registered from argv and environment — and
 * that the composition roots read thousands of lines and several objects away.
 *
 * WHY THIS CLASS EXISTS. Until this split each value lived as its own private
 * static on {@see Bootstrap}, under a doc-block that explained what writes it
 * and why a parameter will not do; forty-one of them made the top of that file
 * a second file in disguise. The stores have one contract among them
 * (below), and it reads better as a named collaborator than as paragraphs in a
 * twelve-thousand-line composition root. Bootstrap keeps thin delegates with
 * the same public surface — the `useX()` registration seams and the readers —
 * so every caller (`bin/candy-code`, `ArgvParser`, `Subcommands`,
 * `NonInteractive`) and every census that reaches launch state through
 * Bootstrap keeps reaching it there.
 *
 * THE TEST CONTRACT, transferred with the state: these statics live for the
 * whole process and are NOT reset between test cases or classes. Clear what
 * you set — {@see Bootstrap}'s `Bootstrap::useModel()` doc-block names the pattern, and
 * `Tests\Cli\LaunchFlagsTest` is the exemplar.
 *
 * WHAT DOES NOT LIVE HERE. The launch-warning shelf ({@see LaunchNotices}),
 * the MCP config-decision path ({@see McpDecision}), and every judgement that
 * reads these values alongside settings or trust — {@see Bootstrap}'s
 * resolvers stay where their other inputs are; they read this class instead of
 * a private static of their own.
 */
final class LaunchContext
{

    /**
     * The config FILE `--config` named, or null to discover
     * `~/.candy-code/config.json` — see {@see useConfigPath()}.
     */
    private static ?string $configPathOverride = null;

    /**
     * The model `--model` named, or null to fall back to `$CANDY_CODE_MODEL`
     * and then the provider default — see {@see useModel()}.
     */
    private static ?string $modelOverride = null;

    /**
     * Where `bin/candy-code`'s `TuiErrorLog::install()` sent `error_log()` for
     * this TUI run, or null when it left the operator's own destination in
     * place — see {@see useTuiErrorLog()}.
     */
    private static ?string $tuiErrorLog = null;

    /**
     * Which session a TUI launch opens — see {@see useSessionLaunch()}.
     * 'new' (the default), 'continue', or 'resume'.
     */
    private static string $sessionLaunchMode = 'new';

    /** The id or name `--resume` named; null with 'resume' opens the picker. */
    private static ?string $sessionLaunchTarget = null;

    /**
     * Where the ↑/↓ prompt history lives, when something other than
     * `~/.candy-code/prompt_history.jsonl` — see {@see pinPromptHistoryPath()}.
     */
    private static ?string $promptHistoryPath = null;

    /**
     * The RAW, UNVALIDATED string `--permission-mode` named, or null when the
     * flag was absent — see {@see usePermissionMode()} for why it is stored
     * unvalidated.
     */
    private static ?string $permissionModeOverride = null;

    /**
     * The RAW, UNVALIDATED string `--max-cost` named, or null when the flag
     * was absent — see {@see useMaxCost()} for why it is stored unvalidated.
     */
    private static ?string $maxCostOverride = null;

    /**
     * The RAW string `--max-turns` named, or null. Registered here, judged in
     * this resolver region — the flag exists only to speak into
     * {@see Bootstrap::resolvedMaxToolSteps()}. {@see useMaxTurns()} for why it is
     * stored RAW; {@see Bootstrap::maxTurns()} for the judgment.
     */
    private static ?string $maxTurnsOverride = null;

    /**
     * Point every reader of the per-user `config.json` at $path instead of the
     * discovered `~/.candy-code/config.json` — what `--config <path>` does.
     *
     * PROCESS-WIDE STATIC, not a parameter, because this class is a static
     * facade whose config readers sit five and six calls below the two entry
     * points `bin/candy-code` calls ({@see Bootstrap::app()} and, through
     * {@see NonInteractive::run()}, {@see Bootstrap::backend()}): threading a path
     * through every one of them would touch far more of this file than the
     * flag is worth, and would still leave {@see Bootstrap::readUserConfig()}'s other
     * callers — `EngineBackend`'s per-turn dispatch settings among them —
     * reading the discovered file while the rest of the process read the
     * chosen one. `bin/candy-code` sets it once, after
     * {@see ArgvParser::configError()} has established the file is readable
     * and before either dispatch. Tests must reset it with null.
     *
     * WHAT IT DOES NOT MOVE, stated exactly: this names one FILE. The agents/,
     * skills/, workflows/ and hooks trees, the session store and the memory
     * store all still resolve off `~/.candy-code`. Nor does it relax the home
     * -ownership gate — {@see Bootstrap::permissionConfig()} still calls
     * {@see Bootstrap::trustedConfigDirPath()} for its throw before honouring the
     * override, so a process that cannot establish whose home it is refuses to
     * start whether or not a config file was named on the command line.
     */
    public static function useConfigPath(?string $path): void
    {
        self::$configPathOverride = $path;
    }

    /**
     * Register `--model <name>` for this process.
     *
     * A PROCESS-WIDE STATIC for the same reason {@see useConfigPath()} is one,
     * and deliberately following that precedent rather than inventing a third
     * mechanism: `bin/candy-code` has THREE dispatch paths — `Subcommands`,
     * `NonInteractive::run()` and `Program`/{@see Bootstrap::app()} — and only the last
     * goes through {@see Bootstrap::chat()}. A flag threaded as a parameter into `chat()`
     * would silently do nothing for `candy-code -p "..."`, which enters at
     * {@see Bootstrap::backend()}/{@see Bootstrap::backendFor()} instead. One static registered
     * before either dispatch is read identically by all three.
     *
     * WHAT `--model` MEANS, because the surrounding code disagrees with itself
     * about the word: it names a MODEL, the same domain as `$CANDY_CODE_MODEL`
     * ("Model name (overrides provider default)" on the help screen), and it is
     * resolved by {@see Bootstrap::selectedModelName()}. It is NOT a provider. The Ctrl+P
     * palette's entry labelled "Switch model" calls
     * {@see Bootstrap::backendFor()}, whose first parameter is a PROVIDER name, and that
     * label is the reason this needed settling in writing: the two are
     * independent axes and this flag is the model one.
     *
     * No top-level `model` key backs it. The persisted choice is the per-provider
     * `models` map in {@see \SugarCraft\Code\Config\LayeredSettings::LAYERED_KEYS}
     * (decision D9), which this flag OUTRANKS for every provider — see
     * {@see Bootstrap::selectedModelName()}, where the flag, `$CANDY_CODE_MODEL` and that
     * map are resolved in one place.
     *
     * ## TEST ISOLATION — read this before calling either setter from a test
     *
     * This static and {@see usePermissionMode()}'s LIVE FOR THE WHOLE PROCESS
     * and are NOT reset between test cases or between test classes. A class
     * that sets one and does not clear it decides the launch of every test that
     * runs after it, in any file, for the rest of the suite.
     *
     * There is deliberately no automatic reset. That matches
     * {@see useConfigPath()}, which has behaved this way since it was written
     * and which `BootstrapConfigPathOverrideTest` and
     * `BootstrapLayeredSettingsTest` each clear in their own `tearDown()`.
     * Generalising the reset was considered and declined: it would mean a
     * shared trait or a suite-wide hook that, to be coherent, would have to
     * take over `useConfigPath()` too — a new mechanism for three statics whose
     * existing convention already works and is followed everywhere it applies.
     *
     * **So the convention is the contract: clear what you set.**
     * `Tests\Cli\LaunchFlagsTest` clears both in `setUp()` AND `tearDown()`,
     * which is the pattern to copy — the `setUp()` half also makes that class
     * immune to a leak from somewhere else.
     */
    public static function useModel(?string $model): void
    {
        self::$modelOverride = ($model === null || $model === '') ? null : $model;
    }

    /**
     * Register where the TUI's `error_log` redirect landed — {@see Bootstrap::app()}
     * passes on `TuiErrorLog::install()`'s answer from `bin/candy-code`.
     *
     * A REGISTRATION RATHER THAN A NOTICE RAISED IN THE BINARY because the
     * launch-notice list is the LAUNCH's: {@see Bootstrap::chat()} starts it empty, so a
     * row seeded before the Chat was built would be wiped before anyone read
     * it. {@see Bootstrap::reportTuiErrorLogFallback()} reads this at construction time,
     * beside the other launch reports. Null clears it, the
     * clear-what-you-set convention {@see useModel()} documents.
     */
    public static function useTuiErrorLog(?string $destination): void
    {
        self::$tuiErrorLog = ($destination === null || $destination === '') ? null : $destination;
    }

    /**
     * Register the session `-c`/`--continue` or `--resume` asked for, for this
     * process — the same process-wide seam as {@see useModel()}, and under the
     * same clear-what-you-set test contract documented there.
     *
     * With neither flag a launch opens a NEW session: two terminals must never
     * land on the same conversation now that each saves its transcript as it
     * changes, and "start fresh, continue on request" is what every comparable
     * agent CLI does. ↑ still reaches the previous session's prompts, because
     * recall reads the cross-session {@see PromptHistory}, not the transcript.
     */
    public static function useSessionLaunch(bool $continue, bool $resume = false, ?string $target = null): void
    {
        self::$sessionLaunchMode = $resume ? 'resume' : ($continue ? 'continue' : 'new');
        self::$sessionLaunchTarget = $resume && $target !== null && $target !== '' ? $target : null;
    }

    /**
     * Point the prompt history at $path instead of `~/.candy-code`, or back at
     * the default with null. For the test suite, which pins it into its sandbox
     * so a test that presses Enter on a {@see Bootstrap::chat()}-built Chat can never add
     * its prompt to the developer's own ↑ history.
     */
    public static function pinPromptHistoryPath(?string $path): void
    {
        self::$promptHistoryPath = $path;
    }

    /**
     * Register `--permission-mode <mode>` for this process. See
     * {@see useModel()} for why this is a static rather than a parameter.
     *
     * STORED RAW AND VALIDATED LATER, on purpose. {@see Bootstrap::permissionModeFrom()}
     * THROWS {@see PermissionConfigException} for a value that is not a mode,
     * and `bin/candy-code` catches that exception around its dispatch block —
     * but registration happens BEFORE that `try`, so validating here would let
     * the exception escape as an uncaught fatal instead of the exit-2 usage
     * error (with the JSON error document under `--output-format json`) that
     * every other bad permission value produces. Deferring the check to
     * {@see Bootstrap::permissionGate()} puts it inside the `try` and reuses the one
     * error message shape, which then names `--permission-mode` as its source
     * exactly as it names `$CANDY_CODE_PERMISSION_MODE` or the config file.
     *
     * DOCUMENTED CONSEQUENCE: a subcommand that never builds a gate (`completion`,
     * `--help`, `--version`) does not validate the value, so
     * `candy-code --permission-mode nonsense completion bash` succeeds. The flag
     * is inert on those paths anyway; failing them would cost a second, eager
     * validation site that could drift from this one.
     */
    public static function usePermissionMode(?string $mode): void
    {
        self::$permissionModeOverride = ($mode === null || $mode === '') ? null : $mode;
    }

    /**
     * Register `--max-cost <usd>` for this process. See {@see useModel()} for
     * why this is a static rather than a parameter, and
     * {@see usePermissionMode()} for why it is stored RAW: the number is
     * judged by {@see Bootstrap::maxCostUsd()}, which throws
     * {@see PermissionConfigException} inside `bin/candy-code`'s dispatch
     * `try` — where it becomes the same exit-2 usage error (with the JSON
     * error document under `--output-format json`) that an unusable
     * `$CANDY_CODE_MAX_COST` or `maxCostUsd` setting already produces —
     * rather than as an uncaught fatal at registration time.
     */
    public static function useMaxCost(?string $maxCost): void
    {
        self::$maxCostOverride = ($maxCost === null || $maxCost === '') ? null : $maxCost;
    }

    /**
     * Register `--max-turns <n>` for this process. See {@see useMaxCost()}
     * for why this is a static storing the RAW value: the number is judged
     * by {@see Bootstrap::maxTurns()}, which throws {@see PermissionConfigException}
     * where the dispatch can catch it — one-shot runs in
     * {@see NonInteractive::run()}'s own pre-flight try, the TUI through the
     * backend construction `bin/candy-code` wraps — so a ceiling that is
     * present and unusable refuses the launch with exit 2 rather than
     * silently running uncapped, exactly as a bad `--max-cost` does.
     */
    public static function useMaxTurns(?string $maxTurns): void
    {
        self::$maxTurnsOverride = ($maxTurns === null || $maxTurns === '') ? null : $maxTurns;
    }


    // -- readers ---------------------------------------------------------

    /** The config FILE `--config` named; null means discover. */
    public static function configPathOverride(): ?string
    {
        return self::$configPathOverride;
    }

    /** The model `--model` named; null means fall back. */
    public static function modelOverride(): ?string
    {
        return self::$modelOverride;
    }

    /** Where the TUI's `error_log` redirect landed; null means untouched. */
    public static function tuiErrorLog(): ?string
    {
        return self::$tuiErrorLog;
    }

    /** 'new' (the default), 'continue', or 'resume'. */
    public static function sessionLaunchMode(): string
    {
        return self::$sessionLaunchMode;
    }

    /** The id or name `--resume` named; null with 'resume' opens the picker. */
    public static function sessionLaunchTarget(): ?string
    {
        return self::$sessionLaunchTarget;
    }

    /** Where the ↑/↓ prompt history lives when the default is overridden. */
    public static function promptHistoryPath(): ?string
    {
        return self::$promptHistoryPath;
    }

    /** The RAW `--permission-mode` string; validation defers to the gate. */
    public static function permissionModeOverride(): ?string
    {
        return self::$permissionModeOverride;
    }

    /** The RAW `--max-cost` string; judgment defers to the resolver. */
    public static function maxCostOverride(): ?string
    {
        return self::$maxCostOverride;
    }

    /** The RAW `--max-turns` string; judgment defers to the resolver. */
    public static function maxTurnsOverride(): ?string
    {
        return self::$maxTurnsOverride;
    }
    // -----------------------------------------------------------------
    // Family B — service-memo stores (moved verbatim from Bootstrap in
    // the v4 S1b split; doc-blocks travel with the stores they document).
    // -----------------------------------------------------------------

    /**
     * The MCP clients whose servers are running, keyed FIRST by the pid that
     * started them and then by the {@see mcpConfigMemoKey()} of the decision
     * that built them — the `.mcp.json` path, and since MCP-3 the user tier's
     * path joined ahead of it when a user file is live.
     *
     * MEMOIZED BECAUSE STARTING IS A SIDE EFFECT. One launch calls
     * {@see tools()} at least twice — {@see app()} once for the shell's tool
     * list and once more through {@see chat()} -> {@see backend()} — and a
     * Ctrl+P provider switch calls {@see backendFor()} again. Building a fresh
     * client each time would `proc_open()` every configured stdio server once
     * per call, so a session would accumulate duplicate third-party processes
     * for as long as it ran, and {@see stopMcpServers()} would only ever reach
     * the last set.
     *
     * KEYED BY PATH rather than a single slot: `chat($repoA)` followed by
     * `chat($repoB)` in one process is a supported shape (the tests do it), and
     * a single slot would hand repo B repo A's servers. The path is the
     * CANONICAL one — see {@see mcpClient()}, where four spellings of one root
     * used to make four clients and eight server processes.
     *
     * KEYED BY PID FIRST, because a `pcntl_fork()`ed child inherits this whole
     * map along with the rest of the parent's memory while the PROCESSES in it
     * remain the PARENT's. Two failures came out of a single flat map:
     *
     *  - a child exiting through PHP's normal shutdown ran the inherited hook and
     *    stopped the LIVE session's servers;
     *  - a child that started servers of its OWN — which
     *    {@see \SugarCraft\Code\Background\BackgroundSessionRunner::executeTask()}
     *    does, via `chdir()` -> {@see backend()} -> {@see tools()} — had them in
     *    a map the parent's {@see stopMcpServers()} never iterates, so they
     *    outlived the worker with nothing left to stop them.
     *
     * Both are the same bug: ownership was recorded once for the process that
     * happened to register first, not per client. The pid key IS the ownership
     * record, and {@see stopMcpServers()} stops exactly this pid's bucket.
     *
     * A SIDE EFFECT WORTH NAMING: a child that asks for the root its PARENT
     * already has a client for gets a MISS and starts its own servers. That is
     * correct rather than wasteful — the inherited client's pipes and process
     * handles are the parent's, and two processes taking turns reading one pipe is
     * a corrupted protocol stream, not a shared connection.
     *
     * @var array<int, array<string, McpClient>>
     */
    private static array $mcpClients = [];

    /**
     * MCP-7: the frozen bridge/dispatch split per launch config, keyed by pid
     * then by the same {@see mcpConfigMemoKey()} the client memo uses — one
     * shape per client, because a shape's dispatched roster must belong to
     * the client whose `callTool()` runs it. NEVER re-derived mid-session:
     * servers or tools that appear after the first read are adopted next
     * session, the E703-α rule for the model-facing roster made structural.
     * Absence is not memoised (no client yet must not freeze the decision),
     * so a shape appears exactly once per key and every later reader gets
     * that same object.
     *
     * @var array<int, array<string, McpToolShape>>
     */
    private static array $mcpToolShapes = [];

    /**
     * E703-α: the bytes each memoised client's config file had AT LAUNCH,
     * keyed by pid, then by the FILE path itself — since MCP-3 one entry per
     * tier, for exactly the tiers that launch was trusted to read. (The client
     * memo keys by the composite decision; these compare per file, because a
     * drift verdict is about a file.)
     *
     * WHY A DIGEST AND NOT A RELOAD: the trusted-roots list is read once per
     * process and frozen (see {@see trustedRootsForThisProcess()} and
     * `docs/MCP.md`), and that freeze is the security property, not an
     * inconvenience — reloading config in-session under a still-valid root
     * grant would let prompt-injected edits to `.mcp.json` reach
     * `proc_open()` with no fresh operator decision. So nothing re-applies the
     * change; this only lets the `/mcp` panel SAY the file has moved since the
     * launch that froze it, instead of the operator discovering it by diffing.
     *
     * Stored at the same instant the client is memoised, so "at launch" means
     * the same bytes the memo key was decided on. A read failure at that
     * instant stores NOTHING — the panel's line is then suppressed, which is
     * silence about the drift, never a claim about it.
     *
     * @var array<int, array<string, array{sha256: string, mtime: int}>>
     */
    private static array $mcpConfigDigests = [];

    /**
     * Whether this process IMAGE has registered the shutdown hook that stops the
     * servers in {@see $mcpClients}.
     *
     * A PLAIN BOOLEAN IS ENOUGH, and it is worth saying why, because it used to be
     * paired with a `$mcpOwnerPid` guard that made the hook a NO-OP in any forked
     * child — which is what left a worker's own servers with nothing to stop them.
     * The hook is inherited across `pcntl_fork()` along with the rest of memory,
     * and {@see stopMcpServers()} acts on `getmypid()`'s bucket of
     * {@see $mcpClients} and no other. So the inherited hook does exactly the
     * right thing in a child — stops what the CHILD started, cannot touch the
     * parent's — and the pid discrimination belongs on the map key, where the
     * ownership actually is, not on the registration.
     *
     * @see registerMcpShutdown() for why there is no other seam.
     */
    private static bool $mcpShutdownRegistered = false;

    /**
     * The launch's language servers, per process and per root — see
     * {@see lspClient()}. A null entry is a root with none configured (or none
     * that started), memoised so the config is read once.
     *
     * @var array<int, array<string, ?LspClient>>
     */
    private static array $lspClients = [];

    /** Whether {@see stopLspServers()} is already registered to run at exit. */
    private static bool $lspShutdownRegistered = false;

    /**
     * The launch's read ledgers, per process and per root — see
     * {@see readLedger()}.
     *
     * @var array<int, array<string, ReadLedger>>
     */
    private static array $readLedgers = [];

    /** Whether {@see stopBackgroundShells()} is already registered to run at exit. */
    private static bool $shellsShutdownRegistered = false;

    /**
     * @var array<int, array<string, BackgroundShellRegistry>>
     */
    private static array $shellRegistries = [];

    /** @var array<string, list<Bundle>> the memoized scans, keyed by root|pluginPaths */
    private static array $bundlesCache = [];

    /**
     * The canonical hash of the keybinding map the LIVE registry holds
     * (plan_comparison TUI-4a) — the applied-state twin of
     * {@see self::$mcpConfigDigests}. Null means "nothing noted", and the
     * first reload after that applies rather than claiming unchanged.
     */
    private static ?string $keybindingsAppliedDigest = null;


    // -----------------------------------------------------------------
    // Service-memo shelf (v4 S1b family B): the MCP/LSP/shell/ledger
    // memos, the bundles cache, and the applied-keybindings digest.
    // The stores and the shutdown managers moved here whole; the
    // builders that MEMOISE INTO them (Bootstrap::mcpClient(),
    // lspClient(), mcpToolShape(), bundles()) stay in Bootstrap with
    // the transcript-seam and inventory census that pins them, and
    // reach these shelves through the accessors below. Same
    // process-global lifecycle, same per-pid bucketing, same
    // ??= identity semantics — the objects stored are singletons by
    // ROOT, and a forked child inherits the parent's map, which is
    // why every bucket is keyed by getmypid().
    // -----------------------------------------------------------------

/**
     * Whether $memoKey already sits in this pid's client bucket (a memoised
     * NULL counts — presence is array_key_exists, not truthiness).
     */
    public static function hasMcpClient(int $pid, string $memoKey): bool
    {

        return array_key_exists($memoKey, self::$mcpClients[$pid] ?? []);
    }


/**
     * The client bucket's entry for $memoKey, or null.
     */
    public static function mcpClientFor(int $pid, string $memoKey): ?McpClient
    {

        return self::$mcpClients[$pid][$memoKey] ?? null;
    }


/**
     * Memoise the client built for $memoKey at its build instant — the same
     * moment that stores the config digest (MCP-3), so 'at launch' cannot
     * straddle two versions of the file.
     */
    public static function rememberMcpClient(int $pid, string $memoKey, ?McpClient $client): void
    {

        self::$mcpClients[$pid][$memoKey] = $client;
    }


/**
     * Hand back THIS pid's whole client bucket and drop it — the stop
     * seam's half of {@see stopMcpServers()}'s per-pid rule.
     */
    public static function takeMcpClientsBucket(int $pid): array
    {

        $bucket = self::$mcpClients[$pid] ?? [];
        unset(self::$mcpClients[$pid]);

        return $bucket;
    }


/**
     * Whether this pid already holds a derived shape for $memoKey.
     */
    public static function hasMcpToolShape(int $pid, string $memoKey): bool
    {

        return array_key_exists($memoKey, self::$mcpToolShapes[$pid] ?? []);
    }


/**
     * The memoised shape for $memoKey, or null.
     */
    public static function mcpToolShapeFor(int $pid, string $memoKey): ?McpToolShape
    {

        return self::$mcpToolShapes[$pid][$memoKey] ?? null;
    }


/**
     * Memoise the derived shape — the MCP-7 cache law: the roster derives
     * once per config memo key and never re-reads mid-session.
     */
    public static function rememberMcpToolShape(int $pid, string $memoKey, ?McpToolShape $shape): void
    {

        self::$mcpToolShapes[$pid][$memoKey] = $shape;
    }


/**
     * The launch's stored digest bytes for $tierPath, or null when this
     * launch stored none for it.
     */
    public static function mcpConfigDigestFor(int $pid, string $tierPath): ?array
    {

        return self::$mcpConfigDigests[$pid][$tierPath] ?? null;
    }


/**
     * Store the digest beside the client memo it was decided on. Public
     * because the builder that stores it is pinned by census to live in
     * Bootstrap and call THIS seam at the memo point — the store itself
     * stays private to this shelf.
     */
    public static function rememberMcpConfigDigest(int $pid, string $path, array $digest): void
    {

        self::$mcpConfigDigests[$pid][$path] = $digest;
    }


/**
     * Whether $root is already resolved in this pid's LSP bucket — a
     * memoised NULL counts (root with none configured).
     */
    public static function hasLspClient(int $pid, string $root): bool
    {

        return array_key_exists($root, self::$lspClients[$pid] ?? []);
    }


/**
     * The memoised client for $root, or null when none started.
     */
    public static function lspClientFor(int $pid, string $root): ?LspClient
    {

        return self::$lspClients[$pid][$root] ?? null;
    }


/**
     * Memoise the outcome for $root — including the null, so the config is
     * read once per process.
     */
    public static function rememberLspClient(int $pid, string $root, ?LspClient $client): void
    {

        self::$lspClients[$pid][$root] = $client;
    }


/**
     * Hand back THIS pid's whole LSP bucket and drop it.
     */
    public static function takeLspClientsBucket(int $pid): array
    {

        $bucket = self::$lspClients[$pid] ?? [];
        unset(self::$lspClients[$pid]);

        return $bucket;
    }


/**
     * This pid's ONE ledger for $root; $mint runs only on a miss (the
     * ??= semantics live here so the store stays behind the shelf).
     */
    public static function readLedgerFor(int $pid, string $root, \Closure $mint): ReadLedger
    {

        return self::$readLedgers[$pid][$root] ??= $mint();
    }


/**
     * This pid's ONE shell table for $root; $mint runs only on a miss.
     */
    public static function backgroundShellRegistryFor(int $pid, string $root, \Closure $mint): BackgroundShellRegistry
    {

        return self::$shellRegistries[$pid][$root] ??= $mint();
    }


/**
     * Whether the bundle scan for $key already ran this process.
     */
    public static function hasBundlesMemo(string $key): bool
    {

        return isset(self::$bundlesCache[$key]);
    }


/**
     * The memoised bundle list for $key.
     */
    public static function bundlesMemo(string $key): ?array
    {

        return self::$bundlesCache[$key] ?? null;
    }


/**
     * Store and return the scan result — `= $bundles` expression semantics.
     */
    public static function rememberBundlesMemo(string $key, array $bundles): array
    {

        return self::$bundlesCache[$key] = $bundles;
    }


/**
     * The digest of the keybinding map the LIVE registry holds, or null
     * when nothing was noted yet.
     */
    public static function keybindingsAppliedDigest(): ?string
    {

        return self::$keybindingsAppliedDigest;
    }


/**
     * Note the applied keybinding map digest (TUI-4a).
     */
    public static function rememberKeybindingsAppliedDigest(?string $digest): void
    {

        self::$keybindingsAppliedDigest = $digest;
    }


    /**
     * Stop every MCP server THIS PID started.
     *
     * PUBLIC because it is BOTH the shutdown hook's callback and the only way a
     * caller that owns a process lifetime (a test, an embedder) can hand the
     * servers back before exit. Idempotent: the client empties its own map, and
     * this empties this pid's bucket of ours.
     *
     * THIS PID'S BUCKET AND NO OTHER — see {@see self::$mcpClients}. A
     * `pcntl_fork()`ed child inherits the parent's whole map, so anything wider
     * than one bucket means a worker's ordinary exit SIGTERMs the live session's
     * servers. Other pids' buckets are left in place rather than dropped: the
     * child's copy of them is a copy, and dropping it in the child would say
     * something untrue about the parent's.
     */
    public static function stopMcpServers(): void
    {
        $pid = getmypid() ?: 0;
        $clients = self::$mcpClients[$pid] ?? [];
        unset(self::$mcpClients[$pid]);
        // Same bucket, same lifetime (E703-α): once this pid owns no client, a
        // launch digest for it describes no live memo, and the panel must not
        // compare against a fact whose subject is gone. A later mcpClient()
        // stores a fresh digest beside a fresh memo.
        unset(self::$mcpConfigDigests[$pid]);

        foreach ($clients as $client) {
            // Bounded by StdioMcpServer::stop()'s SIGTERM-then-9 escalation.
            // Wrapped anyway: this runs during shutdown, and a throw from the
            // first server would leave the rest running.
            try {
                $client->stopServers();
            } catch (\Throwable) {
                // Nothing to report to — stderr may already be gone at shutdown,
                // and there is no remedy left to offer.
            }
        }
    }

    /**
     * THE SHUTDOWN SEAM, and it had to be created: `bin/candy-code` runs
     * `Program::run()` (or {@see \SugarCraft\Code\Cli\NonInteractive::run()}) and then falls off the
     * end of the script, and a `grep` for `register_shutdown_function` across
     * `src/` and `bin/` found nothing at all. A `stopServers()` that is never
     * called leaves an orphaned third-party process per configured server after
     * every session, which is worse than never starting them.
     *
     * REGISTERED HERE rather than in `bin/candy-code` on purpose: this is the one
     * function that STARTS the servers, so start and stop cannot be wired apart.
     * Every entry point — the TUI, `-p` one-shot, an embedder calling
     * {@see Bootstrap::tools()} directly, a test — gets the stop for free, and nothing can
     * acquire the servers without it.
     *
     * ONCE PER PROCESS IMAGE, and a `pcntl_fork()`ed child needs no registration
     * of its own: it inherits this one, and the inherited hook is CORRECT in the
     * child because {@see stopMcpServers()} acts only on `getmypid()`'s bucket of
     * {@see $mcpClients}. So a child that starts servers of its own —
     * {@see \SugarCraft\Code\Background\BackgroundSessionRunner::executeTask()}
     * reaches {@see Bootstrap::tools()} after a `chdir()` — has them stopped when it exits,
     * while the live session's servers are untouched. Both halves of that are
     * measured in
     * {@see \SugarCraft\Code\Tests\Integration\McpToolWiringTest::testAForkedWorkerStopsItsOwnServersAndLeavesTheParentsAlone()}.
     *
     * WHAT IT COVERS: a normal return, any `exit()`, and an uncaught exception.
     * WHAT IT DOES NOT: `SIGKILL`, and any signal for which no PHP handler is
     * installed — a bare `SIGINT`/`SIGTERM` at the shell terminates the process
     * without running shutdown functions. That residue is not closed here because
     * closing it means installing signal handlers on the CLI's main path, which
     * is a decision about the TUI's own Ctrl+C handling and not about MCP. Nor
     * does it cover {@see \SugarCraft\Code\Support\ForkedChild::exitNow()},
     * which deliberately bypasses PHP's shutdown sequence — a child that took
     * that route and had started its own servers leaks them, and that is the
     * fork helper's contract rather than something this seam can override.
     */
    public static function registerMcpShutdown(): void
    {
        if (self::$mcpShutdownRegistered) {
            return;
        }

        self::$mcpShutdownRegistered = true;

        register_shutdown_function(static function (): void {
            self::stopMcpServers();
        });
    }

    /**
     * Stop the language servers THIS process started — the shutdown hook's
     * callback, and the way a caller owning a process lifetime (a test, an
     * embedder) hands them back early. This pid's bucket only, for the reason
     * {@see stopMcpServers()} gives. Idempotent.
     */
    public static function stopLspServers(): void
    {
        $pid = getmypid() ?: 0;
        $clients = self::$lspClients[$pid] ?? [];
        unset(self::$lspClients[$pid]);

        foreach ($clients as $client) {
            $client?->disconnectAll();
        }
    }

    /**
     * Register {@see stopLspServers()} to run at exit, once per process image,
     * BEFORE the first server starts — so nothing can acquire a server without
     * the stop. Same coverage and the same gaps as {@see registerMcpShutdown()}.
     */
    public static function registerLspShutdown(): void
    {
        if (self::$lspShutdownRegistered) {
            return;
        }

        self::$lspShutdownRegistered = true;

        register_shutdown_function(static function (): void {
            self::stopLspServers();
        });
    }

    /**
     * THE SHUTDOWN SEAM for background shells — same shape and same once-
     * per-process-image reasoning as {@see registerMcpShutdown()}: a forked
     * child inherits the hook, and {@see stopBackgroundShells()} acts only on
     * THIS pid's bucket, so a tool child's exit tears down the shells that
     * child itself spawned (none, in the ordinary case — the registry it
     * hands home is rows without handles) and never touches the parent's
     * live shells.
     */
    public static function registerBackgroundShellShutdown(): void
    {
        if (self::$shellsShutdownRegistered) {
            return;
        }

        self::$shellsShutdownRegistered = true;

        register_shutdown_function(static function (): void {
            self::stopBackgroundShells();
        });
    }

    /**
     * Stop every background shell THIS pid holds a handle to, and release
     * the per-pid bucket. PUBLIC for the shutdown-hook reason
     * {@see stopMcpServers()} states, and idempotent the same way: teardown
     * kills by recorded group, and the handle map empties with the bucket.
     *
     * THIS PID'S BUCKET AND NO OTHER — a child's inherited copy of the
     * parent's registries holds no handles (the parent spawned those
     * children), and {@see BackgroundShellRegistry::teardown()} only
     * re-signals rows it could observe as live, so the live session's shells
     * survive a worker's exit.
     */
    public static function stopBackgroundShells(): void
    {
        $pid = getmypid() ?: 0;
        $registries = self::$shellRegistries[$pid] ?? [];
        unset(self::$shellRegistries[$pid]);

        foreach ($registries as $registry) {
            try {
                $registry->teardown();
            } catch (\Throwable) {
                // Nothing to report to — stderr may already be gone at
                // shutdown, and there is no remedy left to offer.
            }
        }
    }



    // ---------------------------------------------------------------
    // Family C — skip/override/frontmatter report stores (v4 S1b split,
    // W7-D C4). The collectors and reporters stay in {@see Bootstrap}
    // (their prose sits inside the transcript-seam and launch-warning
    // censuses); only these process-wide memo stores and their drains
    // live here, so the "same skips must not print twice" ledger has
    // one named home. Accessors read; the extend/mark pair is the only
    // way in, mirroring exactly what the moved call sites did inline.
    // ---------------------------------------------------------------

    /**
     * Every SKILL.md the launch's skill scan gave up on, keyed by path — the
     * diagnostic {@see \SugarCraft\Code\Skills\SkillLoader::recordSkip()}
     * keeps instead of writing to stderr, hoisted here so it survives past the
     * manager that produced it. See {@see skillSkips()}.
     *
     * @var array<string, string>
     */
    private static array $skillSkips = [];
    /**
     * The subset of {@see $skillSkips} already put in front of the user — see
     * {@see reportSkillSkips()}.
     *
     * @var array<string, true>
     */
    private static array $reportedSkillSkips = [];
    /**
     * Memory note files already put in front of the user as unreadable, keyed
     * by path — see {@see reportMemorySkips()}. Process-wide for the reason
     * {@see $reportedSkillSkips} is.
     *
     * @var array<string, true>
     */
    private static array $reportedMemorySkips = [];
    /**
     * Every `*.md` command file this process's command walks could not read,
     * keyed by path (E172) — the `Bootstrap`-side twin of {@see $skillSkips},
     * fed by {@see chat()} from {@see \SugarCraft\Code\Commands\CommandLoader::skippedFiles()}
     * and surfaced by {@see reportCommandSkips()} as ONE aggregate row.
     *
     * HOISTED off the loader instance for the same reason {@see $skillSkips}
     * is hoisted off the skill manager: the object that produced the
     * diagnostic does not outlive the construction that ran it, and a
     * diagnostic nothing can reach after the fact is the same as no
     * diagnostic. PATH-KEYED, which is what makes
     * {@see reportCommandSkips()}'s once-per-path reporting work across a
     * process that builds more than one Chat.
     *
     * NOT a ninth feeder of {@see $projectTierRefusals}, deliberately: that
     * collector's readers print one line per entry, and the whole point of
     * this map is that command skips need a COUNT row instead (the per-entry
     * flood argument runs on {@see LAUNCH_NOTICE_LIMIT}).
     *
     * @var array<string, string> sourcePath => why it was skipped
     */
    private static array $commandSkips = [];
    /**
     * The subset of {@see $commandSkips} already put in front of the user —
     * process-scoped noise control, same contract as
     * {@see $reportedSkillSkips}.
     *
     * @var array<string, true>
     */
    private static array $reportedCommandSkips = [];
    /**
     * Which built-ins this process's command walks replaced, keyed by command
     * name (ISS-036) — the `Bootstrap`-side twin of {@see $commandSkips}, fed
     * by {@see chat()} from {@see \SugarCraft\Code\Commands\CommandLoader::overrides()}
     * and surfaced by {@see reportCommandOverrides()} as ONE aggregate row.
     *
     * The values are the loader's own per-override sentences — name, tier and
     * winning path, already localized — so the aggregate carries the facts
     * without a second render pass.
     *
     * @var array<string, string> commandName => announcement sentence
     */
    private static array $commandOverrides = [];
    /**
     * The subset of {@see $commandOverrides} already put in front of the user —
     * process-scoped noise control, same contract as
     * {@see $reportedCommandSkips}.
     *
     * @var array<string, true>
     */
    private static array $reportedCommandOverrides = [];
    /**
     * Frontmatter keys the launch's files declare and nothing acts on, per
     * format ({@see \SugarCraft\Code\Support\FrontmatterKeyAudit::AGENT} …),
     * keyed by an identity that includes where the file came from =>
     * [display name, labels].
     *
     * Hoisted for {@see $commandSkips}'s reason — the registries and loaders
     * that found them do not outlive the construction — and keyed per file so
     * {@see reportIgnoredFrontmatter()} reports each one once per process.
     * Fed by {@see agentPresets()}, {@see skillRegistry()} and {@see chat()}'s
     * command and rule drain (X-37a).
     *
     * @var array<string, array<string, array{0: string, 1: list<string>}>>
     */
    private static array $ignoredFrontmatter = [];
    /**
     * The subset of {@see $ignoredFrontmatter} already reported, same keys.
     *
     * @var array<string, array<string, true>>
     */
    private static array $reportedIgnoredFrontmatter = [];

    /** The current skill-skip list, in scan order. */
    public static function skillSkips(): array
    {
        return self::$skillSkips;
    }

    /** Merge a later scan's findings without erasing an earlier one's. */
    public static function extendSkillSkips(array $found): void
    {
        self::$skillSkips = [...self::$skillSkips, ...$found];
    }

    /** The path-keyed reported ledger {@see Bootstrap::reportSkillSkips()} diffs against. */
    public static function reportedSkillSkips(): array
    {
        return self::$reportedSkillSkips;
    }

    /** Record one skill skip as put in front of the user. */
    public static function markSkillSkipReported(int|string $path): void
    {
        self::$reportedSkillSkips[$path] = true;
    }

    /** The path-keyed ledger {@see Bootstrap::reportMemorySkips()} diffs against. */
    public static function reportedMemorySkips(): array
    {
        return self::$reportedMemorySkips;
    }

    /** Record one memory file as put in front of the user. */
    public static function markMemorySkipReported(int|string $path): void
    {
        self::$reportedMemorySkips[$path] = true;
    }

    /** The current command-skip list, in scan order. */
    public static function commandSkips(): array
    {
        return self::$commandSkips;
    }

    /** Merge a later walk's skips without erasing an earlier one's. */
    public static function extendCommandSkips(array $found): void
    {
        self::$commandSkips = [...self::$commandSkips, ...$found];
    }

    /** The path-keyed ledger {@see Bootstrap::reportCommandSkips()} diffs against. */
    public static function reportedCommandSkips(): array
    {
        return self::$reportedCommandSkips;
    }

    /** Record one command skip as put in front of the user. */
    public static function markCommandSkipReported(int|string $path): void
    {
        self::$reportedCommandSkips[$path] = true;
    }

    /** The current built-in-shadow announcement list. */
    public static function commandOverrides(): array
    {
        return self::$commandOverrides;
    }

    /** Merge a later walk's overrides without erasing an earlier one's. */
    public static function extendCommandOverrides(array $found): void
    {
        self::$commandOverrides = [...self::$commandOverrides, ...$found];
    }

    /** The name-keyed ledger {@see Bootstrap::reportCommandOverrides()} diffs against. */
    public static function reportedCommandOverrides(): array
    {
        return self::$reportedCommandOverrides;
    }

    /** Record one override announcement as already made. */
    public static function markCommandOverrideReported(int|string $name): void
    {
        self::$reportedCommandOverrides[$name] = true;
    }

    /** The format-keyed frontmatter findings, whole, for the reporter's walk. */
    public static function ignoredFrontmatter(): array
    {
        return self::$ignoredFrontmatter;
    }

    /** File one finding under its format and identity. */
    public static function putIgnoredFrontmatter(int|string $format, string $identity, array $row): void
    {
        self::$ignoredFrontmatter[$format][$identity] = $row;
    }

    /** Whether this format+identity pair was already collected this process. */
    public static function hasIgnoredFrontmatter(int|string $format, string $identity): bool
    {
        return isset(self::$ignoredFrontmatter[$format][$identity]);
    }

    /** The per-format reported slice {@see Bootstrap::reportIgnoredFrontmatter()} diffs against. */
    public static function reportedIgnoredFrontmatterFor(int|string $format): array
    {
        return self::$reportedIgnoredFrontmatter[$format] ?? [];
    }

    /** Record one frontmatter finding as put in front of the user. */
    public static function markIgnoredFrontmatterReported(int|string $format, string $identity): void
    {
        self::$reportedIgnoredFrontmatter[$format][$identity] = true;
    }

    // -----------------------------------------------------------------
    // Family D — trust/policy stores (v4 S1b)
    //
    // The freeze ledgers behind the once-per-process trust answers. The
    // methods that DECIDE — Bootstrap::trustedRootsForThisProcess(),
    // projectMcpIsTrusted(), projectCommandShellIsTrusted(),
    // projectSettingsTrusted(), hookFileEntries(), checkPolicyFreshness(),
    // quarantineNewHookEntries(), and the untrusted-hook notice inside
    // hookFiles() — stay in Bootstrap, census-pinned to the launch-warning
    // pipeline. What lives here is the frozen state itself: the same
    // ledgers under one named home, same clear-what-you-set test contract
    // as every other family on this shelf.
    // -----------------------------------------------------------------

    /**
     * Project hook files this process has already reported as skipped, keyed
     * by path — see the notice in {@see Bootstrap::hookFiles()} for why it may only fire
     * once per launch. Static because the duplication comes from ONE launch
     * building two hook chains, which is a property of the process rather than
     * of any instance.
     *
     * @var array<string, true>
     */
    private static array $reportedUntrustedHookFiles = [];

    /**
     * The trusted project roots this process resolved, keyed by the
     * `config.json` they came out of — see {@see Bootstrap::trustedRootsForThisProcess()}
     * for why the answer may not be recomputed mid-session.
     *
     * @var array<string, list<string>>
     */
    private static array $trustedRoots = [];

    // rv-sec-3 policy-freshness guard state — see Bootstrap::ensurePolicyFreshnessChecked().
    private static bool $policyFreshnessChecked = false;

    private static bool $policyChanged = false;

    private static array $policyPreviousProjectHooks = [];

    private static array $policyPreviousTrustedRoots = [];

    /**
     * The same, for {@see Bootstrap::TRUSTED_PROJECT_MCP_CONFIG_KEY} — a SEPARATE map
     * because it holds a separate key's answer, frozen for the same reason
     * {@see Bootstrap::trustedRootsForThisProcess()} gives: a session that prompt-injects a
     * line into the user's config must not be able to make that line take effect
     * in the session that wrote it.
     *
     * @var array<string, list<string>>
     */
    private static array $trustedMcpRoots = [];

    /**
     * The same again, for {@see Bootstrap::TRUSTED_PROJECT_COMMANDS_CONFIG_KEY}, frozen for
     * the reason {@see Bootstrap::trustedRootsForThisProcess()} gives: a session that gets
     * a line appended to the user's config must not be able to make that line
     * take effect in the session that wrote it.
     *
     * @var array<string, list<string>>
     */
    private static array $trustedCommandRoots = [];

    /**
     * The same again, for {@see LayeredSettings::PROJECT_SETTINGS_TRUST_KEY},
     * frozen for {@see Bootstrap::trustedRootsForThisProcess()}'s reason and for that
     * reason ALONE. The freeze is per resolved config PATH, so a test that
     * repoints `--config` gets a fresh answer.
     *
     * NO EXTRA THREAT OVER ITS THREE SIBLINGS, stated because the first cut of
     * this doc-block claimed one: that "a project's own `settings.local.json`
     * is writable by anything running in the checkout, so without the freeze a
     * turn could append a trust line and have its own later reads honour it".
     * That is not reachable, and the review that caught it measured why. This
     * key is not in {@see LayeredSettings::LAYERED_KEYS}, so
     * `LayeredSettings::only()` drops it from every settings file at every
     * tier — a project's, and the user's own `settings.json` too — and the list
     * itself is read from `config.json` through {@see Bootstrap::permissionConfig()},
     * which the layering does not touch. A write to a settings file therefore
     * cannot add a trust entry at all, frozen or not. What the freeze actually
     * buys here is what it buys for hooks, MCP and commands: one answer per
     * process, so a mid-session edit to the user's OWN `config.json` cannot
     * widen the grant a launch already decided.
     *
     * AND SINCE E74b THAT HOLDS FOR THE REFUSED ANSWER TOO. The one door this
     * freeze used to leave open was the throw: an unusable `config.json` cached
     * nothing, so a later call re-read the file and a config repaired
     * mid-process started contributing the layer its own unparseability had
     * just cost. A refusal now caches the empty list under the path it
     * resolved; only a home this process cannot name — no key to answer under —
     * still reaches the parser twice, and both attempts fail closed.
     *
     * @var array<string, list<string>>
     */
    private static array $trustedSettingsRoots = [];

    /**
     * The project root {@see Bootstrap::readUserConfig()}'s project layer is read from, or
     * null for "no project named yet" — see
     * {@see Bootstrap::useProjectRootForSettings()}.
     */
    private static ?string $projectRootForSettings = null;

    /**
     * The hook entries this process read, keyed by file path — see
     * {@see Bootstrap::hookFileEntries()} for why the file is read once per launch.
     *
     * @var array<string, array<array{name: string, event: string, matcher: string, command: string, description: string, disabled: bool}>>
     */
    private static array $hookFileEntries = [];


    /** The memoised trusted roots for $path, or an unset sentinel via hasTrustedRootsFor(). */
    public static function trustedRootsFor(string $path): array
    {
        return self::$trustedRoots[$path];
    }

    public static function hasTrustedRootsFor(string $path): bool
    {
        return \array_key_exists($path, self::$trustedRoots);
    }

    public static function rememberTrustedRoots(string $path, array $roots): void
    {
        self::$trustedRoots[$path] = $roots;
    }

    public static function trustedMcpRootsFor(string $path): array
    {
        return self::$trustedMcpRoots[$path];
    }

    public static function hasTrustedMcpRootsFor(string $path): bool
    {
        return \array_key_exists($path, self::$trustedMcpRoots);
    }

    public static function rememberTrustedMcpRoots(string $path, array $roots): void
    {
        self::$trustedMcpRoots[$path] = $roots;
    }

    public static function trustedCommandRootsFor(string $path): array
    {
        return self::$trustedCommandRoots[$path];
    }

    public static function hasTrustedCommandRootsFor(string $path): bool
    {
        return \array_key_exists($path, self::$trustedCommandRoots);
    }

    public static function rememberTrustedCommandRoots(string $path, array $roots): void
    {
        self::$trustedCommandRoots[$path] = $roots;
    }

    public static function trustedSettingsRootsFor(string $path): array
    {
        return self::$trustedSettingsRoots[$path];
    }

    public static function hasTrustedSettingsRootsFor(string $path): bool
    {
        return \array_key_exists($path, self::$trustedSettingsRoots);
    }

    public static function rememberTrustedSettingsRoots(string $path, array $roots): void
    {
        self::$trustedSettingsRoots[$path] = $roots;
    }

    /**
     * The E74b freeze: a refusal caches the empty list under the path it
     * resolved — mirrors `??= []`, so an already-cached answer (positive
     * included) is never overwritten.
     */
    public static function freezeTrustedSettingsRootsWithoutTrust(string $path): void
    {
        self::$trustedSettingsRoots[$path] ??= [];
    }

    public static function projectRootForSettings(): ?string
    {
        return self::$projectRootForSettings;
    }

    public static function rememberProjectRootForSettings(?string $root): void
    {
        self::$projectRootForSettings = $root;
    }

    public static function policyFreshnessChecked(): bool
    {
        return self::$policyFreshnessChecked;
    }

    public static function markPolicyFreshnessChecked(): void
    {
        self::$policyFreshnessChecked = true;
    }

    public static function policyChanged(): bool
    {
        return self::$policyChanged;
    }

    public static function rememberPolicyChanged(bool $changed): void
    {
        self::$policyChanged = $changed;
    }

    /** @return array<string, list<string>> */
    public static function policyPreviousProjectHooks(): array
    {
        return self::$policyPreviousProjectHooks;
    }

    /** @param array<string, list<string>> $entries */
    public static function rememberPolicyPreviousProjectHooks(array $entries): void
    {
        self::$policyPreviousProjectHooks = $entries;
    }

    /** @return list<string> */
    public static function policyPreviousTrustedRoots(): array
    {
        return self::$policyPreviousTrustedRoots;
    }

    /** @param list<string> $roots */
    public static function rememberPolicyPreviousTrustedRoots(array $roots): void
    {
        self::$policyPreviousTrustedRoots = $roots;
    }

    public static function hookFileEntriesFor(string $path): array
    {
        return self::$hookFileEntries[$path];
    }

    public static function hasHookFileEntriesFor(string $path): bool
    {
        return \array_key_exists($path, self::$hookFileEntries);
    }

    public static function rememberHookFileEntries(string $path, array $entries): void
    {
        self::$hookFileEntries[$path] = $entries;
    }

    public static function hasReportedUntrustedHookFile(string $file): bool
    {
        return isset(self::$reportedUntrustedHookFiles[$file]);
    }

    public static function markUntrustedHookFileReported(string $file): void
    {
        self::$reportedUntrustedHookFiles[$file] = true;
    }

    // =========================================================================
    // THE COMPOSITION ROOTS.  S1b slice W7-E moves the launch *builders* — the
    // methods that read the shelf above plus settings and trust, and hand the
    // engine its objects — out of {@see Bootstrap} and onto this class, where
    // the values they read already live. Bootstrap keeps same-named public
    // delegates for every door, so callers, `bin/candy-code`, and the
    // Bootstrap-keyed censuses keep reaching the builders there.
    // =========================================================================

    /**
     * Build the built-in coding tools, with a shared InstructionFileLoader
     * wired into every tool that surfaces nested CLAUDE.md/AGENTS.md content
     * (Read/Edit/Glob/Write) so those files are actually reachable when a user
     * runs the real CLI binary.
     *
     * THIS ARRAY IS THE WHOLE MODEL-FACING TOOL SET. Its BUILT-IN half is
     * TWELVE entries as of this writing — it was ten until {@see LspTool} was
     * added and eleven until {@see TaskTool} was — and THAT count, not this
     * array's length, is the domain for every "N built-in tools" figure in
     * `README.md`. `src/Tools/BuiltIn/` holds
     * exactly those twelve concrete `Tool` classes.
     *
     * TWELVE IS THE COUNT OF WIRED TOOLS, NOT OF USABLE ONES, and the two differ
     * on a launch with no `lsp` setting: `LspTool` is reachable and answers every
     * call with a "no language server configured" error until the user lists a
     * server ({@see Bootstrap::lspClient()}). A figure that said "twelve working tools"
     * would be the wrong claim about this array.
     *
     * The array is longer than twelve only when the project ships a `.mcp.json` AND
     * the user has listed this root under `trustedProjectMcp` — both conditions,
     * see {@see mcpClient()} for why the second one exists. Then one
     * {@see McpToolBridge} per tool the configured MCP servers advertise is
     * appended at the end (see {@see mcpTools()}). That is a per-PROJECT number
     * nothing in `src/` can know, which is why every count stated here and every
     * scanned assertion about this method is about the built-in half. The one
     * concrete `Tool` implementor under `src/` that is NOT in the literal below is
     * `McpToolBridge`, for exactly that reason —
     * {@see \SugarCraft\Code\Tests\Tools\BuiltInToolCorpus::DYNAMIC_TOOL_CLASSES}
     * is where that exemption is recorded and asserted.
     *
     * NOT "BY CONSTRUCTION", which is what this doc-block used to say. Nothing
     * derives this array from that directory or that directory from this array;
     * they are two hand-maintained halves, and the reason they agree is a TEST
     * that scans the directory —
     * `BinCandyCodeWiringTest::testBootstrapToolsShipsAWriteToolAndTheWholeBuiltInSet()`,
     * whose expected set comes from `glob()` over `src/Tools/BuiltIn/` rather than
     * a literal. Measured with the old literal assertions in place: an eleventh
     * implementor added to that directory and not listed here left the whole
     * Integration tier at `OK (467 tests, 2681 assertions)`, which is exactly how
     * {@see Write} came to be written, tested, named in the README and unreachable
     * from any real run. If you add a tool class, add it here — the mechanism that
     * tells you is a red test, not the type system.
     *
     * @param InstructionFileLoader|null $loader Pass the caller's loader to
     *        keep the engine's root-instruction reads and the tools' on-touch
     *        reads on ONE instance (its dedup map is per-instance).
     * @param SkillRegistry|null $skills The registry the model-facing {@see
     *        SkillTool} resolves names against. Pass the caller's registry so
     *        the tool, {@see EngineBackend::withSkillRegistry()} and the shell
     *        pane all read ONE instance — two independently scanned
     *        registries would let a skill disabled on one still be invocable
     *        through the other. Defaults to a fresh scan of $root.
     * @param LspClient|null $lsp Language servers for {@see LspTool} and the
     *        Read outline, threaded through the catalog. The launch's callers
     *        pass {@see Bootstrap::lspClient()}; null — no `lsp` setting — leaves the tool
     *        reachable but refusing.
     * @param bool $rgAvailable Host has `rg`; threaded to {@see Grep} only.
     *        {@see \SugarCraft\Code\Tools\Concerns\DetectsCapabilities} is the
     *        probe and the boot call sites below are the only readers of it, so
     *        this factory stays a pure function of its arguments and every test
     *        that calls it without the flag renders the absent description.
     * @param bool $fdAvailable Host has `fd`; same rule, threaded to {@see Glob}.
     * @param RulesState|null $rulesState The session's rulebook toggle set, threaded
     *        to the {@see RulePathNudge} and nothing else — the skills channel has
     *        its own disable mechanism (`SkillRegistry::disableMultiple()`) and the
     *        two trackers deliberately share no state. Trailing and defaulted for the
     *        reason {@see \SugarCraft\Code\Providers\CustomProvider}'s
     *        `$sessionAffinityId` is: the callers that hold no set (the `-p` path, a
     *        background child, every test that boots the tool array for its shape)
     *        keep calling with exactly the arguments they pass today.
     *
     * @return list<Tool>
     */
    public static function tools(
        ?string $root = null,
        ?InstructionFileLoader $loader = null,
        ?SkillRegistry $skills = null,
        ?LspClient $lsp = null,
        bool $rgAvailable = false,
        bool $fdAvailable = false,
        ?RulesState $rulesState = null,
        ?AgentManager $taskManager = null,
        ?\SugarCraft\Code\Agents\AgentWorkerPool $taskPool = null,
    ): array {
        // FILTERED HERE, NOT AT THE CALL SITES, and that is the load-bearing
        // part rather than tidiness: `withTools(self::tools(...))` appears
        // THREE times in `Bootstrap` — in `app()`, `backend()` and
        // `backendFor()` — and a filter applied at two of them is a config key
        // that works until the user switches provider. (The plan for this item
        // named two sites; there are three. An earlier version of this comment
        // named `chat()` as one of them, which is wrong in the way this project
        // keeps being wrong: `chat()` reaches tools only transitively through
        // `backend()` and holds no `self::tools(` call of its own. Measured with
        // `grep -n 'self::tools(' src/Cli/Bootstrap.php`; deliberately no line
        // numbers, since a comment quoting them decays on the next insertion
        // above.) Everything
        // downstream of this return receives an already-filtered set, including
        // `mcpTools()`'s appended bridges, which is what makes
        // `disabledTools: ["mcp__git__*"]` mean anything.
        Bootstrap::installSecretEnvAllowlist();
        $tools = Bootstrap::filterToolSet(self::unfilteredTools(
            $root,
            $loader,
            $skills,
            $lsp,
            $rgAvailable,
            $fdAvailable,
            $rulesState,
        ));

        // GIT GUIDANCE SETTINGS (step 0.3), applied HERE for the reason the
        // filter is: this is the one return all three `withTools()` call
        // sites share. `includeGitInstructions` must be a bool and
        // `attribution` an object of strings; anything else is ignored as if
        // unset (the block on, no trailer), the tolerant direction every
        // other `readUserConfig()` reader takes for a malformed preference.
        // `attribution` can only arrive from the user tier
        // (LayeredSettings::PROJECT_TIER_KEYS does not list it): it is text
        // the model is told to stamp on every commit.
        $gitConfig = Bootstrap::readUserConfig();
        $includeGit = $gitConfig['includeGitInstructions'] ?? true;
        $attribution = $gitConfig['attribution'] ?? [];
        $attribution = is_array($attribution) ? $attribution : [];
        $commitTrailer = is_string($attribution['commit'] ?? null) ? $attribution['commit'] : '';
        $prTrailer = is_string($attribution['pr'] ?? null) ? $attribution['pr'] : '';
        $tools = array_map(
            static fn (Tool $tool): Tool => $tool instanceof Bash
                ? $tool->withGitGuidance(is_bool($includeGit) ? $includeGit : true, $commitTrailer, $prTrailer)
                : $tool,
            $tools,
        );

        // THE TASK FEED (E675). Appended AFTER the filter, on purpose: the
        // gate on this tool is not a config key but the CALLER holding an
        // AgentManager at all — a feed built without one (a bare unit-test
        // harness, an embedder with no agents configured) ships no Task, and
        // fail-closed is the wrong shape for a tool that cannot function.
        // The instance gets the SAME manager the Chat runs on (threaded in
        // from `chat()`), never a fresh one: two managers would mean two
        // sub-agent rosters and two sets of grants, the split-state bug this
        // seam exists to prevent. What the sub-agent may then do is bounded
        // by the preset's grants inside `executeAll`, not by this list.
        //
        // Roadmap 4.3-2: and able to run a delegation in the background. The
        // supervisor is built HERE, in the launching process, so the sessions
        // it spawns from a forked turn child are recorded as this process's
        // and adopted by the host's own supervisor on its next poll
        // (BackgroundSupervisor::adoptHandedOff()) — whichever instance that
        // is. The session works in the same tree the tools are jailed to.
        //
        // Roadmap 4.9: and able to isolate a run. An agent whose preset says
        // `isolation: worktree` gets a git worktree of this launch's root,
        // under `<root>/.candy-code/worktrees/` (or CANDY_CODE_WORKTREES_DIR),
        // through ONE manager per launch — the registry it keeps is shared
        // on disk, so the forked turn children that create trees and the
        // parent that sweeps them agree on which exist.
        if ($taskManager !== null) {
            $taskRoot = $root ?? (getcwd() ?: '.');
            $tools[] = (new TaskTool($taskManager, $taskPool))
                ->withBackgroundSupervisor(new BackgroundSupervisor(), $taskRoot)
                ->withWorktreeManager(\SugarCraft\Code\Agents\WorktreeManager::new($taskRoot));
        }

        return $tools;
    }

    /**
     * The exact array {@see tools()} builds, BEFORE `filterToolSet()` applies
     * the session's `allowedTools`/`disabledTools` — the ceiling itself rather
     * than one session's slice of it.
     *
     * WHY THIS IS PUBLIC. `AgentManager::resolveGrantedTools()` has to tell
     * "the operator disabled this tool" from "the preset misspelled it", and
     * that distinction needs BOTH halves: the filtered set as the grant's
     * ceiling and this unfiltered set as the universe the narrowing happened
     * against. Handing it only `tools()` turned `disabledTools: ["Bash"]` —
     * documented as intentional — into a crash for five of the six built-in
     * presets (E639's measured trap); passing only THIS would let sub-agent
     * rosters ignore `disabledTools`, a widening. The two-set shape is the
     * decided remedy, argued in that method's doc-block.
     *
     * Same construction cost as tools() minus one filter pass — nothing here
     * spawns what backend()/app() are not already spawning on the same launch.
     *
     * @return list<Tool>
     *
     * @see tools() for the argument documentation, which is about THIS build —
     *      tools() is one line of delegation over every parameter below.
     */
    public static function unfilteredTools(
        ?string $root = null,
        ?InstructionFileLoader $loader = null,
        ?SkillRegistry $skills = null,
        ?LspClient $lsp = null,
        bool $rgAvailable = false,
        bool $fdAvailable = false,
        ?RulesState $rulesState = null,
    ): array {
        $root = Bootstrap::requireRoot($root);
        $loader ??= Bootstrap::instructionLoader($root);
        $skills ??= Bootstrap::skillRegistry($root);

        // ONE tracker across every tool that resolves a path — Read, Edit,
        // Glob, Grep and Write: the "announce a path-scoped skill the first
        // time we touch a file it covers" rule is only correct if all of them
        // share the announced-set. Five trackers would re-announce the same
        // skill once per tool (crush_feat.md section 7 E4).
        //
        // This comment said THREE and named Read/Edit/Glob. It was already
        // stale — `Write` below has taken the same pair since it was wired —
        // and `Grep` has now joined them, which is the arithmetic this project
        // keeps getting wrong. The count and the names are both asserted, not
        // just written here: `BinCandyCodeWiringTest` now DERIVES the roster
        // from the tools that declare an `instructionLoader` property instead
        // of restating a hand-kept list, so the next tool to take the pair
        // fails that test until it is acknowledged there.
        $nudge = SkillPathNudge::new($skills);

        // P6.S5b: the second transient channel carries `paths:`-scoped RULES, and
        // it is a separate tracker from the skills one on purpose — one subject
        // per class, so retuning a skill budget never moves a rule budget. The
        // rules come from the same entry point the prompt splice uses, and are
        // RE-WALKED on every consult rather than once here (audit 15d-20): the
        // splice re-walks per build and skips every path-scoped rule as "delivered
        // at tool time", so a tracker frozen at boot left a rule written
        // mid-session in neither channel, kept announcing an edited rule's launch
        // text, and still nudged a rule whose `paths:` had been removed. The cost
        // is one `load()` per path-resolving tool call — see
        // {@see RulePathNudge::fromLoader()}.
        //
        // THE TOGGLE REACHES THIS CHANNEL TOO. The set handed below is the SAME
        // instance `chat()` gave the backend and `Chat`, so a pack silenced in
        // `disabledRules` is absent from the first turn and one silenced later with
        // `/rules` goes quiet on the next tool call — the tracker consults it per
        // rule rather than baking the launch answer into a candidate list, and the
        // loader below is built WITHOUT the set so that per-rule consult stays the
        // one place this channel applies the toggle. Passing a
        // pre-filtered rule list instead would freeze boot-time intent and leave the
        // toggle half of the goal unmet; passing a SECOND `RulesState` built from the
        // same config key would be the two-copies disagreement the note above the
        // `chat()` construction names. Null — every caller that does not thread a
        // set, including the shell's display copies in `app()`, whose tracker no tool
        // call ever reaches — is the pre-fix behaviour, stated rather than assumed.
        $ruleNudge = RulePathNudge::fromLoader(
            static fn (): array => (new RuleLoader($root))->load(),
            $rulesState,
        );

        // THE BUILT-IN HALF COMES FROM THE CATALOG (DH-TOOLS): every class
        // under `src/Tools/BuiltIn/` declares its wire name, permission class
        // and wire position (`#[BuiltInTool]`) and builds itself from this one
        // shared context, so a new tool is a new file rather than an edit
        // here. One loader and one pair of nudge trackers for every
        // path-resolving tool, for the announced-set reason above.
        //
        // MCP bridges are APPENDED, so the built-ins keep the wire order the
        // model has learned and an MCP config can only ever ADD names. Empty
        // unless this project ships a `.mcp.json` AND the user trusted this
        // root — see {@see mcpTools()} and {@see mcpClient()}. MCP-7's
        // dispatcher pair is the exception to APPENDED: `mcp_list`/`mcp_call`
        // are ordinary positioned built-ins BUILT BY THE CATALOG from the
        // frozen shape, and only when something was actually deferred.
        // `Task` is not in this set: `tools()` appends it after the filter,
        // only when an AgentManager is bound.
        $tools = [
            ...\SugarCraft\Code\Tools\Catalog\ToolCatalog::build(new \SugarCraft\Code\Tools\Catalog\ToolBuildContext(
                root: $root,
                loader: $loader,
                skills: $skills,
                skillNudge: $nudge,
                ruleNudge: $ruleNudge,
                lsp: $lsp,
                rgAvailable: $rgAvailable,
                fdAvailable: $fdAvailable,
                // Lazy: the home store is opened on the Memory tool's first
                // call, so building the tool set creates nothing on disk.
                memory: \SugarCraft\Code\Memory\MemoryWriter::new(
                    static fn (): ?MemoryStore => Bootstrap::memoryStoreOrNull($root),
                    $root,
                ),
                // Roadmap 3.I-2: ONE read ledger for this launch and root —
                // Read records into it, Edit/Write/ApplyPatch refuse a stale
                // file against it, and the post-edit format hook records its
                // rewrites through the very same instance
                // ({@see readLedger()}) — owned by the launch, for the
                // loader's reason above.
                readLedger: Bootstrap::readLedger($root),
                // SHL-1: ONE background-shell table per launch and root —
                // Bash's `run_in_background` records into it, ShellOutput and
                // ShellStop read it, and the shutdown hook
                // ({@see registerBackgroundShellShutdown()}) tears down what
                // this pid's copies hold.
                backgroundShells: Bootstrap::backgroundShells($root),
                // MCP-7: the pair is BUILT here, from the frozen shape, like
                // every other conditional tool — null shape or an all-eager
                // config yields null from fromCatalog() and neither name
                // reaches the wire at all.
                mcpShape: Bootstrap::mcpToolShape($root),
            )),
            ...Bootstrap::mcpTools($root),
        ];

        // THE CEILING, not the session's slice of it. Every caller that renders
        // tools to a model wants tools() — which filters this array with the
        // operator's allowedTools/disabledTools in ONE place; the argument for
        // filtering there rather than at the call sites lives on that method.
        // Only a caller that must distinguish narrowing from absence (the
        // sub-agent grant, E639) asks for this one directly.
        return $tools;
    }

    /**
     * The run's selected provider and model as real objects, for the two
     * consumers that need a {@see ProviderInterface} rather than the
     * {@see Backend} the hosted chat runs on: {@see App} (which uses it as a
     * label source for the status bar's provider name and never calls it) and
     * {@see Bootstrap::agentManager()} (which genuinely drives it, for sub-agent
     * completions).
     *
     * Built from the same selection {@see selectedProviderLabel()} reports, and
     * falls back to the offline {@see EchoProvider} whenever this run has no
     * provider or the provider cannot be constructed: {@see Bootstrap::backend()} has
     * already warned on stderr AND seeded the transcript in that case (see
     * {@see Bootstrap::warnPermissionConfigInTranscript()}), and refusing to launch the TUI
     * over an unusable label would be a worse outcome than showing "echo".
     *
     * @return array{0: ProviderInterface, 1: string}
     */
    public static function provider(): array
    {
        [$name, $model] = self::selectedProviderLabel();
        $providerName = self::selectedProviderName();

        if ($providerName !== null) {
            try {
                $factory = new ProviderFactory();
                $config = self::providerConfigFor($providerName, $factory);
                $config['model'] = $model;

                return [$factory->create($config), $model];
            } catch (\Throwable) {
                // fall through to Echo, same degradation backend() applies
            }
        }

        return [new EchoProvider(), $name === 'command' ? $model : 'echo'];
    }
    /**
     * The provider/model pair to stamp on a session row this process
     * creates, mirroring {@see Bootstrap::backend()}'s own selection order.
     *
     * These are labels, not a second backend selection: they record what
     * the run *asked* for. If a requested provider turns out to be
     * unconstructable, {@see Bootstrap::backend()} warns on stderr and degrades to
     * Echo while this row still names the requested provider — recording
     * the request is more useful than recording the fallback, and the
     * store has no column for both. Both shell-out paths
     * (`$CANDY_CODE_BACKEND_CMD`, `$CANDY_CODE_BACKEND_CMD_STREAM`) and the
     * default path have no provider name at all, so they are labelled
     * honestly as such rather than given an invented one.
     *
     * Public because {@see NonInteractive::run()} needs to distinguish "this
     * run is on the offline Echo default" from "this run is on
     * `$CANDY_CODE_BACKEND_CMD`" before it decides whether to warn that the
     * answer did not come from a model — re-reading the same two env vars
     * there would be a second copy of this precedence chain that could drift
     * from {@see Bootstrap::backend()}'s.
     *
     * @return array{0: string, 1: string}
     */
    public static function selectedProviderLabel(): array
    {
        $name = self::selectedProviderName();
        if ($name === null) {
            // ONE label for both shell-out tiers. The label answers "did a
            // model behind a provider produce this?", and the answer is no for
            // either variable; a third label would also have to be taught to
            // {@see provider()}, whose `$name === 'command'` arm is what keeps
            // a shell-out run from being captioned "echo" in the status bar.
            return Bootstrap::backendCommandTierIsSelected() ? ['command', 'unknown'] : ['echo', 'echo'];
        }

        // Same resolver backendFor() builds the real backend from, so the
        // caption cannot name a different model than the one answering.
        $model = self::selectedModelName($name);
        if ($model === null) {
            try {
                $configured = self::providerConfigFor($name, new ProviderFactory())['model'] ?? null;
            } catch (\Throwable) {
                $configured = null;
            }
            $model = is_string($configured) && $configured !== '' ? $configured : 'unknown';
        }

        return [$name, $model];
    }
    /**
     * The provider name this run selected, or null when the run is not on
     * a provider at all (either shell-out tier — see
     * {@see Bootstrap::backendCommandTierIsSelected()} — or the offline Echo default). Same precedence {@see Bootstrap::backend()} applies:
     * `$CANDY_CODE_PROVIDER`, then the name persisted by a previous Ctrl+P
     * "Switch model".
     *
     * Public because this is the exact question the one-shot path has to ask
     * before choosing between {@see Bootstrap::backend()}'s lenient fallback and {@see
     * backendFor()}'s throw-don't-degrade contract (crush_code.md Phase 0
     * item 10): a non-null answer means *this run asked for a specific
     * provider*, and silently answering it from Echo is the bug. Exposing the
     * existing helper rather than re-deriving the precedence in {@see
     * NonInteractive} keeps ONE definition of "which provider did this run
     * select", so the backend, the session row's recorded provider and the
     * one-shot hard-fail can never disagree about it.
     */
    public static function selectedProviderName(): ?string
    {
        $env = getenv('CANDY_CODE_PROVIDER');
        if ($env !== false && $env !== '') {
            return $env;
        }

        if (Bootstrap::backendCommandTierIsSelected()) {
            return null;
        }

        $persisted = Bootstrap::readUserConfig()['provider'] ?? null;

        return is_string($persisted) && $persisted !== '' ? $persisted : null;
    }
    /**
     * The model name this run should use on `$providerName`, or null to let the
     * caller fall back to the provider's own default.
     *
     * Precedence, highest first: `--model` ({@see Bootstrap::useModel()}), then
     * `$CANDY_CODE_MODEL`, then the model PERSISTED FOR THIS PROVIDER — the
     * `models` setting, `{"<provider>": "<model id>"}` (decision D9), which the
     * settings editor writes — then null. Keyed by provider rather than one
     * top-level `model`, because a model id means nothing to any other
     * provider: a single key would send the last provider's model to the next
     * one the moment `/model` switched. The flag and the variable stay
     * provider-blind, as they always were — they name the model for whatever
     * this launch runs.
     *
     * ONE resolver rather than several `??` chains, because there are three
     * readers — {@see Bootstrap::backendFor()}, which builds the backend that actually
     * runs, {@see selectedProviderLabel()}, which produces the status-bar
     * caption, and {@see Bootstrap::workerProviderSpec()}, which hands forked sub-agent
     * workers their provider. If only the first honoured a tier, the status bar
     * would name a different model than the one answering, which is precisely
     * the "a value true of one path displayed as a property of another" defect.
     */
    public static function selectedModelName(string $providerName): ?string
    {
        if (LaunchContext::modelOverride() !== null) {
            return LaunchContext::modelOverride();
        }

        $env = getenv('CANDY_CODE_MODEL');
        if ($env !== false && $env !== '') {
            return $env;
        }

        // The layered read, so `models` in `~/.candy-code/settings.json` counts
        // too; it is user-tier only, so no project file can choose it.
        $persisted = Bootstrap::readUserConfig()['models'] ?? null;
        $model = \is_array($persisted) ? ($persisted[$providerName] ?? null) : null;

        return \is_string($model) && trim($model) !== '' ? $model : null;
    }
    /**
     * The config array one provider NAME resolves to on this launch: the
     * settings-layer override when {@see Bootstrap::providerOverrides()} carries the
     * name — layered over the defaults for its declared `type` so
     * credentials, base URLs and models the entry omits still come from the
     * built-in arm — or {@see ProviderFactory::defaultConfig()} unchanged.
     *
     * Every site that turns a selected provider name into a config array
     * MUST call this instead of `defaultConfig()` directly, or the map is
     * honoured in the palette and ignored on the wire — the exact split this
     * review item (endpoint lockout) was about. An entry naming an unknown
     * `type` lets the factory's own listing-plus-nearest-match error
     * ({@see ProviderFactory::unknownTypeError()}) surface from the base
     * lookup; this funnel adds no opinion about it.
     *
     * @return array<string, mixed>
     */
    public static function providerConfigFor(string $name, ProviderFactory $factory): array
    {
        $entry = Bootstrap::providerOverrides()[$name] ?? null;
        if ($entry === null) {
            return $factory->defaultConfig($name);
        }

        $type = (string) $entry['type'];

        return array_merge($factory->defaultConfig($type), $entry);
    }
    /**
     * The model summaries go to when the user named one, or null for the
     * conversation's own (roadmap 2.4-2): `$CANDY_CODE_SUMMARY_MODEL`, else
     * the `summaryModel` key. Read at launch and carried on the engine
     * ({@see EngineBackend::withSummaryModel()}), never re-read per turn.
     */
    public static function summaryModel(): ?string
    {
        $env = \SugarCraft\Code\Context\Compaction\StepSummarizer::modelOverride();
        if ($env !== null) {
            return $env;
        }
        $configured = Bootstrap::readUserConfig()['summaryModel'] ?? null;

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : null;
    }

    // ── THE COMPOSITION ROOTS: the backend builder family ────────────

    /**
     * Build a Backend for an explicit, already-known provider name - the
     * same construction backend() does for $CANDY_CODE_PROVIDER, extracted
     * so a caller (the Ctrl+P palette's Switch Model action) can request a
     * specific provider directly rather than only via an env var read once
     * at process start.
     *
     * Unlike backend()'s env-var path, which catches failures and falls
     * back to Echo with a warning, this throws on an invalid/unreachable
     * $providerName - a caller here asked for this provider explicitly and
     * should see the real error rather than silently getting something else.
     *
     * @param SkillRegistry|null $skills See {@see Bootstrap::backend()}.
     * @param PermissionGate|null $gate See {@see Bootstrap::backend()}.
     * @param bool $consolePermissionPrompt See {@see Bootstrap::backend()} — same opt-in,
     *        same reason it is not on by default here either: this method is
     *        also what Chat's Ctrl+P provider switch calls, mid-TUI.
     * @param RulesState|null $rulesState See {@see Bootstrap::backend()}. It matters here more
     *        than the phrase "see backend()" suggests: a real launch with
     *        $CANDY_CODE_PROVIDER set, or with a provider persisted by an earlier
     *        Ctrl+P, gets its tools from THIS method, so a set threaded into
     *        {@see Bootstrap::backend()} and not forwarded past here would leave the nudge
     *        unfiltered on exactly the common path. A provider switch reaches here
     *        through the factory {@see Bootstrap::workspace()} builds (N-P3a, O-2a), which
     *        forwards the session's set, so the set survives the switch.
     * @param AgentManager|null $taskManager The manager `Task` delegates to; null
     *        ships no `Task`. Given without `$taskPool`, the pool is built here
     *        for `$providerName`, so no path hands `Task` a manager and no pool.
     *
     * @throws \Throwable
     */
    public static function backendFor(string $providerName, ?string $root = null, ?SkillRegistry $skills = null, ?PermissionGate $gate = null, bool $consolePermissionPrompt = false, ?RulesState $rulesState = null, ?AgentManager $taskManager = null, ?\SugarCraft\Code\Agents\AgentWorkerPool $taskPool = null): Backend
    {
        // See backend(): whichever of the two a run enters through, the sweep
        // happens once, and the config directory is named before any store or
        // skill scan can resolve it out of a `/tmp` stand-in. sweepOnce()
        // latches, so backend() delegating here does not sweep twice.
        Bootstrap::trustedConfigDirPath();
        ToolIpcFiles::sweepOnce();

        $root ??= getcwd() ?: null;

        // Every entry point that resolves a root names it as THE project for
        // the settings layers, before anything below reads a config — see
        // {@see Bootstrap::useProjectRootForSettings()}. Set on all four rather than in
        // one shared helper because there is no single funnel: `app()` does
        // not call `chat()`'s resolution, and `NonInteractive` enters at
        // `backend()`.
        Bootstrap::useProjectRootForSettings(self::configRoot($root));
        $factory = new ProviderFactory();
        $config = self::providerConfigFor($providerName, $factory);
        // --model wins over $CANDY_CODE_MODEL wins over the model persisted for
        // THIS provider (D9, `models`) wins over the provider default.
        $model = self::selectedModelName($providerName) ?? ($config['model'] ?? 'gpt-4o');
        // THE PROVIDER IS BUILT ON THE SAME MODEL THE ENGINE SENDS, as
        // {@see provider()} and {@see Bootstrap::toollessBackend()} already build theirs.
        // Built from the bare default config instead, a `sglang` provider
        // believed it was configured with `SglangProvider::DEFAULT_MODEL`, so
        // it adopted the server's served model and stayed silent about a
        // family mismatch: `--model` naming a DeepSeek-V4 id against a server
        // serving Qwen3.8 drew no notice, while the per-family parser and the
        // fallback window followed the default id rather than the named one.
        $config['model'] = $model;
        $provider = $factory->create($config);

        $loader = Bootstrap::instructionLoader($root);
        $skills ??= Bootstrap::skillRegistry($root);

        // O-2a: A MANAGER WITHOUT A POOL GETS THE POOL FOR THIS PROVIDER, so
        // every path that hands `Task` a manager hands it a governed pool too —
        // the launch, the workspace's switch factory, and an embedder's
        // WorkspaceContext::backendFor() / Chat fallback alike. Built for
        // $providerName rather than shared: the pool's `workerProvider` spec is
        // the provider a forked worker constructs, and a caller that names a
        // provider here is switching to it.
        if ($taskManager !== null && $taskPool === null) {
            $taskPool = self::taskWorkerPool(Bootstrap::agentPoolConfig($providerName));
        }

        $engine = (new EngineBackend($provider, (string) $model))
            // Boot-once capabilities — see the note at app()'s `tools()` call.
            ->withTools(self::tools(
                $root,
                $loader,
                $skills,
                // Step 3.F: the same memoised servers as app()'s tool set.
                lsp: Bootstrap::lspClient($root),
                rgAvailable: Bootstrap::capabilityPresent('rg'),
                fdAvailable: Bootstrap::capabilityPresent('fd'),
                rulesState: $rulesState,
                taskManager: $taskManager,
                taskPool: $taskPool,
            ))
            ->withHooks(Bootstrap::hooks(null, $root))
            // See backend(): `??=` in place, so the approver below can read the
            // mode off the gate that was installed without re-reading the
            // config or reordering the failures.
            ->withPermissionGate($gate ??= Bootstrap::permissionGate())
            ->withSkillRegistry($skills)
            // See backend(): both composition sites of an EngineBackend that
            // carries a registry must carry the enabled bodies too, or a
            // provider switch mid-session would silently drop them from the
            // prompt on the next turn.
            ->withSkills(Bootstrap::promptEnabledSkills($skills))
            ->withInstructionLoader($loader)
            ->withRoot($root)
            // Without this the Phase 5 item 9 memory block is unreachable from a
            // real run: the store would exist, /memory would still write to it,
            // and nothing the user recorded would ever reach the model.
            ->withMemoryStore(Bootstrap::memoryStoreOrNull($root))
            // Step 0.16: a parallel group runs at most the pool's width of
            // delegated Tasks at once, from the same config the Chat's
            // workflow lanes get; the rest queue for a slot.
            ->withMaxConcurrentDelegations(Bootstrap::agentPoolConfig()->maxConcurrent)
            // Roadmap 2.4-2: the model the in-turn step summary and the
            // cache-reusing compaction go to, resolved once here at launch.
            ->withSummaryModel(self::summaryModel());

        // F2: the settings ceiling lands before the approver, after every
        // with*() that ships in this chain — and `toollessBackend()`s engines
        // are deliberately NOT wrapped: title/summary turns never loop on
        // tools, so the orchestration ceiling is not their axis.
        $engine = self::withResolvedMaxToolSteps($engine);
        // PRV-2 (§6.5): the effort tier rides the same launch tail as the step
        // ceiling, for the same reason — one door, both engine settings.
        // The reason half is deliberately unsaid HERE: this door builds a
        // mid-session switch's engine, and by {@see chat()}'s own note the
        // notice shelf is read once at launch, so a row recorded now would sit
        // unread. The Chat that installs this engine states the same fact on
        // the transcript through `Chat::withEngineSettings()`.
        [$engine] = self::withResolvedReasoningEffort($engine);

        return self::withConsolePermissionPrompt($engine, $gate, $consolePermissionPrompt);
    }
    /**
     * Raise or keep the step ceiling on a freshly built engine - one place
     * so {@see Bootstrap::backend()}'s echo path and {@see Bootstrap::backendFor()}'s real path
     * cannot drift on it.
     */
    public static function withResolvedMaxToolSteps(EngineBackend $engine): EngineBackend
    {
        $steps = Bootstrap::resolvedMaxToolSteps();

        return $steps === null ? $engine : $engine->withMaxSteps($steps);
    }
    /**
     * C-PRV-1.b: the configured tier is lowered only where it SHAPES.
     *
     * A persisted `reasoningEffort` (saved while a shaper was live, or hand-
     * edited) must not ride onto a provider+model pair whose wire has no field
     * to read it — that was the set-but-ignored failure PRV-2 itself bans,
     * relocated from the `/effort` command to the launch seam. So the tier is
     * asked of {@see EffortGate::verdict()} for THIS engine, exactly as the
     * command asks it, and on a verdict of no the engine comes back UNTOUCHED
     * with a reason string beside it: the caller that can reach the notice
     * shelf ({@see Bootstrap}'s launch delegate) says why. The mid-session
     * switch factory needs no caller to speak — the Chat that installs this
     * engine re-applies through `Chat::withEngineSettings()`, which states the
     * same fact once on the transcript.
     *
     * @return array{0: EngineBackend, 1: ?string} the engine to carry, and the
     *         reason to say when a saved tier was deliberately left off
     */
    public static function withResolvedReasoningEffort(EngineBackend $engine): array
    {
        $effort = Bootstrap::resolvedReasoningEffort();

        if ($effort === null) {
            return [$engine, null];
        }

        $provider = $engine->provider();
        if ($provider instanceof \SugarCraft\Code\Providers\FallbackProvider) {
            $provider = $provider->primary();
        }

        if (!\SugarCraft\Code\Providers\EffortGate::verdict($provider, $engine->model())) {
            return [$engine, Lang::t('chat.effort.launch_unshaped', [
                'level' => $effort,
                'provider' => $provider->name(),
                'model' => $engine->model(),
            ])];
        }

        return [$engine->withReasoningEffort($effort), null];
    }
    /**
     * Attach the console approver, or hand the engine back untouched.
     *
     * One place rather than two so {@see Bootstrap::backend()} and {@see Bootstrap::backendFor()}
     * cannot drift on the thing that decides whether an ASK is answerable.
     * The mode comes off the gate the engine actually got — not from a second
     * {@see Bootstrap::permissionGate()} call, which would re-read both policy files and
     * re-print any per-rule warnings they produce.
     */
    public static function withConsolePermissionPrompt(EngineBackend $engine, PermissionGate $gate, bool $enabled): EngineBackend
    {
        if (!$enabled) {
            return $engine;
        }

        return $engine->withPermissionApprover(
            (new HeadlessPermissionPrompt($gate->mode()))->approver(),
        );
    }
    /**
     * The governed worker pool for the Task feed (E675), built from the same
     * {@see agentPoolConfig()} the Chat hands its workflow lanes so a `Task`
     * dispatch honours the session's `maxConcurrent` / `stopOnFirstFailure` /
     * worker-provider knobs.
     *
     * WHY THIS MIRRORS `Chat::executeAgents()` RATHER THAN REUSING IT: Chat
     * owns that wiring and constructs the pool inline per fan-out; Bootstrap
     * has no accessor to a live pool (Chat's is a local), so the feed builds
     * a sibling from the same config. The duplication is deliberate and its
     * drift risk is E675's documented seam — if Chat's inline shape changes
     * (executor ctor args, clamp order), this method must follow in-step.
     *
     * ONE DELIBERATE DIFFERENCE: Chat's pool now carries an
     * {@see \SugarCraft\Code\Agents\EngineExecutor} when the chat runs on a
     * real engine, and this one does not. This pool only ever runs on TaskTool's
     * UNBOUND fallback — a bound TaskTool runs its sub-agent through the calling
     * turn's engine and never reaches a pool — so there is no engine to hand it.
     *
     * @param \SugarCraft\Code\Agents\AgentPoolConfig $config the session's pool config
     *
     * @return \SugarCraft\Code\Agents\AgentWorkerPool the governed, stop-on-first-failure pool
     */
    public static function taskWorkerPool(\SugarCraft\Code\Agents\AgentPoolConfig $config): \SugarCraft\Code\Agents\AgentWorkerPool
    {
        $executor = new \SugarCraft\Code\Agents\ProcessExecutor(
            timeoutSeconds: $config->defaultTimeoutSeconds,
            workerProvider: $config->workerProvider,
        );

        return (new \SugarCraft\Code\Agents\AgentWorkerPool(
            maxConcurrent: $config->maxConcurrent,
            executor: $executor,
            workerProvider: $config->workerProvider,
        ))->withStopOnFirstFailure($config->stopOnFirstFailure)->withMaxRetries($config->maxRetries);
    }
    /**
     * The root a launch's `.candy-code/*` lookups resolve against — the
     * repository, on a launch from one of its subdirectories (audit 15d-13
     * (b)); null stays null. The WORKING directory is never this: tools,
     * hooks and spawned sessions keep `$root`. See {@see ProjectRoot}.
     */
    public static function configRoot(?string $root): ?string
    {
        return $root === null ? null : ProjectRoot::resolve($root);
    }

    // ------------------------------------------------------------------
    // THE COMPOSITION ROOTS: the interactive shell builder family
    // (S1b W7-E C5; doors of the same names stay on Bootstrap, private
    // where they were private, for the reflection pins.)
    // ------------------------------------------------------------------

    /**
     * Build the pane shell the CLI binary runs interactively: an {@see App}
     * hosting the very {@see chat()} model this class already builds
     * (crush_feat.md §5 E7, the MERGE branch).
     *
     * §5 E7 gave two ways out of candy-code's two-parallel-UI-systems drift
     * risk — delete the `App`/`Pane` layer, or move the app onto it — and
     * conditioned the delete on there being no plan to switch. There is one,
     * and this method is the last link in it: until now nothing in `src/` or
     * `bin/` ever constructed an `App` with a hosted `Chat`, so the shell
     * (menu bar, pane focus, {@see \SugarCraft\Code\Tui\KeyboardHandler}'s
     * agent-view keys, session tabs) was unreachable from a real run in
     * exactly the "built but never wired" way §5D describes.
     *
     * The `Chat` is taken WHOLE from {@see chat()} rather than rebuilt: it
     * already carries the seeded session row, the title backend, the memory
     * store and the guard chain, and seeding it twice would create a second
     * session row per launch. The App is the frame around it and copies no
     * state out of it — {@see App::withSessionId()} is the one exception, and
     * it is read back off the hosted chat rather than re-derived, so the two
     * can never disagree.
     *
     * The shell's own panes are populated here for the same reason the whole
     * step exists: an empty Tools/Skills sidebar in a freshly-wired shell is
     * the failure mode being fixed, not a neutral default. These are the
     * shell's DISPLAY copies — the engine's authoritative tool list and skill
     * registry live inside the hosted chat's backend, which builds its own;
     * handing both the same instances would mean reshaping {@see backend()}'s
     * internals, which this step does not touch.
     */
    public static function app(?string $root = null, ?string $tuiErrorLog = null, ?string $initialPrompt = null): App
    {
        $root ??= getcwd() ?: null;

        // Where `bin/candy-code`'s TuiErrorLog::install() sent error_log() —
        // registered before chat() so its launch report can say when that is
        // not the home log ({@see reportTuiErrorLogFallback()}). Passed here
        // rather than registered by the binary, whose guard keeps every
        // Bootstrap call out of the span between the redirect and Program.
        Bootstrap::useTuiErrorLog($tuiErrorLog);

        // Every entry point that resolves a root names it as THE project for
        // the settings layers, before anything below reads a config — see
        // {@see useProjectRootForSettings()}. Set on all four rather than in
        // one shared helper because there is no single funnel: `app()` does
        // not call `chat()`'s resolution, and `NonInteractive` enters at
        // `backend()`.
        Bootstrap::useProjectRootForSettings(self::configRoot($root));

        // The words left on the command line (audit CLI-2(b)) ride this call
        // rather than a Bootstrap call of their own in `bin/candy-code`, whose
        // guard keeps every Bootstrap call out of the span between the TUI log
        // redirect and Program. Chat submits them from init().
        $chat = Bootstrap::chat($root)->withInitialPrompt($initialPrompt);
        [$provider, $model] = self::provider();

        // ONE registry for the shell's Skills pane and the Skill tool in its
        // displayed tool list: scanning twice would show the user a roster
        // that the tool could disagree with.
        $skills = Bootstrap::skillRegistry($root);

        // RAISED AFTER chat() HAS ALREADY READ THE LIST, which is why the
        // length is taken here. {@see chat()} seeds the transcript on its way
        // out (see its last statement), so anything the second scan below
        // records into {@see LaunchNotices::$launchNotices} would land in a static that
        // nothing reads again — a migration onto the transcript seam that looks
        // like a fix and is a no-op. This is the interactive path, so it is the
        // one where that matters most.
        $noticesBeforeSecondScan = \count(Bootstrap::launchNotices());

        // This is a SECOND scan — chat() above already reported what its own
        // scan skipped — and it can find skills that one did not, because it
        // runs after it and reads the same trees. reportSkillSkips() only
        // prints what has not been printed yet, so the common case is silence
        // and the case this adds is a skill file that would otherwise have been
        // collected into skillSkips() and never mentioned to anybody.
        Bootstrap::reportSkillSkips();
        // Same argument for the second scan's directory refusals.
        Bootstrap::reportProjectTierRefusals();

        // HOISTED out of the `withTools()` argument below rather than inlined:
        // tools() reaches filterToolSet(), which is itself a transcript source,
        // so its notices have to be in hand before the delta is taken.
        //
        // The two capability booleans are this class's only reads of the host
        // PATH, and DetectsCapabilities memoizes each answer for the process, so
        // a launch that reaches all three `tools()` sites still pays for one stat
        // walk per binary — and no subprocess, which is the failure that would
        // trip phpunit's failOnWarning gate on a container without `rg`.
        $tools = self::tools(
            $root,
            null,
            $skills,
            // Step 3.F: the launch's language servers, started once here
            // and shared by every forked turn (LspConnection is fork-safe).
            lsp: Bootstrap::lspClient($root),
            rgAvailable: Bootstrap::capabilityPresent('rg'),
            fdAvailable: Bootstrap::capabilityPresent('fd'),
            // E675: the SAME manager the Chat runs on (accessor, not a fresh
            // build — see chat()'s hoist note); the pool is a sibling built
            // from the Chat's own config because Chat keeps its pool local.
            taskManager: $chat->agentManager(),
            taskPool: ($chatConfig = $chat->agentPoolConfig()) !== null ? self::taskWorkerPool($chatConfig) : null,
        );

        // THE DELTA, never the whole list. {@see Chat::withLaunchNotices()}
        // APPENDS, and $chat already carries everything chat() had recorded —
        // passing the full list again would put every launch warning in the
        // transcript twice. {@see LaunchNotices::$launchNotices} is append-only within a
        // launch, so a slice from the old length is exactly what these three
        // calls added, in the order they added it.
        //
        // ONE KNOWN UNDERSTATEMENT, named rather than engineered around: if the
        // launch had ALREADY overflowed {@see LAUNCH_NOTICE_LIMIT} before this
        // point, $chat carries an "and N more" row whose N does not grow to
        // cover what the second scan then dropped. That needs a launch with 36+
        // distinct warnings before the shell is built, and the row still says
        // the full list is on stderr, where it is.
        $chat = $chat->withLaunchNotices(array_slice(Bootstrap::launchNotices(), $noticesBeforeSecondScan));

        return App::new($provider, $model)
            ->withChat($chat)
            ->withSessionId($chat->currentSessionId())
            ->withTools($tools)
            ->withAvailableSkills($skills)
            // Same string the tools/skill scan above used, so the shell's
            // Settings pane, the environment block the model reads and every
            // hook context all name one directory.
            ->withRoot($root)
            // Pane-docking phase 2: the `layout` setting loads onto the
            // model, and every model-level dock mutation writes the manifest
            // straight back — the same injected-persistence shape
            // `chat()` uses for `onConfigChange` above, its own key because
            // the value is the versioned DockLayout manifest, not a string.
            ->withDock(Bootstrap::dockFromUserConfig())
            // ISS-049: the same blob carries the panes the user toggled by
            // hand, so yesterday's manual close outlives the process — the
            // auto-show consults this roster and never re-docks its entries.
            ->withPaneAutoShow(Bootstrap::paneAutoShowFromUserConfig())
            ->withOnLayoutChange(
                static fn(array $blob) => Bootstrap::writeUserConfig(['layout' => $blob]),
            )
            // N-P1: the settings view explains THIS launch's layers — its
            // project-trust answer, its environment and its flags — rather
            // than SettingsSources::bestEffort()'s guess, which cannot know
            // the trust answer and so hides a trusted project's values. A
            // closure because the view resolves when it is opened, not here.
            ->withSettingsSources(static fn (): \SugarCraft\Code\Tui\Settings\SettingsSources => \SugarCraft\Code\Tui\Settings\SettingsSources::fromLaunch(
                $root,
                $root === null ? null : Bootstrap::projectSettingsTrusted($root),
                Bootstrap::userSettingsDirOrNull(),
                Bootstrap::userConfigPath(),
                array_filter(getenv(), 'is_string'),
                LaunchContext::permissionModeOverride() === null ? [] : ['--permission-mode' => LaunchContext::permissionModeOverride()],
                // The editor's provider choices: the same list the Ctrl+P
                // "Switch model" palette offers.
                \SugarCraft\Code\Config\Settings\OptionsProvider::new()->withList(
                    \SugarCraft\Code\Config\Settings\OptionsSource::Providers,
                    array_keys(Bootstrap::availableProviders()),
                ),
            ))
            // N-P2: the settings editor's own write door, NOT `onConfigChange`
            // (whose census stays `provider` + `theme`). Its "You" tier goes
            // through writeUserConfig() — the one writer config.json has — and
            // its project tier only to a trusted project's settings.local.json.
            //
            // The workspace's writer — the one `/model` saves through — so a
            // launch has one SettingsWriter, built by settingsWriter().
            ->withSettingsWriter(
                ($writer = $chat->workspace()?->service(\SugarCraft\Code\Config\Settings\SettingsWriter::class)) instanceof \SugarCraft\Code\Config\Settings\SettingsWriter
                    ? $writer
                    : self::settingsWriter($root),
            )
            // C-SEC-5.d: ask the trust question AT LAUNCH, once per root per
            // process, when the census finds declared-but-untrusted rows.
            // This is the dialog's only seed: `-p` and serve never build an
            // App, so they never see one — they refuse the same rows they
            // always have. The answer itself rides the shell's one grant door
            // (Bootstrap::grantProjectTrust), never this class.
            ->withTrustDialog(self::launchTrustDialog($root))
            // P-TABS.d: the tab strip's session factory (field only — the
            // routing lands in P-TABS.e). Each call hands Bootstrap::tabChat()
            // this launch's root, so every tab rides the workspace the launch
            // described: an existing id loads that session, null mints a fresh
            // tab lazily, and a session another process holds opens read-only.
            ->withTabFactory(
                static fn (?string $sessionId): \SugarCraft\Code\Chat => Bootstrap::tabChat($root, $sessionId),
            );
    }

    /**
     * The launch's settings write door (N-P2): its "You" tier goes through
     * writeUserConfig() — the one writer config.json has — and its project
     * tier only to a trusted project's settings.local.json. Built once per
     * launch by {@see workspace()}, which registers it for Chat's `/model`
     * (N-P3b); {@see app()} reuses that instance for the settings editor.
     */
    public static function settingsWriter(?string $root): \SugarCraft\Code\Config\Settings\SettingsWriter
    {
        return \SugarCraft\Code\Config\Settings\SettingsWriter::new(
            Bootstrap::userConfigPath(),
            static fn (array $set, array $unset) => Bootstrap::writeUserConfig($set, $unset),
        )->withProject($root, $root !== null && Bootstrap::projectSettingsTrusted($root));
    }

    /**
     * Roots the launch dialog has already asked about — process memory only,
     * the same once-per-process discipline the launch notices keep (C-SEC-5.d).
     * A grant taken from the dialog lands in the FROZEN lists, so it cannot
     * change this process's census; re-asking the identical question in the
     * same launch could only annoy.
     *
     * @var array<string, true>
     */
    private static array $trustDialogAsked = [];

    /**
     * The launch dialog for an interactive TUI start (C-SEC-5.d): the
     * {@see \SugarCraft\Code\Cli\Bootstrap::pendingProjectTrust()} census of
     * `$root` wrapped in a dialog, or null when the root declares nothing
     * pending or has already been asked this process. Only {@see app()}
     * calls this — the interactive shell is the only surface that builds an
     * App, which is what keeps `-p` and serve dialog-free by construction.
     *
     * @return \SugarCraft\Code\Tui\TrustDialog|null
     */
    public static function launchTrustDialog(?string $root): ?\SugarCraft\Code\Tui\TrustDialog
    {
        if ($root === null || $root === '' || isset(self::$trustDialogAsked[$root])) {
            return null;
        }

        $targets = Bootstrap::pendingProjectTrust($root);
        if ($targets === []) {
            return null;
        }

        self::$trustDialogAsked[$root] = true;

        return \SugarCraft\Code\Tui\TrustDialog::new($root, $targets, self::pendingTrustSettingsKeyCount($root, $targets));
    }

    /**
     * The number the dialog's Settings row names — how many keys the
     * project layer would change. Counted from the two project files'
     * decoded key sets, the same stat-and-decode pre-read the census'
     * declares probe performs; zero unless a Settings row stands pending.
     *
     * @param list<\SugarCraft\Code\Config\Trust\TrustTarget> $targets
     */
    private static function pendingTrustSettingsKeyCount(string $root, array $targets): int
    {
        if (!\in_array(\SugarCraft\Code\Config\Trust\TrustTarget::Settings, $targets, true)) {
            return 0;
        }

        $configRoot = self::configRoot($root);
        if ($configRoot === null) {
            return 0;
        }

        $canonical = realpath($configRoot);
        $base = rtrim($canonical !== false ? $canonical : $configRoot, '/');

        $keys = array_merge(
            array_keys(LayeredSettings::decodedFile($base . '/' . LayeredSettings::SHARED_PATH)),
            array_keys(LayeredSettings::decodedFile($base . '/' . LayeredSettings::LOCAL_PATH)),
        );

        return \count(array_unique($keys));
    }

    /**
     * Test seam for the once-per-process ask latch — production never resets
     * it; the process exiting does.
     */
    public static function resetTrustDialogLatchForTesting(): void
    {
        self::$trustDialogAsked = [];
    }

    /**
     * The docked-pane layout the `layout` setting names, or null — which
     * {@see \SugarCraft\Code\App\App::dock()} resolves to the launch
     * default — when the key is absent, malformed, or left over from a build
     * whose manifest this one cannot read. Failing soft here is the whole
     * point: a bad geometry blob is a stale file, not a reason to refuse to
     * start.
     */
    public static function dockFromUserConfig(): ?DockLayout
    {
        $raw = Bootstrap::readUserConfig()['layout'] ?? null;

        if (!is_array($raw)) {
            return null;
        }

        try {
            return DockLayout::fromArray($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The auto-show memory the `layout` blob's `userToggled` list names
     * (ISS-049) — pane values the previous session's user touched by hand,
     * so a closed pane does not ride back in on the next activity. Same
     * fail-soft as {@see dockFromUserConfig()}: the list is an annotation on
     * the layout key, and a malformed row is dropped, never fatal — its
     * worst outcome is re-enabling one auto-dock the user meant to silence.
     * Rows naming a non-dockable pane are dropped too: they can never be
     * consulted, and storing them would launder a hand-edited config into
     * shell state.
     */
    public static function paneAutoShowFromUserConfig(): PaneAutoShow
    {
        $raw = Bootstrap::readUserConfig()['layout'] ?? null;
        $rows = is_array($raw) && is_array($raw['userToggled'] ?? null)
            ? $raw['userToggled']
            : [];

        $toggled = [];
        foreach ($rows as $row) {
            if (!is_string($row)) {
                continue;
            }
            $pane = Pane::tryFrom($row);
            if ($pane !== null && $pane->dockable() && !in_array($row, $toggled, true)) {
                $toggled[] = $row;
            }
        }

        return new PaneAutoShow([], $toggled);
    }
}
