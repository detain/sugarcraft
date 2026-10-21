<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

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
}
