<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

use SugarCraft\Code\Background\BackgroundShellRegistry;
use SugarCraft\Code\Bundles\Bundle;
use SugarCraft\Code\LSP\LspClient;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpToolShape;
use SugarCraft\Code\Tools\ReadLedger;
use SugarCraft\Code\Session\PromptHistory;

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
}
