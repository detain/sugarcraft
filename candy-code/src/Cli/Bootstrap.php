<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\AgentDefinition;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Agents\AgentPreset;
use SugarCraft\Code\Agents\AgentPresetRegistry;
use SugarCraft\Code\Agents\Effort;
use SugarCraft\Code\Agents\ForeignAgentPresetRegistry;
use SugarCraft\Code\App\App;
use SugarCraft\Code\App\PaneAutoShow;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CommandBackend;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Backend\StreamingCommandBackend;
use SugarCraft\Code\Bundles\Bundle;
use SugarCraft\Code\Bundles\BundleDiscovery;
use SugarCraft\Code\Bundles\BundleRecommender;
use SugarCraft\Code\Bundles\MarketplaceRegistry;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\CommandLoader;
use SugarCraft\Code\Commands\KeyBindingRegistry;
use SugarCraft\Code\Config\LayeredSettings;
use SugarCraft\Code\Config\PolicySnapshot;
use SugarCraft\Code\Config\Settings\Validator\KeyBindingsValidator;
use SugarCraft\Code\Config\StatusLineCommand;
use SugarCraft\Code\Config\Trust\ProjectTrustCensus;
use SugarCraft\Code\Config\Trust\TrustTarget;
use SugarCraft\Code\Context\EnvironmentBlock;
use SugarCraft\Code\Context\InstructionFileLoader;
use SugarCraft\Code\Context\ProjectMemoryWriter;
use SugarCraft\Code\Context\RuleLoader;
use SugarCraft\Code\Context\RulePathNudge;
use SugarCraft\Code\Context\RulesState;
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Diagnostics\TuiErrorLog;
use SugarCraft\Code\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Code\Hooks\ClaudeSettingsHooks;
use SugarCraft\Code\Hooks\HookConfig;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Lang;
use SugarCraft\Code\LSP\LspClient;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpToolShape;
use SugarCraft\Code\MCP\McpTrustPins;
use SugarCraft\Code\Memory\MemoryStore;
use SugarCraft\Code\Memory\UnreadableNotes;
use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Permissions\ProjectNarrowingRules;
use SugarCraft\Code\Permissions\SafetyClassifier;
use SugarCraft\Code\Providers\EchoProvider;
use SugarCraft\Code\Providers\ProviderFactory;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\PromptHistory;
use SugarCraft\Code\Session\SessionStore;
use SugarCraft\Code\Background\BackgroundShellRegistry;
use SugarCraft\Code\Background\BackgroundSupervisor;
use SugarCraft\Code\Skills\Skill;
use SugarCraft\Code\Skills\SkillLoader;
use SugarCraft\Code\Skills\SkillManager;
use SugarCraft\Code\Skills\SkillPathNudge;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Support\ContainedPath;
use SugarCraft\Code\Support\EnvBool;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Support\Log;
use SugarCraft\Code\Support\MacAltNotice;
use SugarCraft\Code\Support\ProcessContainment;
use SugarCraft\Code\Support\ProjectRoot;
use SugarCraft\Code\Support\TimedFileLock;
use SugarCraft\Code\Support\ToolIpcFiles;
use SugarCraft\Code\ToolResult;
use SugarCraft\Code\Tools\BuiltIn\Bash;
use SugarCraft\Code\Tools\BuiltIn\Edit;
use SugarCraft\Code\Tools\BuiltIn\Glob;
use SugarCraft\Code\Tools\BuiltIn\Grep;
use SugarCraft\Code\Tools\BuiltIn\LspTool;
use SugarCraft\Code\Tools\BuiltIn\Read;
use SugarCraft\Code\Tools\BuiltIn\SkillTool;
use SugarCraft\Code\Tools\BuiltIn\TaskTool;
use SugarCraft\Code\Tools\BuiltIn\WebFetch;
use SugarCraft\Code\Tools\BuiltIn\Write;
use SugarCraft\Code\Tools\Concerns\DetectsCapabilities;
use SugarCraft\Code\Tools\McpToolBridge;
use SugarCraft\Code\Tools\ReadLedger;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tui\Pane;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Constraints\Dock\DockLayout;
use SugarCraft\Core\Util\AtomicJsonFile;

/**
 * Wires up the CLI's shared, side-effecting collaborators: backend
 * selection, the built-in coding tools, and the real on-disk
 * SessionStore/MemoryStore/InstructionFileLoader that back /branch,
 * /rewind, and /memory (P6.S9/S11/S12/S15).
 *
 * R19: extracted out of `bin/candy-code`'s construction IIFE so this wiring
 * can be exercised from a PHPUnit test. `bin/candy-code` cannot be
 * `require`d directly in a test — it unconditionally ends in
 * `Program::run()`, which attaches to a real TTY and blocks. Pulling the
 * *construction* logic (everything before `run()`) into this ordinary
 * class, with the bin script reduced to `require autoload` +
 * `Bootstrap::chat()` + `run()`, was the smallest change that made the
 * wiring independently testable without touching the CLI's runtime
 * behaviour at all.
 */
final class Bootstrap
{
    use DetectsCapabilities;

    /**
     * The per-invocation override and the persisted key for the launch's
     * permission mode, plus the mode used when neither says anything. See
     * {@see permissionGate()} for why the default is the permissive one.
     */
    private const PERMISSION_MODE_ENV = 'CANDY_CODE_PERMISSION_MODE';

    private const PERMISSION_MODE_CONFIG_KEY = 'permissionMode';

    private const PERMISSION_RULES_CONFIG_KEY = 'permissionRules';

    /**
     * The keys {@see permissionSettingsLayer()} accepts from the user's
     * hand-authored `settings.json` — Phase 6 item 4.
     *
     * A WHITELIST, so that file cannot reach the OTHER things
     * {@see permissionConfig()}'s return value decides. `trustedProjectHooks`,
     * `trustedProjectMcp`, `trustedProjectCommands` and
     * `trustedProjectSettings` are deliberately not here: they are the grants
     * that make a repository's files policy at all, and the file they are
     * written in should be the one the CLI itself writes and the one a user
     * edits knowing it is the root of trust — not a second file that happens to
     * be read for a mode.
     *
     * @var list<string>
     */
    private const PERMISSION_SETTINGS_KEYS = [
        self::PERMISSION_MODE_CONFIG_KEY,
        self::PERMISSION_RULES_CONFIG_KEY,
    ];

    /**
     * The per-user opt-in that makes a PROJECT `.candy-code/hooks.yaml`
     * eligible to run at all — a list of project roots, in the same
     * `~/.candy-code/config.json` {@see PERMISSION_RULES_CONFIG_KEY} is read
     * from and parsed with the same warn-and-skip-the-bad-entry tolerance.
     * See {@see hookFiles()} for why the default has to be "no".
     */
    private const TRUSTED_PROJECT_HOOKS_CONFIG_KEY = 'trustedProjectHooks';

    /**
     * The SIBLING opt-in that makes a PROJECT `.mcp.json` eligible to start its
     * servers — same file, same shape, same parser as
     * {@see TRUSTED_PROJECT_HOOKS_CONFIG_KEY}. See {@see mcpClient()} for why
     * starting an MCP server is code execution and therefore needs a gate at all.
     *
     * A SEPARATE KEY, and reusing the hooks one was considered and refused:
     * every root a user has already listed under `trustedProjectHooks` would
     * silently acquire the right to spawn arbitrary long-lived processes, which
     * is a security grant being widened by an upgrade rather than by the user.
     * The two decisions are also genuinely different — "run the shell commands
     * this repo checked into `hooks.yaml`" is not "let this repo start whatever
     * servers it names and keep them running for the session".
     *
     * Public because the verdict and the opt-in write that consult it are
     * computed in {@see McpDecision}, one class away.
     */
    public const TRUSTED_PROJECT_MCP_CONFIG_KEY = 'trustedProjectMcp';

    /**
     * The THIRD opt-in of the same shape: it makes a PROJECT command file's
     * `` !`cmd` `` form eligible to run at all
     * ({@see \SugarCraft\Code\Chat::refuseCommandShell()}).
     *
     * A THIRD SEPARATE KEY, for the reason {@see TRUSTED_PROJECT_MCP_CONFIG_KEY}
     * gives for the second: folding it into either existing key would hand every
     * root already listed there a new capability by upgrade rather than by
     * decision. And the decision genuinely differs again — "run what this repo
     * put in hooks.yaml" fires on tool use, whereas this fires when the operator
     * types a `/name` the popup offered them, which is the one place a shell
     * execution looks like a menu selection.
     */
    private const TRUSTED_PROJECT_COMMANDS_CONFIG_KEY = 'trustedProjectCommands';

    /**
     * The mode `-p` and the background daemon start in when nothing names
     * one; the TUI starts in {@see INTERACTIVE_DEFAULT_PERMISSION_MODE}. See
     * {@see permissionGate()} for why the two differ.
     */
    private const DEFAULT_PERMISSION_MODE = PermissionMode::BypassPermissions;

    /** The TUI's default mode (decision D5) — see {@see permissionGate()}. */
    private const INTERACTIVE_DEFAULT_PERMISSION_MODE = PermissionMode::Default;

    /**
     * The most launch notices one launch may seed a transcript with.
     *
     * A CAP ON A LIST THAT NOTHING ELSE BOUNDS. {@see LaunchNotices::$launchNotices} feeds
     * {@see Chat::withLaunchNotices()}, which turns each entry into a UI-only
     * {@see Role::System} row of the transcript. WHAT THIS SAID: that the list
     * was SENT TO THE MODEL on every turn, making an unbounded list a per-token
     * cost for the whole session. WHAT IS TRUE NOW: since audit 15b-03 a launch
     * notice never reaches the model, so the cost is the transcript's — a wall
     * of rows the user scrolls past, which buries the one that matters. That
     * is still worth a cap, for the clutter reason alone.
     *
     * SIZED AGAINST A ROSTER, NOT A RECOLLECTION (audit CLI-3). The cap exists
     * for the per-ENTRY fan-outs — thirty malformed `permissionRules`, a long
     * `enabledSkills` list — and must never be what hides a row from a source
     * that is bounded on its own. It used to be argued in prose here ("18 is
     * the most a launch reached … 24 cleared it with headroom"), and the prose
     * fell behind: by wave 9 the bounded sources could raise 26 rows against a
     * cap of 24, and a recount for this change found 31, because the
     * narrowed-grant aggregate, the MCP rows and the `enabledSkills` shape row
     * had never been counted at all. {@see LAUNCH_NOTICE_BOUNDED_SOURCES} is
     * now the count, and
     * {@see \SugarCraft\Code\Tests\Cli\BootstrapLaunchNoticeCapCensusTest}
     * fails when a method that reaches the seam is missing from it or when its
     * sum stops fitting under this cap — so the next source cannot spend the
     * headroom silently.
     *
     * 50 is that sum — 49 after the C-SEC-6.b project-narrowing row
     * joined the roster on top of the C-EXT-4.d launch-recommendation
     * aggregate row, which joined on top of the C-PRV-1.b
     * unshaped-launch-tier row, which joined on top of the v1:F-1
     * Claude-settings skip drain, the v1:m-1 home stand-in row and the
     * v2:a11y-F3 terminal-guidance row, which joined the roster on top of the PLG-1
     * bundles discovery seam and the ISS-047
     * keybinding-collision drain — plus one row
     * of headroom. A launch that does hit
     * the cap loses nothing silently: the overflow is COUNTED and reported as
     * one trailing row — see {@see launchNotices()} — and stderr carries every
     * row, because a silently truncated warning list is the defect this seam
     * exists to end.
     */
    public const LAUNCH_NOTICE_LIMIT = 50;

    /**
     * Roadmap N-P4d: the setting that replaces {@see LAUNCH_NOTICE_LIMIT} (its
     * default) — `config.json` only, read as notices are recorded
     * ({@see launchNoticeLimit()}), so it applies to the next launch.
     */
    public const SETTING_LAUNCH_NOTICE_LIMIT = 'notices.transcriptLimit';

    /**
     * How many launch notices this launch seats on the transcript:
     * `notices.transcriptLimit` when it is an int of at least 0 (0 seats none,
     * leaving the one "and N more" row), else {@see LAUNCH_NOTICE_LIMIT}.
     *
     * Read from `config.json` ALONE, not the merged settings: the merge itself
     * can raise launch notices (a refused project file), and a reader that
     * re-entered it from inside the recorder would recurse. Every notice
     * still reaches stderr whatever this says.
     */
    public static function launchNoticeLimit(): int
    {
        $value = self::rawUserConfig()[self::SETTING_LAUNCH_NOTICE_LIMIT] ?? null;
        if (\is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 1e15) {
            $value = (int) $value;
        }

        return \is_int($value) && $value >= 0 ? $value : self::LAUNCH_NOTICE_LIMIT;
    }

    /**
     * Every method that raises launch notices onto the transcript seam, with
     * the most rows its BOUNDED part can raise in one launch — the roster
     * {@see LAUNCH_NOTICE_LIMIT} is sized against (audit CLI-3).
     *
     * Two entries also fan out per ENTRY of a user list, and only their
     * bounded part is counted: `permissionRules` (one whole-key complaint,
     * then one row per malformed rule) and `promptEnabledSkills` (one row for
     * a value that is not a list, then one per bad or missing name). Those
     * fan-outs are what the cap is for. `reportProjectTierRefusals` raises one
     * row per refused path: it counts eight, one directory for each of the
     * eight subsystems its doc-block names, and its fan-out is the command
     * loader's one row per refused command FILE. `lspClient` counts its one
     * whole-key row (a malformed `lsp` value, or the server cap reached); its
     * one row per configured server that did not start is a fan-out too.
     * `bundles` counts two, one per fixed install container; its
     * `pluginPaths` rows are the same shape as those.
     *
     * Read by the census test only; nothing at runtime sums it.
     *
     * @var array<string, int>
     */
    private const LAUNCH_NOTICE_BOUNDED_SOURCES = [
        'reportSkillSkips' => 1,
        'reportCommandSkips' => 1,
        'reportCommandOverrides' => 1,
        'hookFiles' => 1,
        'filterToolSet' => 1,
        'reportProjectTierToolRemovals' => 1,
        'agentPresets' => 1,
        'foreignAgentPresets' => 1,
        'backend' => 2,
        'reportPrunedSessions' => 1,
        'reportProjectTierRefusals' => 8,
        'permissionRules' => 1,
        'promptEnabledSkills' => 1,
        'reportMemorySkips' => 2,
        'reportPromptBudgetDeferrals' => 2,
        'reportNonsenseLimits' => 2,
        'reportTuiErrorLogFallback' => 1,
        'drainNarrowedGrantWarnings' => 2,
        'mcpClient' => 2,
        'reportIgnoredFrontmatter' => 4,
        'lspClient' => 1,
        'permissionGate' => 1,
        // C-SEC-6.b: one new PLACE that warns — the project-narrowing notice,
        // raised by the filter it reports on, never from inside the banner
        // branch above it. Independent worst-case row: a bypassed launch on a
        // narrowing project raises both.
        'projectNarrowingRules' => 1,
        // Roadmap PLG-1: two, one per fixed install container (the project
        // and user tier roots) whose walk can raise a problem row on any
        // launch; rows naming entries of the pluginPaths setting are fan-out
        // over a user list — the very thing the cap is for.
        'bundles' => 2,
        'availableProviders' => 1,
        'checkPolicyFreshness' => 1,
        'reportKeybindingCollisions' => 1,
        // v1:F-1, widened by C-EXT-1.b: one aggregate row over every unknown
        // event and every unsupported entry type or key in every CC file the
        // launch parsed — names, groups and files are counted INTO the row,
        // never per entry.
        'reportSkippedClaudeSettingsEvents' => 1,
        // v1:m-1: one row, message-identity de-duped, whatever how many reads
        // took the stand-in.
        'reportHomeDirectoryStandIn' => 1,
        // v2:a11y-F3: one row, gated on the dumb family AND a tty, so a
        // scripted or piped launch contributes nothing.
        'reportTerminalGuidance' => 1,
        // C-PRV-1.b: at most one row — the saved tier that shapes nothing on
        // this launch's pair. The shelf de-dupes on message identity, and the
        // reason is a single sentence whatever the tier, so one row is the
        // bounded worst case, not a per-entry fan-out.
        'withResolvedReasoningEffort' => 1,
        // C-EXT-4.d: ONE aggregate row whatever the recommendation count —
        // every matching `name@marketplace` is named inside the single
        // sentence, so the bounded worst case is 1, not MAX_RECOMMENDATIONS.
        'reportBundleRecommendations' => 1,
    ];

    /**
     * Alias: the row bound now lives with the shelf it bounds, in
     * {@see LaunchNotices::LAUNCH_NOTICE_MAX_CHARS}; kept reachable here
     * because the transcript seam's doc-figures and reflection reads address
     * this class. PUBLIC because a Bootstrap constant expression consumes it,
     * and constant expressions resolve per the ACCESSING class — a private
     * alias is invisible there even inside its own declaring file.
     */
    public const LAUNCH_NOTICE_MAX_CHARS = LaunchNotices::LAUNCH_NOTICE_MAX_CHARS;

    /** Alias of {@see LaunchNotices::LAUNCH_NOTICE_TAIL_CHARS} — see the row-bound alias above. */
    public const LAUNCH_NOTICE_TAIL_CHARS = LaunchNotices::LAUNCH_NOTICE_TAIL_CHARS;

    /** Alias of {@see LaunchNotices::LAUNCH_NOTICE_CLIP_SUFFIX} — see the row-bound alias above. */
    public const LAUNCH_NOTICE_CLIP_SUFFIX = LaunchNotices::LAUNCH_NOTICE_CLIP_SUFFIX;

    /**
     * The one line every warning this class raises takes on stderr.
     *
     * A `public const` AND `sprintf()`ed FROM, not a literal with a copy in a
     * test. E104 recorded that every `sprintf()` in this class reaching stderr
     * or the transcript was pinned only by a test re-typing it; by the time
     * E118 landed that had already been half-answered, and E118 is the rest of
     * the repair.
     *
     * WHAT THIS PARAGRAPH SAID ABOUT THE STATE IT REPLACED:
     * {@see \SugarCraft\Code\Tests\Config\ReadmeRosterDriftTest} "used to
     * recover this envelope by running a regex over this file's SOURCE TEXT,
     * which is a scrape that GOES QUIET the moment the line is reformatted".
     * WHAT IS TRUE NOW: it went LOUD. The scrape's recovery step was an
     * `assertSame(1, $matched)`, so zero matches is a failure naming the method
     * whose shape moved, not a silent pass. MEASURED at `06126017`, PHP 8.3.6:
     * turning this line's interpolation into `sprintf()` — which is exactly
     * what this commit did to it, and which changes not one byte of output —
     * takes that suite to `Tests: 14, Assertions: 89, Failures: 1`.
     * WHY THE ARGUMENT STILL EARNS ITS PLACE: the complaint against a source
     * scrape was never that it fails silently, it is that it reds on a pure
     * refactor, and a guard that reds on a refactor is a guard the next person
     * weakens. A name is what lets the README end and the launcher end agree
     * without either one caring how the other is written. That reasoning was
     * right; the mechanism sentence under it was inverted, and inverted
     * mechanism sentences are how three previous rounds shipped a defect.
     *
     * THE PREFIX AND THE FULL STOP ARE BOTH PART OF IT, and both are load-bearing
     * to a reader: `README.md` and `docs/SETTINGS.md` quote a launch-report line
     * complete with `candy-code: ` and the trailing `.`, and the transcript copy
     * {@see warnPermissionConfigInTranscript()} seeds carries NEITHER, because
     * both are added here on the stderr side.
     *
     * A `%` INSIDE `$message` IS NOT A HAZARD, and the first draft of this
     * paragraph claimed it was. The message is the ARGUMENT, never the format,
     * so `sprintf()` never scans it — MEASURED on PHP 8.3.6, a message
     * containing `Bash(*%z*)` comes out byte-identical to the concatenation this
     * line replaced. What a second conversion in the FORMAT would do is
     * different and worse: `sprintf()` in PHP 8 throws
     * `ArgumentCountError: 3 arguments are required, 2 given` (measured, same
     * box), so a second `%s` here takes the launch down rather than mangling a
     * line. Hence exactly one, and the count is asserted by the README guard
     * rather than merely intended.
     */
    public const STDERR_LINE_FORMAT = "candy-code: %s.\n";

    /**
     * The launch-report line {@see reportProjectTierToolRemovals()} builds,
     * promoted out of that method for the reason {@see STDERR_LINE_FORMAT} is.
     *
     * `%s` the settings file, `%d` removed, `%d` the ceiling this launch would
     * otherwise have offered, `%s` the removed names, `%s` the survivor clause —
     * which is {@see PROJECT_TIER_TOOL_REMOVAL_LEAVING} plus a list, or
     * {@see PROJECT_TIER_TOOL_REMOVAL_LEAVING_NONE} when the project's globs took
     * everything.
     */
    public const PROJECT_TIER_TOOL_REMOVAL_FORMAT =
        '%s (disabledTools) disabled %d of the %d tools your own settings left — %s — %s';

    /** Prefixes the survivor list in {@see PROJECT_TIER_TOOL_REMOVAL_FORMAT}'s last field. */
    public const PROJECT_TIER_TOOL_REMOVAL_LEAVING = 'leaving: ';

    /** The survivor field when the project's globs left nothing at all. */
    public const PROJECT_TIER_TOOL_REMOVAL_LEAVING_NONE = 'leaving no tools at all';

    /**
     * The aggregate row {@see reportSkillSkips()} raises for skill files that
     * did not load: unreadable ones, and readable ones that lost a name
     * collision to a skill of higher precedence.
     *
     * WORDED FOR BOTH SINCE AUDIT 15d-03. {@see skillSkips()} has carried the
     * shadowed files since `9105feb48` (`SkillLoader::recordShadowing()`
     * writes them through the same `recordSkip()` an unreadable file takes),
     * but the row still said they "could not be read" — a repository's
     * `deploy` losing to the user's own read as a broken file the user had to
     * go and fix. The debug listing the row points at says which is which.
     *
     * PROMOTED BECAUSE A SECOND PARTY READS IT (E164).
     * {@see \SugarCraft\Code\Tests\Cli\BootstrapLaunchNoticeRoutingTest::testSkippedSkillFilesReachBothChannelsAsOneAggregateRow()}
     * reproduces a rendered span of this sentence, plural machinery included —
     * and the plural machinery is the part that drifts, because `%s` here is
     * three separate agreement slots (`file/files`, `was/were`, `it/them`) that
     * a reworded message silently desynchronises.
     *
     * `%d` the count, `%s` the noun plural, `%s` the verb, `%s` the env var
     * that lists the paths, `%s` the object pronoun. Five, and the count is
     * asserted rather than intended — `sprintf()` on PHP 8 throws
     * `ArgumentCountError` when a format asks for more than it is given, so a
     * sixth conversion is a launch-time fatal, not a cosmetic drift.
     */
    public const SKILL_SKIP_NOTICE_FORMAT =
        '%d skill file%s %s not loaded (unreadable, or shadowed by a same-named skill); set %s=1 to list %s';

    /**
     * The aggregate row {@see reportPromptBudgetDeferrals()} raises for
     * instruction files the system prompt leaves out (audit R1).
     *
     * `%d` the count, `%s` the noun plural, `%s` was/were, `%s` the
     * `path (reason); …` list. The reasons are the loader's and the splice's
     * own words ({@see InstructionFileLoader::refusedPaths()}), so the row
     * says over WHICH budget, or that the file resolved out of the checkout,
     * without a second vocabulary for the same verdicts.
     */
    public const INSTRUCTION_DEFERRAL_NOTICE_FORMAT =
        '%d instruction file%s %s left out of the system prompt — %s';

    /**
     * The aggregate row {@see reportPromptBudgetDeferrals()} raises for enabled
     * skills whose bodies are over the skill budget (audit R1).
     *
     * `%d` the count, `%s` the noun plural, `%s` is/are, `%s` its/their, `%s`
     * the `name (reason); …` list.
     */
    public const SKILL_BUDGET_DEFERRAL_NOTICE_FORMAT =
        '%d enabled skill%s %s over the skill budget, so only %s heading and a pointer reach the system prompt — %s';

    /**
     * The row {@see reportNonsenseLimits()} raises for a `maxToolSteps` or
     * `maxOutputTokens` value its resolver will not use (audit R12).
     *
     * `%s` the key, `%s` the value as JSON, `%s` what the key takes, `%s` what
     * happens instead — the last two are the per-key constants below, so one
     * sentence shape serves both keys.
     */
    public const NONSENSE_LIMIT_NOTICE_FORMAT = '%s is %s, which is not %s; %s';

    /** {@see NONSENSE_LIMIT_NOTICE_FORMAT}'s expectation for `maxToolSteps`. */
    private const NONSENSE_STEPS_EXPECTED = 'a positive whole number';

    /** ...and what a nonsense `maxToolSteps` leaves in force. */
    private const NONSENSE_STEPS_CONSEQUENCE = 'the shipped per-turn step ceiling applies';

    /** {@see NONSENSE_LIMIT_NOTICE_FORMAT}'s expectation for `maxOutputTokens`. */
    private const NONSENSE_TOKENS_EXPECTED = 'a positive token count';

    /** ...and what a nonsense `maxOutputTokens` leaves in force. */
    private const NONSENSE_TOKENS_CONSEQUENCE = "no output ceiling is sent, so the provider's own default applies";

    /**
     * The row {@see reportTuiErrorLogFallback()} raises when the TUI's
     * `error_log` redirect fell back from the home log to a private temp-dir
     * log (audit R16 follow-up). `%s` the home log as the user spells it,
     * `%s` the file this session's diagnostics went to instead.
     */
    public const TUI_ERROR_LOG_FALLBACK_NOTICE_FORMAT = "%s could not be written, so this session's diagnostics go to %s";

    /**
     * ...and when it fell all the way to the null device, where nothing is
     * kept. `%s` the home log.
     */
    public const TUI_ERROR_LOG_DISCARDED_NOTICE_FORMAT =
        "%s could not be written and no private log file could be prepared either, so this session's diagnostics are discarded";

    /**
     * The aggregate row {@see reportCommandSkips()} raises for unreadable
     * command files (E172).
     *
     * THE SKILL ROW'S TWIN in shape: the same five conversion slots —
     * `%d` the count, `%s` the noun plural, `%s` the was/were verb, `%s` the
     * env var that lists the paths, `%s` the object pronoun — because the
     * failure the two sentences answer is the same class of thing (some of the
     * files that WOULD have been a capability did not load) and the plural
     * machinery is the part that drifts when the two are edited separately.
     * Not the same words: the skill row also counts skills shadowed by a
     * same-named one (audit 15d-03), and a command file has no such loss to
     * report. PROMOTED BECAUSE A SECOND PARTY READS IT (E164):
     * {@see \SugarCraft\Code\Tests\Cli\BootstrapLaunchNoticeRoutingTest::testSkippedCommandFilesReachBothChannelsAsOneAggregateRow()}
     * reproduces the rendered span through this constant, plural slots
     * included.
     */
    public const COMMAND_SKIP_NOTICE_FORMAT =
        '%d command file%s could not be read and %s skipped; set %s=1 to list %s';

    /**
     * The aggregate row {@see reportCommandOverrides()} raises for built-ins a
     * surviving command file replaced this launch (ISS-036's drain half).
     *
     * THE SKIP ROW'S SIBLING in shape — `%d` the count, `%s` the noun plural,
     * `%s` the rows — and promoted for the same E164 reason:
     * {@see \SugarCraft\Code\Tests\Cli\CommandOverrideLaunchNoticeTest}
     * reproduces the rendered span through this constant. Unlike the skip row
     * it NAMES its rows (built-in, tier, winning path per entry, already
     * localized by the loader): an override is not a failure to look behind,
     * it is a fact about what just launched, and the per-name stderr line
     * behind {@see \SugarCraft\Code\Commands\CommandLoader::DEBUG_REFUSALS_ENV}
     * is the same sentence, not more detail. The count/plural pair mirrors the
     * skip and skill rows so the agreement machinery is one shape.
     */
    public const COMMAND_OVERRIDE_NOTICE_FORMAT =
        '%d built-in command%s replaced by a command file this launch: %s';

    /**
     * The one row {@see agentPresets()} raises for preset files
     * {@see \SugarCraft\Code\Agents\AgentPresetRegistry::list()} skipped
     * (audit AG-2): `%d` the count, `%s` the noun plural, `%s` the
     * `path (reason)` rows joined by `; `.
     *
     * NAMES THE PATHS, unlike its skill and command siblings above, which
     * count and point at a debug variable: a launch carries a handful of
     * presets, not a directory of them, and the file is what the user has to
     * open to get the agent back. One row however many, so the seam is not
     * crowded. PROMOTED BECAUSE A SECOND PARTY READS IT (E164):
     * {@see \SugarCraft\Code\Tests\Agents\AgentPresetSkipMalformedFileTest::testALaunchKeepsTheGoodPresetAndNoticesTheSkippedFileByPath()}
     * reproduces the rendered span through this constant.
     */
    public const AGENT_PRESET_SKIP_NOTICE_FORMAT = 'skipped %d agent preset file%s: %s';

    /**
     * The one row {@see agentPresets()} raises when the same-name walk (ISS-031)
     * displaced native preset files: `%d` the count, `%s` the noun plural, `%s`
     * the `loser — kept winner` rows joined by `; `.
     *
     * The displacement is a DECISION the operator cannot otherwise see: after
     * the user > project flip, a repository's preset yielding to the operator's
     * own is the correct outcome, and the notice is what makes it a KNOWN one.
     * PROMOTED BECAUSE A SECOND PARTY READS IT (E164):
     * {@see \SugarCraft\Code\Tests\Agents\AgentPresetShadowNoticeTest} pins the
     * rendered row through this constant.
     */
    public const AGENT_PRESET_SHADOW_NOTICE_FORMAT = '%d native agent preset file%s displaced by a higher-precedence file: %s';

    /**
     * The one row {@see foreignAgentPresets()} raises for foreign agent files
     * the importer skipped (ISS-088): `%d` the count, `%s` the noun plural,
     * `%s` the `path (reason)` rows joined by `; `.
     *
     * The per-file catch that keeps one broken import from costing the whole
     * directory used to cost it its visibility too — a vanished agent is
     * indistinguishable from an unwritten one from inside the roster.
     * PROMOTED BECAUSE A SECOND PARTY READS IT (E164):
     * {@see \SugarCraft\Code\Tests\Agents\ForeignAgentPresetNoticeTest} pins
     * the rendered row through this constant.
     */
    public const FOREIGN_PRESET_SKIP_NOTICE_FORMAT = 'skipped %d foreign agent file%s: %s';

    /**
     * The one row {@see foreignAgentPresets()} raises when foreign imports
     * survived only with losses (ISS-088, the "imported with changes"
     * envelope): `%d` the count, `%s` the noun plural, `%s` the
     * {@see \SugarCraft\Code\Agents\ForeignAgentPresetRegistry::warnings()}
     * rows joined by `; `.
     *
     * The warnings already existed and named their agent; what did not exist
     * was a reader. Same promotion reason as
     * {@see FOREIGN_PRESET_SKIP_NOTICE_FORMAT}.
     */
    public const FOREIGN_PRESET_CHANGE_NOTICE_FORMAT = 'imported %d foreign agent file%s with changes: %s';

    /**
     * ISS-090(d): how many per-file rows either foreign-preset notice lists.
     *
     * The merged skip+change line already fights {@see
     * FOREIGN_PRESET_SKIP_NOTICE_FORMAT}'s clip — ISS-088 had to de-duplicate
     * paths inside each row to keep the second sentence alive — and a bad
     * tier directory is bad for EVERY file in it: sixty rows is a wall no
     * launch notice survives being read. The byte clip bounds one line; this
     * bounds the COUNT, and the envelope still reports the true totals ("N
     * files" keeps counting everything, and the tail names what was folded).
     * Ten matches the row budget of the narrowed-grant launch notices.
     */
    private const FOREIGN_PRESET_NOTICE_ROWS = 10;

    /**
     * Alias of {@see LaunchNotices::LAUNCH_NOTICE_OVERFLOW_FORMAT}. The
     * overflow row is rendered by the shelf now; the name stays on this class
     * because the catalogue and the format census read constants from here.
     */
    public const LAUNCH_NOTICE_OVERFLOW_FORMAT = LaunchNotices::LAUNCH_NOTICE_OVERFLOW_FORMAT;

    /** Alias of {@see LaunchNotices::NARROWED_GRANT_NOTICE_FORMAT} — see the overflow alias above. */
    public const NARROWED_GRANT_NOTICE_FORMAT = LaunchNotices::NARROWED_GRANT_NOTICE_FORMAT;

    /** Alias of {@see LaunchNotices::NARROWED_GRANT_OVERFLOW_FORMAT} — see the overflow alias above. */
    public const NARROWED_GRANT_OVERFLOW_FORMAT = LaunchNotices::NARROWED_GRANT_OVERFLOW_FORMAT;

    /**
     * The one-line summary {@see reportPrunedSessions()} seeds the transcript
     * with.
     *
     * PROMOTED BECAUSE TWO DOC PAGES QUOTE THE RENDERED LINE (E164) —
     * `docs/ENVIRONMENT.md`'s `CANDY_CODE_SESSION_RETENTION_DAYS` row and
     * `docs/SETTINGS.md`'s routing section both carry
     * `retention removed 3 unnamed sessions untouched for 30+ days (ids on
     * stderr)` verbatim. That is the strongest form of the readership rule this
     * class promotes on: a page an operator reads and this line have to agree,
     * and a name is how two parties agree.
     * {@see \SugarCraft\Code\Tests\Cli\BootstrapLaunchFormatConstantsTest}
     * renders both pages' copy from this constant.
     *
     * `%d` the count, `%s` the noun plural, `%d` the retention window in days.
     * The `+` after the last conversion is literal text, not part of it.
     */
    public const SESSION_RETENTION_SUMMARY_FORMAT =
        'retention removed %d unnamed %s untouched for %d+ days (ids on stderr)';

    /**
     * The per-session detail row {@see reportPrunedSessions()} writes to stderr
     * and deliberately does NOT seed into the transcript.
     *
     * PROMOTED AS THE OTHER HALF OF ONE REPORT, which is the same argument
     * {@see PROJECT_TIER_TOOL_REMOVAL_LEAVING_NONE} is kept under rather than a
     * second-party one: `docs/ENVIRONMENT.md` and `docs/SETTINGS.md` document
     * the SPLIT — summary to both channels, ids to stderr alone — so the two
     * lines are one contract, and splitting a contract across a constant and an
     * inline literal is worse than either choice made consistently. Its own
     * external readers assert fragments (`'<id> (last used …'`), not the line.
     *
     * IT CARRIES ITS OWN ENVELOPE, and that is not a mistake to be tidied away:
     * the leading `candy-code:   ` and the trailing newline duplicate what
     * {@see STDERR_LINE_FORMAT} adds, because these rows are a CONTINUATION of
     * the summary above them and are indented under it by the two extra spaces.
     * The two obvious tidyings each cost something, and they cost DIFFERENT
     * things — which is why they are named separately rather than as one
     * "route it through the helper". Sending these rows through
     * {@see warnPermissionConfig()} would keep them on stderr and only lose the
     * indent and gain a full stop after an id list. Sending them through
     * {@see warnPermissionConfigInTranscript()} — the seam the SUMMARY takes —
     * would additionally seed one transcript row per deleted session, which is
     * the per-entry fan-out that buries the other launch notices and that
     * {@see LAUNCH_NOTICE_LIMIT} exists to refuse, and that `docs/SETTINGS.md`
     * names as the reason this half of the report did not migrate with the
     * other half.
     *
     * `%s` the session id, `%s` its last-used timestamp, `%d` the message
     * count, `%s` that count's noun plural.
     */
    public const SESSION_RETENTION_DETAIL_FORMAT = "candy-code:   %s (last used %s UTC, %d %s)\n";

    /**
     * Notice for a `--continue` launch that fell back to a session from another
     * project: same-cwd sessions win, and when none exist the newest session
     * anywhere is resumed — work the user asked to come back, even if it comes
     * from the wrong directory. Announced stderr-alone, like the retention
     * detail lines beside it, not through the transcript launch-notice seam:
     * the seam call-site census lives in docs this lane may not edit, and the
     * notice is a one-line operational tell, not conversation content.
     *
     * Not (yet) in {@see LAUNCH_NOTICE_CATALOGUE()} nor lang/en.php, so
     * translated() passes the English through verbatim; promoting it to the
     * catalogue is closeout's i18n call.
     *
     * `%s` the cwd the resumed session was recorded under.
     */
    public const SESSION_RESUME_CROSS_PROJECT_FORMAT = "candy-code: --continue resumed a session from another project (%s); pass --resume to pick deliberately\n";

    /**
     * ISS-026's residual half (rv6): the SAME crossed-project tell for an
     * EXPLICIT `--resume <id>` pick of a session recorded under another cwd.
     * The ladder above announces the surprise; naming a session id from the
     * picker or a remembered id crossed just as quietly, so the picker's
     * answer ("pass --resume to pick deliberately") was only the launch's
     * half of the honesty. The pick stands — the user named it — so this row
     * REPORTS the crossing and asks nothing; the continue arm's clause does
     * not copy over because there is nothing more deliberate to pass.
     *
     * Stderr-alone for the two reasons the continue format already gives,
     * and NOT in {@see LAUNCH_NOTICE_CATALOGUE} for its same reason.
     *
     * `%s` the cwd the picked session was recorded under.
     */
    public const SESSION_RESUME_CROSS_PROJECT_PICKED_FORMAT = "candy-code: --resume picked a session from another project (%s)\n";

    /**
     * The envelope {@see reportProjectTierRefusals()} wraps every refused
     * project directory in — the path this launch would not read, and the
     * reason, joined by an em dash.
     *
     * PROMOTED AFTER A MUTATION FALSIFIED THE JUDGEMENT THAT LEFT IT INLINE
     * (E164), and the correction is worth recording because the mis-reading is
     * an easy one to repeat. WHAT E164'S WALK CONCLUDED: this envelope has no
     * external reader, because the two files that mention it — `McpToolWiringTest`
     * and `WorkflowRegistryTest` — name it only in COMMENTS and assert the
     * `%s` REASON rather than the sentence. WHAT IS TRUE: MEASURED on PHP 8.3.6
     * by rewording it `ignoring` → `skipping`,
     * {@see \SugarCraft\Code\Tests\Cli\BootstrapLaunchNoticeRoutingTest::testARefusedProjectDirectoryReachesBothChannels()}
     * goes red — `Tests: 177, Assertions: 615, Failures: 1`. It reconstructs
     * the whole envelope twice, `'ignoring ' . $path . ' — ' . $reason` for the
     * transcript row and `'ignoring ' . $path` for a `substr_count()` on
     * stderr. That is a second party agreeing on a SENTENCE, which is the test
     * the walk applies, and the walk answered it from the wrong two files.
     *
     * `%s` the path, `%s` the reason with its own full stop already stripped —
     * the stop belongs to {@see STDERR_LINE_FORMAT}, which adds one, and two
     * would read as a typo.
     */
    public const PROJECT_TIER_REFUSAL_FORMAT = 'ignoring %s — %s';

    /**
     * The diagnostic {@see mcpClient()} sends to `error_log()` when a `.mcp.json`
     * threw part-way through starting its servers.
     *
     * PROMOTED BECAUSE ITS SECOND PARTY AGREES ON THE SENTENCE, NOT ON AN IDEA
     * (E164). This looked like a fragment reader —
     * {@see \SugarCraft\Code\Tests\Integration\McpToolWiringTest} asserts
     * `'could not be fully started'`, which is a clause. MEASURED on PHP 8.3.6:
     * rewording `'; continuing without it.'` — a span no documented fragment
     * covers — takes that file to `Tests: 29, Assertions: 128, Failures: 3`.
     * (E684 RE-MEASURED LIVE on this tree; E164's original `263 / 1157 / 3`
     * had totals that drifted with the file while the failure count — the
     * number the sentence actually leans on — did not.) It pins THREE separate clauses of this line, because the point of that
     * file's `testOnAnUnsetErrorLogBoxBothLinesReachStderrAndSayDifferentThings()`
     * is that this line and
     * {@see MCP_PARTIAL_START_NOTICE_FORMAT} must not collapse into each other.
     * Two lines whose distinctness is the assertion are two names.
     *
     * IT CARRIES ITS OWN `candy-code: ` PREFIX because nothing else will add
     * one: this line goes through `error_log()` rather than
     * {@see STDERR_LINE_FORMAT}, and its own full stop is here for the same
     * reason.
     *
     * WHAT THIS PARAGRAPH SAID, corrected under round 46's review (MAJOR 4):
     * "`error_log()`, which prepends a timestamp and no label of ours". WHAT IS
     * TRUE NOW — MEASURED on PHP 8.3.6, and it depends on the destination the
     * OPERATOR configured, which is the whole difficulty with this call.
     * `php -d error_log= -r 'error_log("probe");'` puts `probe` on stderr with
     * NO prefix whatsoever; pointed at a file, the same call writes
     * `[22-Aug-2026 15:00:37 UTC] probe`. So the timestamp is the file
     * destination's, not `error_log()`'s, and the unset-ini box is exactly the
     * one
     * {@see \SugarCraft\Code\Tests\Integration\McpToolWiringTest::testOnAnUnsetErrorLogBoxBothLinesReachStderrAndSayDifferentThings()}
     * runs, where the reworded claim would have been wrong about the very case
     * three lines above it. WHY THE SENTENCE STILL EARNS ITS PLACE: its
     * CONCLUSION survives both destinations unchanged — neither one contributes
     * a `candy-code: ` label, so the label has to be in the message — and the
     * same distinction is drawn correctly at this call's site in
     * {@see mcpClient()}, which is where the destination question is argued out.
     *
     * `%s` the config path, `%s` the exception class, `%s` its message.
     */
    public const MCP_PARTIAL_START_LOG_FORMAT =
        'candy-code: MCP config %s could not be fully started (%s: %s); continuing without it.';

    /**
     * The transcript-and-stderr notice {@see mcpClient()} raises for the same
     * failure {@see MCP_PARTIAL_START_LOG_FORMAT} logs.
     *
     * PROMOTED AS THE OTHER LINE OF A PAIR WHOSE DISTINCTNESS IS ASSERTED
     * (E164). MEASURED: rewording `'this session has only the tools that did
     * load'` gives
     * {@see \SugarCraft\Code\Tests\Integration\McpToolWiringTest}
     * `Tests: 29, Assertions: 135, Failures: 2` — the same E684 re-measurement
     * as its sibling above: E164 recorded `263 / 1164 / 2`, and again only the
     * totals moved. That file's doc-block records
     * a round where one of these two wordings was nested inside the other and
     * the guard went red; the shared clause `could not be fully started` is
     * deliberate, and what must differ is everything around it.
     *
     * NO `candy-code: ` PREFIX AND NO FULL STOP, unlike its sibling: this one
     * reaches stderr through {@see warnPermissionConfigInTranscript()}, so
     * {@see STDERR_LINE_FORMAT} adds both — and the transcript copy takes
     * neither, which is why they are not in the message.
     *
     * `%s` the config path, `%s` the exception message.
     */
    public const MCP_PARTIAL_START_NOTICE_FORMAT =
        'MCP tools from %s are incomplete: the server list could not be fully started (%s); '
        . 'this session has only the tools that did load';

    /**
     * Audit MCP-5's launch row: servers of a trusted `.mcp.json` that were NOT
     * started because they differ from what the user trusted. `%s` the config
     * path, `%s` the `; `-joined {@see MCP_SERVER_CHANGED_FORMAT} /
     * {@see MCP_SERVER_ADDED_FORMAT} items.
     */
    public const MCP_SERVER_REFUSED_NOTICE_FORMAT =
        'MCP servers in %s not started — they differ from what you trusted: %s. Review the file, then run '
        . '"candy-code mcp trust" in the project to approve the current entries';

    /** One refused item: `%s` the server name, `%s` the recorded summary, `%s` the current one. */
    public const MCP_SERVER_CHANGED_FORMAT = '"%s" changed (was: %s; now: %s)';

    /** One refused item: `%s` the server name, `%s` its summary. */
    public const MCP_SERVER_ADDED_FORMAT = '"%s" is new (%s)';

    /**
     * The one-time launch row for audit 15d-05's legacy binding: unkeyed
     * home-store project notes moved into THIS project's keyed directory
     * ({@see \SugarCraft\Code\Memory\MemoryStore::bindLegacyProjectNotes()}).
     * Routed through {@see reportMemorySkips()}'s seam call, beside the
     * unreadable-notes row.
     *
     * `%d` the count, `%s` the plural, `%s` is/are, `%s` the project root,
     * `%s` the keyed directory.
     */
    public const MEMORY_LEGACY_BOUND_NOTICE_FORMAT =
        '%d project memory note%s written before notes were kept per project %s now bound to this project '
        . '(%s) and shown nowhere else, in %s; move a file out if it belongs to another project';

    /**
     * W4/MacAlt: the one-line hint that Alt chords may die on a Mac terminal
     * that maps Option to dead keys. It carries no arguments, so unlike the
     * formats above it is a whole sentence, not a printf shape; it still
     * rides translated() through the catalogue so the launch row is recorded
     * in English and painted in the operator locale, exactly like every
     * other launch notice.
     */
    public const MAC_ALT_NOTICE_TEXT = 'Alt chords may be disabled on this Mac terminal: enable "Use Option as Meta key" (Terminal.app) or "Left Option: Esc+" (iTerm2), or run /keys reload after choosing Ctrl aliases — see docs/SETTINGS.md (a Kitty-speaking terminal needs neither)';

    /**
     * v1:F-1, widened by C-EXT-1.b: one aggregate row draining
     * {@see \SugarCraft\Code\Hooks\ClaudeSettingsHooks::skippedEvents()} and
     * {@see \SugarCraft\Code\Hooks\ClaudeSettingsHooks::skippedEntries()} —
     * events a foreign Claude Code settings file asked for that candy-code
     * has no case for, plus the entry types and keys this dialect does not
     * speak. The first conversion is the clause list assembled by
     * {@see reportSkippedClaudeSettingsEvents()}; it opens with the event
     * clause whenever events were skipped, so a file that skipped only
     * events reads the same as before this step. The group figure is a
     * CENSUS OF DISTINCT PHYSICAL GROUPS
     * ({@see \SugarCraft\Code\Hooks\ClaudeSettingsHooks::skippedGroups()}),
     * not a tally of skips: one group that carried two off-dialect names is
     * one group contributing no guard (C-EXT-1.b, round 2). NOT LISTED in the catalogue
     * below, ON PURPOSE, the standing {@see STDERR_LINE_FORMAT} already has:
     * the English constant IS the row, and a closeout may mint the locale key.
     */
    public const CLAUDE_SETTINGS_SKIPPED_EVENTS_NOTICE_FORMAT =
        'Claude Code settings: %s, so %d hook group(s) across %d file(s) '
        . 'contribute no guard and were skipped; everything else in those files loaded';

    /**
     * v1:m-1: one row for a launch running on the
     * {@see \SugarCraft\Code\Support\HomeDirectory::path()} temp stand-in.
     * Not in the catalogue below, ON PURPOSE (the {@see STDERR_LINE_FORMAT}
     * standing); the English constant IS the row.
     */
    public const HOME_STAND_IN_NOTICE_TEXT =
        'HOME could not be established for this process, so untrusted reads run from the world-writable '
        . 'temp-directory stand-in — export HOME to silence this (owned-home paths such as the trusted '
        . 'config and hooks refuse while it is unset)';

    /**
     * v2:a11y-F3: one row for a launch on a TTY whose terminal identifies
     * itself as the dumb family (empty, `dumb…`, `unknown…` — the values
     * that advertise no capability to query). Not in the catalogue below,
     * ON PURPOSE (the {@see STDERR_LINE_FORMAT} standing); the row names
     * the two fallbacks such a user actually HAS: the one-shot path and
     * the mouse opt-outs.
     */
    public const TERMINAL_GUIDANCE_NOTICE_TEXT =
        'This terminal reports a dumb-family TERM, so the interactive screen may not render correctly: '
        . 'use the one-shot path with "candy-code -p <prompt>" instead, and if mouse input confuses the '
        . 'terminal set CANDY_CODE_DISABLE_MOUSE=1 or "mouse": false in the config';

    /**
     * The catalogue key each launch-notice text above is translated through
     * (audit 15b-14, step 15b-14-2), keyed by the English text itself.
     *
     * THE CONSTANTS STAY THE ENGLISH SOURCE OF TRUTH, the D7 shape
     * `SettingCategory::label()` already has: every guard, test and doc page
     * that reads one of these names — the format census, the page sweep, the
     * README and SETTINGS samples — keeps reading the English, and
     * `tests/Cli/CliLangCatalogueTest.php` pins each `lang/en.php` value to the
     * constant it translates, byte for byte, so routing a line through
     * {@see translated()} changes nothing in English. Another locale's entry
     * is a printf format of its own: it may reorder with `%1$s` positions and
     * may drop the English agreement slots (`s`, `was`/`were`, `it`/`them`),
     * which `sprintf()` passes and ignores, but it may not ask for more
     * arguments than the English does — the same test renders each one.
     *
     * NOT LISTED, ON PURPOSE: {@see STDERR_LINE_FORMAT}, the `candy-code: `
     * envelope rather than a sentence, and {@see PROMPT_ATTRIBUTION_FORMAT},
     * which the MODEL reads inside an agent's prompt and so stays English.
     *
     * @var array<string, string>
     */
    public const LAUNCH_NOTICE_CATALOGUE = [
        self::LAUNCH_NOTICE_CLIP_SUFFIX => 'cli.launch.clip_suffix',
        self::PROJECT_TIER_TOOL_REMOVAL_FORMAT => 'cli.launch.tool_removal',
        self::PROJECT_TIER_TOOL_REMOVAL_LEAVING => 'cli.launch.tool_removal_leaving',
        self::PROJECT_TIER_TOOL_REMOVAL_LEAVING_NONE => 'cli.launch.tool_removal_leaving_none',
        self::SKILL_SKIP_NOTICE_FORMAT => 'cli.launch.skill_skip',
        self::INSTRUCTION_DEFERRAL_NOTICE_FORMAT => 'cli.launch.instruction_deferral',
        self::SKILL_BUDGET_DEFERRAL_NOTICE_FORMAT => 'cli.launch.skill_budget_deferral',
        self::NONSENSE_LIMIT_NOTICE_FORMAT => 'cli.launch.nonsense_limit',
        self::NONSENSE_STEPS_EXPECTED => 'cli.launch.nonsense_steps_expected',
        self::NONSENSE_STEPS_CONSEQUENCE => 'cli.launch.nonsense_steps_consequence',
        self::NONSENSE_TOKENS_EXPECTED => 'cli.launch.nonsense_tokens_expected',
        self::NONSENSE_TOKENS_CONSEQUENCE => 'cli.launch.nonsense_tokens_consequence',
        self::TUI_ERROR_LOG_FALLBACK_NOTICE_FORMAT => 'cli.launch.tui_error_log_fallback',
        self::TUI_ERROR_LOG_DISCARDED_NOTICE_FORMAT => 'cli.launch.tui_error_log_discarded',
        self::COMMAND_SKIP_NOTICE_FORMAT => 'cli.launch.command_skip',
        self::COMMAND_OVERRIDE_NOTICE_FORMAT => 'cli.launch.command_overrides',
        self::AGENT_PRESET_SKIP_NOTICE_FORMAT => 'cli.launch.agent_preset_skip',
        self::AGENT_PRESET_SHADOW_NOTICE_FORMAT => 'cli.launch.agent_preset_shadow',
        self::FOREIGN_PRESET_SKIP_NOTICE_FORMAT => 'cli.launch.foreign_preset_skip',
        self::FOREIGN_PRESET_CHANGE_NOTICE_FORMAT => 'cli.launch.foreign_preset_change',
        self::LAUNCH_NOTICE_OVERFLOW_FORMAT => 'cli.launch.overflow',
        self::NARROWED_GRANT_NOTICE_FORMAT => 'cli.launch.narrowed_grant',
        self::NARROWED_GRANT_OVERFLOW_FORMAT => 'cli.launch.narrowed_grant_overflow',
        self::SESSION_RETENTION_SUMMARY_FORMAT => 'cli.launch.retention_summary',
        self::SESSION_RETENTION_DETAIL_FORMAT => 'cli.launch.retention_detail',
        self::PROJECT_TIER_REFUSAL_FORMAT => 'cli.launch.project_tier_refusal',
        self::MCP_PARTIAL_START_LOG_FORMAT => 'cli.launch.mcp_partial_start_log',
        self::MCP_PARTIAL_START_NOTICE_FORMAT => 'cli.launch.mcp_partial_start',
        self::MCP_SERVER_REFUSED_NOTICE_FORMAT => 'cli.launch.mcp_server_refused',
        self::MCP_SERVER_CHANGED_FORMAT => 'cli.launch.mcp_server_changed',
        self::MCP_SERVER_ADDED_FORMAT => 'cli.launch.mcp_server_added',
        self::MEMORY_LEGACY_BOUND_NOTICE_FORMAT => 'cli.launch.memory_legacy_bound',
        self::MAC_ALT_NOTICE_TEXT => 'cli.launch.mac_alt_notice',
    ];

    /**
     * $text in the active locale: its catalogue entry when it is one of
     * {@see LAUNCH_NOTICE_CATALOGUE}'s texts, else $text unchanged.
     */
    private static function translated(string $text): string
    {
        $key = self::LAUNCH_NOTICE_CATALOGUE[$text] ?? null;

        return $key === null ? $text : Lang::t($key);
    }

    /**
     * How many CALL sites in this file route a warning onto
     * {@see warnPermissionConfigInTranscript()}.
     *
     * IT LIVES HERE BECAUSE THIS IS THE FILE THAT CHANGES. Whoever adds the next
     * call site is editing this file and nothing else; a count that lived only
     * in a test they never open is a count they never see. The number is quoted
     * in prose in ten sentences across `src/`, `tests/` and `docs/` — every one
     * of them is a row in
     * {@see \SugarCraft\Code\Tests\Cli\BootstrapTranscriptSeamCallSiteCensusTest}'s
     * `PROSE_SITES`, and that file's token scan is what decides whether this
     * constant is right. Bump this, bump the ten sentences, in one commit.
     *
     * IT IS NOT AN ORACLE AND MUST NOT BE READ AS ONE. The oracle is the token
     * scan; this is a hand-maintained declaration the scan checks. That is also
     * why the census test no longer keeps a second hand-maintained copy of its
     * own — two integers that must agree, neither of them measured, is one more
     * thing to bump and no more evidence than one.
     *
     * PUBLIC because the census test is its only reader and a test may not read
     * a private const; nothing in `src/` branches on it, and nothing should —
     * see {@see warnPermissionConfigInTranscript()} for the seam itself.
     */
    public const TRANSCRIPT_SEAM_CALL_SITES = 32;

    /**
     * Every directory this launch refused to read, keyed by the path as
     * configured — see {@see reportProjectTierRefusals()}.
     *
     * "PROJECT-TIER" IS NOW NARROWER THAN THE CONTENTS, and the name is kept
     * while the claim is corrected rather than the reverse. FOUR subsystems feed
     * it — the count was two for one round after a third arrived, and three until
     * crush_code.md Phase 1 item 3 wired the fourth: the workflow registry
     * ({@see \SugarCraft\Code\Workflows\WorkflowRegistry::projectTierRefusal()}),
     * the skill loader
     * ({@see \SugarCraft\Code\Skills\SkillManager::refusedDirectories()}), the
     * native agent-preset registry
     * ({@see \SugarCraft\Code\Agents\AgentPresetRegistry::refusedDirectories()}),
     * merged in {@see agentPresets()} on both its return and its degradation
     * paths, and the FOREIGN one
     * ({@see \SugarCraft\Code\Agents\ForeignAgentPresetRegistry::refusedDirectories()}),
     * merged in {@see foreignAgentPresets()} on both of its paths for the same
     * reason, and the custom-command loader
     * ({@see \SugarCraft\Code\Commands\CommandLoader::refusedDirectories()}),
     * drained in {@see chat()} AFTER the {@see \SugarCraft\Code\Chat}
     * construction that performs the walk (crush_code.md Phase 2 item 4).
     *
     * SIX SEAMS, THEREFORE, NOT FIVE: the workflow registry exposes a SECOND
     * one, {@see \SugarCraft\Code\Workflows\WorkflowRegistry::userTierRefusal()},
     * drained in {@see workflowEngine()} — so four feeders expose five seams. Its
     * subject is `~/.candy-code/workflows`
     * — the user's OWN directory, whose location no repository chose — so it is
     * the one entry here that is not a project tier. It is drained into this map
     * anyway, because the map's real subject is "directories this launch will not
     * read, and why", the notice that prints it is worded `ignoring <path> —
     * <reason>`, and the user tier is the tier whose files are `require`d: a
     * refusal there costs the user workflows they wrote themselves and must be
     * the LOUDEST entry, not an absent one. Renaming the collector would touch
     * every reader of {@see projectTierRefusals()} and is not this change-set;
     * the mismatch is recorded here instead of being left for the next reader to
     * infer from the values.
     *
      * TEN OTHER repository-chosen paths do NOT feed this — nine with a holder
      * of their own and the tenth, the `.candy-code/memory` sentinel of the
      * importer's chat trigger, named inside that entry below — and each
      * is named rather than counted, because "five feeders" quietly becoming
      * "six things nobody drains" is the drift this collector keeps producing. It
      * was FOUR until crush_code.md Phase 1 item 3 wired
      * {@see \SugarCraft\Code\Agents\ForeignAgentPresetRegistry}, and THREE until
      * Phase 2 item 4 wired {@see \SugarCraft\Code\Commands\CommandLoader} — which
      * is what a named gap is for, twice over. It is FIVE now, and the three
      * that arrived with Phase 6 items 1+2 are NOT of the same kind as the two
      * that were already here, so "dormant and gated" is no longer the whole
      * list's property and is stated per entry instead. P6.S2 added the sixth:
     *
     *  - {@see \SugarCraft\Code\Memory\ForeignMemoryImporter}
     *    (`.opencode/memory`) is WIRED — P7.S6 gave it `Chat::memoryImport()` —
     *    and GATED, which wiring no more implies than dormancy did. It exposes
     *    `refusedDirectories()` with nothing reading it here yet: its chat
     *    command surfaces refusals to the user, the collector still does not.
     *    The trigger also writes its re-import sentinel under `.candy-code/memory`
     *    in the project — a repository-chosen path gated at its call site
     *    (containment judged before the directory is ever created, re-judged
     *    after, and the file lands by temp-create and rename: no write follows
     *    a planted symlink, and no mkdir follows one that was there when the
     *    gate ran) and answered in the
     *    command response, drained nowhere;
     *  - `.candy-code/hooks.yaml` has its own trust gate
     *    ({@see projectHooksAreTrusted()}) and refuses the LAUNCH rather than
     *    degrading, so a collector entry would be unreachable;
     *  - `.candy-code/config.json` as read by
     *    {@see \SugarCraft\Code\Agents\WorktreeConfig::readConfig()} became
     *    repository-chosen when {@see \SugarCraft\Code\Agents\WorktreeManager}
     *    started passing the repository under management as its config
     *    directory, and {@see tools()} builds one per launch since roadmap
     *    4.9 — but it is read on that build, before any collector this map
     *    feeds exists, and its one repository-chosen path
     *    (`worktreeIncludeFile`) is gated where it is used
     *    ({@see \SugarCraft\Code\Agents\WorktreeManager::resolveWorktreeInclude()}),
     *    so a refusal recorded here would have no reader;
     *  - `.candy-code/settings.json` and
     *  - `.candy-code/settings.local.json`
     *    ({@see \SugarCraft\Code\Config\LayeredSettings}) are the opposite of
     *    dormant — {@see readUserConfig()} reads them, and `EngineBackend`
     *    calls that once per TURN. They are gaps because of the FREQUENCY, not
     *    the wiring: an entry per read would either repeat every turn or make
     *    "this project is not opted in" — the ordinary state of every project
     *    the user has not listed under
     *    {@see \SugarCraft\Code\Config\LayeredSettings::PROJECT_SETTINGS_TRUST_KEY}
      *    — read as a failure in a doctor report. Both are gated
      *    ({@see projectSettingsTrusted()} plus two `ContainedPath` compares);
      *    neither is reported;
      *  - `.candy-code/rules`, and with it the INT-2 foreign imports
      *    `.claude/rules` and `.cursor/rules` (the Phase 6 P6.S2 rules tier plus
      *    its W4 families, read by
      *    {@see \SugarCraft\Code\Context\RuleLoader}) are a gap for a fourth
      *    reason: `RuleLoader` records its containment refusals LOADER-LOCAL in
      *    its own `refusedPaths()` and nothing here drains them, so this launch
      *    reports none. It is gated on both anchors (`ContainedPath::below` on the
      *    directory, `::within` on each entry) — the same discipline as
      *    `CommandLoader` — and drains only into a doctor pane when
      *    `CANDY_CODE_DEBUG_RULES` is set. Deliberate, per the split ruling; not
      *    pending work;
      *  - `.github/copilot-instructions.md` (the INT-1(c) instruction tail
      *    alias, read per candidate level by
      *    {@see \SugarCraft\Code\Context\InstructionFileLoader}) is gated by the
      *    very same `ContainedPath` compares as every other instruction file —
      *    the alias adds candidates, never a new read path — and its refusals
      *    land LOADER-LOCAL in `refusedPaths()`, read only by
      *    {@see instructionDeferrals()} for the launch transcript row. Nothing
      *    spreads them into this map; same discipline as `RuleLoader`, gap for
      *    the same fourth reason.
      *
     * The full enumeration and its derivation live in
     * {@see \SugarCraft\Code\Tests\Cli\ProjectTierRefusalInventoryTest}.
     *
     * One collector rather than four because the user does not care which class
     * noticed that their repository's directory was rejected.
     *
     * SIX WRITERS NOW, not five feeders: {@see mcpClient()} writes here DIRECTLY
     * rather than through a `refusedDirectories()`-style seam, because `.mcp.json`
     * is a FILE and there is no registry class between the read and this
     * collector. It is the only entry that is not a directory, which is worth
     * knowing when reading a refusal message from here — and it is also the only
     * one INVISIBLE to the derivation that produces the counts in
     * {@see projectTierRefusals()}, which sees `.<dir>/<segment>` literals and so
     * cannot see a bare dot-file. That doc-block says so where the numbers are;
     * the two writers `mcpClient()` registers here are "outside the tree" and
     * "not trusted", and both are gated before any `proc_open()`.
     *
     * WHAT IT DOES NOT COLLECT, so the absence is a decision: a `.mcp.json` that
     * was granted and then failed to START does not land here. That is a
     * different subject — a file read and honoured, not one declined — and it
     * would collide on this map's key with the two refusals above. See
     * {@see mcpClient()}'s catch for where that diagnostic goes and for the four
     * broken shapes that currently produce none at all.
     *
     * @var array<string, string>
     */
    private static array $projectTierRefusals = [];

    /**
     * P-TABS.d: the workspace memo {@see sharedWorkspace()} reads — the
     * instance the launch's {@see describeWorkspace()} left behind, with the
     * root it was described for so a tab aimed at another root falls back to
     * a fresh describe instead of riding the wrong workspace.
     */
    private static ?\SugarCraft\Code\Host\WorkspaceContext $memoWorkspace = null;

    /** @see self::$memoWorkspace — the root the memoized workspace describes. */
    private static ?string $memoWorkspaceRoot = null;

    /**
     * The subset of {@see $projectTierRefusals} already reported.
     *
     * @var array<string, true>
     */
    private static array $reportedProjectTierRefusals = [];

    /**
     * C-SEC-6.b: the deny/ask rules {@see projectNarrowingRules()} loaded
     * this process, keyed by `action pattern` so the tier column of
     * `/permissions` can tell a project row from a user row, and so a
     * repeated gate build adds nothing new.
     *
     * @var array<string, true>
     */
    private static array $projectNarrowingSignatures = [];

    /**
     * Resolve the launch's image render mode and hand it to
     * {@see \SugarCraft\Code\ToolResult::setRenderMode()} (W2.2).
     *
     * Precedence mirrors the settings layers the row declares: the
     * `CANDY_CODE_MEDIA_RENDER_MODE` env binding wins over the merged
     * `ui.imageRenderMode` value, which wins over `auto`. The env hop is a
     * literal `getenv` because the launch does not resolve through
     * {@see \SugarCraft\Code\Config\Settings\SettingsResolver} (advisory
     * only) — same shape as {@see \SugarCraft\Code\Media\Sd\Client::resolveBaseUrl()}
     * for its own env row. An empty value is absence, as every CANDY_CODE_*
     * reader treats it; a junk word is ToolResult's problem to fail soft on
     * (=> auto), never a throw on the launch path.
     *
     * @param array<string, mixed> $userConfig the merged layered view
     */
    public static function applyMediaRenderMode(array $userConfig): void
    {
        $env = getenv('CANDY_CODE_MEDIA_RENDER_MODE');
        $config = $userConfig['ui.imageRenderMode'] ?? null;

        ToolResult::setRenderMode(
            (\is_string($env) && \trim($env) !== '')
                ? $env
                : (\is_string($config) && \trim($config) !== '' ? $config : 'auto'),
        );
    }

    /**
     * Build the launch's OBS-1 event sink (roadmap: typed spans from the
     * `src/Events/*` DTOs over OTLP/HTTP-JSON, hand-rolled — no
     * open-telemetry packages on a TUI tree).
     *
     * The dark decision lives ONCE in {@see \SugarCraft\Code\Observability\OtelConfig::buildSink()}
     * — this seam only supplies the exporter factory, and that factory never
     * runs while dark: without `OTEL_EXPORTER_OTLP_ENDPOINT` the whole call
     * is one `getenv()` read and a null sink — no Guzzle client, no shutdown
     * registration, no network call site built. When lit, the shutdown arm
     * is a best-effort FLUSH of the bounded batch: the per-request Guzzle
     * timeout bounds it, so this is a finite HTTP call at exit, NOT a
     * wall-clock kill (E646 law).
     */
    public static function observabilitySink(): \SugarCraft\Code\Observability\EventSink
    {
        return \SugarCraft\Code\Observability\OtelConfig::sinkFromEnvironment(
            static function (\SugarCraft\Code\Observability\OtelConfig $config): \SugarCraft\Code\Observability\EventSink {
                $sink = new \SugarCraft\Code\Observability\BatchEventSink(
                    \SugarCraft\Code\Observability\OtelSink::fromConfig($config, new \GuzzleHttp\Client()),
                    $config->batchSize,
                );
                register_shutdown_function(static function () use ($sink): void {
                    $sink->flush();
                });

                return $sink;
            },
        );
    }

    /**
     * Build the fully-wired Chat model the CLI binary hands to Program.
     *
     * $root defaults to getcwd() — the directory the CLI was invoked from —
     * matching the original inline IIFE's behaviour.
     */
    public static function chat(?string $root = null): Chat
    {
        return self::buildChat($root, null, true);
    }

    /**
     * P-TABS.d: the tab strip's session factory — one Chat per tab, built on
     * the SAME workspace the launch described (the {@see sharedWorkspace()}
     * memo), so opening a tab costs no re-scan of config, skills, hooks or
     * agents.
     *
     * `$sessionId` names an existing session to load — its transcript is read
     * from the store and its single-writer lock is taken, and a session
     * another process already holds opens through the SAME read-only window
     * {@see Chat::withSessionLocking()} gives the launch — or null to open a
     * freshly-minted row, lazily as at launch (ISS-102).
     *
     * A tab carries NONE of the launch's traffic: no launch notices are
     * seeded, the runtime-notice inbox is not drained (`drainsRuntimeNotices:
     * false` — the launch's Chat owns that destructive read, E171), the
     * process decisions the launch made (status line, media render mode,
     * static spinner) are not re-run because each CLEARS as well as sets, and
     * the refusal/skip/override reports belong to the launch that armed them.
     * Each tab gets its own `liveToolEvents` ArrayObject from Chat's
     * constructor default, so its pump lineage is its own.
     */
    public static function tabChat(?string $root, ?string $sessionId): Chat
    {
        return self::buildChat($root, $sessionId, false);
    }

    /**
     * The body {@see chat()} carried before P-TABS.d, shared with the tab
     * path. `$launch` decides: whether the workspace is described fresh or
     * taken from the memo, whether the session opens through the launch-mode
     * ladder ({@see openSession()}) or the tab's direct store read
     * ({@see openTabSession()}), whether Chat is appointed the notice drainer,
     * and whether the notice/report/drain tail runs at all.
     */
    private static function buildChat(?string $root, ?string $sessionId, bool $launch): Chat
    {
        // `?: null` rather than a bare `getcwd()`: the call can fail (deleted
        // cwd, permissions), every $root consumer below is typed ?string, and
        // the one layer that genuinely needs a string — {@see requireRoot()},
        // reached through backend() -> tools() — reports the missing root as a
        // clear error rather than handing a path jail a `false`.
        $root ??= getcwd() ?: null;

        // O-2a: EVERYTHING BELOW THAT IS NOT A SCREEN IS BUILT BY workspace() —
        // config, gate, skills, commands, the `/rules` set, the agent manager and
        // Task pool, the backend and its provider-switch factory, memory, hooks,
        // the workflow engine and the runtime-notice inbox — so a host that runs
        // sessions without a TUI builds exactly what this launch does. What stays
        // here is the session this window opens and the Chat that paints it.
        $workspace = $launch
            ? self::describeWorkspace($root)
            : self::sharedWorkspace($root);
        $userConfig = $workspace->userConfig;
        $sessionStore = $workspace->sessionStore ?? throw new \LogicException('workspace() builds a session store');
        $agentManager = $workspace->agentManager;
        $commandLoader = $workspace->commandLoader ?? throw new \LogicException('workspace() builds a command loader');

        // The `statusLine` command, installed for the whole process before any
        // frame is painted. Wired HERE rather than in {@see app()} because
        // `app()` calls this method, so this is the one funnel both interactive
        // entry points share — and because a standalone `Chat` (the shell-less
        // path {@see \SugarCraft\Code\Renderer}'s docblock names as also
        // live) has to get it too.
        //
        // CALLED ON EVERY LAUNCH, including when nothing is configured: the
        // call CLEARS as well as sets, and a process that builds a second
        // Chat against different settings must not keep painting the first
        // one's text. In a test suite that runs many launches in one process
        // this is the only thing standing between them.
        //
        // $root, not `getcwd()`: a `git`-shaped status command must report the
        // repository the session was launched against. `--root <lib>` in a
        // monorepo is exactly the case where the two differ.
        //
        // P-TABS.d: gated on the launch. A tab rides the launch's memoized
        // workspace and its $userConfig, so re-running a call that clears as
        // well as sets could only re-state the same decision — or, after a
        // mid-session change to the live one, roll it back. {@see tabChat()}.
        if ($launch) {
            StatusLineCommand::configure($userConfig, $root);
        }

        // W2.2 (crush_media §8.7 gap-1): the resolved `ui.imageRenderMode`
        // becomes a process decision BEFORE the first image-bearing probe,
        // because the same statement chain below threads `ToolResult::mosaic()`
        // into Chat and ToolResult memoizes per mode from then on. Same
        // launch-called, clear-as-well-as-set shape as the statusLine line
        // above it: a second launch in one process must not keep painting
        // with the first one's mode — and a tab must not repaint at all.
        if ($launch) {
            self::applyMediaRenderMode($userConfig);
        }

        // rv1014 FX1 (carries rv-a11y-4): the reduced-motion switch lands on the
        // same launch funnel, one line, before any frame can index the spinner.
        // Truthy vocabulary is the narrow house law ({@see EnvBool::isTrue()} —
        // `1`/`true`/`yes`, case-insensitive), the same one the app's other
        // opt-in env reads speak; unset or any other word leaves the spinner
        // animating. Gated inside AgentLiveRegistry so every drawing surface
        // goes static at once (see {@see \SugarCraft\Code\Agents\Live\AgentLiveRegistry::$staticSpinner}).
        if ($launch) {
            \SugarCraft\Code\Agents\Live\AgentLiveRegistry::setStaticSpinner(
                EnvBool::isTrue(getenv('CANDY_CODE_REDUCED_MOTION')),
            );
        }

        // The session this window runs in, opened on the workspace's ONE store:
        // seedSession() is what makes /sessions, the tab strip, /branch and the
        // auto-title call reachable at all on a real run (crush_feat.md §5 E1).
        // P-TABS.d: a tab names its session (or null for a fresh lazy mint) and
        // goes through {@see openTabSession()} — no launch-mode ladder.
        $opened = $launch
            ? self::openSession($sessionStore, ...self::selectedProviderLabel())
            : self::openTabSession($sessionStore, $sessionId);
        $sessionId = $opened['id'];
        $sessionName = $opened['name'];

        // C-SES-7.c: an incognito launch hands its Chat NO store at all. The
        // null-store guards Chat already carries everywhere (transcript
        // arming, lazy minting, the /branch and /rename refusals) ARE what
        // "keeps nothing" means to the running loop, and arming none of them
        // is the whole design — no new persistence branch inside Chat. The
        // workspace store above is still built and used for this line's own
        // resume lookups and prompt recall: incognito turns WRITES off, not
        // READS of things already stored (BRIEF risk note).
        $keptStore = self::incognito() ? null : $sessionStore;

        // OBS-1: the OTLP event sink, dark unless the launch environment
        // names an OTLP endpoint. chat() and the headless `-p` path thread
        // this sink into Chat directly; `serve` sessions run on
        // Host\SessionHost instead and fold through a per-session sink
        // handed out by the EventSinkFactory workspace service registered
        // in self::workspace() (owner ruling §6.1, C-SES-4.d). Wiring the
        // serve path closed the OBS-02 gap COMPARISON.md used to record.
        $eventSink = self::observabilitySink();

        $chat = new Chat(
            // The resumed conversation (--continue / --resume <id>), or [] for
            // a new session.
            history: $opened['history'],
            backend: $workspace->backend,
            memoryStore: $workspace->memoryStore,
            sessionStore: $keptStore,
            currentSessionId: $sessionId,
            currentSessionName: $sessionName,
            // ISS-102: for a plain launch this is the minter of the row the
            // id above names; every other mode has a real row already and
            // passes null. C-SES-7.c rv1 MAJOR-1: the TAB path is the other
            // lazy shape — openTabSession() hands a fresh tab a minter, and
            // Chat's submit fires it regardless of the null store the line
            // above threads, so an incognito process opening a new tab minted
            // a real row on its first prompt. The flag gates the argument
            // itself here: an incognito tab carries no minter, exactly as the
            // launch arm of openSession() already carries none.
            sessionMint: self::incognito() ? null : ($opened['mint'] ?? null),
            // SES-1: a resumed session's spend does not restart at $0.00 —
            // the durable usage_records rollup reseeds the tracker at THE
            // chat-construction seam both `--continue` and `--resume` pass
            // through (serve runs no Chat at all — `Host\SessionHost` adopts
            // the same ledger at its own SES-1b seam, per its own contract).
            // A fresh session has no rows, so the
            // accessor answers null and Chat's `?? new TokenTracker()`
            // fallback keeps byte-identical launch semantics.
            tokenTracker: $keptStore instanceof EnhancedSessionStore
                ? $keptStore->resumedTokenTracker($sessionId)
                : null,
            // OBS-1 (roadmap): typed spans over the Events DTOs to OTLP/HTTP.
            // {@see observabilitySink()} answers NullEventSink unless
            // OTEL_EXPORTER_OTLP_ENDPOINT is set, so the dark path costs one
            // no-op call per folded event and zero I/O.
            eventSink: $eventSink,
            // A resumed session keeps the "always" answers it was given.
            permissionGrants: Chat::storedPermissionGrants($keptStore, $sessionId),
            // C-SES-7.c: the flag itself, so the surfaces that decide to keep
            // (the turn-settle memory passes, the status bar segment) read
            // one Chat field instead of sniffing the store's absence — a
            // tabbed or embedded Chat may lawfully run store-less for other
            // reasons, and must not start claiming to be incognito.
            incognito: self::incognito(),
            titleBackend: $workspace->titleBackend,
            // crush_code.md Phase 5 item 6. Without this argument `/compact`
            // reaches only the heuristic summarizer, whose stage-2 output for a
            // long exchange was the literal string "[exchanged information]" —
            // a compaction that preserved nothing of what a compaction exists to
            // preserve.
            summaryBackend: $workspace->summaryBackend,
            // Roadmap N-P4b: the compaction tiers, kept tail, summary clips,
            // absolute caps (100k / 150k by default) and the model's own
            // context-management thresholds, from the `compaction.*` and
            // `contextPruning.*` settings. Read once here, so they apply at
            // the next launch; the Chat hands the same instance to every
            // turn's backend (TurnRunner::backendForTurn()).
            compactorConfig: \SugarCraft\Code\Context\CompactorConfig::fromSettings($userConfig),
            // crush_code.md Phase 5 item 7. Null unless the launch set a cap;
            // `/budget` can set one at runtime either way.
            maxCostUsd: $workspace->maxCostUsd,
            themeName: is_string($userConfig['theme'] ?? null) ? $userConfig['theme'] : 'dark',
            onConfigChange: static fn(string $key, string $value) => self::writeUserConfig([$key => $value]),
            mosaic: ToolResult::mosaic(),
            // The cross-session prompt file ↑/↓ recall walks and Enter appends to.
            promptHistory: self::promptHistory(),
            // The same built-in guard chain backend()/backendFor() hand the
            // engine backend. Without it, Chat's own registerTool() calls
            // would still be the one unguarded tool path in the live binary
            // (crush_feat.md §1 E1) - hooks that a call gets gated by on the
            // engine pipeline would silently not apply on this one.
            hooks: $workspace->hooks,
            // Without a supervisor instance /bg answers "Background sessions
            // not configured" on every real run, which leaves crush_feat.md
            // §5 E3 (`/bg` dispatching onto BackgroundSupervisor) implemented
            // everywhere except where a user can reach it. One supervisor per
            // launch: it owns the spawned sessions' IPC table, and a second
            // instance would not know about the first's children.
            backgroundSupervisor: $workspace->backgroundSupervisor,
            // Roadmap 4.3-3: daemons an EARLIER run of this project spawned
            // and outlived are re-adopted here, at boot — the caller
            // BackgroundSupervisor::reconnect() never had. Each is folded in
            // as ADOPTED_STATUS, a status nothing reports, so the first
            // background poll announces what it really is: "re-adopted" (and
            // running), or the result of one that finished while no TUI was
            // open.
            backgroundStatuses: array_fill_keys(
                array_keys($workspace->backgroundSupervisor?->reconnect($root) ?? []),
                BackgroundSupervisor::ADOPTED_STATUS,
            ),
            // AUT-1 (plan_comparison.md §3): the ONE home roster, registered at
            // the workspace so a TUI and `serve` built on it share one clock —
            // and so the row locks, not luck, decide who fires when two hosts
            // run on one home anyway.
            scheduleRegistry: $workspace->service(\SugarCraft\Code\Background\ScheduleRegistry::class),
            // The same root the tools above are jailed to. Chat's own
            // pipeline builds hook contexts and spawns background sessions
            // without an App or Runtime in reach, so it needs its own copy
            // or `--root` stops at the tool boundary (crush_code.md Phase 0
            // item 6).
            projectRoot: $root,
            // crush_code.md Phase 0 item 13's second half. Every provider on
            // the engine path already streamed, and {@see Chat} already had a
            // `$streaming` flag - but nothing ever turned it on, so
            // {@see Chat::scheduleBackendCompletion()} passed a null $onToken
            // to the backend on every real run and the reply arrived in one
            // piece after a silent "thinking…" spinner, having paid the full
            // SSE-parsing cost for nothing.
            //
            // No `onToken:` closure alongside it: that field is an OPTIONAL
            // extra observer for embedders (see its docblock), and the live
            // TUI rendering is driven off the shared inbox instead. Passing
            // one here would only duplicate what the pump already does.
            streaming: true,
            // crush_code.md Phase 1 item 1. Until now this argument was never
            // passed on the one construction path `bin/candy-code` runs, so
            // `/agents`, Ctrl+A, the transcript's agent strip,
            // AgentDashboardPane's agent rows, PermissionGate (whose only
            // consumer is the sub-agent path) and the whole
            // TeamManager/worktree stack downstream of them were built,
            // tested, and unreachable — `Chat::handleAgentsCommand()` answered
            // "Agent manager not configured" on every real run.
            agentManager: $agentManager,
            // E652: closes the E649 seam — the session's serializable provider
            // spec rides into Chat's fallback pool so FORKED sub-agent workers
            // consult the same configured provider the hosted chat uses. When no
            // provider is derivable the spec stays null and workers FAIL CLOSED
            // naming the absence; this wiring never substitutes `echo` for an
            // unconfigured production session.
            agentPoolConfig: $workspace->agentPoolConfig,
            // crush_code.md Phase 2 item 3. `/workflow run|pause|resume|status|
            // list` answered "Workflow engine not configured" on every real run
            // because this argument was never passed — the 2,200-line
            // Workflows/ subsystem, the shipped `workflows/deep-research.php`
            // and `examples/workflows/lint-then-fix.yaml` included, was
            // reachable only from its own tests.
            //
            // Chat's constructor is what links the two: an engine that arrives
            // without a manager is given this launch's, so a parallel stage's
            // sub-agents register where the renderer reads telemetry from — and
            // it can only do that with both in hand. (Both are NAMED arguments
            // and both are evaluated before the constructor body runs, so the
            // order they appear in here is style, not mechanism.)
            workflowEngine: $workspace->workflowEngine,
            // crush_code.md Phase 2 item 4. Until now nothing in src/ or bin/
            // constructed a CommandLoader at all, so `~/.candy-code/commands`
            // and `<root>/.candy-code/commands` were directories the loader
            // knew how to walk and no launch ever asked it to — a `*.md` command
            // file was inert on every real run.
            //
            // THE INSTANCE IS HELD, not inlined into the argument, because the
            // refusals it accumulates are drained off it below. An anonymous
            // `new CommandLoader()` here would report a commands directory that
            // resolves outside the checkout to `error_log()` and nowhere else,
            // which on a full-screen TUI is nowhere the user will look.
            commandLoader: $commandLoader,
            // The `` !`cmd` `` gate for the PROJECT tier of that loader. Resolved
            // here rather than inside Chat so it is answered once, at launch,
            // from the frozen trust list — see
            // {@see projectCommandShellIsTrusted()}. A null $root cannot be
            // trusted by a list of absolute paths, so it is false without asking.
            projectCommandsTrusted: $workspace->projectCommandsTrusted,
            // THE APPOINTMENT THAT MAKES THE ARM MEAN SOMETHING (E171).
            // `RuntimeNoticeSink::arm()` in workspace() opens the inbox; this
            // is what says WHICH Chat reads it. The two belong together because
            // `drain()` is destructive — a second Chat that polled would steal
            // rows rather than duplicate them — and this method (with the
            // workspace() it calls first) is the only place in `src/` that
            // does either. P-TABS.d: the appointment stays with the launch
            // alone; a tab passes false and never polls the shared inbox.
            drainsRuntimeNotices: $launch,
            // The instance the backend above got too — see the block there. This is
            // the argument that makes `/rules` reach the prompt rather than merely
            // decorate the transcript.
            rulesState: $workspace->rulesState,
            // O-2a: the workspace this Chat's session runs in. A provider switch
            // builds its replacement backend through it (the N-P3a factory now
            // lives on the workspace — see workspace()), and the Host\* services
            // later steps extract are reached through its service() locator, so
            // none of them adds constructor state here.
            workspace: $workspace,
        );

        if (!$launch) {
            // P-TABS.d: the tab takes its single-writer session lock — the
            // same audit SES-3(b) door the launch uses below, so a tab opened
            // on a session another process (or the launch itself) already
            // holds lands in Chat's existing read-only window — and carries
            // none of the launch's traffic. The notices, refusals, skips,
            // overrides and the narrowed-grant drain belong to the launch
            // that armed them; `workspace()` resets the lists they collect
            // into, which is why the tab rides the memo instead of calling it
            // again. Each Chat here already carries its own `liveToolEvents`
            // ArrayObject (Chat's constructor default), so the tab's pump
            // lineage is its own.
            return $chat->withSessionLocking();
        }

        // Drained AFTER construction for the same reason the workflow registry's
        // refusals are: the walk happens inside the constructor, so there is
        // nothing to collect until it has run. Adding this makes commands the
        // EIGHTH feeder of {@see $projectTierRefusals} — see
        // `ProjectTierRefusalInventoryTest`, which pins the feeder/gap split so
        // a directory cannot quietly stop being either.
        self::$projectTierRefusals = [
            ...self::$projectTierRefusals,
            ...$commandLoader->refusedDirectories(),
        ];

        // And the per-FILE refusals: a command file that tried to take over a
        // control-plane name ({@see \SugarCraft\Code\Commands\CommandRegistry::CONTROL_PLANE}).
        // Path-keyed at the source, so it spreads in like every other feeder,
        // and it introduces no new repository-chosen DOT-PATH — the file lives
        // under `.candy-code/commands`, already the eighth feeder above.
        self::$projectTierRefusals = [
            ...self::$projectTierRefusals,
            ...$commandLoader->refusedCommands(),
        ];

        // And the skipped per-FILE refusals — contained-outside and
        // unparseable `*.md` — which are NOT spread above (E172): that
        // collector prints one row per entry, and a directory of twenty
        // malformed files would evict the capability warnings the seam exists
        // to carry. They hoist onto {@see LaunchContext::commandSkips()} instead and surface
        // as ONE aggregate row below, the shape
        // {@see reportSkillSkips()} already established for skills.
        LaunchContext::extendCommandSkips($commandLoader->skippedFiles());

        // And the accepted-override announcements (ISS-036): which built-ins
        // this launch's surviving file rows replaced. Hoisted off the loader
        // beside the skips and for the identical lifecycle reason — the
        // sentences must outlive the construction that wrote them — and they
        // surface below as ONE aggregate row, because a root that ships a
        // dozen shadows should read as "twelve built-ins moved", not as a
        // dozen transcript rows competing with the capability warnings.
        LaunchContext::extendCommandOverrides($commandLoader->overrides());

        // AFTER the construction above, not beside reportSkillSkips() further
        // up: the workflow registry that decides whether this project's
        // `.candy-code/workflows` is readable is built inside
        // workflowEngine(), which is one of the named arguments to the
        // constructor call. Still construction time, so still before Program
        // takes the terminal — the requirement reportSkillSkips()'s doc-block
        // states.
        self::reportProjectTierRefusals();

        // The command-file skips, immediately after the directory refusals
        // and for the identical reasons: construction time, deduplicated
        // per-path against {@see LaunchContext::reportedCommandSkips()}, and ONE row whatever
        // the count (E172 — the drain half of the finding whose feeder half
        // landed in round 46 and stood dormant behind a pin that pointed here).
        self::reportCommandSkips();

        // The accepted-override aggregate, immediately after its skip sibling
        // and for its identical reasons: construction time, deduplicated
        // per-name against {@see LaunchContext::reportedCommandOverrides()}, ONE row whatever
        // the count. This is the drain that makes the ISS-036 claim
        // "every override is reported" true on the transcript as well as on
        // stderr.
        self::reportCommandOverrides();

        // X-37a: frontmatter keys every format's files declare and nothing
        // acts on, one aggregated row per format. The command rows are read off
        // the Chat (the loader hands its specs only to the constructor) and the
        // rules are walked here, quietly — their loader is otherwise built per
        // prompt inside the backend, which never reaches this process.
        $audit = \SugarCraft\Code\Support\FrontmatterKeyAudit::class;
        foreach ($chat->customCommands() as $name => $spec) {
            if ($spec->ignoredFrontmatter !== []) {
                LaunchContext::putIgnoredFrontmatter($audit::COMMAND, (string) json_encode([$root, $spec->tier, $name]),
                    ['/' . $name, $spec->ignoredFrontmatter]);
            }
        }
        if ($root !== null) {
            // Never fatal: the audit is a notice about files that loaded, and a
            // walk that fails here fails again, reported, on the first turn.
            try {
                $rules = new RuleLoader($root, reportRefusals: false);
                $rules->load();
                foreach ($rules->ignoredFrontmatter() as $key => $labels) {
                    LaunchContext::putIgnoredFrontmatter($audit::RULE, (string) json_encode([$root, $key]), [$key, $labels]);
                }
            } catch (\Throwable) {
                // Deliberately silent here (rv-php m): this is the pre-paint
                // preview walk with `reportRefusals: false`, and the same walk
                // runs again WITH reporting on the first turn, where a failure
                // belongs — duplicating a stderr line before the alt screen is
                // up would only pre-empt the reported one.
            }
        }
        self::reportIgnoredFrontmatter();

        // v1:F-1: the CC-dialect skip record, drained where every other
        // launch fact is — after workspace() read the hook files.
        self::reportSkippedClaudeSettingsEvents();

        // v1:m-1: a launch standing on the temp-directory home stand-in says
        // so once, beside its siblings in this drain.
        self::reportHomeDirectoryStandIn();

        // v2:a11y-F3: a TTY whose terminal advertises no capabilities is
        // pointed at the paths that work there, once, before the frame.
        self::reportTerminalGuidance();

        // C-EXT-4.d: the marketplace bundles that fit this project, named
        // once per project per marketplace pin, from local bytes only.
        self::reportBundleRecommendations($root);

        // LAST, so every warning the build raised is in hand — including
        // reportProjectTierRefusals() immediately above, which is one of the
        // THIRTY-TWO call sites now routed onto the transcript seam. This said
        // SIXTEEN, counting reportPrunedSessions()'s retention summary (E78,
        // round 42) as the last one until E86 (round 43) added the sixteenth,
        // in mcpClient()'s start-then-throw catch, and P7.S3 added the
        // seventeenth and eighteenth, the two enabled-skill drop notices in
        // promptEnabledSkills(), plus its nineteenth and twentieth, the two
        // `enabledSkills` shape notices in that same method; it said twenty
        // until E653 (round 65) added the twenty-first, the narrowed-grant
        // drain below; it said twenty-one until E172 (round 70) added the
        // twenty-second, the command-file skip aggregate just above, and
        // twenty-two until the audit 15d-02 follow-up added the twenty-third,
        // the unreadable-memory-note aggregate; lane f-app-launch (2026-10-14)
        // added the last three — the rv-sec-1 bypass-mode banner in
        // permissionGate(), the rv-sec-3 policy-freshness notice in
        // checkPolicyFreshness() and the rv-enduser-1 unselectable
        // provider-override row in availableProviders(). All six are the reason
        // this line is LAST rather than merely tidy: the retention summary is
        // raised from sessionStore() far EARLIER in this method, and the MCP
        // one is raised far LATER, transitively through backend() -> tools() ->
        // mcpTools(), so only a read at the END has both in hand. The count is
        // a grep of this file for `self::` immediately followed by the seam's
        // name — deliberately not spelled out here, because a comment quoting
        // that literal makes itself an extra hit. No line numbers
        // either, for the same reason one insertion above decays them.
        // See {@see Chat::withLaunchNotices()}.
        //
        // THE ACCESSOR, not the raw list: {@see launchNotices()} appends the
        // "and N more" row when a launch overflowed {@see LAUNCH_NOTICE_LIMIT},
        // and reading the property directly here would hand the transcript a
        // silently truncated list — the exact failure mode the cap was added
        // with a counter rather than as a bare array_slice().
        // E653 ADOPTED HERE (round 65) AS AN AGGREGATE, never as a per-warning
        // seam: narrowedGrantWarnings() is an uncapped per-agent-per-tool list,
        // and round 62 measured the naive drain flooding this transcript with
        // a dozen near-identical sentences — the same per-agent-flood defect
        // round-61 lane A recorded. drainNarrowedGrantWarnings() gives stderr
        // the complete record exactly as before and gives the transcript at
        // most two rows: a header packing as many compact grant pairs as fit
        // inside LAUNCH_NOTICE_MAX_CHARS, and an "and M more" tail when the
        // pack ran out of room.
        self::drainNarrowedGrantWarnings($agentManager);

        $chat = $chat->withLaunchNotices(self::launchNotices());

        // The single-writer lock on the session just opened (audit SES-3(b)).
        // Taken LAST so that, when another TUI already has the session, the
        // read-only notice and its `/branch` offer are the bottom row of the
        // first frame rather than buried above the launch warnings. Every
        // later session switch moves the lock with it inside Chat.
        $chat = $chat->withSessionLocking();

        // Bare `--resume`: the picker opens over the fresh session so choosing
        // is the first thing the user does, and Esc leaves a usable chat.
        return $opened['picker'] ? $chat->withSessionPickerOpen() : $chat;
    }

    /**
     * P-TABS.d: the tab path's workspace memo read — the instance the launch
     * (or an earlier tab, or this method's own cold fallback) described, for
     * as long as the root matches. A cold process with no launch to ride is
     * the one case that still pays a full {@see workspace()} describe, and
     * memoizes it so the next tab does not; a tab aimed at a DIFFERENT root
     * falls back the same way rather than riding the wrong workspace.
     */
    private static function sharedWorkspace(?string $root): \SugarCraft\Code\Host\WorkspaceContext
    {
        if (self::$memoWorkspace !== null && self::$memoWorkspaceRoot === $root) {
            return self::$memoWorkspace;
        }

        return self::describeWorkspace($root);
    }

    /**
     * P-TABS.d: describe a fresh workspace and leave it on the tab memo. The
     * launch path of {@see buildChat()} always comes through here: a second
     * launch still re-describes — `workspace()`'s own one-process-one-root
     * law (Appendix O §4.1) — and the memo simply follows it.
     */
    private static function describeWorkspace(?string $root): \SugarCraft\Code\Host\WorkspaceContext
    {
        $workspace = self::workspace($root);
        self::$memoWorkspace = $workspace;
        self::$memoWorkspaceRoot = $root;

        return $workspace;
    }

    /**
     * P-TABS.d: the tab path's session open — the {@see openSession()} shape
     * without the launch-mode ladder, the attach probe or the picker. A named
     * session with a row is read from the store (its transcript loads, and
     * the caller takes its lock through {@see Chat::withSessionLocking()}, so
     * a row another process holds opens read-only exactly as at launch); a
     * named session with no row and an unnamed tab both mint LAZILY, under
     * the given id or a fresh one — the ISS-102 shape, so an empty tab leaves
     * no record behind. A named session whose row ALREADY exists carries no
     * mint to return, so the key is simply absent there.
     *
     * @return array{id: string, name: ?string, history: list<\SugarCraft\Code\Message>, picker: bool, mint?: ?\Closure}
     */
    private static function openTabSession(SessionStore|EnhancedSessionStore $store, ?string $sessionId): array
    {
        if ($sessionId !== null) {
            $row = $store->session($sessionId);
            if ($row !== null) {
                return [
                    'id' => $sessionId,
                    'name' => \is_string($row['name'] ?? null) && $row['name'] !== '' ? $row['name'] : null,
                    'history' => Chat::loadTranscript($store, $sessionId),
                    'picker' => false,
                ];
            }
            $id = $sessionId;
        } else {
            $id = bin2hex(random_bytes(8));
        }

        [$provider, $model] = self::selectedProviderLabel();
        $cwd = getcwd() ?: null;
        $branch = $cwd === null ? null : SessionStore::gitBranchAt($cwd);
        // ISS-102 shape: the row itself is minted only when the tab first
        // dispatches a real prompt, and the defensive re-read keeps an
        // arriving row's own metadata if anything else created it first.
        $mint = static function () use ($store, $id, $provider, $model, $cwd, $branch): void {
            if ($store->session($id) !== null) {
                return;
            }
            $store->createSession($id, $provider, $model, null, null, $cwd, $branch);
        };
        if ($store instanceof EnhancedSessionStore && !self::incognito()) {
            try {
                $store->pruneEmptySessions($id);
            } catch (\Throwable) {
                // Housekeeping only; opening a tab never fails over it.
                // C-SES-7.c rv1: incognito turns WRITES off, and the sweep is
                // a write to the store — an incognito tab opens read-only.
            }
        }

        return ['id' => $id, 'name' => null, 'history' => [], 'picker' => false, 'mint' => $mint];
    }

    /**
     * The non-UI half of {@see chat()}: everything a session needs from its
     * project root that is not a screen, as one {@see \SugarCraft\Code\Host\WorkspaceContext}
     * (roadmap O-2a, Appendix O §4.3).
     *
     * EXTRACTED, NOT DUPLICATED. Every statement below used to be inline in
     * `chat()`, in this order, and the order is load-bearing in the ways the
     * comments say — the refusal before the side effects, the inbox armed
     * before any fork, the launch-notice list reset before the first report.
     * `chat()` now calls this first and keeps the session it opens, the
     * status-line command and the `Chat` itself; a server builds its sessions
     * on the same workspace and cannot drift from the TUI.
     *
     * One process, one root: the {@see $projectTierRefusals} and launch-notice
     * lists this resets, and the trust lists it reads, are process statics, so
     * a second call re-describes the launch rather than adding a second
     * workspace beside the first (Appendix O §4.1 puts multi-root in a
     * process per root).
     */
    public static function workspace(?string $root = null): \SugarCraft\Code\Host\WorkspaceContext
    {
        $root ??= getcwd() ?: null;


        // Every entry point that resolves a root names it as THE project for
        // the settings layers, before anything below reads a config — see
        // {@see useProjectRootForSettings()}. Set on all four rather than in
        // one shared helper because there is no single funnel: `app()` does
        // not call `chat()`'s resolution, and `NonInteractive` enters at
        // `backend()`.
        self::useProjectRootForSettings(self::configRoot($root));

        // A LAUNCH's refusals, not a PROCESS's. This map is what
        // {@see projectTierRefusals()} advertises to a doctor report or a debug
        // pane, and without the reset `chat($badRepo)` followed by
        // `chat($goodRepo)` still reported $badRepo's directory as refused —
        // harmless for the one launch a real binary makes, wrong for every
        // consumer the accessor's doc-block invites.
        //
        // {@see $reportedProjectTierRefusals} is deliberately NOT reset with it,
        // and the asymmetry is the point rather than a half-done one: that set is
        // process-scoped noise control of the same kind
        // {@see warnPermissionConfigOnce()} keeps — "say this once per process"
        // stays true across launches, while "these are the directories refused"
        // is a fact about the launch being built.
        self::$projectTierRefusals = [];

        // Reset HERE, for {@see $projectTierRefusals}'s reason and in the same
        // breath: what the transcript being built has to carry is a fact about
        // this launch. It has to happen before `backend()` below, which is what
        // reaches {@see filterToolSet()} and raises the notice.
        LaunchNotices::reset();

        // THE MID-SESSION HALF OF THE SAME SEAM, armed here and for the same
        // reason the shelf reset above runs here (E171). The list above is
        // drained into {@see Chat::withLaunchNotices()} exactly ONCE, at the
        // end of chat(), so a warning raised after that — by a tool-call
        // parser mid-turn, by a provider that degraded on turn forty — has no
        // reader at all. {@see RuntimeNoticeSink} is that reader's inbox, and
        // {@see Chat::subscriptions()} is what polls it.
        //
        // ARMED HERE SPECIFICALLY BECAUSE OF THE FORK, not for tidiness.
        // {@see \SugarCraft\Code\Backend\EngineBackend::completeAsync()}
        // runs the whole engine loop — provider, parser, tools — inside a
        // `pcntl_fork()`ed child, and a child can only inherit a transport that
        // already existed when it was forked. A turn cannot start before the
        // workspace it runs in exists, so anywhere in here is before every
        // fork; anywhere later would not be. The inbox is the PROCESS sink
        // (see RuntimeNoticeSink::process()), which is the workspace's
        // `notices` below: one session per process, so one inbox per process,
        // exactly as before O-2a split the state onto NoticeSink.
        //
        // AND ARMED HERE RATHER THAN AT THE SINK'S FIRST WRITE, which is the
        // half that keeps the `-p` one-shot out of it. `RuntimeNoticeSink`
        // DROPS until something arms it, and the only reader is
        // `Chat::subscriptions()` — so the seam opens exactly where a
        // workspace for a `Chat` is built and nowhere else. {@see \SugarCraft\Code\Cli\NonInteractive}
        // never reaches this method (it goes straight to `backend()` and
        // `complete()`), so a `-p` run keeps every one of these diagnostics on
        // stderr, where its caller can read them, instead of queueing them in a
        // process that is about to exit.
        //
        // RESET BEFORE ARM, for the reason the two `$launchNotices` lines above
        // are reset here: this method can run twice in one process (the
        // second-scan path, a test), and the second Chat must not inherit the
        // first's undrained inbox. `arm()` is idempotent, so the reset is what
        // makes the pair re-entrant rather than the arm.
        RuntimeNoticeSink::reset();
        RuntimeNoticeSink::arm();

        // plan_comparison TUI-5 stage 1: an Apple-terminal launch is the one
        // place Alt chords die by a setting outside this app. Recorded AFTER
        // the arm because `record()` drops on an unarmed sink — before it,
        // this row would exist only in mutation runs.
        self::announceMacAltFallback();

        // RESOLVED FOR ITS REFUSAL, NOT FOR ITS VALUE, and resolved FIRST.
        // {@see trustedConfigDirPath()} throws when this process cannot tell
        // whose home it is in, which is what stops the launch reading policy
        // out of `sys_get_temp_dir()`. It used to be reached through
        // permissionGate() — five calls further down, after sessionStore() and
        // skillRegistry() had already run — so a launch with `HOME` unset and
        // `TMPDIR` pointed at an attacker-owned directory still refused, but
        // only after CREATING `session.db` inside it. Naming the directory
        // before anything is built is what makes the refusal precede the side
        // effects it exists to prevent.
        self::trustedConfigDirPath();

        $userConfig = self::readUserConfig();

        // ONE store instance for the workspace, so every session this root
        // opens — chat()'s, or a host's — reads and writes one session.db.
        $sessionStore = self::sessionStore();

        // ONE registry across the engine and the sub-agents, for the same
        // reason {@see tools()} shares one across Read/Edit/Glob: two
        // independently scanned registries would let a skill disabled on one
        // still be invocable through the other, and a sub-agent granted a
        // skill the main loop was told not to offer is the wrong side of that
        // to be on. It also keeps the launch to ONE disk scan rather than one
        // per consumer.
        $skills = $root === null ? null : self::skillRegistry($root);

        // Construction time, before Program takes the terminal — see
        // {@see reportSkillSkips()} for why here and nowhere else.
        self::reportSkillSkips();
        self::reportMemorySkips($root);
        self::reportPromptBudgetDeferrals($root, $skills);
        self::reportNonsenseLimits();
        self::reportTuiErrorLogFallback();

        // ONE gate for the whole launch, for the reason the registry above is
        // one: PermissionGate's Auto-mode circuit breaker is per-INSTANCE
        // state, so two gates would each need three strikes before either
        // escalated, and a user watching one counter would be watching half
        // the session's refusals (crush_code.md Phase 1 item 2). It is also
        // ONE read of the config, so the engine and Chat's own tool path can
        // never end up enforcing two different modes — including across a
        // Ctrl+P provider switch, which carries this instance rather than
        // rebuilding one (see Chat::selectPaletteProvider()).
        //
        // Do not over-read the circuit-breaker half of that on the TUI path:
        // EngineBackend::completeAsync() runs the turn in a pcntl_fork()ed
        // child, so the strike increments a turn makes die with the child.
        // Sharing the instance is what unifies the MODE and the RULES today;
        // "one counter for the session" needs the gate's state to cross the
        // fork boundary, which is a separate queued step. See
        // PermissionGateHook::NAME.
        $permissionGate = self::permissionGate(interactive: true);

        // ISS-047: the `keybindings` setting, validated at launch exactly like
        // the spend cap — present-but-unusable throws PermissionConfigException
        // and bin/candy-code reports it as an exit-2 usage refusal. Registered
        // here (not in the registry) so the reader owns the settings layer; the
        // registry is the pure consult (`chordFor()`/`effective()`), and
        // `App::handleKey()` honours a registered override at the keystroke
        // funnel — see KeyBindingRegistry::aliasPress(). An override that
        // lands on another row's shipped chord is legitimate dispatch but a
        // silent steal of that row's own key, so the drain right after it
        // says so — {@see reportKeybindingCollisions()}.
        KeyBindingRegistry::useOverrides(self::keybindingsSetting());
        self::reportKeybindingCollisions();
        self::noteKeybindingsApplied();

        // Built here rather than inside the constructor call so its refusals
        // survive the statement — see the `commandLoader:` argument below.
        $commandLoader = new CommandLoader();

        // PLG-1: the launch's installed plugin bundles ride THIS loader, the
        // one every command consumer reads — an argument parameter would make
        // Chat, SessionHost and the protocol surface each re-derive what the
        // launcher already scanned, and the scan's notices are memoized on
        // exactly one call being the first.
        $commandLoader->useBundles(self::bundles($root));

        // ONE set, THREE READERS (P6.S3; the third added with the nudge gate).
        // `Chat` is the only thing that writes it (the
        // `/rules` command is handled there) and `EngineBackend` is the only thing
        // that reads it (it builds each turn's `App`, and the rules splice subtracts
        // what the App carries). Neither is in a call chain with the other, so the
        // instance is created here and handed to both — which is the same reason
        // {@see tools()} shares one guard chain and {@see agentRoster()} one manager
        // across the launch: two copies would be two sources of truth about which
        // packs the operator just switched off, and the failure is silent. The third
        // reader is the {@see RulePathNudge} that `backend()` → `tools()` builds, and
        // it reads the set at announce time; that is what makes a `/rules` keystroke
        // reach the transient channel and not only the standing one, and it is the
        // same one-instance argument rather than a new one.
        //
        // Guarded by `instanceof` because {@see backend()} answers `Backend`, and a
        // launch configured to shell out to an external binary returns a
        // {@see \SugarCraft\Code\Backend\CommandBackend} that builds no prompt at
        // all. On that path the toggle still records the session's intent and still
        // lists correctly, but it cannot change bytes this process never assembles —
        // stated rather than left to be discovered.
        // THE OPERATOR'S OWN DISABLE-LIST, seeded here so a hand-edited
        // `disabledRules` is honoured from the first turn rather than needing a
        // keystroke (P6.S4). `$userConfig` is the MERGED layered view — assigned
        // above from {@see readUserConfig()}, which answers `mergedConfig(true)`
        // — so
        // `~/.candy-code/config.json` and `~/.candy-code/settings.json` both
        // reach it and no project file can: `disabledRules` is deliberately
        // absent from {@see LayeredSettings::PROJECT_TIER_KEYS}, for the reason
        // on {@see RulesState} — a session may silence a user pack and never a
        // repository one, so a checkout choosing which user packs are off is
        // outside any trust grant. `RulesState::new()`'s `$disabled` parameter
        // existed with no production caller until this line.
        //
        // NOTHING on the launch path may throw, and {@see rulePacksToDisable()}
        // is where that property is kept: `RulesState::new()` parses every entry
        // and rejects a blank or non-string one, while a config file is read
        // tolerantly by design. So the junk is filtered HERE, up front, rather
        // than caught around it.
        $rulesState = RulesState::new(self::rulePacksToDisable($userConfig['disabledRules'] ?? null));

        // E675: the manager and the pool config are HOISTED above the backend
        // so ONE AgentManager instance serves both the tool feed (Task's
        // delegation target) and the Chat (every `/agents` surface). Building
        // a second one for the tool list would fork the roster: `@foo` from a
        // model turn and `@foo` from the TUI would answer different questions.
        $agentManager = self::agentManager($root, $skills);
        $agentPoolConfig = self::agentPoolConfig();
        $taskPool = self::taskWorkerPool($agentPoolConfig);

        $backend = self::backend($root, $skills, $permissionGate, rulesState: $rulesState, taskManager: $agentManager, taskPool: $taskPool);
        if ($backend instanceof EngineBackend) {
            $backend = $backend->withRulesState($rulesState);
        }

        // N-P3a: THE PROVIDER SWITCH BUILDS ITS BACKEND FROM THE LAUNCH'S
        // COLLABORATORS, not from whatever `Chat` happens to hold. Ctrl+P
        // "Switch model" and `/model <provider>` used to call backendFor() with
        // only the root and the gate, so the replacement engine came back
        // without `rulesState` (every `/rules` toggle stopped reaching the
        // prompt) and without a task manager (tools() only adds `Task` when it
        // has one, so the tool vanished mid-session). Chat cannot name these
        // itself — the skill registry, the manager and the pool's provider spec
        // are launch decisions — so the launch hands it this closure.
        //
        // The pool is REBUILT for the new provider rather than reused: its
        // `workerProvider` spec is the provider a forked worker constructs, and
        // the launch's spec names the provider being switched away from.
        $backendFactory = static function (string $providerName) use ($root, $skills, $permissionGate, $rulesState, $agentManager): Backend {
            $switched = self::backendFor(
                $providerName,
                $root,
                $skills,
                $permissionGate,
                rulesState: $rulesState,
                taskManager: $agentManager,
                taskPool: self::taskWorkerPool(self::agentPoolConfig($providerName)),
            );

            return $switched instanceof EngineBackend ? $switched->withRulesState($rulesState) : $switched;
        };

        $workspace = \SugarCraft\Code\Host\WorkspaceContext::new(
            root: $root,
            userConfig: $userConfig,
            // The inbox armed above — the process sink, which is the one every
            // emitter in a one-session process reaches.
            notices: RuntimeNoticeSink::current(),
            rulesState: $rulesState,
            sessionStore: $sessionStore,
            skills: $skills,
            permissionGate: $permissionGate,
            commandLoader: $commandLoader,
            agentManager: $agentManager,
            agentPoolConfig: $agentPoolConfig,
            taskPool: $taskPool,
            backend: $backend,
            backendFactory: $backendFactory,
            // From here down, IN THE ORDER chat() used to evaluate them as
            // constructor arguments: named arguments evaluate in call-site
            // order, and several of these feed the refusal collector that
            // chat() reports after the Chat exists.
            memoryStore: self::memoryStore($root),
            titleBackend: self::titleBackend(),
            summaryBackend: self::summaryBackend($backend),
            maxCostUsd: self::maxCostUsd(),
            hooks: self::hooks($permissionGate, $root),
            backgroundSupervisor: new BackgroundSupervisor(),
            workflowEngine: self::workflowEngine($root, $permissionGate, $skills),
            projectCommandsTrusted: $root !== null && self::projectCommandShellIsTrusted(self::configRoot($root)),
        );

        // THE HOST SERVICES (Appendix O §4.3), registered where the workspace
        // is built so the TUI and any host built on this workspace share ONE
        // instance per root — the transcript store's event log above all, which
        // is per session store and must not be opened twice. Chat resolves each
        // through WorkspaceContext::service() and falls back to a default only
        // when none is registered.
        $transcripts = \SugarCraft\Code\Host\TranscriptStore::new($sessionStore);
        $workspace = $workspace
            ->withService(\SugarCraft\Code\Host\TitleService::class, \SugarCraft\Code\Host\TitleService::new())
            ->withService(\SugarCraft\Code\Host\SpendLedger::class, \SugarCraft\Code\Host\SpendLedger::new())
            ->withService(\SugarCraft\Code\Host\ContextMeter::class, \SugarCraft\Code\Host\ContextMeter::new())
            // Roadmap 5.4-1: model-written summaries are journalled under the
            // home memory directory, per project.
            ->withService(\SugarCraft\Code\Host\CompactionService::class, \SugarCraft\Code\Host\CompactionService::new()->withJournal(
                ($home = self::memoryStoreOrNull($root)) === null ? null : \SugarCraft\Code\Memory\CompactionJournal::forStore($home),
            ))
            // P-AGENT.a: the roster is built by now, so every folded run
            // learns the colour word its preset declares (null when the name
            // is off-roster or the preset declared none).
            ->withService(\SugarCraft\Code\Agents\Live\AgentLiveRegistry::class, \SugarCraft\Code\Agents\Live\AgentLiveRegistry::new()
                ->withColorResolver(static fn (string $name): ?string => $agentManager->get($name)?->color))
            // O-2f: the runner a server host listen()s on; without it every
            // Chat lineage falls back to a runner of its own (TurnRunner::of()).
            ->withService(\SugarCraft\Code\Host\TurnRunner::class, \SugarCraft\Code\Host\TurnRunner::new())
            // O-2g: the submit/queue/dispatch controller the TUI and SessionHost
            // share; stateless today, registered so both resolve one instance.
            ->withService(\SugarCraft\Code\Host\TurnController::class, \SugarCraft\Code\Host\TurnController::new())
            // N-P3b: `/model <provider> <model>` persists through this writer,
            // and app() hands the SAME instance to the settings editor — one
            // writer per launch, so the two doors cannot disagree on trust.
            ->withService(\SugarCraft\Code\Config\Settings\SettingsWriter::class, self::settingsWriter($root))
            ->withService(\SugarCraft\Code\Host\TranscriptStore::class, $transcripts)
            // AUT-1: schedules.json is read once per launch here; every door
            // that lists or fires rows resolves THIS instance (TUI pump,
            // /schedules, serve's BackgroundEvents). Reload-on-stat-change
            // keeps hand-edits landing within a tick of the roster's own
            // clock, so nothing else needs to watch the file.
            // C-AGT-1: the launch root rides this call as the host's binding —
            // the single place a registry learns which project it speaks for,
            // so a row fires only where its own `root` says it belongs and not
            // in whichever host happened to claim the shared file first.
            ->withService(\SugarCraft\Code\Background\ScheduleRegistry::class, \SugarCraft\Code\Background\ScheduleRegistry::loadDefault($root))
            // C-TOOL-3.d: `/shells` reads the launch's shell table through
            // the SAME memoized registry `self::backgroundShells()` hands the
            // Shell tool spawns onto, so the command lists what this process
            // actually started — never a second table, never a second reaper.
            ->withService(\SugarCraft\Code\Background\BackgroundShellRegistry::class, self::backgroundShells($root));

        // C-SES-4.d (owner ruling §6.1, decided 2026-10-26): serve exports
        // OTel spans when OTEL_EXPORTER_OTLP_ENDPOINT is set. The export
        // lands as a workspace SERVICE, not a threaded sink, because a
        // serve process hosts many sessions and OtlpEncoder keys open turn
        // spans by step number — one shared encoder would collide identical
        // steps across sessions. Each SessionHost therefore builds its own
        // sink with its own session.id-tagged encoder through this factory;
        // the dark branch never registers the service at all.
        $otelConfig = \SugarCraft\Code\Observability\OtelConfig::fromEnvironment();
        if ($otelConfig->exportsEvents()) {
            $workspace = $workspace->withService(
                \SugarCraft\Code\Observability\EventSinkFactory::class,
                $otelConfig->buildFactory(
                    static fn (\SugarCraft\Code\Observability\OtelConfig $config): \SugarCraft\Code\Observability\EventSinkFactory => \SugarCraft\Code\Observability\EventSinkFactory::fromConfig($config, new \GuzzleHttp\Client()),
                ),
            );
        }

        // C-AGT-5.f: outbound webhooks, read ONCE here at launch from the
        // user tier only — `webhooksOut` is not in LayeredSettings'
        // LAYERED_KEYS, so a project or system file can never widen it. A
        // present-but-unusable value throws PermissionConfigException, the
        // same exit-2 launch refusal as the spend cap and keybindings
        // above: a mis-typed endpoint should cost a restart message, not a
        // silently missing delivery. The dispatcher is registered as a
        // workspace SERVICE (like the OTel sink factory directly above) so
        // both hosts of this workspace — the TUI Chat and every served
        // SessionHost — reach the same single queue through
        // WorkspaceContext::service(); the dark branch never registers it.
        $outboundWebhooks = self::outboundWebhookEndpoints($userConfig);
        if ($outboundWebhooks !== []) {
            $workspace = $workspace->withService(
                \SugarCraft\Code\Automation\WebhookDispatcher::class,
                \SugarCraft\Code\Automation\WebhookDispatcher::fromConfig($outboundWebhooks),
            );
        }

        $events = $transcripts->events();

        return $events === null
            ? $workspace
            : $workspace->withService(\SugarCraft\Code\Host\EventLog::class, $events);
    }

    /**
     * C-AGT-5.f: the `webhooksOut` user-tier map, validated for launch.
     * Parsing lives in {@see \SugarCraft\Code\Automation\OutboundWebhookConfig::parse()}
     * (https-only except literal loopback, secrets of at least 16 bytes,
     * known event names only); this method only decides *where* the setting
     * is read and turns a parse refusal into the exit-2 launch refusal.
     * Absent key means dark, exactly like an empty map.
     *
     * @param array<string,mixed> $userConfig the user-tier view from {@see readUserConfig()}
     *
     * @return array<string,array{url:string,secret:string,events:list<string>}>
     *
     * @throws PermissionConfigException when the key is present and unusable
     */
    public static function outboundWebhookEndpoints(array $userConfig): array
    {
        if (!isset($userConfig['webhooksOut'])) {
            return [];
        }

        try {
            return \SugarCraft\Code\Automation\OutboundWebhookConfig::parse($userConfig['webhooksOut']);
        } catch (\InvalidArgumentException $e) {
            throw new PermissionConfigException(
                Lang::t('settings.webhooksOut.refuse', ['reason' => $e->getMessage()]),
            );
        }
    }

    /**
     * The {@see WorkflowEngine} `/workflow` dispatches onto, reading workflows
     * from this user's `~/.candy-code/workflows` and, when the launch has a
     * root, that project's `.candy-code/workflows`.
     *
     * {@see trustedConfigDirPath()} rather than {@see configDirPath()}, and the
     * distinction is not bookkeeping: {@see WorkflowRegistry::load()} reaches a
     * `.php` workflow through `require`, so the user tier is a directory whose
     * contents get EXECUTED, which is the same class of thing as `hooks.yaml`
     * and not the class of thing the session store is. Under the stand-in home
     * a local user could pre-create `/tmp/.candy-code/workflows/deploy.php`
     * and own the session the moment its owner typed `/workflow run deploy`.
     * {@see chat()} already refuses that launch on its first line, so this is
     * not a second gate — it is the requirement stated where the directory it
     * applies to is named, so a future second caller of this method inherits
     * the refusal rather than having to remember it.
     *
     * The project tier needs no such refusal because it is `.yaml`-only by
     * construction — see {@see WorkflowRegistry::__construct()} for why a
     * checked-in `.php` workflow is not honoured at all.
     *
     * Both `$model` and `$provider` come from the launch's own selection rather
     * than {@see WorkflowEngine}'s defaults; see that constructor for what the
     * defaults were and why they are wrong for any session that did not select
     * them.
     *
     * @param PermissionGate $gate The launch's ONE gate — the same instance
     *        {@see chat()} hands the backend and the hook chain, for the reason
     *        stated there. Required rather than defaulted precisely because a
     *        default would let a future caller build a SECOND gate here without
     *        noticing: two gates from one config enforce the same mode but split
     *        PermissionGate's per-instance Auto-mode strike counter, which is the
     *        thing {@see chat()} building exactly one exists to prevent. Passing
     *        it is what makes a workflow-spawned
     *        {@see \SugarCraft\Code\Agents\SubAgent} carry a gate at all
     *        (before this, every one of them was constructed with
     *        `permissionGate: null`) and what lets the engine refuse a stage
     *        whose DECLARED tools this session's mode denies. Read
     *        {@see WorkflowEngine::__construct()} for the precise boundary of
     *        what that does and does not enforce today — it is narrower than
     *        "workflow tool calls are gated", and the difference is stated there
     *        rather than implied here.
     * @param SkillRegistry|null $skills Pass the caller's registry so the
     *        tool universe built for the engine's `toolRegistry:` resolves
     *        skills the same way the session's own set does — the same sharing
     *        {@see agentManager()} documents for itself. Null re-scans at $root.
     */
    private static function workflowEngine(?string $root, PermissionGate $gate, ?SkillRegistry $skills = null): WorkflowEngine
    {
        [$provider, $model] = self::selectedProviderLabel();

        $userConfigDir = self::trustedConfigDirPath();

        // The PROJECT tier walks up to the repository (audit 15d-13 (b)); the
        // engine's `environmentRoot` below stays the launch directory.
        $configRoot = self::configRoot($root);

        $registry = new WorkflowRegistry(
            $userConfigDir . '/workflows',
            $configRoot === null ? null : rtrim($configRoot, '/') . '/.candy-code/workflows',
            // The root is passed, not merely used to build the path above,
            // because it is the boundary the project workflows DIRECTORY is
            // held inside — and it is the complete boundary only when the
            // registry is told it. Without it the registry falls back to
            // that directory's own parent, which a committed
            // `.candy-code -> /elsewhere` walks straight past. See
            // {@see WorkflowRegistry::readableProjectDir()}.
            projectRoot: $configRoot,
            // THE SAME ANCHOR, FOR THE TIER THAT IS `require`d, and the reason it
            // is passed rather than left to the registry's parent-directory
            // fallback is the reason $root above is: the fallback catches a link
            // AT `workflows` and walks past one at `.candy-code`. Derived from
            // the ONE trusted resolution the directory itself came from — the
            // same construction {@see agentPresetTiers()} uses — so the workflows
            // directory and the home it is held inside can never be two
            // different homes. Measured before this existed: a tarball-delivered
            // `~/.candy-code/workflows -> <outside>` had `/workflow run` execute
            // arbitrary PHP as the launching uid; see
            // {@see WorkflowRegistry::__construct()}.
            userHome: \dirname($userConfigDir),
        );

        // Asked EAGERLY, here, rather than left for the first `/workflow list`
        // to discover: the refusal is otherwise invisible in every direction a
        // user looks — the not-found message stops naming the directory,
        // `projectWorkflowsPath()` still reports it, and the listing simply has
        // fewer names in it. See {@see reportProjectTierRefusals()}.
        $refusal = $registry->projectTierRefusal();
        if ($refusal !== null && $registry->projectWorkflowsPath() !== null) {
            self::$projectTierRefusals[$registry->projectWorkflowsPath()] = $refusal;
        }

        // AND THE USER TIER'S, which is the one refusal in this collector that is
        // not about a repository-chosen directory — see the collector's own
        // doc-block for why it is drained here anyway. A user whose own
        // `~/.candy-code/workflows` this launch will not `require` out of has
        // otherwise nothing at all telling them so: `list()` is simply shorter and
        // the not-found message names a directory the loader never opened.
        $userRefusal = $registry->userTierRefusal();
        if ($userRefusal !== null) {
            self::$projectTierRefusals[$registry->workflowsPath()] = $userRefusal;
        }

        // E641's Bootstrap-side seam: a workflow task declares tool NAMES, and
        // resolveRequestTools() can only turn one into the Tool OBJECT every
        // provider demands if the engine holds the session's registry. The
        // value mirrors `agentManager()`'s `toolRegistry:` exactly — the same
        // universe (threading $skills so a skill disabled in the user config
        // is disabled for workflow sub-agents too) through the same PURE
        // PREDICATE `toolSetUnder()`, deliberately not the reporting `tools()`
        // wrapper: the launch notices for a removed or emptied set already fire
        // at the model-facing call sites, and re-firing them here would tell
        // the operator twice what one config line means — the same reason
        // `agentManager()` states for itself.
        // E663: the engine's pool gets the SAME launch-derived worker spec the
        // chat sub-agent path was fed in E652 — reached through the same
        // `agentPoolConfig()` so the selection logic lives in exactly one
        // place and cannot drift. `/workflow run` stages now fork workers that
        // genuinely consult the model. NO `executor:` is injected here, on
        // purpose: an injected executor flips the pool onto the synchronous
        // in-parent dispatch documented at AgentWorkerPool::__construct() and
        // silently opts the engine out of the forking topology (and its live
        // progress) that the pool's own `workerProvider` parameter is the
        // production seam for. A null spec is a verdict, not a gap: the pool
        // builds its default refusing worker and every stage surfaces FAILED
        // naming the absence — this wiring deliberately never echo-degrades.
        $poolConfig = self::agentPoolConfig();

        return new WorkflowEngine(
            $registry,
            // forkedExecutor: every stage agent is a real tool loop through
            // the session engine (bound late by Chat's constructor), run in
            // the pool's forked child so a long stage never blocks the TUI.
            // ONLY WITH A PROVIDER: a launch with none degrades the CHAT to the
            // offline echo backend, and a stage run through that engine would
            // "succeed" with echoed text — so a spec-less pool keeps the
            // worker that fails closed naming the absence (E663). A spec whose
            // provider then fails to build lands on the same echo engine;
            // EngineExecutor refuses that one itself at run time.
            pool: (new \SugarCraft\Code\Agents\AgentWorkerPool(
                maxConcurrent: $poolConfig->maxConcurrent,
                workerProvider: $poolConfig->workerProvider,
                forkedExecutor: $poolConfig->workerProvider === null ? null : new \SugarCraft\Code\Agents\EngineExecutor(),
            ))->withStopOnFirstFailure($poolConfig->stopOnFirstFailure)->withMaxRetries($poolConfig->maxRetries),
            model: $model,
            provider: $provider,
            permissionGate: $gate,
            environmentRoot: $root,
            toolRegistry: self::toolSetUnder(self::unfilteredTools($root, skills: $skills), self::readUserConfig()),
        );
    }

    /**
     * Every project-tier directory this launch declined to read, keyed by the
     * configured path, mapped to why.
     *
     * The pull-based seam {@see skillSkips()} is, for the other half of the same
     * question: a repository chooses where these paths point, and one that
     * resolves out of the checkout is refused wholesale. Kept where a doctor
     * report or a debug pane can ask for it, with
     * {@see reportProjectTierRefusals()} putting one bounded line in front of
     * the user at launch.
     *
     * TWENTY-FOUR repository-chosen DOT-DIRECTORY paths exist in `src/` — and the
     * qualifier is the number's domain rather than decoration. What the
     * derivation counts is a string literal of the shape `.<dir>/<segment>`:
     * FORTY-EIGHT distinct ones on this tree, twenty-four of them classified
     * repository-chosen. This list said FOUR, then FIVE, both hand-written; it is
     * now DERIVED from `src/` by
     * {@see \SugarCraft\Code\Tests\Cli\ProjectTierRefusalInventoryTest}, which
     * reds when a new path appears unclassified.
     *
     * IT WENT TEN -> THIRTEEN IN ONE CHANGE-SET, from three different causes, and
     * they are worth separating because only one of them is a new path; P6.S2 then
     * added `.candy-code/rules` (the rules tier) as a fourteenth, and it is a new
     * path and a named gap at once — see the gap column below. P7.S6 added
     * `.candy-code/memory` — the sentinel directory of the now-wired
     * `/memory import` trigger — as a fifteenth, again a new path and a named gap
     * at once, gated at its call site rather than drained here. INT-1(c) made it
     * SEVENTEEN with the instruction tail alias `.github/copilot-instructions.md`
     * — once more a new path and a named gap at once: the loader gates it with
     * the instruction files' own compares and records refusals loader-local.
     * INT-2 (plan_comparison W4) made it NINETEEN with the two foreign rule
     * trees `.claude/rules` and `.cursor/rules` — each ONE literal in
     * {@see \SugarCraft\Code\Context\RuleLoader} serving both tiers, so the
     * project leg is repository-chosen and joins the gap column under the same
     * loader-local discipline as its native `.candy-code/rules` sibling.
     * INT-3 (plan_comparison W5, lane w5d) made it TWENTY-TWO with the three
     * foreign command-floor trees `.claude/commands`, `.opencode/command` and
     * `.opencode/commands` — each ONE literal in
     * {@see \SugarCraft\Code\Commands\ForeignCommandDiscovery} serving both
     * tiers, so the project legs are repository-chosen and gated loader-local
     * under the same discipline as the rules trees above.
     * PLG-1 (plan_comparison W6, lane w6a) made it TWENTY-FOUR with the bundle
     * floor `.candy-code/bundles` — a TENTH feeder, its containment refusals
     * drained at the {@see bundles()} choke — and the bundle manifest
     * `.claude-plugin/plugin.json`, one literal in
     * {@see \SugarCraft\Code\Bundles\Bundle} serving both tiers, gated with its
     * bundle directory and named as a gap beside the command-floor trees.
     *
     *  - `.candy-code/settings.json` and `.candy-code/settings.local.json` are
     *    genuinely new — the project tier of
     *    {@see \SugarCraft\Code\Config\LayeredSettings}.
     *  - `.candy-code/config.json` was RECLASSIFIED, not added. It was
     *    package-relative for as long as {@see \SugarCraft\Code\Agents\WorktreeManager}
     *    built its config with a bare `WorktreeConfig::new()`; that constructor
     *    now passes the repository under management, so the same literal reads a
     *    tree the operator cloned.
     *
     * The DISTINCT count had also drifted on its own: it read "twenty" while
     * `src/` held twenty-one, because no assertion stood behind it — a figure in
     * prose decays silently. {@see \SugarCraft\Code\Tests\Cli\ProjectTierRefusalInventoryTest::testBothCensusFiguresThisDocBlockQuotes()}
     * now pins both numbers, which is the only thing that keeps a sentence like
     * this one true.
     *
     * THE SHAPE EXCLUDES A BARE DOT-FILE, and one of those is in this map. A
     * literal with no directory component — `src/` holds exactly two,
     * `.mcp.json` in {@see mcpClient()} and `.phpunit.cache` in `IgnoreRules` —
     * cannot match, so `.mcp.json` is repository-chosen, feeds this collector, and
     * is not one of the directory paths. TEN repository-chosen paths therefore
     * produce entries here: the eight below, `.candy-code/commands` (see the
     * paragraph on it), and `.candy-code/bundles`; beside them `.mcp.json`, which
     * {@see $projectTierRefusals} records as the only entry that is not a
     * directory. Stating the two figures without their domain is how the count
     * that this whole enumeration exists to prevent gets made in the sentence
     * describing it.
     *
     * The EIGHT whose refusals reach THIS map:
     *
     *   `.candy-code/workflows`  `.candy-code/skills`  `.claude/skills`
     *   `.opencode/skills`        `.candy-code/agents`  `.claude/agents`
     *   `.opencode/agents`        `.agents/skills`
     *
     * The last two joined in crush_code.md Phase 1 item 3, which wired
     * {@see foreignAgentPresets()} and gave that registry's refusal seam its first
     * reader; the split was five and five before it.
     *
     * The FOURTEEN that are gated elsewhere and named as gaps rather than
     * counted here — `.opencode/memory`, `.candy-code/hooks.yaml`,
     * `.candy-code/config.json`, `.candy-code/settings.json`,
     * `.candy-code/settings.local.json`, the rules tier `.candy-code/rules`,
     * the import sentinel `.candy-code/memory`, and the instruction tail alias
     * `.github/copilot-instructions.md`, and the three command-floor trees
     * `.claude/commands`, `.opencode/command` and `.opencode/commands`, and the
     * bundle manifest `.claude-plugin/plugin.json` — are
     * itemised on {@see $projectTierRefusals}, all fourteen of them.
     *
     * `.candy-code/commands` IS NOT ONE OF THEM, and this sentence used to say
     * it was — a gap list that went stale when crush_code.md Phase 2 item 4
     * wired {@see \SugarCraft\Code\Commands\CommandLoader} and
     * {@see chat()} started draining `refusedDirectories()` straight into
     * {@see $projectTierRefusals}. It is a ninth feeder beside the eight above,
     * and PLG-1's `.candy-code/bundles` is the tenth beside those two.
     * The stale sentence
     * then very nearly reclassified the live code to match itself, which is the
     * direction this project's recurring defect always runs: prose is easier to
     * believe than a drain twenty lines long.
     *
     * The last three of the five joined with the settings layering and the
     * `WorktreeConfig` reclassification above, and all three are gaps
     * DELIBERATELY rather than pending work. `.candy-code/config.json` is read
     * by a class nothing in `src/` constructs, so a refusal it recorded would
     * have no reader. The two `settings*.json` files are read by
     * {@see readUserConfig()}, which `EngineBackend` calls once per TURN: an
     * entry per read would either repeat or, worse, make "this project is not
     * opted in" — the ordinary state of every project the user has not listed —
     * look like a failure in a doctor report. Both are gated; neither is
     * reported.
     *
     * @return array<string, string> configured path => why it was refused
     */
    public static function projectTierRefusals(): array
    {
        return self::$projectTierRefusals;
    }

    /**
     * Tell the user, once per refused directory, that a project directory their
     * repository ships was not read.
     *
     * NAMED INDIVIDUALLY, unlike {@see reportSkillSkips()}'s bare count, and the
     * asymmetry is deliberate: a skipped SKILL.md is somebody else's malformed
     * file and there can be dozens, while a refused directory is a deliberate
     * refusal of something this repository committed, there is at most one per
     * subsystem, and the path plus the reason is the whole of what a user needs
     * to fix it. A count alone ("1 directory was refused") would be the same
     * silence the notice exists to end.
     *
     * Construction time only, for the reason {@see reportSkillSkips()} states:
     * stderr under a live alt screen lands inside a frame the renderer believes
     * it owns.
     */
    public static function reportProjectTierRefusals(): void
    {
        $new = array_diff_key(self::$projectTierRefusals, self::$reportedProjectTierRefusals);

        foreach ($new as $path => $reason) {
            self::$reportedProjectTierRefusals[$path] = true;
            // BOTH CHANNELS — see {@see warnPermissionConfigInTranscript()}. A
            // refused directory is this repository's skills, commands, agents
            // or workflows absent from the session, and the user meets that by
            // typing a name that does not resolve. The path plus the reason is
            // the whole of what they need, which is why this stayed a per-path
            // line rather than a count.
            //
            // THE ONE PER-PATH SOURCE ON THE SEAM, and the reason
            // {@see LAUNCH_NOTICE_LIMIT} exists: the ten feeders named above
            // are bounded, but $commandLoader->refusedCommands() is one entry
            // per refused FILE and nothing caps that.
            self::warnPermissionConfigInTranscript(
                sprintf(self::translated(self::PROJECT_TIER_REFUSAL_FORMAT), $path, rtrim($reason, '.')),
            );
        }
    }

    /**
     * The installed plugin bundles this launch reads, keyed by the exact
     * (root, pluginPaths) pair it was last asked about.
     *
     * PLG-1 (plan_comparison W6) ships the READER, not an installer and not a
     * registry: a bundle is a directory carrying `.claude-plugin/plugin.json`
     * (or the tolerated bare `plugin.json`) whose `skills/`, `commands/` and
     * `agents/` trees map onto the three loaders that already import those
     * surfaces from Claude Code and opencode. Which roots hold bundles —
     * `<root>/.candy-code/bundles`, `~/.candy-code/bundles`, and every
     * directory listed in the user-tier `pluginPaths` setting — is
     * {@see BundleDiscovery}'s subject; this method is the ONE caller that
     * turns them on:
     *
     *  - {@see workspace()} feeds the result to the launch's single
     *    {@see CommandLoader} (`useBundles`), which every command consumer —
     *    Chat, SessionHost, the protocol's command list — then reads;
     *  - {@see skillRegistry()} passes it to {@see SkillManager::loadAll()};
     *  - {@see foreignAgentPresets()} passes it to the preset registry's
     *    `discover()`.
     *
     * Two of the format's five surfaces are DECLINED AT THE WIRE and this is
     * where the decline is said, once per launch: bundle HOOKS are never
     * registered (hook surfaces stay candy-code-owned, the same ruling that
     * keeps `hooks.json` out of cloned `.claude/` trees), and a bundle's
     * `.mcp.json` is never AUTO-STARTED — the operator reviews it and imports
     * it deliberately through `candy-code mcp import`. Both get a launch
     * notice naming the affected bundles, because a bundle that loads its
     * skills but silently runs no hooks looks exactly like a bundle whose
     * hooks the user never wanted.
     *
     * Refused bundle directories feed {@see $projectTierRefusals} like every
     * other containment answer; per-bundle parse problems rise as warnings.
     * The whole scan — notices included — is memoized per (root, paths), so
     * the many scans a launch performs (chat, app, provider switches) cost
     * one walk and say their decline exactly once.
     *
     * @return list<Bundle>
     */
    public static function bundles(?string $root): array
    {
        $configured = self::readUserConfig()[BundleDiscovery::SETTING_KEY] ?? [];
        $paths = array_values(array_filter(\is_array($configured) ? $configured : [], 'is_string'));

        $key = ($root ?? '') . '|' . implode('|', $paths);

        if (LaunchContext::hasBundlesMemo($key)) {
            return LaunchContext::bundlesMemo($key);
        }

        $discovery = new BundleDiscovery($root ?? '', $paths);
        $bundles = $discovery->discover();

        foreach ($discovery->warnings() as $warning) {
            self::warnPermissionConfigInTranscript($warning);
        }

        self::$projectTierRefusals = [...self::$projectTierRefusals, ...$discovery->refusedDirectories()];

        $hooked = [];
        $mcped = [];

        foreach ($bundles as $bundle) {
            if ($bundle->hooksPaths() !== []) {
                $hooked[] = $bundle->name;
            }

            if ($bundle->mcpFile() !== null) {
                $mcped[] = $bundle->name;
            }
        }

        // Lang::t(), not sprintf(): this file's launch-format census counts
        // sprintf call sites, and a notice added here must not move it — the
        // placeholder interpolation is what the catalogue entries are for.
        if ($hooked !== []) {
            self::warnPermissionConfigInTranscript(Lang::t('bundles.hooks_declined', [
                'count' => (string) \count($hooked),
                'names' => implode(', ', $hooked),
            ]));
        }

        if ($mcped !== []) {
            self::warnPermissionConfigInTranscript(Lang::t('bundles.mcp_not_auto_started', [
                'count' => (string) \count($mcped),
                'names' => implode(', ', $mcped),
            ]));
        }

        return LaunchContext::rememberBundlesMemo($key, $bundles);
    }

    /**
     * The {@see AgentManager} a launch delegates through, with the run's
     * roster already registered.
     *
     * Built here rather than inside {@see Chat} because a manager needs a
     * {@see ProviderInterface} and a {@see SkillRegistry} — Chat holds neither
     * (it holds the unrelated {@see Backend} interface), which is the exact
     * reason `Renderer.php`'s R20.fix note gave for the wiring never having
     * landed. Both are already built here for other consumers, so this is a
     * construction-wiring method, not new machinery.
     *
     * @param SkillRegistry|null $skills Pass the caller's registry to avoid a
     *        second disk scan; the sub-agents this manager runs resolve their
     *        `skills:` names against it, so sharing the instance also means a
     *        skill disabled in the user config is disabled for sub-agents too.
     * @param \Closure(\SugarCraft\Code\ToolCall, \SugarCraft\Code\Agents\SubAgent): bool|null $approver
     *        Settles a sub-agent's {@see \SugarCraft\Code\Permissions\PermissionDecision::Ask}.
     *        Null leaves it failing closed — see
     *        {@see AgentManager::evaluateToolCalls()}. Nothing supplies one
     *        yet: the caller that should is {@see Chat}, which owns the
     *        blocking prompt UI, and that wiring is its own change.
     *
     * @throws PermissionConfigException when the permission config is present
     *         and unusable — raised HERE, on the launch path, rather than from
     *         inside the gate factory at {@see AgentManager::createSubAgent()}
     *         time. See the eager read in the body.
     */
    public static function agentManager(?string $root = null, ?SkillRegistry $skills = null, ?\Closure $approver = null): AgentManager
    {
        $root = self::requireRoot($root);
        [$provider, $model] = self::provider();

        // Read here, EAGERLY, rather than inside the factory closure below.
        // The closure runs at createSubAgent() time, which — once `/agents`
        // dispatches onto it — is mid-TUI, where a PermissionConfigException's
        // only handler is `bin/candy-code`'s and its exit(2) would abandon the
        // terminal in alt-screen/raw mode with the message painted over the
        // frame. Reading at CONSTRUCTION puts the same refusal on the launch
        // path, before Program::run() has taken the screen, which is where
        // every other permission-config refusal already lands.
        //
        // The consequence is deliberate: the policy a launch starts with is
        // the policy its sub-agents get, even if the file is edited underneath
        // a running session. That is the same "one config source for the whole
        // launch" the main-loop gate already commits to.
        //
        // C-SEC-6.b completes that identity on the RULES axis: the trusted
        // project's deny/ask entries ride this feed BEFORE the user's, exactly
        // as they ride ahead at {@see permissionGate()}. Without the prepend
        // here the project's narrowing would bind the main chat's gate only
        // and a Task sub-agent would run under the user rules alone — a
        // child wider than the session that spawned it, which is the one
        // direction the deny/ask-only tier exists to forbid. The notice the
        // load raises is signature-deduped per process, so a launch that
        // builds both gates still says it once.
        $subAgentUserRules = self::permissionRules(self::permissionConfig());
        $permissionRules = [...self::projectNarrowingRules(), ...$subAgentUserRules];

        // ONE skill registry for the manager AND the tool set: the SkillTool in
        // the grant below reads the same instance the sub-agent prompt splice
        // does, so a skill disabled in the user config is disabled for
        // sub-agents in both senses at once.
        $resolvedSkills = $skills ?? self::skillRegistry($root);

        // THE SUB-AGENT GRANT NEEDS BOTH HALVES of the tool story, which is
        // why this builds the ceiling ONCE and filters it locally rather than
        // calling tools(): AgentManager distinguishes "the operator disabled
        // Bash" (intersect — the grant survives narrowed) from "the preset
        // misspelled Reed" (refuse), and that comparison needs the filtered
        // set as the roster's ceiling and the unfiltered one as the universe.
        // Passing only the filtered set turned `disabledTools: ["Bash"]` —
        // documented as intentional — into a hard crash for five of the six
        // built-in presets; E639's measured trap and the reason this wiring
        // is not the one-liner the backlog first called it.
        //
        // The filter here is the PURE PREDICATE, not the reporting wrapper:
        // the session's launch notices for a removed or emptied set already
        // fire at the model-facing call sites (app/backend/backendFor), and a
        // manager that re-fired them would tell the operator twice what one
        // config line means. Same array, same predicate, no second announcement.
        $toolUniverse = self::unfilteredTools($root, skills: $resolvedSkills);

        $manager = new AgentManager(
            $provider,
            $resolvedSkills,
            // Sub-agent gates are built from the SAME config the main loop's
            // gate is (crush_code.md Phase 1 item 2's "one config source"),
            // rather than AgentManager's own bare `new PermissionGate($mode)`
            // fallback: that fallback passes no SafetyClassifier, and
            // PermissionGate::evaluateAuto() fails closed without one — so a
            // preset declaring `permissionMode: auto` got a gate that asked
            // about literally every call instead of classifying any of them.
            // The MODE still comes from the preset, not from the config: an
            // agent that declares its own mode means it.
            permissionGateFactory: static fn(PermissionMode $mode): PermissionGate => new PermissionGate(
                $mode,
                $permissionRules,
                new SafetyClassifier(),
                // Named honestly: this gate's mode came from the preset, not
                // from the precedence chain permissionGate() walks, and a
                // sub-agent's `/permissions` must not claim otherwise.
                Lang::t('cli.launch_source.preset_mode'),
            ),
            permissionApprover: $approver,
            toolRegistry: self::toolSetUnder($toolUniverse, self::readUserConfig()),
            toolUniverse: $toolUniverse,
            // The root the tools above resolve against, so a sub-agent's path
            // rules judge the file the tool opens, not just its spelling (F-J3).
            projectRoot: $root,
        );

        // A snapshot captured at $root for every agent, which is what closes
        // the "sub-agents are told the process cwd, not the configured root"
        // gap: Agent::systemPrompt()'s own last-resort
        // EnvironmentBlock::capture(getcwd()) is documented as the fallback for
        // callers holding no session snapshot, and this caller holds one. A
        // `--root sugar-markdown` run now orients its sub-agents at sugar-markdown
        // rather than at wherever the binary was invoked from.
        //
        // Captured PER AGENT, at that agent's own model. The block renders a
        // `Model:` line into the prompt, so one shared instance stamped the
        // SESSION's model onto every agent: a preset declaring
        // `model: gpt-5-turbo` was handed to a sub-agent whose system prompt
        // said `Model: echo`. Sharing bought nothing in exchange either --
        // EnvironmentBlock::render() is not memoised, so its git shell-out
        // happens once per systemPrompt() call whichever instance it is called
        // on, and capture() itself only stores three values.
        // Roadmap 4.1-1: `subagentModel` is the model every agent that would
        // otherwise inherit the session's runs on — a preset's `model:
        // inherit` (or none) and the built-in definitions. An agent that names
        // its own model keeps it, and a Task call's `model` argument still
        // wins over both (TaskTool::chooseModel()).
        $userConfig = self::readUserConfig();
        $subagentModel = $userConfig['subagentModel'] ?? null;
        $subagentModel = is_string($subagentModel) && trim($subagentModel) !== '' ? trim($subagentModel) : null;

        foreach (self::agentRoster($root, self::selectedProviderName() ?? 'echo', $model) as $agent) {
            if ($subagentModel !== null && $agent->inheritsModel) {
                $agent = $agent->withModel($subagentModel);
            }
            // `env.*` (roadmap N-P4d) shape a sub-agent's block as they shape
            // the main session's (Runtime::environmentSnapshot()).
            $manager->register($agent->withEnvironment(EnvironmentBlock::capture($root, $agent->model)->withSettings($userConfig)));
        }

        // Step P-C1: a finished delegation becomes a `subagent` child session
        // of the session that delegated it. The manager writes it from the
        // parent process (its projection runs there), into this launch's
        // session database — opened on the first finish, not at launch, and
        // without the launch-time prune, which sessionStore() leaves to chat().
        $childStore = null;
        $manager->recordChildSessionsIn(
            static function () use (&$childStore): EnhancedSessionStore {
                return $childStore ??= self::sessionStore(false);
            },
            \SugarCraft\Code\Agents\Live\SubAgentTranscriptLog::defaultRoot(),
        );

        // Roadmap 4.6-2: teams. The manager is the per-user team store
        // (TeamManager's default base path), the one the `Team` tool opens in the
        // process that runs each call; the registry on disk is what they
        // share, so building it here reads and opens nothing until asked —
        // no team's SQLite task list is opened in this process before a
        // turn forks from it.
        $manager->setTeamManager(new \SugarCraft\Code\Agents\TeamManager());

        return $manager;
    }

    /**
     * The sentence {@see attributeInheritedPrompt()} appends to a prompt a
     * body-less preset inherited instead of erasing.
     *
     * WHY THE FIRST `%s` IS A SEPARATE ARGUMENT rather than two whole sentences:
     * built-in and imported are the same claim about the same two tiers, and a
     * second copy of the sentence would be free to drift from the first. The two
     * words are also the entire difference between the variants, so a test can
     * pin both from one place.
     *
     * WHAT THIS MUST NEVER SAY. No prompt written here may claim a TOOL grant —
     * a preset's `tools:` frontmatter is resolved against a registry the roster
     * never builds, so such a claim would be provably false in production. This
     * sentence is deliberately about the SOURCE of the prose and about nothing
     * the agent can do. (The inherited reviewer prompt does say "skills you have
     * been granted"; that is `AgentDefinition`'s own wording, copied
     * byte-verbatim, and a SKILL claim rather than a tool one.)
     */
    private const PROMPT_ATTRIBUTION_FORMAT = 'Your operating instructions were supplied by the %s '
        . "agent definition '%s'; this preset contributed only its frontmatter settings.";

    /**
     * Every agent a launch can delegate to: the six built-in
     * {@see AgentDefinition} templates, then any `.md`+frontmatter preset
     * discovered under `{root}/.candy-code/agents` or `~/.candy-code/agents`.
     *
     * Presets are applied second and by name, so a project that ships its own
     * `reviewer.md` replaces the built-in `reviewer` rather than adding a
     * duplicate row to `/agents`.
     *
     * Everything is registered INACTIVE. On {@see Agent} active means
     * "currently working" — the renderers turn it into the literal word — so a
     * roster registered active would paint an agent strip on every launch
     * claiming six agents were working on a session where nothing has been
     * delegated. {@see AgentManager::active()} derives the live value from
     * running sub-agents instead.
     *
     * Foreign presets (`.claude/agents`, `.opencode/agents`, via
     * {@see \SugarCraft\Code\Agents\ForeignAgentPresetRegistry}) ARE merged
     * here as of crush_code.md Phase 1 item 3 — see
     * {@see foreignAgentPresets()}. They go in FIRST, which makes the roster's
     * precedence three layers deep, lowest first:
     *
     *     foreign imports  <  the six built-in definitions  <  native presets
     *
     * NATIVE WINS AT EVERY TIER, built-ins included. Skills share only half of
     * that direction: {@see \SugarCraft\Code\Skills\SkillManager::loadAll()}
     * lays the native manifest over the foreign trees INSIDE one tier, but
     * since audit 15d-03(b) the tier decides first
     * ({@see \SugarCraft\Code\Skills\SkillOrigin::precedence()}: built-in <
     * project < user), so a user's foreign skill beats a project's native one.
     * Agents keep native-over-foreign across tiers; the argument for it is
     * slightly sharper here, because `reviewer`, `coder`, `tester`, `architect`,
     * `debugger` and `devops` are the names `/agents`, Ctrl+A and the agent strip
     * are documented against: cloning a repository that ships
     * `.claude/agents/reviewer.md` must not silently re-point `reviewer` at
     * somebody else's prompt. Additive is the only safe direction for a new
     * discovery source. WITHIN the native layer, the two on-disk tiers order
     * user > project as of ISS-031 — see {@see agentPresetTiers()} — so a
     * cloned repository can add names to this roster but can no longer displace
     * the operator's own presets, and a displacement of any same-name file by
     * any higher-precedence one is a launch notice, not silence
     * ({@see AGENT_PRESET_SHADOW_NOTICE_FORMAT}).
     *
     * WINNING IS PER FIELD, AND THE PROMPT IS THE ONE FIELD A PRESET MIGHT NOT
     * HAVE. A native preset declaring no prompt at all — no markdown body and no
     * `initialPrompt:` — does not win an empty prompt over a written one: it
     * inherits the prompt it shadowed and carries
     * {@see PROMPT_ATTRIBUTION_FORMAT} so the agent can say where the text came
     * from. Read the precedence above as "every setting the preset DECLARES
     * replaces the built-in's", which is what it always meant, and not as "the
     * whole built-in entry is discarded" — that second reading is precisely the
     * defect the frontmatter-only `coder.md` and `reviewer.md` this repository
     * ships fell through.
     *
     * WHAT THE IMPORT CARRIES INTO THE ROSTER USED TO BE NARROWER THAN THE
     * PRESET, AND NO LONGER IS — read this before relying on the sentence it
     * replaced, which said {@see Agent::fromPreset()} reads six fields "and
     * NOTHING ELSE" and that an imported `permissionMode:` "does not travel
     * this path at all". Both were true when written and are now false:
     * `fromPreset()` reads all seventeen of the preset's fields. Stated where
     * the merge happens rather than left to be inferred, because THIS method
     * is the one that performs it.
     *
     * `permissionMode` IS GATED ON PROVENANCE, which is what replaced the
     * accidental bound rather than nothing. {@see Agent::fromPreset()} copies
     * a NATIVE preset's mode and forces every foreign one to
     * {@see \SugarCraft\Code\Permissions\PermissionMode::Default}, so the
     * `bypass-permissions` the foreign registry's own measurement showed
     * reaching a preset still cannot reach a rostered `Agent` from
     * `.claude/agents` or `.opencode/agents`. MEASURED end-to-end through this
     * method before the gate landed: a `.claude/agents` preset produced an
     * `Agent` carrying `BypassPermissions`. Asserted by
     * {@see \SugarCraft\Code\Tests\Integration\ForeignAgentPresetWiringTest::testAnImportedPresetsPermissionModeIsForcedToDefaultOnTheRoster()}
     * — on the VALUE now, through this method's real path, rather than on
     * `Agent`'s field list, which is what the old assertion checked and what a
     * refactor could change without touching either file.
     *
     * The other fifteen fields are carried unconditionally and are UNREAD
     * rather than unrepresentable: nothing outside `Agent` itself consumes
     * them yet. That is the weaker of the two kinds of bound and it is worth
     * naming as such — it holds because of a census
     * ({@see \SugarCraft\Code\Tests\Integration\ForeignAgentPresetWiringTest::testNoSourceFileOutsideAgentReadsAnAgentsPermissionMode()})
     * rather than because of the type. It bounds THIS path and nothing else:
     * {@see \SugarCraft\Code\Agents\AgentPreset} still carries every field, so a
     * future consumer reading presets directly inherits them ungated.
     *
     * {@see \SugarCraft\Code\Agents\AgentPreset::$source} HAS A CARRIER AND NOW
     * HAS A READER. {@see Agent} carries a `$source` field and `fromPreset()`
     * copies it; since ISS-031/ISS-034 the roster table badges imported rows and
     * `/agent <name>` prints the source and, through the new
     * {@see Agent::$sourcePath}, the exact file a preset was read from — the
     * display half Phase 1 item 3 left unclaimed is claimed.
     *
     * @return list<Agent>
     */
    public static function agentRoster(string $root, string $provider, string $model): array
    {
        // RESOLVED FIRST, THOUGH IT IS CONSUMED LAST. agentPresets() is the call
        // that routes the user tier through {@see trustedConfigDirPath()}, which
        // THROWS when this process cannot establish whose home it is in — so
        // asking for it before any foreign directory is read keeps "refuse the
        // launch rather than read policy a stranger may have written" true of
        // this method on its own, not only of {@see chat()}, which happens to
        // resolve the same gate on its first line. The precedence documented
        // above is decided by the ORDER THESE ARE INSERTED into $agents below,
        // which is independent of the order they are fetched in.
        $native = self::agentPresets($root);

        $agents = [];

        // LOWEST PRIORITY: everything after this overwrites a shared key.
        foreach (self::foreignAgentPresets($root) as $name => $preset) {
            $agents[$name] = Agent::fromPreset($preset, $provider, $model);
        }

        $builtInNames = [];
        foreach ([
            AgentDefinition::TYPE_CODER,
            AgentDefinition::TYPE_REVIEWER,
            AgentDefinition::TYPE_DEBUGGER,
            AgentDefinition::TYPE_ARCHITECT,
            AgentDefinition::TYPE_TESTER,
            AgentDefinition::TYPE_DEVOPS,
        ] as $type) {
            $definition = AgentDefinition::fromType($type, $type);
            if ($definition !== null) {
                $agents[$definition->name] = Agent::fromDefinition($definition, $provider, $model);
                // Recorded AS THE LOOP ENUMERATES rather than restated as a second
                // list: the merge below has to know which shadowed entries came from
                // the built-in tier so it can name that tier in its attribution, and
                // a duplicated six-name literal is one rename away from lying.
                $builtInNames[$definition->name] = true;
            }
        }

        // BODY-LESS NATIVE PRESETS INHERIT THE BUILT-IN PROMPT RATHER THAN ERASE
        // IT. A preset with no markdown body and no `initialPrompt:` key resolves
        // to null ({@see \SugarCraft\Code\Agents\AgentPresetRegistry::resolveInitialPrompt()}),
        // which {@see Agent::fromPreset()} maps to the empty string — so the two
        // frontmatter-only presets this repository ships, `coder.md` and
        // `reviewer.md`, were overwriting their built-in twins' prompts with
        // nothing, and every sub-agent delegated to them arrived carrying only its
        // environment block. This merge replaces ONLY the prompt the preset never
        // declared: every other field still comes from the frontmatter above it,
        // which is the whole difference between "the preset was silent about the
        // prompt" and "the preset said the prompt should be empty".
        //
        // AT THE ROSTER, AND NOWHERE LOWER. The built-in definitions are invisible
        // to `AgentPresetRegistry`, whose instances are transient inside
        // {@see agentPresets()}, so this loop is the only seam that has both tiers
        // in hand at once.
        //
        // A BODY-LESS PRESET WITH NOTHING TO INHERIT keeps the empty prompt it has
        // always had — the real case is this repository's `security-auditor.md`,
        // which has no built-in twin. No instructions are invented for it here,
        // because that is a content decision rather than this step's authority. It
        // also gets NO notice, and the reason is honest rather than an oversight:
        // the transcript seam is census-pinned by {@see TRANSCRIPT_SEAM_CALL_SITES}
        // and asserted against ten prose sites in five files, two of which are
        // outside this step's reach, so a new call site here moves a number this
        // lane cannot correct. No existing envelope states the truth either —
        // {@see PROJECT_TIER_REFUSAL_FORMAT} reads "ignoring", and nothing is being
        // ignored: the agent IS launched, with its frontmatter. The recorded reason
        // is therefore deferred to P7.S5b, which owns choosing the channel.
        foreach ($native as $name => $preset) {
            // The `!== ''` test is what separates inherit from no-twin: a shadowed
            // entry with an empty prompt has nothing worth inheriting, and
            // attributing one would hand the agent a sentence about instructions it
            // does not have.
            $shadowed = $agents[$name] ?? null;
            if ($preset->initialPrompt === null && $shadowed !== null && $shadowed->prompt !== '') {
                $preset = self::presetCarryingPrompt(
                    $preset,
                    self::attributeInheritedPrompt(
                        $shadowed->prompt,
                        $name,
                        isset($builtInNames[$name]),
                    ),
                );
            }

            $agents[$name] = Agent::fromPreset($preset, $provider, $model);
        }

        return array_values($agents);
    }

    /**
     * The same preset with one prompt, so {@see Agent::fromPreset()} can be run
     * over it unchanged.
     *
     * WHY THE PRESET IS COPIED AND NOT THE AGENT. `Agent` is a `final readonly`
     * class with no `withPrompt()`, so the alternative is spelling out its
     * twenty-one constructor arguments — which would freeze `fromPreset()`'s own
     * decisions (the `model: inherit` substitution, the permission mode gated on
     * native-versus-foreign provenance) into a second copy inside this file.
     * Copying the preset and calling `fromPreset()` keeps every one of those
     * rules on the single code path that owns them.
     *
     * WHY A SPREAD INSTEAD OF A FIELD LIST. Neither class declares a `with*()`
     * for this, and a list of the preset's seventeen fields would carry two costs
     * at once: a field ADDED to `AgentPreset` later would default here rather
     * than travel, and naming its `permissionMode` at all trips
     * {@see \SugarCraft\Code\Tests\Integration\ForeignAgentPresetWiringTest::testNoSourceFileOutsideAgentReadsAnAgentsPermissionMode()},
     * whose census of that property is deliberately text-based because
     * `Agent`'s fields are public and reach the wire through `toArray()`. That
     * guard is right to stay ignorant of presets, and this copy has no business
     * making it less so. Spreading the object's own public properties names no
     * field, so a future one travels by construction and the census stays about
     * `Agent`.
     *
     * `get_object_vars()` called from OUTSIDE the class returns the public half
     * only, which is all `AgentPreset` has, and the keys are the property names —
     * exactly the named-argument shape the constructor wants.
     */
    private static function presetCarryingPrompt(AgentPreset $preset, string $prompt): AgentPreset
    {
        $fields = \get_object_vars($preset);
        $fields['initialPrompt'] = $prompt;

        return new AgentPreset(...$fields);
    }

    /**
     * Appends {@see PROMPT_ATTRIBUTION_FORMAT} to a prompt the preset did not
     * supply, so the agent can say where its instructions came from.
     *
     * WHY A HELPER AT ALL, rather than an inline `sprintf` at the one call site:
     * the merge and the idempotence guard have to agree on the exact bytes, and a
     * literal copied into a test would stop pinning anything the moment this
     * wording changed.
     *
     * The empty-input short circuit is not defensive padding — it is what keeps
     * the no-twin case byte-identical to what it was before this merge existed.
     */
    private static function attributeInheritedPrompt(
        string $inherited,
        string $definitionName,
        bool $fromBuiltInTier,
    ): string {
        if ($inherited === '') {
            return $inherited;
        }

        $attribution = sprintf(
            self::PROMPT_ATTRIBUTION_FORMAT,
            $fromBuiltInTier ? 'built-in' : 'imported',
            $definitionName,
        );

        // A SUFFIX test, not a substring one. A prompt that merely quoted this
        // sentence somewhere in its middle has not already been attributed, and
        // skipping it there would lose the record the sentence exists to carry.
        return str_ends_with($inherited, $attribution)
            ? $inherited
            : $inherited . "\n\n" . $attribution;
    }

    /**
     * The agent presets other coding CLIs left on disk — Claude Code's
     * `.claude/agents` and opencode's `.opencode/agents`, project tier and user
     * tier — mapped onto {@see \SugarCraft\Code\Agents\AgentPreset} and keyed by
     * filename stem, the same key space {@see agentPresets()} uses.
     *
     * crush_code.md Phase 1 item 3. Until this method existed nothing in `src/`
     * or `bin/` constructed
     * {@see \SugarCraft\Code\Agents\ForeignAgentPresetRegistry}: an agent
     * authored for either tool was read by that class's own unit tests and by
     * nothing else, so dropping a `reviewer.md` under `~/.claude/agents` had zero
     * observable effect on a real run. The class's doc-block said so plainly,
     * which is the only reason this gap was cheap to find.
     *
     * SEPARATE FROM {@see agentPresets()} RATHER THAN MERGED INTO IT, and the
     * split is the precedence decision rather than tidiness. That method's
     * contract is "the NATIVE presets", and its result is applied over the six
     * built-in definitions in {@see agentRoster()} — so folding the imports into
     * its return value would have ranked a cloned repository's
     * `.claude/agents/reviewer.md` ABOVE the built-in `reviewer`, which is the
     * opposite of the native-wins rule stated on `agentRoster()` and of the merge
     * {@see \SugarCraft\Code\Skills\SkillManager::loadAll()} performs for skills.
     * Two return values let the caller insert three layers in one order.
     *
     * DEGRADES, NEVER THROWS. Every per-file failure is already contained inside
     * the registry (one malformed foreign `.md` is `error_log`ged and skipped), so
     * the only escape left is something unforeseen from the walk itself; a
     * `Throwable` there costs the launch its imported agents and nothing more.
     * The native sibling degrades for the same reason and says so at more length.
     *
     * REFUSALS ARE DRAINED INTO THE LAUNCH COLLECTOR, on both paths. A repository
     * that committed `.claude/agents` or `.opencode/agents` as a link out of the
     * checkout gets the tree dropped, and until this drain existed the seam
     * {@see \SugarCraft\Code\Agents\ForeignAgentPresetRegistry::refusedDirectories()}
     * exposes had no reader at all — a refused directory was indistinguishable
     * from an empty one, which is the silence crush_code.md Phase 1 item 3 and
     * Phase 2 item 6 both exist to end. See {@see $projectTierRefusals} for the
     * other four seams and {@see reportProjectTierRefusals()} for the notice.
     *
     * WHAT THE COLLECTOR LINE COULD NOT CARRY GETS ITS OWN (ISS-088):
     * {@see \SugarCraft\Code\Agents\ForeignAgentPresetRegistry::warnings()} —
     * lossy MAPPINGS like opencode's per-command `permission:` rules collapsing
     * to one allow/ask/deny, an inline server definition reduced to its name,
     * a quoted word read as a boolean — and the per-file parse failures
     * {@see \SugarCraft\Code\Agents\ForeignAgentPresetRegistry::skippedFiles()}
     * records. Neither fits the collector's `ignoring <path> — <reason>`
     * wording, so both are raised through the transcript seam as one row each,
     * "imported with changes" and "skipped", before the launch reads the
     * roster. Before this, a CC agent with bad YAML disappeared silently and
     * the lossy ISS-086 casts were invisible even in the log.
     *
     * THE USER TIER'S OWN REFUSAL HAS NO ENTRY HERE, and cannot: when
     * {@see \SugarCraft\Code\Support\HomeDirectory::owned()} returns null the
     * registry omits that tier and records nothing
     * ({@see \SugarCraft\Code\Agents\ForeignAgentPresetRegistry::userDir()}).
     * On every path that reaches this method the condition is already unreachable
     * — {@see trustedConfigDirPath()} throws on exactly `owned() === null`, and
     * {@see agentRoster()} resolves it before calling here — so the user does not
     * get a quieter roster, they get a refused launch naming the home and the
     * reason. That is a louder surface than a collector line, not a missing one.
     *
     * @return array<string, \SugarCraft\Code\Agents\AgentPreset> keyed by preset filename stem
     */
    public static function foreignAgentPresets(string $root): array
    {
        $registry = new ForeignAgentPresetRegistry();

        try {
            $presets = $registry->discover($root, self::bundles($root));
        } catch (\Throwable $e) {
            // BOTH CHANNELS, and the stderr bytes are unchanged — see
            // {@see warnPermissionConfigInTranscript()}. A launch whose
            // `.claude/agents` presets did not load is a launch whose `/agents`
            // roster is short, and the operator finds that out by typing a
            // name that is not there. Raised from agentRoster(), reached
            // through agentManager(), which is one of chat()'s named
            // constructor arguments — so it is recorded before chat() reads the
            // list on its way out.
            self::warnPermissionConfigInTranscript(
                Lang::t('cli.warn.foreign_presets_unavailable', ['error' => $e->getMessage()]),
            );
            $presets = [];
        }

        // ISS-088, the visible half: the import's own verdicts on what it read.
        // Skips and warnings are DIFFERENT OUTCOMES and keep different
        // envelopes — "skipped N files (why)" is a shorter roster, "imported N
        // files with changes" is the same roster carrying less than its author
        // wrote — but they rise as ONE seam call, the way agentPresets seats
        // its skip and shadow rows (the seam census counts call sites, and a
        // bad directory is rare enough that one line per outcome cannot
        // crowd it). Collected on the throwing path too: the registry records
        // per-file failures while walking, before whatever tripped the
        // outer catch.
        $notice = null;
        $skipped = $registry->skippedFiles();
        if ($skipped !== []) {
            // ISS-090(d): the row cap folds the tail into one honest count
            // by CONCATENATION — a new sprintf site here would re-open the
            // launch-format census that every Bootstrap notice must pass.
            $rows = [];
            $skippedShown = 0;
            foreach (array_slice($skipped, 0, self::FOREIGN_PRESET_NOTICE_ROWS, true) as $path => $why) {
                ++$skippedShown;
                $why = str_starts_with($why, $path . ': ')
                    ? substr($why, \strlen($path) + 2)
                    // ISS-088: the foreign parser's messages END with the path
                    // ("No YAML frontmatter found in: {file}"), and the row
                    // already names the file — a doubled 100-character path
                    // pushed the second sentence of a merged skip+change row
                    // past the clip bound. De-duplicate whichever side it rides.
                    : trim(rtrim(str_replace([' in: ' . $path, ': ' . $path], ['', ''], $why), ' :'), ' ');
                $rows[] = "{$path} ({$why})";
            }

            $skippedRest = \count($skipped) - $skippedShown;

            $notice = sprintf(
                self::translated(self::FOREIGN_PRESET_SKIP_NOTICE_FORMAT),
                \count($skipped),
                \count($skipped) === 1 ? '' : 's',
                implode('; ', $rows) . ($skippedRest > 0 ? ' (and ' . $skippedRest . ' more)' : ''),
            );
        }

        $warned = $registry->warnings();
        if ($warned !== []) {
            $warnedShown = array_slice($warned, 0, self::FOREIGN_PRESET_NOTICE_ROWS);
            $warnedRest = \count($warned) - \count($warnedShown);
            $changeNotice = sprintf(
                self::translated(self::FOREIGN_PRESET_CHANGE_NOTICE_FORMAT),
                \count($warned),
                \count($warned) === 1 ? '' : 's',
                implode('; ', $warnedShown) . ($warnedRest > 0 ? ' (and ' . $warnedRest . ' more)' : ''),
            );
            $notice = $notice === null ? $changeNotice : $notice . ' ' . $changeNotice;
        }

        if ($notice !== null) {
            self::warnPermissionConfigInTranscript($notice);
        }

        // Collected whether the walk finished or not: refusedDirectories() is
        // filled as each tier is rejected, before any file is parsed, so a walk
        // that recorded a refusal and then tripped over something else must not
        // lose the refusal to the degradation. Same argument, same shape, as the
        // native sibling's throwing path.
        self::$projectTierRefusals = [...self::$projectTierRefusals, ...$registry->refusedDirectories()];

        return $presets;
    }

    /**
     * The native agent presets on disk, project directory first so a checked-in
     * preset overrides a same-named one in the user's home.
     *
     * NATIVE ONLY, deliberately: the imports from other tools' conventions are
     * {@see foreignAgentPresets()}, a separate call whose result
     * {@see agentRoster()} inserts BENEATH both these presets and the built-in
     * definitions. See that method for why the two are not one.
     *
     * Resolved off {@see configDirPath()}, never {@see configDir()}: listing
     * agents is a read, and a read must not be what creates ~/.candy-code.
     *
     * A malformed preset costs THAT preset, not the roster: these files are
     * hand-authored, and {@see AgentPresetRegistry::list()} skips a file it
     * cannot parse and names it in
     * {@see AgentPresetRegistry::skippedFiles()}, which this method reports as
     * one launch notice. It used to throw on the first bad file, and this
     * method degraded that to "no presets this launch" — so one `.md` in
     * Claude Code's `tools: Read, Grep` spelling erased every preset in every
     * tier (audit AG-2). A failure of the walk ITSELF still degrades to the
     * built-in agents with a warning rather than escaping: letting it escape
     * would stop `bin/candy-code` from starting at all.
     *
     * The project half is ANCHORED to $root, and that is a containment boundary
     * rather than a tidiness rule: `<root>/.candy-code/agents` is a path the
     * repository chose, so a committed `.candy-code/agents -> <outside>` used to
     * relocate the per-entry check instead of tripping it — measured on this host
     * against the pre-fix build, a fixture whose only content was that one line
     * had this method return a preset carrying an outside file's description, its
     * body as `initialPrompt`, and `permissionMode: bypass-permissions`. See
     * {@see \SugarCraft\Code\Agents\AgentPresetRegistry}.
     *
     * THE USER HALF IS ANCHORED TOO, to `$HOME`, and the sentence it replaces —
     * "the user half is NOT anchored: `~/.candy-code/agents` is the user's own
     * directory" — was a claim about who chose the LOCATION, made by code that
     * only checked who owned the home. See {@see agentPresetTiers()} for the
     * measurement that refuted it and for what the anchor costs.
     *
     * @return array<string, \SugarCraft\Code\Agents\AgentPreset> keyed by preset name
     */
    public static function agentPresets(string $root): array
    {
        // trustedConfigDirPath(), NOT configDirPath(), and resolved OUTSIDE
        // the catch below. A preset carries `permissionMode:` and `tools:`
        // ({@see \SugarCraft\Code\Agents\AgentPreset}), which is policy by the
        // same definition {@see hookFiles()} uses — so the user half of this
        // pair may no more be read out of a world-writable `/tmp` stand-in than
        // `config.json` may. Outside the catch because that arm degrades to the
        // built-in agents, and "this process cannot tell whose home this is" is
        // not a degradable condition: it is the launch refusal
        // {@see trustedConfigDirPath()} exists to raise.
        [$searchPaths, $anchors] = self::agentPresetTiers($root);

        $registry = new AgentPresetRegistry(
            $searchPaths,
            // Keyed by the paths AS SPELLED in agentPresetTiers(), which is why
            // they are returned rather than rebuilt here. A key that names no
            // search path is refused at construction rather than silently
            // anchoring nothing — see {@see AgentPresetRegistry::__construct()}.
            anchors: $anchors,
        );

        // ONE seam call for both outcomes, so this method still counts once in
        // {@see TRANSCRIPT_SEAM_CALL_SITES}: list() now skips a malformed file
        // instead of throwing (audit AG-2), and the skips are the notice that
        // used to be this degradation's.
        $notice = null;

        try {
            $presets = $registry->list();

            // X-37a: what the presets that DID load declare and nothing acts on
            // (`permissionMode:` under `Task`, a misspelt key). Collected here,
            // reported once with the other formats by reportIgnoredFrontmatter().
            foreach ($registry->ignoredFrontmatter() as $name => $labels) {
                $format = \SugarCraft\Code\Support\FrontmatterKeyAudit::AGENT;
                LaunchContext::putIgnoredFrontmatter($format, (string) json_encode([$root, $name]), [$name, $labels]);
            }

            // The skipped preset files, NAMED: a launch has a handful of
            // presets rather than a directory of twenty commands, and "which
            // agent did I lose" is answered by the path alone. One row, so a
            // tier full of Claude-Code-dialect leftovers still cannot crowd
            // the seam (E172's argument, scaled down).
            $skipped = $registry->skippedFiles();
            if ($skipped !== []) {
                $rows = [];
                foreach ($skipped as $path => $why) {
                    // The registry's reason usually opens with the path too;
                    // once per row is enough.
                    $why = str_starts_with($why, $path . ': ') ? substr($why, \strlen($path) + 2) : $why;
                    $rows[] = "{$path} ({$why})";
                }

                $notice = sprintf(
                    self::translated(self::AGENT_PRESET_SKIP_NOTICE_FORMAT),
                    \count($skipped),
                    \count($skipped) === 1 ? '' : 's',
                    implode('; ', $rows),
                );
            }
        } catch (\Throwable $e) {
            // The native sibling of the foreign-preset degradation above, and
            // routed for the same reason: the roster the user configured is not
            // the roster they got. A malformed FILE no longer lands here (see
            // above); what is left is a failure of the walk itself.
            $notice = Lang::t('cli.warn.presets_unavailable', ['error' => $e->getMessage()]);
            $presets = [];
        }

        // ISS-031, the visible half: with the tiers flipped to user > project,
        // a file that LOST the same-name walk is a displacement the operator
        // cannot otherwise observe — the roster simply does not mention the
        // loser. Report it through the SAME seam call as the skips (one
        // emission per outcome, the seam census counts call sites not
        // sentences, and a reader of "your repo's reviewer.md yielded to your
        // own" wants it on the same launch it silently got).
        $shadowed = $registry->shadowedFiles();
        if ($shadowed !== []) {
            $rows = [];
            foreach ($shadowed as $loser => $winner) {
                $rows[] = $winner === ''
                    ? (string) $loser
                    : $loser . ' — kept ' . $winner;
            }

            $shadowNotice = sprintf(
                self::translated(self::AGENT_PRESET_SHADOW_NOTICE_FORMAT),
                \count($shadowed),
                \count($shadowed) === 1 ? '' : 's',
                implode('; ', $rows),
            );
            $notice = $notice === null ? $shadowNotice : $notice . ' ' . $shadowNotice;
        }

        if ($notice !== null) {
            self::warnPermissionConfigInTranscript($notice);
        }

        // The agents third of the project-tier refusal, joining the workflow
        // registry's and the skill loader's in one collector — see
        // {@see projectTierRefusals()}. Collected on the throwing path TOO:
        // list() records its refusals before it parses anything, so a launch
        // that both refused a directory and then failed must not lose the
        // refusal to the degradation.
        self::$projectTierRefusals = [...self::$projectTierRefusals, ...$registry->refusedDirectories()];

        return $presets;
    }

    /**
     * The agent-preset search paths and their trust anchors, for $root.
     *
     * THE `.git` DISCRIMINATOR THIS REPLACES RESTED ON A FALSE PREMISE, stated
     * in the code it guarded: *"`$HOME/.git` is the discriminator because the
     * escape needs a COMMITTED symlink and nothing can be committed without a
     * repository."* A symlink does not need to be committed to arrive. `tar`,
     * `zip`, `rsync -a`, `degit` and "download the release tarball" all carry
     * one and carry no `.git`, and the discriminator was defeated three
     * further ways: a bare-repo dotfiles layout leaves no `.git` at `$HOME`
     * (this was the stated bound), and a DANGLING `.git` symlink answers
     * `file_exists()` false while being every bit a checkout.
     *
     * THE MEASUREMENT THAT SETTLED IT, on this host, `$HOME` mode 0700 and
     * owned, its only content `.candy-code/agents -> <outside>` delivered by
     * `tar xzf` — four launch shapes, the discriminator working exactly as
     * designed:
     *
     *     no .git,  agentPresets($HOME)    presets=["pwned"] mode=bypass-permissions refusals=[]
     *     no .git,  agentPresets(<project>) presets=["pwned"] mode=bypass-permissions refusals=[]
     *     .git dir, agentPresets($HOME)    presets=[]        refusals=["…outside the checkout…"]
     *     .git dir, agentPresets(<project>) presets=["pwned"] mode=bypass-permissions refusals=[]
     *
     * The third row is the only one the discriminator ever changed. Row FOUR is
     * the point: with `.git` present — the check firing correctly — the escape
     * is fully live the moment the user launches from any directory that is not
     * their home, which is every ordinary launch. The discriminator defended one
     * launch shape out of four and the rationale for it was false, so it is
     * gone rather than patched.
     *
     * NO RELIABLE DISCRIMINATOR EXISTS for the question it was asking. "Did a
     * repository choose this content" is not answerable from the filesystem: a
     * tarball-delivered dotfiles tree and a hand-authored one are byte-identical.
     * So the question is replaced with one that IS answerable and that makes the
     * old claim true rather than assumed — *is this directory inside the home
     * this process established as the user's?* The user tier is anchored to
     * `$HOME`. Every row above becomes a refusal, in every launch shape.
     *
     * WHAT IT COSTS, stated rather than implied away, because it is a real
     * working layout that stops working: `~/.candy-code/agents` symlinked to a
     * path OUTSIDE `$HOME` — a network share, `/opt/team-agents` — is now
     * refused. The layout the old sentence actually named as its justification,
     * a link to `~/.claude/agents`, is INSIDE `$HOME` and is unaffected; so is
     * every roster that is a real directory. {@see \SugarCraft\Code\Tests\Agents\AgentPresetHomeRootTest}
     * pins both halves.
     *
     * WHY THE ANCHOR AND NOT THE OWNERSHIP CHECK. {@see HomeDirectory::owned()}
     * already establishes that `$HOME` is this user's, and in the measurement
     * above it was — the user extracted a hostile tarball into their own home.
     * Ownership answers "whose directory is this"; it cannot answer "who chose
     * where this link points", and only containment does.
     *
     * `$sameDirectory` survives as pure DE-DUPLICATION and no longer decides
     * anything: when `$root` IS `$HOME` the two expressions are one directory
     * and one anchor, so scanning it twice would record the same refusal under
     * the same key. Resolved identity as well as spelled, so `$root` reached
     * through a symlink to `$HOME` is the same launch.
     *
     * @return array{0: list<string>, 1: array<string, string>} search paths in
     *         precedence order, and their anchors keyed by the SAME spellings
     */
    private static function agentPresetTiers(string $root): array
    {
        // The project tier and its anchor walk up to the repository together
        // (audit 15d-13 (b)), so the directory read is always held inside the
        // root it was found under.
        $root = ProjectRoot::resolve($root);
        $projectAgents = rtrim($root, '/') . '/.candy-code/agents';

        // Both derived from the ONE trusted resolution, so the agents directory
        // and the home it is anchored to can never be two different homes.
        $userConfigDir = self::trustedConfigDirPath();
        $userAgents = $userConfigDir . '/agents';
        $userHome = \dirname($userConfigDir);

        $sameDirectory = $projectAgents === $userAgents
            || (realpath($projectAgents) !== false && realpath($projectAgents) === realpath($userAgents));

        if ($sameDirectory) {
            return [[$userAgents], [$userAgents => $userHome]];
        }

        // USER FIRST (ISS-031). The order these paths are returned in IS the
        // precedence — {@see AgentPresetRegistry::list()} lets the first path
        // that claims a name keep it — and this method used to return project
        // first. A cloned checkout's `.candy-code/agents/reviewer.md` therefore
        // silently displaced both the operator's own `~/.candy-code/agents/
        // reviewer.md` and the built-in it shadowed, which is repository
        // content steering a local session, the exact direction skills had
        // already reversed (audit 15d-03(b): built-in < project < USER). Agents
        // now agree with them: your own presets outrank a repository's; what a
        // repository can do is ADD names, not replace yours. The displacement
        // side of the old order is not hidden either — see
        // {@see AgentPresetRegistry::shadowedFiles()} and the notice
        // {@see agentPresets()} renders from it.
        return [
            [$userAgents, $projectAgents],
            [$userAgents => $userHome, $projectAgents => $root],
        ];
    }

    /**
     * Delegates to {@see LaunchContext::app()} — the body moved in the S1b
     * composition split (W7-E C5); this keeps the door the binary and the
     * settings-schema reader citations name.
     */
    public static function app(?string $root = null, ?string $tuiErrorLog = null, ?string $initialPrompt = null): App {
        return LaunchContext::app($root, $tuiErrorLog, $initialPrompt);
    }

    /**
     * Delegates to {@see LaunchContext::settingsWriter()} — body moved in
     * W7-E C5; the door keeps its private visibility for the reflection pins.
     */
    private static function settingsWriter(?string $root): \SugarCraft\Code\Config\Settings\SettingsWriter {
        return LaunchContext::settingsWriter($root);
    }

    /**
     * Delegates to {@see LaunchContext::dockFromUserConfig()} — body moved in
     * W7-E C5. PUBLIC since the same commit: the moved app() body reaches this
     * door, and src/App/App.php's citation names it as the launch's loader,
     * while DockPersistenceRoundTripTest keeps reflecting it here.
     */
    public static function dockFromUserConfig(): ?DockLayout {
        return LaunchContext::dockFromUserConfig();
    }

    /**
     * Delegates to {@see LaunchContext::paneAutoShowFromUserConfig()} — body
     * moved in W7-E C5. PUBLIC since the same commit: the moved app() body
     * reaches this door, and DockPersistenceRoundTripTest keeps reflecting it
     * here.
     */
    public static function paneAutoShowFromUserConfig(): PaneAutoShow {
        return LaunchContext::paneAutoShowFromUserConfig();
    }

    /**
     * Delegates to {@see LaunchContext::provider()} — the body moved in the S1b
     * composition split (W7-E C3); this keeps the door (and its visibility)
     * every caller and the settings-schema reader citations name.
     */
    public static function provider(): array {
        return LaunchContext::provider();
    }

    /**
     * The pool configuration this launch hands {@see \SugarCraft\Code\Chat}
     * (E652). Everything else on {@see \SugarCraft\Code\Agents\AgentPoolConfig}
     * keeps its documented default; this method exists to carry what only the
     * launch can answer: which provider the forked workers inherit, and the
     * `subagentMaxConcurrent` setting (roadmap N-P4f) — the pool width and the
     * engine's per-batch `Task` cap, read from the merged config here.
     *
     * @param ?string $providerName The provider a mid-session switch moved to
     *        (N-P3a); null keeps the launch's own selection.
     */
    public static function agentPoolConfig(?string $providerName = null): \SugarCraft\Code\Agents\AgentPoolConfig
    {
        return (new \SugarCraft\Code\Agents\AgentPoolConfig(
            workerProvider: self::workerProviderSpec($providerName),
        ))->withSettings(self::readUserConfig());
    }

    /**
     * The serializable provider SPEC (not an instance) forked sub-agent workers
     * are handed at startup (E652), built from the SAME selection
     * {@see provider()} uses for the hosted backend: the default config of the
     * selected provider plus the launch's model override. The worker constructs
     * its own provider child-side from this array — {@see ProviderFactory::create()}
     * there, and `['type' => 'echo']` honoured as the one offline spelling.
     *
     * NULL means NO spec is derivable — no selected provider, or its config
     * cannot be read. That is a verdict, not a shrug: the worker then refuses
     * with a FAILED result naming the absence. It deliberately does NOT degrade
     * to `echo` the way {@see provider()} does for the status-bar label, because
     * an echo-backed sub-agent answering in the transcript is precisely the
     * silently-fabricated "Completed" the E641-era refusal was minted to kill.
     *
     * @param ?string $providerName A provider a mid-session switch named
     *        (N-P3a), built on the model {@see backendFor()} sends to it;
     *        null keeps the launch's selection.
     *
     * @return ?array<string, mixed>
     */
    public static function workerProviderSpec(?string $providerName = null): ?array
    {
        $model = null;
        if ($providerName === null) {
            $providerName = self::selectedProviderName();
            if ($providerName === null) {
                return null;
            }

            [, $model] = self::selectedProviderLabel();
        }

        try {
            $factory = new ProviderFactory();
            $config = self::providerConfigFor($providerName, $factory);
            $config['model'] = $model ?? self::selectedModelName($providerName) ?? ($config['model'] ?? 'gpt-4o');

            // E652 review fix — never put a RESOLVED credential on the fork
            // frame. defaultConfig() bakes getenv() output into apiKey (and
            // openai's organization); the child re-resolves ${VAR} placeholders
            // inside ProviderFactory::create() and inherits this process's
            // environment whole (spawnWorker's proc_open passes null env), so
            // the placeholder is functionally identical while the JSON startup
            // frame stops carrying the secret in clear text. A value that is
            // NOT exactly the env default is a file-configured credential, and
            // the spec is the child's only copy of it — those stay.
            $credentialEnvVars = match ($providerName) {
                'openai' => ['apiKey' => 'OPENAI_API_KEY', 'organization' => 'OPENAI_ORG_ID'],
                'anthropic' => ['apiKey' => 'ANTHROPIC_API_KEY'],
                'sglang' => ['apiKey' => 'SGLANG_API_KEY'],
                default => [],
            };
            foreach ($credentialEnvVars as $key => $var) {
                if (
                    \is_string($config[$key] ?? null)
                    && $config[$key] !== ''
                    && \getenv($var) !== false
                    && $config[$key] === \getenv($var)
                ) {
                    $config[$key] = '${' . $var . '}';
                }
            }

            return $config;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Backend selection, in priority order:
     *
     *   1. $CANDY_CODE_PROVIDER (+ provider env, e.g. $OPENAI_API_KEY) — run the
     *      full agent engine: that provider driven by the Runtime with the
     *      built-in coding tools (Bash/Read/Edit/Glob/Grep/WebFetch) gated by the
     *      safety hooks. $CANDY_CODE_MODEL overrides the model.
     *   2. $CANDY_CODE_BACKEND_CMD — dependency-free shell-out: a command that
     *      reads JSON history on stdin and writes the reply to stdout, which
     *      is returned as-is but for one `trim()` at the ends
     *      ({@see CommandBackend}).
     *   3. $CANDY_CODE_BACKEND_CMD_STREAM — the same shell-out under the
     *      OTHER stdout contract, a TOKEN STREAM rather than prose: one token
     *      per TERMINATED line, a TERMINATED BLANK line meaning a literal
     *      newline in the answer, an unterminated empty remainder meaning
     *      nothing, and the tokens joined with the EMPTY STRING — the newline
     *      between two tokens is framing, not text
     *      ({@see StreamingCommandBackend}). `$onToken` is called once per
     *      token as the command produces it, and the TUI now repaints as they
     *      arrive: the display half of that claim used to be withdrawn here
     *      because the read loop ran to completion inside one
     *      `Loop::futureTick` and blocked the render loop for the whole
     *      round-trip. It is driven from a periodic timer now (see that class's
     *      `completeAsync()` for the before/after measurement), so tiers 2 and
     *      3 are as non-blocking as tier 1.
     *      Ranked BELOW tier 2 rather than above it so tier 2 stays
     *      byte-identical for everyone who already uses it, including a run
     *      with both variables exported — the two protocols are genuinely
     *      different and neither serves the other (a PROSE wrapper run through
     *      tier 3 loses every newline it emitted, and each blank line it
     *      emitted comes back as ONE newline rather than two, so a paragraph
     *      break, a list and a code fence do not survive), so a run that sets
     *      both is ambiguous and the older, documented meaning wins. Ranked
     *      above tier 4 for the same reason tier 2 is: an exported variable is
     *      a decision made about THIS run, a persisted provider is one made
     *      about some earlier one.
     *   4. A provider name persisted by a previous Ctrl+P "Switch model"
     *      (see writeUserConfig()) — makes that choice survive a restart
     *      without needing $CANDY_CODE_PROVIDER exported every time.
     *   5. (default) the offline EchoProvider, still run through the engine so the
     *      binary launches with zero network and zero config.
     *
     * For BOTH shell-out variables, absence means unset, empty OR
     * whitespace-only — see {@see backendCommandEnv()}, which is also what
     * {@see backendCommandTierIsSelected()} asks, so the tier this method
     * selects and the tier the label helpers report can never disagree.
     *
     * Also the process's one startup sweep of abandoned forked-tool payloads
     * ({@see ToolIpcFiles::sweepOnce()}) — every real run reaches this method
     * or {@see backendFor()} exactly once, and the files being swept are by
     * definition ones whose owning process was killed before it could clean up
     * after itself.
     *
     * @param SkillRegistry|null $skills The registry to thread into the
     *        engine and its tools; defaults to a fresh scan of $root. Pass the
     *        caller's so a launch scans once and every consumer sees the same
     *        enabled/disabled set (see {@see chat()}).
     * @param PermissionGate|null $gate The launch's safety gate, threaded into
     *        {@see EngineBackend::withPermissionGate()}. Pass the caller's so
     *        the engine and Chat's own tool path share ONE circuit breaker;
     *        defaults to a gate built fresh from the same config.
     * @param bool $consolePermissionPrompt Attach
     *        {@see HeadlessPermissionPrompt} as the engine's approver, so a
     *        {@see \SugarCraft\Code\Hooks\HookResult::ask()} on the engine
     *        path is PUT TO SOMEONE instead of failing closed.
     *
     *        Opt-in, and defaulted OFF, because this method has two kinds of
     *        caller and only one of them owns a console. {@see chat()} and
     *        {@see \SugarCraft\Code\Chat}'s provider switch reach here from
     *        inside a TUI that holds the terminal in raw mode and alt-screen:
     *        a closure that blocks on `fgets(STDIN)` from in there would eat
     *        the keystrokes the render loop is reading and print its question
     *        underneath the frame. {@see NonInteractive} — the `-p` one-shot —
     *        owns stdin outright and passes true. See the class docblock on
     *        {@see HeadlessPermissionPrompt} for why the TUI needs a different
     *        mechanism rather than this one wired more widely.
     * @param RulesState|null $rulesState The session's rulebook toggle set, threaded
     *        to {@see tools()} so the `paths:`-scoped rules of a pack named in
     *        `disabledRules` (or switched off later with `/rules`) stay off the
     *        tool-time channel as surely as they are off the splice. Trailing and
     *        defaulted: a caller that holds no set — {@see NonInteractive}, the `-p`
     *        path — changes nothing, and the splice it builds never had a set either.
     */
    public static function backend(?string $root = null, ?SkillRegistry $skills = null, ?PermissionGate $gate = null, bool $consolePermissionPrompt = false, ?RulesState $rulesState = null, ?AgentManager $taskManager = null, ?\SugarCraft\Code\Agents\AgentWorkerPool $taskPool = null): Backend
    {
        // Same first-line refusal {@see chat()} makes, for the same ordering
        // reason: this method is the `-p` path's entry point, where nothing
        // else resolves the config directory until hooks() does — five calls
        // and one full skill scan later. See {@see trustedConfigDirPath()}.
        self::trustedConfigDirPath();

        ToolIpcFiles::sweepOnce();

        $root ??= getcwd() ?: null;

        // Every entry point that resolves a root names it as THE project for
        // the settings layers, before anything below reads a config — see
        // {@see useProjectRootForSettings()}. Set on all four rather than in
        // one shared helper because there is no single funnel: `app()` does
        // not call `chat()`'s resolution, and `NonInteractive` enters at
        // `backend()`.
        self::useProjectRootForSettings(self::configRoot($root));

        $providerType = getenv('CANDY_CODE_PROVIDER');
        if ($providerType !== false && $providerType !== '') {
            try {
                return self::backendFor($providerType, $root, $skills, $gate, $consolePermissionPrompt, $rulesState, taskManager: $taskManager, taskPool: $taskPool);
            } catch (PermissionConfigException $e) {
                // Not a provider problem, and not survivable by degrading:
                // the echo fallback below builds the very same gate and would
                // throw again, after blaming the provider for it on stderr.
                //
                // Written out even though PHP already lets an unmatched type
                // through the `\Throwable` arm below — it is NOT unmatched, it
                // is a SUBTYPE, and without this arm the degrade-to-echo
                // handler swallows it. Static analysis flags the rethrow as
                // redundant; it is load-bearing.
                throw $e;
            } catch (\Throwable $e) {
                // THE MOST USER-VISIBLE DEGRADATION THIS CLASS REPORTS, and
                // until now it was reported only to the channel an interactive
                // launch paints over 0.47s later. "Your model silently became
                // EchoProvider" is not something an operator should have to
                // infer from the replies. Both channels — see
                // {@see warnPermissionConfigInTranscript()}. backend() is
                // chat()'s FIRST named constructor argument, so this lands well
                // before chat() reads the list.
                self::warnPermissionConfigInTranscript(
                    Lang::t('cli.warn.provider_unavailable', ['provider' => $providerType, 'error' => $e->getMessage()]),
                );
            }
        }

        $cmd = self::backendCommandEnv('CANDY_CODE_BACKEND_CMD');
        if ($cmd !== null) {
            return new CommandBackend($cmd);
        }

        $streamCmd = self::backendCommandEnv('CANDY_CODE_BACKEND_CMD_STREAM');
        if ($streamCmd !== null) {
            // No idle deadline argument: the default is "none", which is the
            // parity CommandBackend above has always had. See
            // {@see StreamingCommandBackend::__construct()} for why the old
            // 120-second total cap is gone rather than merely raised. Pinned by
            // {@see \SugarCraft\Code\Tests\Cli\BootstrapShellOutTierTest::testTheStreamingTierIsConstructedWithNoIdleDeadline()},
            // which reflects on the instance this line returns — a comment
            // cannot stop someone passing a 1 here, and a test on the class's
            // DEFAULT does not see the call site at all.
            return new StreamingCommandBackend($streamCmd);
        }

        $persisted = self::readUserConfig()['provider'] ?? null;
        if (is_string($persisted) && $persisted !== '') {
            try {
                return self::backendFor($persisted, $root, $skills, $gate, $consolePermissionPrompt, $rulesState, taskManager: $taskManager, taskPool: $taskPool);
            } catch (PermissionConfigException $e) {
                // See the env-var branch above: this arm exists to keep the
                // `\Throwable` degrade-to-echo arm below from catching it.
                throw $e;
            } catch (\Throwable $e) {
                // The env-var tier's twin, and reachable in the SAME launch as
                // it: an env-var provider that throws falls through to here,
                // and a persisted provider that also throws degrades to echo.
                // Two distinct sentences, so both are recorded.
                self::warnPermissionConfigInTranscript(
                    Lang::t('cli.warn.persisted_provider_unavailable', ['provider' => $persisted, 'error' => $e->getMessage()]),
                );
            }
        }

        $loader = self::instructionLoader($root);
        $skills ??= self::skillRegistry($root);

        $engine = (new EngineBackend(new EchoProvider(), 'echo'))
            // Boot-once capabilities — see the note at app()'s `tools()` call.
            ->withTools(self::tools(
                $root,
                $loader,
                $skills,
                // Step 3.F: the same memoised servers as app()'s tool set.
                lsp: self::lspClient($root),
                rgAvailable: self::capabilityPresent('rg'),
                fdAvailable: self::capabilityPresent('fd'),
                rulesState: $rulesState,
                taskManager: $taskManager,
                taskPool: $taskPool,
            ))
            ->withHooks(self::hooks(null, $root))
            // `??=`, not `??`, and INSIDE the chain rather than hoisted above
            // it: the approver below needs the gate that was actually
            // installed, and moving the resolution to its own statement would
            // move permissionGate()'s PermissionConfigException ahead of
            // tools()/hooks(), changing which failure a broken launch reports
            // first. Assigning in place keeps the evaluation order byte-for-byte
            // what it was and still leaves $gate non-null afterwards.
            ->withPermissionGate($gate ??= self::permissionGate())
            ->withSkillRegistry($skills)
            // The `enabledSkills` config key resolved to Skill objects — the
            // body channel EngineBackend::withSkills() has waited since P7.S1
            // for its first production caller. Empty default: see
            // promptEnabledSkills(); a launch that configures nothing builds
            // the byte-identical prompt it built before this line existed.
            ->withSkills(self::promptEnabledSkills($skills))
            ->withInstructionLoader($loader)
            ->withRoot($root)
            // Without this the Phase 5 item 9 memory block is unreachable from a
            // real run: the store would exist, /memory would still write to it,
            // and nothing the user recorded would ever reach the model.
            ->withMemoryStore(self::memoryStoreOrNull($root));

        // F2: the settings ceiling lands before the approver, after every
        // with*() that ships in this chain — and `toollessBackend()`s engines
        // are deliberately NOT wrapped: title/summary turns never loop on
        // tools, so the orchestration ceiling is not their axis.
        $engine = self::withResolvedMaxToolSteps($engine);
        // PRV-2 (§6.5): the effort tier rides the same launch tail as the step
        // ceiling, for the same reason — one door, both engine settings.
        $engine = self::withResolvedReasoningEffort($engine);

        return self::withConsolePermissionPrompt($engine, $gate, $consolePermissionPrompt);
    }

    public static function backendFor(string $providerName, ?string $root = null, ?SkillRegistry $skills = null, ?PermissionGate $gate = null, bool $consolePermissionPrompt = false, ?RulesState $rulesState = null, ?AgentManager $taskManager = null, ?\SugarCraft\Code\Agents\AgentWorkerPool $taskPool = null): Backend
    {
        // Delegates to {@see LaunchContext::backendFor()} — the body moved in the
        // S1b composition-roots split (2026-10-21); the door, its visibility and
        // its parameter contract are untouched.
        return LaunchContext::backendFor($providerName, $root, $skills, $gate, $consolePermissionPrompt, $rulesState, $taskManager, $taskPool);
    }

    /**
     * The `settings.json` key F2 made configurable; named rather than
     * inlined so the drift guards and the docs row cite one spelling.
     */
    private const MAX_TOOL_STEPS_CONFIG_KEY = 'maxToolSteps';

    /**
     * Delegates to {@see LaunchContext::useMaxTurns()} — the store and its full
     * doc-block moved to the launch-context shelf in the v4 S1b split; the
     * seam stays here so every caller reaches its registration there.
     */
public static function useMaxTurns(?string $maxTurns): void
    {
        LaunchContext::useMaxTurns($maxTurns);
    }

    /**
     * The `--max-turns` ceiling for this launch as a positive whole number,
     * or null when the flag was not given.
     *
     * THE FLAG REFUSES WHAT THE SETTING ONLY LOSES: a `maxToolSteps` config
     * value this cannot read resolves to null and is reported by
     * {@see reportNonsenseLimits()} — a hand-edited file's typo costs that
     * one knob. A typed flag is an explicit act (the argument
     * {@see useMaxCost()} documents for the money ceiling): present and
     * unreadable refuses the launch, in the same message shape family as
     * `cli.refuse.max_cost_flag`. Accepted spelling: digits only, one or
     * greater, no sign, no separators, no leading `$`, nothing within range
     * of the int overflow that the float path guards below.
     *
     * @throws PermissionConfigException when the flag is present and unusable
     */
    public static function maxTurns(): ?int
    {
        $raw = LaunchContext::maxTurnsOverride();
        if ($raw === null) {
            return null;
        }

        $trimmed = trim($raw);
        if (preg_match('/^\d+$/', $trimmed) === 1) {
            // Digits longer than PHP_INT_MAX's spelling overflow (int) — a
            // ceiling too large to represent is the "never triggers" class
            // maxCostUsd() refuses 1e309 for. Refused, not clamped: the
            // resolver's own doctrine against guessed bounds. The comparison
            // is on zero-stripped decimal strings, where lexical order IS
            // numeric order for equal lengths.
            $max = (string) PHP_INT_MAX;
            $digits = ltrim($trimmed, '0') === '' ? '0' : ltrim($trimmed, '0');
            if (strlen($digits) < strlen($max) || (strlen($digits) === strlen($max) && $digits <= $max)) {
                $value = (int) $digits;
                if ($value >= 1) {
                    return $value;
                }
            }
        }

        throw new PermissionConfigException(
            Lang::t('cli.refuse.max_turns_flag', ['value' => $trimmed]),
        );
    }


    /**
     * The `settings.json` key E707 made configurable, as
     * `EngineBackend::MAX_OUTPUT_TOKENS_CONFIG_KEY` spells it — named here for
     * {@see reportNonsenseLimits()}, the launch's only reader of it.
     */
    private const MAX_OUTPUT_TOKENS_CONFIG_KEY = 'maxOutputTokens';

    /**
     * The operator's per-turn provider-call ceiling — the `--max-turns`
     * flag ({@see maxTurns()}) if given, else the settings key below — or
     * null when no usable one was set - F2 (spawn-latency plan), flag half
     * added by COMPARISON.md §6.5 AUT-5.
     *
     * NULL IS THE DEFAULT'S VOICE: with no resolved value the engine keeps
     * its shipped `maxSteps` (1000 since WAVE_PLAN_2 §5; it was 8 when this
     * was written) untouched, exactly the contract
     * {@see \SugarCraft\Code\Backend\EngineBackend::maxOutputTokens()}
     * records for the token ceiling - nonsense answers null rather than
     * clamping, and no env hatch is worth a second authority over a money
     * axis. (The flag is held to a HIGHER standard than the key: present
     * and unusable, it refuses the launch — see {@see maxTurns()} — while
     * a bad hand-edited key still just resolves to null and is reported.)
     * Accepted for the key: any positive WHOLE number, whether a JSON int,
     * an integral float (`8.0`) or a numeric string (`"16"`); no upper
     * bound - the spend cap and the operator own that question.
     *
     * A FRACTION IS NONSENSE, NOT A ROUNDING PROBLEM (audit 15d-16): a step
     * is a provider call, there is no half of one, and docs/SETTINGS.md has
     * always promised that a non-integer resolves to the default. This
     * resolver used to truncate `1.5` to `1` instead - a ceiling far LOWER
     * than the shipped default, chosen by nobody. The token ceiling still truncates;
     * its doc-comment says why that axis differs.
     *
     * "NO UPPER BOUND" STOPS AT THE INT TYPE (audit 15d-16): a float at or
     * past 2**63 - `1e19`, or a numeric string too long for an int, which
     * `+ 0` turns into one - has no int to become, and `(int)` of it WRAPS
     * (`(int) 1e19` is -8446744073709551616 on PHP 8.3) rather than
     * saturating. Such a value answers null exactly as INF already does:
     * clamping it to some named ceiling would be the guessed bound this key
     * deliberately has none of, and the step-exhausted notice names this
     * key, so a "no limit" spelled too large is discoverable at the first
     * turn it matters — and, since audit R12, at launch: every value this
     * answers null for while the key is set raises
     * {@see reportNonsenseLimits()}' notice.
     *
     * Read at BACKEND CONSTRUCTION, not per turn, and deliberately so:
     * TaskTool raises the ceiling for its OWN sub-agent turns via
     * `withMaxSteps()` AFTER this value lands, and that explicit per-run
     * override must keep winning - a per-turn re-read would silently
     * re-apply the user ceiling under the sub-agent's raised one.
     *
     * @param ?array<string, mixed> $config the already-read merged config;
     *                                      null reads it
     */
    public static function resolvedMaxToolSteps(?array $config = null): ?int
    {
        // `--max-turns` outranks the settings key when it is given, and is
        // already judged — maxTurns() returns a positive whole number or
        // refuses, so a present flag never reaches this method's nonsense-
        // tolerant path. The precedence this ranks is flag-over-config; it
        // does not touch the explicit per-run override TaskTool raises on
        // the constructed instance afterwards, which still wins.
        $flag = self::maxTurns();
        if ($flag !== null) {
            return $flag;
        }

        $raw = ($config ?? self::readUserConfig())[self::MAX_TOOL_STEPS_CONFIG_KEY] ?? null;

        if (is_string($raw)) {
            $raw = is_numeric($raw) ? $raw + 0 : null;
        }

        if (!is_int($raw) && !(is_float($raw) && is_finite($raw))) {
            return null;
        }

        // Both float-only checks run BEFORE the cast, so a value is judged on
        // its own magnitude and not on whatever an overflowing (int) made of
        // it. (float) PHP_INT_MAX is exactly 2**63, the first float past the
        // int range; an int $raw is in range by construction and must not be
        // compared against it (int-vs-float comparison would reject
        // PHP_INT_MAX itself).
        if (is_float($raw) && ($raw >= (float) PHP_INT_MAX || $raw !== floor($raw))) {
            return null;
        }

        return $raw >= 1 ? (int) $raw : null;
    }

    private static function withResolvedMaxToolSteps(EngineBackend $engine): EngineBackend
    {
        // Delegates to {@see LaunchContext::withResolvedMaxToolSteps()}; the door
        // stays private for the reflection that pins it (v4:S1b, 2026-10-21).
        return LaunchContext::withResolvedMaxToolSteps($engine);
    }

    /**
     * PRV-2 (§6.5): the configured `reasoningEffort` as the name of one
     * {@see Effort} tier, or null when it is absent or anything else. There is
     * deliberately no flag for it: the roster in `Cli/ArgvParser.php` is
     * frozen for this shape of knob, and `/effort` is the live door anyway.
     *
     * Nonsense is exactly as good as unset, and this never throws — like
     * `maxOutputTokens`'s resolver, because a settings typo must not break
     * every launch (or every `Chat::withEngineSettings()` re-apply) that
     * follows it. A recognized tier is lowered to its wire spelling, so
     * `"High"` in a hand-edited file is the same tier as `high`.
     */
    public static function resolvedReasoningEffort(): ?string
    {
        $raw = self::readUserConfig()['reasoningEffort'] ?? null;

        if (!\is_string($raw)) {
            return null;
        }

        return Effort::tryFrom(strtolower(trim($raw)))?->value;
    }

    private static function withResolvedReasoningEffort(EngineBackend $engine): EngineBackend
    {
        // Delegates to {@see LaunchContext::withResolvedReasoningEffort()} (the
        // door stays private, v4:S1b, 2026-10-21); C-PRV-1.b added the reason
        // half, and this is the seam that SPEAKS it: a launch whose saved tier
        // shapes nothing carries no tier and records the one row explaining
        // why, on the same shelf — through the same funnel — every other
        // launch notice rides. The reason arrives pre-built from
        // LaunchContext; this door adds no format constant and no new emitter,
        // which is why the stderr and launch-format censuses do not move.
        [$engine, $reason] = LaunchContext::withResolvedReasoningEffort($engine);

        if ($reason !== null) {
            self::warnLaunchRows([$reason]);
        }

        return $engine;
    }

    private static function withConsolePermissionPrompt(EngineBackend $engine, PermissionGate $gate, bool $enabled): EngineBackend
    {
        // Delegates to {@see LaunchContext::withConsolePermissionPrompt()}; the
        // door stays private (v4:S1b, 2026-10-21).
        return LaunchContext::withConsolePermissionPrompt($engine, $gate, $enabled);
    }

    /**
     * The `providers` map from the layered settings reader (rv-enduser-1):
     * name => partial config array, each entry needing at least a string
     * `type` to be honoured. Entries without one are dropped silently here
     * and reported by {@see availableProviders()} when the palette asks, so
     * a malformed row costs a name, never a launch.
     *
     * WHICH LAYER WINS: {@see readUserConfig()}'s merge is shallow at the top
     * level, so the highest layer that carries a `providers` key supplies the
     * WHOLE map — a user config.json map replaces a settings.json map
     * wholesale rather than deep-merging per name. That is the same
     * replace-not-merge doctrine every other Map-typed key already lives by
     * (`models`, `modelPrices`), and it keeps "which endpoint am I actually
     * talking to" answerable from one file.
     *
     * The tier is USER-only on purpose: `providers` decides where prompts and
     * credentials are sent, which is RiskClass::Egress, and the schema's own
     * {@see \SugarCraft\Code\Config\Settings\RiskClass::projectSettableAllowed()}
     * refuses a checked-out repository that direction — a clone must never be
     * able to redirect the operator's API key. Project-side NAMES already
     * reach selection through the package's own
     * .candy-code/config.dev.json fixture ({@see availableProviders()}
     * merges it below), which is the half of the review's "project layer"
     * that survived the doctrine check.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function providerOverrides(): array
    {
        try {
            $raw = self::readUserConfig()['providers'] ?? null;
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($raw)) {
            return [];
        }

        $overrides = [];
        foreach ($raw as $name => $entry) {
            if (is_string($name) && is_array($entry)
                && isset($entry['type']) && is_string($entry['type']) && $entry['type'] !== '') {
                $overrides[$name] = $entry;
            }
        }

        return $overrides;
    }

    /**
     * Delegates to {@see LaunchContext::providerConfigFor()} — the body moved in the S1b
     * composition split (W7-E C3); this keeps the door (and its visibility)
     * every caller and the settings-schema reader citations name.
     */
    public static function providerConfigFor(string $name, ProviderFactory $factory): array {
        return LaunchContext::providerConfigFor($name, $factory);
    }

    /**
     * Every provider name currently selectable, for the Ctrl+P palette's
     * Switch Model action: the built-in provider types {@see
     * ProviderFactory::availableTypes()} knows about, plus every name
     * declared under 'providers' in the project's
     * .candy-code/config.dev.json (e.g. 'dev-sglang') - reusing
     * ProviderFactory's existing lookups rather than adding new discovery
     * logic. Silently returns just the built-ins when that config file is
     * absent/unreadable/invalid, matching {@see
     * ProviderFactory::projectProviderConfig()}'s own tolerance for a
     * missing project config.
     *
     * Since rv-enduser-1 the layered `providers` map joins the same list,
     * last: a settings-file name overrides a same-named built-in or dev-json
     * entry (highest layer wins), and an entry whose `type` resolves nowhere
     * is reported and skipped rather than killing the palette.
     *
     * @return array<string, array<string, mixed>> name => defaultConfig()'s config array
     */
    public static function availableProviders(): array
    {
        $factory = new ProviderFactory();
        $providers = [];

        foreach ($factory->availableTypes() as $type) {
            $providers[$type] = $factory->defaultConfig($type);
        }

        // readableDefaultConfigPath(), NOT defaultConfigPath(): this reads the
        // package's own `__DIR__`-relative `.candy-code/config.dev.json` AT LAUNCH,
        // and under a composer install that file arrives with the dependency rather
        // than being chosen by this session. See
        // {@see ProviderFactory::readableDefaultConfigPath()} for the two boundaries
        // and for why an ungated `__DIR__` climb is the same read path
        // {@see \SugarCraft\Code\Agents\WorktreeConfig} was closed for.
        $configPath = ProviderFactory::readableDefaultConfigPath();
        if ($configPath !== null && is_file($configPath)) {
            $contents = file_get_contents($configPath);
            $data = $contents !== false ? json_decode($contents, true) : null;
            if (is_array($data) && is_array($data['providers'] ?? null)) {
                foreach ($data['providers'] as $name => $config) {
                    if (is_string($name) && is_array($config)) {
                        $providers[$name] = $config;
                    }
                }
            }
        }

        // The settings-layer map lands last so it beats both earlier sources,
        // mirroring how every other layered key shadows what it redefines.
        foreach (self::providerOverrides() as $name => $entry) {
            try {
                $providers[$name] = self::providerConfigFor($name, $factory);
            } catch (\Throwable $e) {
                // A name whose type resolves nowhere must not blank the
                // palette: report once through the launch-notice channel and
                // keep whatever earlier layer offered for that name.
                self::warnPermissionConfigInTranscript(
                    "provider override '{$name}' is not selectable: " . $e->getMessage(),
                );
            }
        }

        return $providers;
    }

    /**
     * ~/.candy-code/config.json — per-user persisted UI choices
     * (`provider`/`theme`, written by the Ctrl+P palette's Switch Model/
     * Switch Theme actions; `layout`, written by the shell; and whatever the
     * settings editor saves on the "You" tier, the per-provider `models` among
     * them) plus hand-authored settings, such as the `instructions` glob array
     * {@see forcedInstructions()} reads. Distinct from {@see
     * ProviderFactory::defaultConfigPath()}'s project-level
     * .candy-code/config.dev.json dev/test fixture — that one is checked
     * into the repo and shared; this one is a real per-user runtime state
     * file, same directory convention as {@see sessionStore()}/{@see
     * memoryStore()}.
     *
     * Resolved off {@see configDirPath()}, NOT {@see configDir()}: naming the
     * config file must never be what creates ~/.candy-code. Every read path
     * runs through here — including {@see \SugarCraft\Code\Backend\EngineBackend}'s
     * per-turn dispatch settings — and a process that only ever reads its
     * config should leave no directory behind on a box where the user never
     * persisted anything. {@see writeUserConfig()} creates the directory at
     * the point it actually has something to put in it.
     */
    public static function userConfigPath(): string
    {
        // `--config <path>` wins over discovery — see {@see useConfigPath()}.
        return LaunchContext::configPathOverride() ?? self::configDirPath() . '/config.json';
    }

    /**
     * Delegates to {@see LaunchContext::useConfigPath()} — the store and its full
     * doc-block moved to the launch-context shelf in the v4 S1b split; the
     * seam stays here so every caller reaches its registration there.
     */
public static function useConfigPath(?string $path): void
    {
        LaunchContext::useConfigPath($path);
    }

    /**
     * Delegates to {@see LaunchContext::useSessionLaunch()} — the store and its full
     * doc-block moved to the launch-context shelf in the v4 S1b split; the
     * seam stays here so every caller reaches its registration there.
     */
public static function useSessionLaunch(bool $continue, bool $resume = false, ?string $target = null): void
    {
        LaunchContext::useSessionLaunch($continue, $resume, $target);
    }

    /**
     * Delegates to {@see LaunchContext::useIncognito()} — C-SES-7.c: bin
     * registers `--incognito` here exactly as it registers the launch mode,
     * and every session-opening surface reads the one static.
     */
    public static function useIncognito(bool $incognito): void
    {
        LaunchContext::useIncognito($incognito);
    }

    /** Whether this launch keeps nothing — {@see LaunchContext::incognito()}. */
    public static function incognito(): bool
    {
        return LaunchContext::incognito();
    }

    /**
     * Delegates to {@see LaunchContext::pinPromptHistoryPath()} — the store and its full
     * doc-block moved to the launch-context shelf in the v4 S1b split; the
     * seam stays here so every caller reaches its registration there.
     */
public static function pinPromptHistoryPath(?string $path): void
    {
        LaunchContext::pinPromptHistoryPath($path);
    }

    /** The cross-session prompt file ↑/↓ recall reads and Enter appends to. */
    public static function promptHistory(): PromptHistory
    {
        return new PromptHistory(LaunchContext::promptHistoryPath() ?? self::configDir() . '/prompt_history.jsonl');
    }

    /**
     * Why the session `--resume <target>` named cannot be opened, or null when
     * it can (or when no target was named). `bin/candy-code` asks before the
     * TUI takes the terminal, so a typo is a usage error rather than a silent
     * fresh session.
     */
    public static function sessionLaunchError(): ?string
    {
        if (LaunchContext::sessionLaunchMode() !== 'resume' || LaunchContext::sessionLaunchTarget() === null) {
            return null;
        }

        try {
            $found = self::findSession(self::sessionStore(false), LaunchContext::sessionLaunchTarget());
        } catch (\Throwable $e) {
            return 'candy-code: ' . Lang::t('cli.argv.resume.store_unavailable', ['error' => $e->getMessage()]);
        }

        return $found === null
            ? 'candy-code: ' . Lang::t('cli.argv.resume.not_found', ['target' => LaunchContext::sessionLaunchTarget()])
            : null;
    }

    /**
     * The session row a `--resume` target names: an exact id, then an exact
     * name, then an id PREFIX that matches exactly one session (ids are long
     * hex strings; `session list` prints them whole, but nobody types sixteen
     * characters when six are unique). Null when nothing, or more than one
     * prefix match, answers.
     *
     * Resolved by {@see \SugarCraft\Code\Session\SessionResolver}, the one
     * resolver the `candy-code session <verb> <target>` subcommands share, over
     * every kind and archived rows too: the prefix step used to search the
     * default list, which hides both, so a prefix of an archived session's id
     * matched nothing while its full id resumed it.
     *
     * @return array<string, mixed>|null
     */
    private static function findSession(SessionStore|EnhancedSessionStore $store, string $target): ?array
    {
        return \SugarCraft\Code\Session\SessionResolver::find($store, $target)?->toArray();
    }

    /**
     * Open the session this launch runs in, as {@see useSessionLaunch()}
     * registered it, with the conversation to put back on screen.
     *
     *  - new: a fresh row. ISS-102: the row is NOT written here any more —
     *    the launch only picks the id, and the returned `mint` closure creates
     *    the row the first time the Chat dispatches a real prompt, so a launch
     *    abandoned at the prompt leaves no empty record behind. Empty rows
     *    older than an hour that earlier (pre-lazy) launches left behind are
     *    still swept ({@see EnhancedSessionStore::pruneEmptySessions()}).
     *  - continue: the most recently used session that holds a conversation,
     *    scoped to this launch's cwd first and falling back to the global
     *    latest ({@see EnhancedSessionStore::latestResumableSessionFromCwd()}),
     *    else {@see seedSession()}.
     *  - resume: the named session; with no target, a fresh row plus the
     *    session picker open over it, so Esc still leaves a working chat.
     *
     * @return array{id: string, name: ?string, history: list<\SugarCraft\Code\Message>, picker: bool, mint?: ?\Closure}
     */
    public static function openSession(
        SessionStore|EnhancedSessionStore $store,
        string $provider = 'candy-code',
        string $model = 'unknown',
    ): array {
        // Roadmap O-8a: `candy-code attach` opened the session on a running
        // server, and the server holds it — its transcript is what goes on
        // screen, and no row is created or swept in the local store.
        $attached = \SugarCraft\Code\Host\RemoteSessionHost::forLaunch();
        if ($attached !== null && $attached->sessionId() !== null) {
            return ['id' => (string) $attached->sessionId(), 'name' => $attached->sessionName(), 'history' => $attached->history(), 'picker' => false];
        }

        // C-SES-7.c: an incognito launch keeps nothing. The id it runs under
        // is generated here and dies with the process — no row is minted (no
        // `mint` key, so Chat's lazy minter never exists), no empty-session
        // sweep touches the store, and no transcript store is ever armed
        // because the caller threads a null store alongside this verdict.
        // The parser refuses the flag beside --continue/--resume, so the
        // ladder below (which reopens stored rows) is unreachable with it.
        if (LaunchContext::incognito()) {
            return ['id' => bin2hex(random_bytes(8)), 'name' => null, 'history' => [], 'picker' => false];
        }

        if (LaunchContext::sessionLaunchMode() === 'continue') {
            // The newest session WITH a conversation, not the newest row — and,
            // in this order, the newest one recorded under this cwd before any
            // other project's (ISS-026). A launch quit without typing is newer
            // than the work the user wants back, so "with a conversation" is
            // the predicate; crossing into another project is allowed only
            // when nothing local has content, and says so on stderr.
            // seedSession() answers only when nothing has content anywhere.
            $cwd = \getcwd();
            $pick = $store instanceof EnhancedSessionStore
                ? $store->latestResumableSessionFromCwd(\is_string($cwd) && $cwd !== '' ? $cwd : null)
                : null;
            if ($pick !== null) {
                [$row, $crossed] = $pick;
                $id = (string) $row['id'];
                $name = \is_string($row['name'] ?? null) && $row['name'] !== '' ? $row['name'] : null;
                if ($crossed) {
                    fwrite(STDERR, sprintf(
                        self::translated(self::SESSION_RESUME_CROSS_PROJECT_FORMAT),
                        \is_string($row['cwd'] ?? null) && $row['cwd'] !== '' ? $row['cwd'] : 'unknown',
                    ));
                }
            } else {
                [$id, $name] = self::seedSession($store, $provider, $model);
            }

            return ['id' => $id, 'name' => $name, 'history' => Chat::loadTranscript($store, $id), 'picker' => false];
        }

        if (LaunchContext::sessionLaunchMode() === 'resume' && LaunchContext::sessionLaunchTarget() !== null) {
            $row = self::findSession($store, LaunchContext::sessionLaunchTarget());
            if ($row !== null) {
                $id = (string) $row['id'];
                $name = \is_string($row['name'] ?? null) && $row['name'] !== '' ? $row['name'] : null;

                // ISS-026 residual (rv6): the crossing the ladder announced
                // must not go silent just because the pick was deliberate.
                $launchCwd = \getcwd();
                $crossedCwd = self::crossProjectRowCwd($row, \is_string($launchCwd) ? $launchCwd : '');
                if ($crossedCwd !== null) {
                    fwrite(STDERR, sprintf(
                        self::translated(self::SESSION_RESUME_CROSS_PROJECT_PICKED_FORMAT),
                        $crossedCwd,
                    ));
                }

                return ['id' => $id, 'name' => $name, 'history' => Chat::loadTranscript($store, $id), 'picker' => false];
            }
        }

        $id = bin2hex(random_bytes(8));
        // Where the session was opened, for the picker's footer and its
        // Ctrl+B branch filter (audit B1), which compares against the process
        // working directory's branch.
        $cwd = getcwd() ?: null;
        $branch = $cwd === null ? null : SessionStore::gitBranchAt($cwd);
        // ISS-102: the row itself is minted LAZILY — the closure rides to
        // Chat and runs the first time dispatchTurn() sends a real prompt —
        // so `candy-code`, look, quit` leaves no empty session record.
        $mint = static function () use ($store, $id, $provider, $model, $cwd, $branch): void {
            // Defensive: createSession() INSERTs and a duplicate id throws.
            // If anything else minted this row between the launch and the
            // first prompt (a backfilling save, a hand of the same id), the
            // row exists and that writer's metadata stands.
            if ($store->session($id) !== null) {
                return;
            }
            $store->createSession($id, $provider, $model, null, null, $cwd, $branch);
        };
        if ($store instanceof EnhancedSessionStore) {
            try {
                $store->pruneEmptySessions($id);
            } catch (\Throwable) {
                // Housekeeping only; a launch never fails over it.
            }
        }

        return ['id' => $id, 'name' => null, 'history' => [], 'picker' => LaunchContext::sessionLaunchMode() === 'resume', 'mint' => $mint];
    }

    /**
     * The cwd of a stored session row that was recorded in ANOTHER project
     * than this launch's, or null when the row does not accuse itself.
     *
     * THE SAME PREDICATE AS THE CONTINUE LADDER's `$crossed` flag
     * ({@see EnhancedSessionStore::latestResumableSessionFromCwd()}): an
     * absent or empty `cwd` never accuses — rows minted before the column
     * existed, or by writers with no getcwd, would otherwise spam the launch
     * of every session they touch.
     *
     * @param array<array-key, mixed> $row
     */
    private static function crossProjectRowCwd(array $row, string $launchCwd): ?string
    {
        $rowCwd = \is_string($row['cwd'] ?? null) ? $row['cwd'] : '';

        return $rowCwd === '' || $launchCwd === '' || $rowCwd === $launchCwd ? null : $rowCwd;
    }

    /**
     * Delegates to {@see LaunchContext::useModel()} — the store and its full
     * doc-block moved to the launch-context shelf in the v4 S1b split; the
     * seam stays here so every caller reaches its registration there.
     */
public static function useModel(?string $model): void
    {
        LaunchContext::useModel($model);
    }

    /**
     * Delegates to {@see LaunchContext::useTuiErrorLog()} — the store and its full
     * doc-block moved to the launch-context shelf in the v4 S1b split; the
     * seam stays here so every caller reaches its registration there.
     */
public static function useTuiErrorLog(?string $destination): void
    {
        LaunchContext::useTuiErrorLog($destination);
    }

    /**
     * Delegates to {@see LaunchContext::usePermissionMode()} — the store and its full
     * doc-block moved to the launch-context shelf in the v4 S1b split; the
     * seam stays here so every caller reaches its registration there.
     */
public static function usePermissionMode(?string $mode): void
    {
        LaunchContext::usePermissionMode($mode);
    }

    /**
     * Delegates to {@see LaunchContext::useMaxCost()} — the store and its full
     * doc-block moved to the launch-context shelf in the v4 S1b split; the
     * seam stays here so every caller reaches its registration there.
     */
public static function useMaxCost(?string $maxCost): void
    {
        LaunchContext::useMaxCost($maxCost);
    }

    /**
     * Delegates to {@see LaunchContext::selectedModelName()} — the body moved in the S1b
     * composition split (W7-E C3); this keeps the door (and its visibility)
     * every caller and the settings-schema reader citations name.
     */
    private static function selectedModelName(string $providerName): ?string {
        return LaunchContext::selectedModelName($providerName);
    }

    /**
     * Reads the persisted user config, tolerantly: a missing, unreadable,
     * or invalid-JSON file returns [] rather than throwing - there is
     * nothing yet to persist on a fresh install, and a corrupt file
     * shouldn't block the CLI from starting.
     *
     * The read is `@`-silenced because the `false` branch below already IS the
     * handling for an unreadable file: without it a config the user has
     * chmod'ed away leaks a `Permission denied` warning into the TUI's own
     * output (and fails any `failOnWarning` suite) on a path that then goes on
     * to degrade gracefully anyway.
     *
     * LAYERED SINCE {@see LayeredSettings} — the return value is this file's
     * contents with that class's whitelisted keys backfilled from
     * `~/.candy-code/settings.json` and, for a TRUSTED project root only, from
     * `<root>/.candy-code/settings.json` and `settings.local.json`. For every
     * key `LayeredSettings` does not name, the answer is unchanged: this
     * file, alone, exactly as before. See that class for the precedence order
     * and for why the user's files outrank the project's.
     *
     * STILL TOLERANT AND STILL NON-THROWING, which the layering must not
     * change: `EngineBackend` calls this once per turn, and the trust lookup it
     * now performs goes through {@see permissionConfig()}, which throws on an
     * unusable config and on an unknowable home. {@see projectSettingsTrusted()}
     * swallows that into `false`, so every uncertainty costs the project layer
     * and nothing else.
     *
     * THE USER LAYER DOES NOT FOLLOW `--config`, which is the one asymmetry
     * worth naming here because the first cut of this method got it wrong.
     * `settings.json` is resolved from {@see userSettingsDirOrNull()} — the
     * home-owned `~/.candy-code` — and NOT from `dirname(userConfigPath())`.
     * MEASURED against the first cut: `--config /tmp/anything/config.json`
     * made `/tmp/anything/settings.json` a USER-TIER file, so a `provider` and
     * an `instructions` list — the two keys this whole design refuses to a
     * project at any trust level — came out of a directory with no containment
     * check, no home-ownership check and no trust gate. A repository shipping
     * `crush.json` alongside a `settings.json` and a README saying
     * `candy-code --config ./crush.json` would have had the user tier.
     * {@see useConfigPath()} already documents that the flag names ONE FILE and
     * moves nothing else in `~/.candy-code`; the settings file is one of the
     * things it does not move.
     *
     * @return array<string, mixed>
     */
    public static function readUserConfig(): array
    {
        return self::mergedConfig(true);
    }

    /**
     * {@see readUserConfig()} WITHOUT the project tier — what the user's own
     * files (and the session tier) say. For a key a trusted project may only
     * switch OFF ({@see \SugarCraft\Code\Permissions\ReadOnlyCommands::autoAllowEnabled()}):
     * the merged value lets the project narrow it, this one stops the project
     * widening it back.
     *
     * @return array<string, mixed>
     */
    public static function readUserConfigWithoutProjectTier(): array
    {
        return self::mergedConfig(false);
    }

    /**
     * {@see readUserConfig()}, with the project tier optionally left out.
     *
     * ONE COPY OF THE LAYERING, and that is the whole reason this exists rather
     * than a second `LayeredSettings::merge(...)` call beside its caller.
     * {@see reportProjectTierToolRemovals()} needs the same stack MINUS layers
     * 1+2 so it can diff the two, and a hand-rolled "same thing but without the
     * project layer" would be free to disagree with this one about which layer
     * wins — the precedence bug this class has already been bitten by twice.
     *
     * THE SESSION TIER SITS ON TOP (roadmap N-P3): a value the settings view
     * saved "for this session only" ({@see \SugarCraft\Code\Config\Settings\SessionSettings}) outranks every
     * file, `config.json` included, and is outranked by the environment and
     * flags, whose readers consult them before this array. It is laid on the
     * merged result rather than handed to {@see LayeredSettings::merge()} so
     * that method's three-layer contract — and the docs that explain it — stay
     * exactly as they are. Both halves of the tool-removal diff get it, so it
     * can never read as a project-tier removal.
     *
     * @return array<string, mixed>
     */
    private static function mergedConfig(bool $withProjectTier): array
    {
        $root = LaunchContext::projectRootForSettings();
        $userSettingsDir = self::userSettingsDirOrNull();

        return array_merge(
            LayeredSettings::merge(
                self::rawUserConfig(),
                $userSettingsDir === null ? [] : LayeredSettings::userLayer($userSettingsDir),
                $root === null || !$withProjectTier
                    ? []
                    : LayeredSettings::projectLayer($root, self::projectSettingsTrusted($root)),
            ),
            \SugarCraft\Code\Config\Settings\SessionSettings::all(),
        );
    }

    /**
     * The directory {@see LayeredSettings::USER_FILE} is read from, or null
     * when this process cannot establish whose home it is.
     *
     * {@see trustedConfigDirPath()}, NOT {@see configDirPath()} and NOT
     * `dirname(userConfigPath())`, and the two exclusions have different
     * reasons:
     *
     *  - not `dirname(userConfigPath())`, because that follows `--config` — see
     *    {@see readUserConfig()} for the measurement;
     *  - not `configDirPath()`, because that resolves through
     *    {@see homePath()}'s world-writable STAND-IN when no home is knowable,
     *    and a `settings.json` may carry `provider` and `instructions`. Those
     *    are the same policy tier `hooks.yaml` and the agent presets are in, so
     *    they get the same gate those already have.
     *
     * NULL RATHER THAN A THROW, unlike every other caller of that method: this
     * one is reached from {@see readUserConfig()}, whose contract is that no
     * uncertainty costs more than the setting it was about. On a real launch
     * the question is already settled — {@see chat()}, {@see backend()} and
     * {@see backendFor()} each resolve {@see trustedConfigDirPath()} before
     * they build anything, so a process that cannot establish its home has
     * already refused to start. What this guard covers is the direct caller:
     * `EngineBackend`'s per-turn read, and a subcommand that reads the config
     * without a launch behind it.
     *
     * PUBLIC since the S1b composition split (W7-E C5): the `app()` builder's
     * body now lives on {@see LaunchContext} and reaches this door across the
     * class line. The door itself stays here — this is launch state, not a
     * composition.
     */
    public static function userSettingsDirOrNull(): ?string
    {
        try {
            return self::trustedConfigDirPath();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The CLI-written user file ALONE — {@see readUserConfig()} minus the
     * layering.
     *
     * Separate from the layered read because {@see writeUserConfig()} merges
     * onto what it reads and then writes the result back. Reading the LAYERED
     * view there would copy every effective value into the user's own file the
     * first time anything persisted a theme: a `disabledSkills` a project chose
     * would become a permanent user-tier setting, outliving the checkout that
     * suggested it and surviving into every other repository. That is a
     * one-way promotion from the lowest-trust layer to the highest, performed
     * by a UI action that says "Switch theme".
     *
     * FORGIVING, AND THEREFORE A READ-ONLY ANSWER: an unreadable or unparsable
     * file comes back `[]` so a corrupt config costs a setting and not the
     * session. That is wrong as a MERGE BASE — it would write the patch over
     * everything the file held — so the write path does not use this method;
     * it reads through {@see userConfigForMerge()}, which says null instead.
     *
     * @return array<string, mixed>
     */
    private static function rawUserConfig(): array
    {
        $path = self::userConfigPath();
        if (!is_file($path)) {
            return [];
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return [];
        }

        $data = json_decode($contents, true);

        return is_array($data) ? $data : [];
    }

    /**
     * Name the project whose `.candy-code/settings.*` files this process may
     * consider — called by the four entry points that resolve a `$root`
     * ({@see chat()}, {@see app()}, {@see backend()}, {@see backendFor()}).
     *
     * NO PROJECT LAYER UNTIL AN ENTRY POINT HAS NAMED ONE, and that is the
     * whole of the rule. `readUserConfig()` has no `$root` parameter and cannot
     * grow one — its callers include `EngineBackend`'s per-turn read, five and
     * six frames below anything that knows a root — so the root has to be
     * remembered, the same way {@see useConfigPath()} remembers a config path
     * and for the same reason. Deriving it from `getcwd()` inside the read
     * instead would have been wrong whenever `--root` named something else: the
     * settings would come from the directory the user was standing in and the
     * files from the repository they pointed at.
     *
     * Falling back to `getcwd()` was the other candidate and is deliberately
     * NOT taken: a subcommand like `candy-code models` reads the user config
     * without ever naming a project, and having it silently pick up the CWD's
     * settings would make the project tier apply on paths that never opened a
     * project. Unnamed means user tier only, which is the pre-layering
     * behaviour and the safe direction.
     *
     * Null resets it, which tests must do — and a BLANK string is normalised to
     * null rather than stored, which buys no behaviour today and is kept anyway
     * for one measured reason: on PHP 8.3 `realpath('')` answers with the
     * PROCESS CWD, not `false`. Stored blank, {@see projectSettingsTrusted()}
     * would put the trust question to whatever directory the shell was standing
     * in — a directory no entry point named. The layer still comes back empty
     * because {@see LayeredSettings::projectLayer()} refuses a blank root, so
     * this is the outer of three guards; it is pinned directly, by reflection,
     * in `BootstrapLayeredSettingsTest`, since nothing observable downstream can
     * tell the three apart.
     */
    public static function useProjectRootForSettings(?string $root): void
    {
        LaunchContext::rememberProjectRootForSettings($root === null || trim($root) === '' ? null : $root);
        self::ensurePolicyFreshnessChecked();
    }

    /**
     * rv-sec-3: the one-launch policy-freshness guard, armed at most once per
     * process (see {@see PolicySnapshot} for what it records and why).
     */
    private static function ensurePolicyFreshnessChecked(): void
    {
        if (LaunchContext::policyFreshnessChecked()) {
            return;
        }
        LaunchContext::markPolicyFreshnessChecked();

        try {
            self::checkPolicyFreshness();
        } catch (\Throwable) {
            // Fail-open by ruling: the guard needs the config directory to be
            // writable and the policy files to be readable, and every one of
            // those uncertainties has a louder, already-reported path in the
            // same launch (requirePrivatePolicyFile, the hook-chain refusal).
            // A guard that can brick a launch on its own bookkeeping failure
            // is a second bug, not a safety property.
            LaunchContext::rememberPolicyChanged(false);
        }
    }

    /**
     * Compare this launch's policy files to the snapshot the LAST launch
     * wrote, report any drift loudly, quarantine what is new for this launch
     * only, then record the current state for the next comparison.
     *
     * Order matters: the notice is pushed AFTER the new record is written, so
     * a launch that fatals between the two still leaves the quarantine armed
     * consistently for its successor; and the current-state record is built
     * from the UNFILTERED trust list, so the launch after the quarantine
     * honours exactly what the operator has now seen announced.
     */
    private static function checkPolicyFreshness(): void
    {
        $configDir = static fn (): string => self::trustedConfigDirPath();
        $userConfigPath = $configDir() . '/config.json';
        $userHooksPath = $configDir() . '/hooks.yaml';
        // C-EXT-1.b: the user's `.claude/settings.json` feeds the same hook
        // chain as `hooks.yaml` with no gate at all, yet INT-4-P2's record
        // never hashed it — a silent edit there was exactly the gap.
        $userClaudePath = self::userClaudeSettingsFile();

        $previous = PolicySnapshot::read($configDir);
        $currentConfig = PolicySnapshot::fingerprint($userConfigPath);
        $currentHooks = PolicySnapshot::fingerprint($userHooksPath);
        $currentClaude = PolicySnapshot::fingerprint($userClaudePath);

        $trustedRoots = self::trustedProjectHookRoots(self::permissionConfig());
        $projectHookIdentities = [];
        foreach ($trustedRoots as $root) {
            // Every PROJECT file `hookFiles()` will read, not just the native
            // one: a `.claude/settings.json` GAINING a hook is the same
            // ISS-090(a) event, and recording only `hooks.yaml` would let the
            // CC rows run un-quarantined on the launch that first saw them.
            $files = array_merge(
                [$root . '/.candy-code/hooks.yaml'],
                array_map(
                    static fn (string $name): string => $root . '/.claude/' . $name,
                    ClaudeSettingsHooks::FILENAMES,
                ),
            );
            $entries = [];
            try {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        $entries = array_merge($entries, self::hookFileEntries($file));
                    }
                }
            } catch (\Throwable) {
                // Unreadable/malformed today: `hooks()` will refuse the launch
                // over it with the real message; the record stores the empty
                // set, which quarantines everything tomorrow — the safe side.
                $entries = [];
            }
            $projectHookIdentities[$root] = array_values(array_map(
                static fn (array $e): string => $e['name'] . '|' . $e['event'] . '|' . $e['command'],
                $entries,
            ));
        }

        $userChanged = $previous !== null
            && ($currentConfig !== $previous['userConfig']
                || $currentHooks !== $previous['userHooks']
                || $currentClaude !== $previous['userClaudeSettings']);

        // ISS-090(a): a trusted repo's hooks.yaml GAINING entries is exactly
        // the event the entry filter exists for, and it used to arm nothing —
        // the fingerprints covered only the user's two files, so the hook a
        // `git pull` walked in ran on the very launch that first saw it. The
        // identities the next filter needs were already being recorded; the
        // decision simply never read them. Comparing whole identity maps
        // (not "did the file appear") also covers a REMOVED entry changing
        // the list, which is harmless to quarantine but keeps the snapshot
        // honest about what changed.
        $projectHooksChanged = $previous !== null
            && $projectHookIdentities !== ($previous['projectHooks'] ?? []);

        $changed = $userChanged || $projectHooksChanged;

        LaunchContext::rememberPolicyChanged($changed);
        LaunchContext::rememberPolicyPreviousProjectHooks($changed ? ($previous['projectHooks'] ?? []) : []);
        LaunchContext::rememberPolicyPreviousTrustedRoots($changed && is_array($previous['trustedRoots'] ?? null)
            ? $previous['trustedRoots']
            : []);

        PolicySnapshot::write($configDir, [
            'version' => PolicySnapshot::VERSION,
            'userConfig' => $currentConfig,
            'userHooks' => $currentHooks,
            'userClaudeSettings' => $currentClaude,
            'trustedRoots' => $trustedRoots,
            'projectHooks' => $projectHookIdentities,
        ]);

        if ($changed) {
            // One seam call still (the census counts call sites, not keys):
            // the project-only drift names the file the operator actually
            // needs to review, which is in the repo, not in ~/.candy-code.
            self::warnPermissionConfigInTranscript(Lang::t(
                $projectHooksChanged && !$userChanged
                    ? 'appLaunch.notice.project_policy_changed'
                    : 'appLaunch.notice.policy_changed'
            ));
        }
    }

    /**
     * Whether THIS launch is quarantining freshly-seen project policy
     * (rv-sec-3). True only when the last launch's snapshot exists and the
     * user's config.json, hooks.yaml or `.claude/settings.json` hashed
     * differently, or a trusted
     * project's hooks.yaml carries identities the last launch never saw —
     * see {@see checkPolicyFreshness()}.
     */
    private static function policyQuarantineActive(): bool
    {
        self::ensurePolicyFreshnessChecked();

        return LaunchContext::policyChanged();
    }

    /**
     * Whether the operator opted this project root in to contributing settings
     * — {@see projectCommandShellIsTrusted()}'s shape for
     * {@see LayeredSettings::PROJECT_SETTINGS_TRUST_KEY}, with the same
     * fail-closed-on-every-uncertainty behaviour and the same once-per-process
     * freeze.
     *
     * THE THROW IS SWALLOWED HERE and nowhere else in the family. Its three
     * siblings let {@see PermissionConfigException} out, because each is called
     * from a launch path that SHOULD refuse to start on an unusable permission
     * policy. This one is called from {@see readUserConfig()}, whose contract is
     * that a corrupt config costs the theme and not the session — see its
     * doc-block — and which `EngineBackend` calls once per turn. So an
     * unreadable config, or a home this process cannot establish ownership of,
     * costs the project settings layer. That is fail-closed: the layer is the
     * lowest-trust input in the stack, and its absence is the pre-layering
     * behaviour.
     *
     * THE SWALLOW IS FROZEN LIKE ANY OTHER ANSWER (E74b). The throw caches the
     * empty list for the config path it had resolved before failing, so the
     * refusal is this process's answer for that path and not a question the
     * next turn may re-ask — {@see \SugarCraft\Code\Tests\Cli\BootstrapLayeredSettingsTest::testACorruptUserConfigCostsTheProjectLayerAndNotTheRead()}
     * pins the repaired-config-mid-process sequence this closes. The one
     * outcome that cannot be frozen is a home this process cannot name: there
     * is no key to answer under, and the next call fails closed the same way.
     *
     * PUBLIC since the S1b composition split (W7-E C5): the moved `app()` and
     * `settingsWriter()` bodies on {@see LaunchContext} reach this door across
     * the class line.
     */
    public static function projectSettingsTrusted(string $root): bool
    {
        $canonical = realpath($root);
        if ($canonical === false) {
            return false;
        }

        // INSIDE the try as well, unlike the three siblings: this is the
        // home-ownership gate, and it throws on exactly the launch
        // ({@see requireHomeDirectory()}) that must not take the theme with it.
        // Leaving it outside would have made the swallow above cosmetic — the
        // first uncertainty in the chain would still have escaped.
        $path = null;

        try {
            $path = self::trustedConfigDirPath() . '/config.json';

            if (!LaunchContext::hasTrustedSettingsRootsFor($path)) {
                LaunchContext::rememberTrustedSettingsRoots($path, self::trustedProjectRoots(
                    self::permissionConfig(),
                    LayeredSettings::PROJECT_SETTINGS_TRUST_KEY,
                    Lang::t('cli.warn.untrusted_settings'),
                ));
            }
        } catch (\Throwable) {
            // THE REFUSED ANSWER FREEZES TOO (E74b). It used to be the ONE
            // outcome this freeze did not cache: a throw left the key absent,
            // so the NEXT call re-read `config.json` — and a config repaired
            // mid-process then widened a grant this process had already
            // refused, which is precisely the widening the property doc-block
            // says the freeze exists to forbid. An empty list is the honest
            // shape of "nothing is trusted from this config path": the freeze
            // is keyed by path, so the refusal cannot leak to a different
            // `~/.candy-code`. `??=` is deliberate-code insurance, not a
            // covered sequence: today a throw with a computed `$path` can only
            // originate inside the fill branch, which runs solely when the key
            // is ABSENT, so plain assignment would behave identically. It
            // stands because the invariant it guards — no later failure may
            // overwrite an earlier cached answer, positive included — would
            // break the moment a throw source moves below the `array_key_exists`
            // gate, and the failure mode of that refactor is a silently revoked
            // trust grant, not a red line. What stays uncached is a throw
            // before `$path` could be computed: with no key there is nothing to
            // freeze, and the next call fails closed the same way.
            if ($path !== null) {
                LaunchContext::freezeTrustedSettingsRootsWithoutTrust($path);
            }

            return false;
        }

        return in_array($canonical, LaunchContext::trustedSettingsRootsFor($path), true);
    }

    /**
     * Read-merge-write $patch into the persisted user config, so a single
     * call only ever touches the keys it names (e.g. switching the theme
     * doesn't clobber a previously-persisted provider choice). This is the
     * ONE door through which the CLI writes its settings file; the private
     * helpers below it only resolve, lock and read.
     *
     * The replacement is ATOMIC — write a sibling temp file, then `rename()`
     * over the target — because a partial write here is not merely a lost
     * theme: {@see permissionConfig()} refuses to launch on a config it cannot
     * parse, so a `/theme` or Ctrl+P persist interrupted by SIGINT, an OOM
     * kill or a full disk would leave a truncated file that bricks every
     * subsequent run, from inside the one binary that could have fixed it.
     * `rename()` within a directory is atomic on POSIX, so a reader ever sees
     * the old file or the new one and never a half of either.
     *
     * A SYMLINKED CONFIG SURVIVES THE WRITE (audit 15d-15). `rename()` onto the
     * link path replaces the LINK, so a dotfiles setup
     * (`~/.candy-code/config.json -> ~/dotfiles/…/config.json`) used to lose
     * its link to the first `/theme`: the live policy then came from a private
     * copy, and every later `permissionMode`/`permissionRules` edit to the real
     * file silently stopped applying. The write now goes to the file the link
     * RESOLVES to — temp file beside it, rename onto it — and only when that
     * file passes the same "this account's, not world-writable" test
     * {@see requirePrivatePolicyFile()} holds the launch to. A dangling link, a
     * link to a directory, or a link to a file that fails that test is not
     * written at all: losing the setting is the documented outcome of a failed
     * write, and replacing a link the user made is not.
     *
     * AN UNPARSABLE CONFIG IS NEVER OVERWRITTEN. The read side treats one as
     * `{}` ({@see rawUserConfig()}), and merging the patch onto that wrote a
     * one-key file over a config an editor was mid-save on or a hand edit had
     * broken — the user's whole policy destroyed by a theme switch. A file that
     * exists but cannot be read, does not decode, or is not a JSON object now
     * refuses the persist instead; a missing or zero-byte file is still "nothing
     * configured", as {@see permissionConfig()} reads it.
     *
     * THE READ-MERGE-WRITE IS SERIALISED by a {@see TimedFileLock} on a sidecar
     * beside the path being written through ({@see acquireUserConfigLock()}),
     * so two sessions persisting different keys no longer lose one of them. A
     * lock that cannot be had within the wait costs THIS setting, never a
     * throw: the callers are a TUI closure (`/theme`, Ctrl+P, `/model`) and the
     * shell's dock persistence, and none of them can do anything with an
     * exception but crash the session over a theme.
     *
     * KEYS ARE REMOVED ONLY BY NAME: `$unset` lists keys to delete from the
     * merged result, which is how the settings editor's reset works — a reset
     * deletes the key rather than writing its default, so a later change to
     * the default is still picked up. `null` in `$patch` is NOT a removal: an
     * explicit null is a statement that outranks every lower layer
     * ({@see \SugarCraft\Code\Config\LayeredSettings}'s `only()`).
     *
     * @param array<string, mixed> $patch
     * @param list<string> $unset keys to remove from the file
     */
    public static function writeUserConfig(array $patch, array $unset = []): void
    {
        $path = self::userConfigPath();

        // The write, not the read, is what earns the directory — see
        // {@see userConfigPath()}. Taken from the target FILE rather than from
        // configDirPath(): the two name the same directory until `--config`
        // points the target somewhere else ({@see useConfigPath()}), and then
        // configDirPath() is the wrong one in two ways at once. The sibling
        // check below would still PASS — tempnam() put the temp file exactly
        // where it was asked to — and the rename() under it would then be a
        // cross-directory move that is atomic only by luck and fails outright
        // across a mount, losing the persist; and ensureDir() would create
        // ~/.candy-code purely as scratch space on a run that was told to
        // stay out of it. MEASURED: with configDirPath() here, a persist to a
        // /tmp override still lands (same filesystem) but leaves that stray
        // directory behind, which is what
        // {@see BootstrapConfigPathOverrideTest::testWriteUserConfigPersistsIntoTheOverrideFile()}
        // pins. A symlinked config's directory holds the link, so it exists.
        self::ensureDir(\dirname($path));

        $lock = self::acquireUserConfigLock($path);
        if ($lock === null) {
            return;
        }

        try {
            // Resolved and read UNDER the lock: a target or a merge base taken
            // before it would be exactly the stale read the lock exists to stop.
            $target = self::userConfigWriteTarget($path);
            if ($target === null) {
                return;
            }

            // The strict read, NOT readUserConfig(): see rawUserConfig() for why
            // merging onto the LAYERED view would persist a project's or a
            // settings.json's values into the user's own file as a side effect
            // of switching a theme — and see the doc-block above for why
            // rawUserConfig()'s own `{}`-on-garbage answer is not safe to write.
            $existing = self::userConfigForMerge($target);
            if ($existing === null) {
                return;
            }

            $merged = array_merge($existing, $patch);
            foreach ($unset as $key) {
                unset($merged[$key]);
            }

            // An emptied file is still an OBJECT: `[]` would decode as a list,
            // which every reader of this file treats as "not a config".
            $json = json_encode($merged === [] ? new \stdClass() : $merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return;
            }

            $dir = \dirname($target);

            // The temp file must be the target's SIBLING: rename() is only atomic
            // within one filesystem, and tempnam() silently falls back to the
            // system temp dir when the requested one is unusable — which on a
            // separate mount would turn the rename into a failure rather than a
            // torn write, but is worth refusing explicitly either way. For a
            // symlinked config the sibling is the RESOLVED file's: the link and
            // its target may sit on different mounts (a dotfiles checkout on
            // another volume is ordinary).
            //
            // Compared through realpath() on BOTH sides, never as raw strings:
            // tempnam() hands back a CANONICAL path, so a `HOME` with a trailing
            // slash (`HOME=/root/` is ordinary in a Dockerfile), a doubled slash,
            // or a `/./` made `dirname($temp) !== $dir` true for every write and
            // silently disabled config persistence outright — the sibling check
            // refusing the one directory it was pointed at. `realpath()` cannot
            // fail here: ensureDir() has just guaranteed $dir (or the link
            // resolved into it), and tempnam() returns a file it created.
            $temp = @tempnam($dir, '.config.json.');
            if ($temp === false || realpath(\dirname($temp)) !== realpath($dir)) {
                if (is_string($temp)) {
                    @unlink($temp);
                }

                return;
            }

            if (@file_put_contents($temp, $json) !== strlen($json) || !@rename($temp, $target)) {
                // Losing the setting is the correct outcome of a failed write.
                // Leaving the previous config intact is the important half.
                @unlink($temp);

                return;
            }

            // tempnam() creates at 0600; the file this replaces was created at the
            // process umask. 0600 is kept deliberately — this file now carries the
            // launch's permission policy, so it is nobody else's business.
            @chmod($target, 0600);
        } finally {
            TimedFileLock::release($lock);
            fclose($lock);
        }
    }

    /**
     * The file {@see writeUserConfig()} should rename onto, or null when
     * $path is a symlink this process must not write through.
     *
     * A plain file (or a path that does not exist yet) is its own target. A
     * symlink is followed — that is the point, see the write's doc-block — but
     * only to a REGULAR FILE that {@see requirePrivatePolicyFile()} accepts:
     * owned by this account and neither it nor its directory writable by every
     * account. That is the test the launch already applies to the policy file
     * it reads, so a link the write follows is a link the launch would have
     * run; following one the launch would refuse would let a theme switch
     * write the user's policy into somebody else's file. A dangling link is
     * refused rather than "repaired" by creating its target: the launch
     * refuses that state too (README, permission policy), and guessing which
     * file the user meant is not this method's call.
     */
    private static function userConfigWriteTarget(string $path): ?string
    {
        clearstatcache(true, $path);
        if (!is_link($path)) {
            return $path;
        }

        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved)) {
            return null;
        }

        try {
            self::requirePrivatePolicyFile($resolved);
        } catch (PermissionConfigException) {
            return null;
        }

        return $resolved;
    }

    /**
     * The persisted config to merge a patch onto, or null when the file there
     * must not be overwritten — {@see rawUserConfig()}'s read with its
     * forgiveness removed, for the write path only.
     *
     * The read callers keep the forgiving answer on purpose: a corrupt config
     * costs a theme on read, never the session. On WRITE the same `[]` is not
     * forgiveness but deletion — it is the merge base, so everything the file
     * held is gone the moment the rename lands. Missing and zero-byte stay
     * `[]`, matching {@see permissionConfig()}: there is nothing to lose. A
     * top-level JSON list is refused like any other non-object, on the JSON
     * TEXT, because `json_decode()` maps `[]` and `{}` to the same value.
     *
     * @return array<string, mixed>|null
     */
    private static function userConfigForMerge(string $target): ?array
    {
        clearstatcache(true, $target);
        if (!file_exists($target)) {
            return [];
        }

        if (!is_file($target)) {
            return null;
        }

        $contents = @file_get_contents($target);
        if ($contents === false) {
            return null;
        }

        if (trim($contents) === '') {
            return [];
        }

        $data = json_decode($contents, true);
        if (!is_array($data) || !str_starts_with(ltrim($contents), '{')) {
            return null;
        }

        return $data;
    }

    /**
     * Take the exclusive lock that serialises {@see writeUserConfig()}'s
     * read-merge-write, or null when it cannot be had.
     *
     * A SIDECAR (`.<name>.lock` beside $path), never the config itself: the
     * write replaces the config's inode by rename(), so a lock held on it
     * would guard a file that is no longer the one on disk, and a second
     * writer opening the path afterwards would lock the new inode unopposed.
     * The sidecar is never unlinked for the same reason TaskList's are not —
     * an unlink lets a waiter win the lock on a dead inode while a newcomer
     * locks a fresh one.
     *
     * Beside the path WRITTEN THROUGH, not beside a symlink's target: every
     * session reaches the config by that one path, and the target is often a
     * dotfiles checkout where a stray lock file would show up as untracked.
     * Created under umask 077 for the reason the config is chmod 0600 —
     * anyone who can open it can hold it, and holding it is a way to make
     * this user's settings stop persisting.
     *
     * A timeout or an unopenable sidecar is null rather than the
     * {@see TimedFileLock} throw: see writeUserConfig() for why its callers
     * must not receive one. The setting is lost and the file left as it was —
     * failing CLOSED, which is the half of TimedFileLock's doctrine that
     * matters here (a write that went ahead without the lock is the lost
     * update this exists to prevent).
     *
     * @return resource|null
     */
    private static function acquireUserConfigLock(string $path)
    {
        $lockPath = \dirname($path) . '/.' . basename($path) . '.lock';

        $umask = umask(0077);
        try {
            $fp = @fopen($lockPath, 'c');
        } finally {
            umask($umask);
        }

        if ($fp === false) {
            return null;
        }

        try {
            TimedFileLock::acquire($fp, \LOCK_EX, $lockPath);
        } catch (\RuntimeException) {
            fclose($fp);

            return null;
        }

        return $fp;
    }

    /**
     * The `disabledRules` config value, parsed into the list
     * {@see RulesState::new()} will accept without throwing.
     *
     * WHY A NARROWER FILTER THAN THE SIBLING KEY. `disabledSkills` reads the same
     * shape with `array_filter($disabled, 'is_string')`, and that is correct
     * there because {@see \SugarCraft\Code\Skills\SkillRegistry::disable()} takes
     * a blank name harmlessly. {@see RulesState::new()} does not: it runs every
     * entry through a parse step that REJECTS a non-string or a
     * whitespace-only one, because a set of pack identities that contains `""` is
     * a bug worth naming at the boundary rather than carrying. On the launch path
     * an exception there is not a validation message — it is candy-code refusing
     * to start because of a stray `""` in a hand-edited JSON file, against
     * {@see readUserConfig()}'s standing promise that a malformed value costs the
     * key and nothing else. So every unusable entry is dropped here:
     * a non-array value, a non-string element, and a blank or whitespace-only
     * string. There is no `try`/`catch`, because a `catch` around the seed would
     * swallow the one error class this filter exists to make unreachable and would
     * also hide a genuinely malformed key as "nothing disabled".
     *
     * WHAT A PACK NAME IS. `RulesState` keys on {@see \SugarCraft\Code\Context\Rule::$key},
     * which {@see \SugarCraft\Code\Context\RuleLoader::ruleKeyFor()} derives as the
     * file path RELATIVE TO ITS TIER DIRECTORY with separators normalised to `/`
     * and `.md` removed — so a pack in `~/.candy-code/rulebooks/style/terse.md`
     * is `style/terse`, not `terse`, and a config entry has to say the whole
     * thing. The flat case still spells as the basename minus the extension. Both
     * shapes are pinned at the loader that writes them — flat at
     * {@see \SugarCraft\Code\Tests\Context\RuleLoaderTest::testUserRulebooksDirectoryLoadsCarryingTheUserTier()},
     * nested at
     * {@see \SugarCraft\Code\Tests\Context\RuleLoaderTest::testARulebookBelowTheTierDirectoryIsKeyedByItsRelativePathAndNotItsBasename()},
     * the second also asserting that a bare `terse` selects nothing there. The
     * launch fixture at
     * {@see \SugarCraft\Code\Tests\Cli\RulesStateWiringTest::seedRulebook()} seeds
     * flat names only, and the value example beside the `disabledRules` row of
     * `docs/SETTINGS.md` is an example rather than a pin. One exception is worth knowing before anyone
     * debugs a no-op: the repository root's `RULES.md` is keyed as the literal
     * `'RULES.md'`, extension included, and carries tier `root`, so
     * {@see RulesState} will never subtract it and a config entry naming it is
     * inert by design rather than by accident.
     *
     * @return list<string>
     */
    private static function rulePacksToDisable(mixed $configured): array
    {
        if (!\is_array($configured)) {
            return [];
        }

        $packs = [];

        foreach ($configured as $pack) {
            if (\is_string($pack) && trim($pack) !== '') {
                $packs[] = $pack;
            }
        }

        return $packs;
    }

    /**
     * Discover every skill reachable from $root and hand back the populated
     * registry: built-in (src/Skills/BuiltIn), user (~/.candy-code/skills),
     * project ({$root}/.candy-code/skills), and foreign imports from other
     * coding CLIs' conventions — {$root}/.agents/skills, ~/.agents/skills,
     * {$root}/.claude/skills, ~/.claude/skills, {$root}/.opencode/skills,
     * ~/.opencode/skills, ~/.config/opencode/skills (see {@see
     * \SugarCraft\Code\Skills\ForeignSkillDiscovery}).
     *
     * The foreign half of that list was ASPIRATIONAL until crush_code.md
     * Phase 2 item 6: nothing called ForeignSkillDiscovery, so a
     * `~/.claude/skills` tree was discovered by its unit tests and by nothing
     * else. {@see \SugarCraft\Code\Skills\SkillManager::loadAll()} now calls
     * it, registering the imports FIRST so a native skill still wins a name
     * collision — see that method for why additive is the only safe merge
     * direction here.
     *
     * This is the missing link crush_feat.md section 10.5(1) needed: without
     * it, SkillManager/SkillLoader/ForeignSkillDiscovery were only ever
     * exercised by their own unit tests — nothing in `bin/candy-code` called
     * them, so a skill dropped under any of those directories had zero
     * observable effect on a real run. Called from both {@see backend()} and
     * {@see backendFor()} so every provider path (env-driven, persisted, and
     * explicit Ctrl+P selection) gets the same discovered {@see
     * SkillRegistry} threaded into {@see EngineBackend::withSkillRegistry()}.
     */
    public static function skillRegistry(string $root): SkillRegistry
    {
        $registry = new SkillRegistry();
        $manager = new SkillManager(new SkillLoader(), $registry);
        $manager->loadAll($root, self::bundles($root));

        // section 7 E1's disableFromConfig() step: a user who does not want a
        // discovered skill offered to the model needs somewhere to say so,
        // and the persisted user config is the only per-user settings file
        // the CLI already reads. Absent key => nothing disabled.
        $disabled = self::readUserConfig()['disabledSkills'] ?? [];
        if (is_array($disabled)) {
            $manager->disableFromConfig(array_values(array_filter($disabled, 'is_string')));
        }

        // The diagnostic outlives the manager that produced it — see
        // {@see skillSkips()}. Merged rather than replaced because a launch
        // scans more than once (chat(), app(), and every Ctrl+P provider
        // switch), and a later scan finding nothing wrong must not erase what
        // an earlier one found.
        LaunchContext::extendSkillSkips($manager->skipped());

        // X-37a: the skills that loaded and declare keys nothing acts on
        // (`allowed-tools`, `context: fork`, a typo). The registry keeps typed
        // fields only, so each file's frontmatter head is re-read — once per
        // path per process, and never for a built-in, which the user did not
        // write and cannot edit.
        $audit = \SugarCraft\Code\Support\FrontmatterKeyAudit::class;
        foreach ($registry->all() as $skill) {
            $identity = $skill->sourcePath;
            if ($skill->origin === \SugarCraft\Code\Skills\SkillOrigin::BuiltIn
                || LaunchContext::hasIgnoredFrontmatter($audit::SKILL, $identity)
            ) {
                continue;
            }

            try {
                [$head] = \SugarCraft\Code\Skills\SkillFileReader::head($skill->sourcePath, 'SKILL.md');
            } catch (\Throwable) {
                continue;
            }

            LaunchContext::putIgnoredFrontmatter($audit::SKILL, $identity, [
                $skill->name,
                $audit::inspect($audit::SKILL, $audit::metaOf($head)),
            ]);
        }

        // The skills half of the project-tier refusal — a repository that
        // committed `.candy-code/skills`, `.claude/skills` or `.opencode/skills`
        // as a link out of the checkout gets the tree dropped, and said so
        // nowhere. Merged, not replaced, for the reason above.
        self::$projectTierRefusals = [...self::$projectTierRefusals, ...$manager->refusedDirectories()];

        return $registry;
    }

    /**
     * The skills whose FULL BODIES the system prompt carries every turn.
     *
     * Reads the `enabledSkills` key of the merged user tier
     * ({@see readUserConfig()}: `config.json` over `~/.candy-code/settings.json`,
     * never a project file) — a list of names resolved against the SAME registry {@see skillRegistry()} built,
     * so discovery tiers and the `disabledSkills` list decide what a name can
     * mean before it can mean a body. THE DEFAULT IS THE EMPTY LIST: no user
     * gets standing skill instructions they did not ask for, and the channel
     * EngineBackend::withSkills() opens becomes reachable only via explicit
     * config. Threaded at both composition sites — backend() and backendFor()
     * — because a provider switch must not drop what the echo launch promised.
     *
     * FAIL-SAFE BY DECISION, unlike a PermissionConfigException: a stale name
     * in a config file (a skill since deleted, a typo) drops with a launch
     * notice through the same bounded transcript/stderr channel every other
     * config warning uses — one dead entry must not brick the CLI. And a name
     * that `disabledSkills` also lists resolves to null here (registry->get()
     * honours the disable), so opting out wins over opting in: the two keys
     * contradicting each other cannot smuggle a body past a deliberate ban.
     * Fail-safe about ENTRIES, not about the KEY'S SHAPE — a value that is not
     * a list, or a list element that is not a name, is the user's config being
     * wrong rather than one entry being stale, and each of those is said the way
     * {@see permissionRules()} says its own: one notice naming the key for the
     * first, one naming the index for the second, and no silent drop.
     *
     * EACH NAME COUNTS ONCE: the list is deduplicated strictly before it is
     * resolved, because `["pdf", "pdf"]` is one skill asked for twice, and Runtime
     * renders one section per entry it is handed — so an undeduplicated list put
     * the same standing instructions in every turn of the session. The
     * exactly-once guarantee the bodies carry is decided here, not downstream.
     *
     * LAYERED, USER TIER ONLY, since settings step N-DOC-2. This paragraph
     * used to say the key was config.json-level on purpose, deferring the
     * roster growth to "a settings-surface step"; that step has landed, so
     * `enabledSkills` is in {@see LayeredSettings::LAYERED_KEYS} and a
     * `~/.candy-code/settings.json` value now reaches this read the way its
     * sibling `disabledSkills` always did. No PROJECT file may set it (it is
     * absent from {@see LayeredSettings::PROJECT_TIER_KEYS}): a name here
     * makes a body standing prompt text, which is `instructions`' argument.
     * Nothing in this method changed — {@see readUserConfig()} already
     * answers the merge; only the merge's whitelist grew.
     *
     * Registry entries are STAGE-1 manifests — `SkillManager::loadAll()`
     * deliberately never reads a body off disk — so each resolved name gets
     * its full `Skill::fromFile()` here, the same source-of-truth load the
     * Skill tool performs at invocation (SkillTool loads via the loader; this
     * parses once at composition and hands Runtime the finished object).
     * A source file that vanished between discovery and launch drops with a
     * notice for the same reason an unknown name does: a body-less heading
     * would inject a fact about nothing.
     *
     * @return list<Skill>
     */
    public static function promptEnabledSkills(SkillRegistry $registry): array
    {
        $config = self::readUserConfig();
        if (!\array_key_exists('enabledSkills', $config)) {
            return [];
        }

        $enabled = $config['enabledSkills'];
        if (!is_array($enabled)) {
            self::warnPermissionConfigInTranscript(
                Lang::t('cli.warn.enabled_skills_not_list'),
            );

            return [];
        }

        // THE LIST IS PARSED HERE, ONCE, into trusted strings — so the loop
        // below needs no defensive `is_string` of its own. A wrong-shaped entry
        // is SKIPPED, AND SAID, with its index: the position in the file is the
        // only handle a user has on which entry they mistyped, and dropping it
        // quietly leaves them believing `42` is a skill they configured. Same
        // fail-loud shape as every other list key this class reads.
        $names = [];
        foreach ($enabled as $index => $entry) {
            if (!is_string($entry)) {
                self::warnPermissionConfigInTranscript(
                    Lang::t('cli.warn.enabled_skill_not_name', ['index' => $index]),
                );

                continue;
            }

            $names[] = $entry;
        }

        $skills = [];
        // STRICT DEDUPE, because the exactly-once invariant lives here: a name
        // listed twice resolves to two `Skill` objects, Runtime renders a section
        // for each, and every turn of the session pays for a duplicate while
        // saying nothing about it. `SORT_REGULAR` compares the
        // strings as strings, and `array_values` re-indexes so the surviving
        // order is the configured one.
        foreach (array_values(array_unique($names, SORT_REGULAR)) as $name) {
            $manifest = $registry->get($name);
            if ($manifest === null) {
                // Opting out beats opting in: a name `disabledSkills` also lists
                // resolves to null here because `SkillRegistry::get()` honours
                // the disable. `isDisabled()` is the registry's only public
                // disabled-check and it cannot separate "listed and disabled"
                // from "listed, disabled, never discovered" — so the cause named
                // below is the CONFIG, true in both cases and the only one the
                // reader can act on. A presence accessor would be API churn
                // bought for the phrasing of one notice.
                self::warnPermissionConfigInTranscript($registry->isDisabled($name)
                    ? Lang::t('cli.warn.enabled_skill_disabled', ['skill' => $name])
                    : Lang::t('cli.warn.enabled_skill_missing', ['skill' => $name]));

                continue;
            }
            try {
                $skills[] = Skill::fromFile($manifest->sourcePath);
            } catch (\RuntimeException|\InvalidArgumentException $e) {
                self::warnPermissionConfigInTranscript(
                    Lang::t('cli.warn.enabled_skill_unreadable', ['skill' => $name, 'error' => $e->getMessage()]),
                );
            }
        }

        return $skills;
    }

    /**
     * Every SKILL.md this process's skill scans could not read, keyed by path.
     *
     * The seam that replaced {@see \SugarCraft\Code\Skills\SkillLoader}'s old
     * per-skip `error_log()`. Getting those lines off stderr was right — they
     * are OTHER TOOLS' files, one broken third-party skill printed on every
     * launch, and a skill scan also runs mid-session on the Ctrl+P provider
     * switch, where the line lands inside a frame the renderer believes it
     * owns — but a diagnostic nothing reads is the same as no diagnostic. So
     * the detail lives here, reachable from a doctor report or a debug pane,
     * and {@see reportSkillSkips()} puts ONE bounded line in front of the user
     * at launch so they know it is here to ask for.
     *
     * @return array<string, string> sourcePath => why it was skipped
     */
    public static function skillSkips(): array
    {
        return LaunchContext::skillSkips();
    }

    /**
     * Tell the user, once and in one line, that some skill files were skipped.
     *
     * ONE LINE regardless of how many, and only when there ARE some: the
     * failure this replaced was N lines every launch. Called from
     * {@see chat()} rather than from {@see skillRegistry()} deliberately —
     * skillRegistry() also runs on the Ctrl+P provider switch, mid-session,
     * with the alt screen up, and that is precisely where a stderr write
     * corrupts the frame. Construction time, before Program takes the
     * terminal, is the only safe moment, which is the same reasoning
     * {@see hookFiles()}'s skip notice is written under.
     *
     * PUBLIC because two construction paths outside this method's original
     * caller reach a skill scan and never reported it: {@see app()} scans a
     * second time for the shell's panes (skips found only by THAT scan were
     * collected and never surfaced), and {@see NonInteractive::run()} builds a
     * backend without going through {@see chat()} at all, so a `-p` run
     * swallowed the notice outright. Both call it at construction time, and the
     * `-p` path has no alt screen to corrupt in the first place.
     */
    public static function reportSkillSkips(): void
    {
        // Only what has not been reported yet, keyed the same way
        // {@see LaunchContext::$reportedUntrustedHookFiles} is: a process that builds more
        // than one Chat (a test suite, an embedder) must not re-print the
        // same skips, and a second scan that found something NEW still says so.
        $new = array_diff_key(LaunchContext::skillSkips(), LaunchContext::reportedSkillSkips());
        if ($new === []) {
            return;
        }

        foreach (array_keys($new) as $path) {
            LaunchContext::markSkillSkipReported($path);
        }

        $count = \count($new);
        // BOTH CHANNELS — see {@see warnPermissionConfigInTranscript()}. A skill
        // that did not load is a capability the session does not have, and the
            // user meets that as `/skill` not offering something they wrote. ONE
            // ROW, whatever the count: this message is already an aggregate, which
            // is what makes it safe to put in a transcript that also has to carry
            // thirty-one other sources. THIS SAID ELEVEN. Round 44 could not correct
        // it — this file was outside that lane's ownership, which is the whole
        // reason, and not how long the sentence had been wrong — so it asserted
        // the gap instead, with a test whose failure message was the
        // instruction for closing it (E119). The number
        // is now a row in BootstrapTranscriptSeamCallSiteCensusTest's
        // PROSE_SITES and a declaration in {@see TRANSCRIPT_SEAM_CALL_SITES}, so
        // a further site reds this sentence rather than dating it.
        self::warnPermissionConfigInTranscript(sprintf(
            self::translated(self::SKILL_SKIP_NOTICE_FORMAT),
            $count,
            $count === 1 ? '' : 's',
            $count === 1 ? 'was' : 'were',
            SkillLoader::DEBUG_SKIPS_ENV,
            $count === 1 ? 'it' : 'them',
        ));
    }

    /**
     * Tell the user, once and in one line, that some of their memory notes
     * could not be read and are being skipped.
     *
     * Since audit 15d-04 a malformed note is skipped instead of failing every
     * turn, and the only party told was the MODEL, inside the prompt — the
     * person who wrote the note, and the only one who can fix it, learned
     * nothing. Both stores the session reads are scanned whole
     * ({@see MemoryStore::unreadable()}), every scope: a broken user-scope note
     * never reaches the prompt either, and `/memory` is the only other place
     * it would ever surface. The wording is {@see UnreadableNotes::notice()}'s,
     * shared with the `/memory` section that names each file.
     *
     * Construction time, beside {@see reportSkillSkips()} and for its reason.
     * A store that cannot be opened is no notice at all — the prompt route
     * degrades the same way ({@see memoryStoreOrNull()}).
     *
     * PUBLIC FOR THE `-p` PATH (audit 15d-02 residual R10), for the reason
     * {@see reportSkillSkips()} is: {@see NonInteractive::run()} builds its
     * backend without going through {@see chat()}, so a one-shot run fed the
     * prompt from the same memory stores and told nobody a note had been left
     * out. A null root is the cwd, as {@see backend()} resolves it, so the
     * `-p` caller reads the project store its backend reads.
     */
    public static function reportMemorySkips(?string $root): void
    {
        $root ??= getcwd() ?: null;
        $home = self::memoryStoreOrNull($root);
        $notices = [];

        // THE ONE-TIME BINDING NOTICE (audit 15d-05). Unkeyed project notes an
        // earlier build left in the shared `project/` directory now belong to
        // the first project whose launch read them; say so once, here, whether
        // this launch or an earlier `-p` run did the moving — the store keeps
        // the record until it is taken. Read BEFORE the unreadable scan, which
        // is itself a read of the project scope and so performs the binding.
        $bound = $home?->takeBoundLegacyNotes() ?? [];
        if ($bound !== [] && $home !== null) {
            $notices[] = sprintf(
                self::translated(self::MEMORY_LEGACY_BOUND_NOTICE_FORMAT),
                \count($bound),
                \count($bound) === 1 ? '' : 's',
                \count($bound) === 1 ? 'is' : 'are',
                ProjectRoot::resolve((string) $root),
                $home->projectDirectory(),
            );
        }

        $unreadable = [];
        foreach ([$home, $root === null ? null : ProjectMemoryWriter::forRoot($root)?->store()] as $store) {
            if ($store !== null) {
                $unreadable = [...$unreadable, ...$store->unreadable()];
            }
        }

        $new = array_diff_key($unreadable, LaunchContext::reportedMemorySkips());
        foreach (array_keys($new) as $path) {
            LaunchContext::markMemorySkipReported($path);
        }
        if ($new !== []) {
            // BOTH CHANNELS, ONE ROW whatever the count — the skill-skip row's
            // contract ({@see reportSkillSkips()}).
            $notices[] = UnreadableNotes::notice($new);
        }

        // Through the shared row emitter, whose one seam call site serves every
        // aggregate report that raises more than one row — so the census
        // ({@see TRANSCRIPT_SEAM_CALL_SITES}) does not move per report.
        self::warnLaunchRows($notices);
    }

    /**
     * Put each row on both launch channels, through ONE seam call site.
     *
     * The reports that can raise several bounded rows from one call —
     * {@see reportMemorySkips()}, {@see reportPromptBudgetDeferrals()},
     * {@see reportNonsenseLimits()}, {@see reportTuiErrorLogFallback()} — share this rather than each spelling the
     * seam call, so {@see TRANSCRIPT_SEAM_CALL_SITES} counts the seam's call
     * sites and not the reports that reach it. What bounds the ROWS is
     * {@see LAUNCH_NOTICE_LIMIT}'s argument, which names each of these
     * reports as a source.
     *
     * @param list<string> $rows
     */
    private static function warnLaunchRows(array $rows): void
    {
        foreach ($rows as $row) {
            self::warnPermissionConfigInTranscript($row);
        }
    }

    /**
     * Tell the user, at launch, which instruction files and enabled skill
     * bodies the system prompt leaves out (audit 15d-09 / C3 residual R1).
     *
     * Since C3 an instruction document over its budget — or one refused for
     * resolving outside the checkout, or an `@import` that does not fit — is a
     * pointer line in the prompt and an entry in
     * {@see InstructionFileLoader::refusedPaths()}, and an enabled skill over
     * the skill budget is its heading and a pointer. The MODEL is told; the
     * user, who wrote the CLAUDE.md or enabled the skill and expects it obeyed,
     * was told nothing, because nothing drained either record.
     *
     * THE VERDICTS ARE THE PROMPT'S OWN: the launch prices its loader through
     * {@see \SugarCraft\Code\Runtime::planInstructionDocuments()} and its
     * enabled skills through {@see \SugarCraft\Code\Runtime::planEnabledSkills()},
     * the two methods every prompt build calls, against the same files the
     * backend's loader reads. A launch-time pass rather than a drain of the
     * backend's loader because a turn runs in a forked child, whose recorded
     * deferrals never reach this process; the inputs are on disk, so the same
     * pricing here reaches the same answer. The skill budget is the defaults,
     * because no launch path configures a {@see \SugarCraft\Code\App\App::$compactorConfig}.
     *
     * Files touched mid-session ({@see InstructionFileLoader::loadForPath()})
     * are not covered HERE: they arrive as the model reads paths, after the
     * terminal is taken, so the loader announces each one itself, through the
     * mid-session notice seam, as it is refused (audit R1's last residual).
     * ONE ROW PER KIND whatever the count, for
     * {@see LAUNCH_NOTICE_LIMIT}'s reason. Construction time, beside
     * {@see reportSkillSkips()} and for its reason; public for the `-p` path
     * for {@see reportMemorySkips()}'s.
     */
    public static function reportPromptBudgetDeferrals(?string $root, ?SkillRegistry $skills = null): void
    {
        $root ??= getcwd() ?: null;
        $rows = [];

        $documents = self::instructionDeferrals($root);
        if ($documents !== []) {
            $rows[] = sprintf(
                self::translated(self::INSTRUCTION_DEFERRAL_NOTICE_FORMAT),
                \count($documents),
                \count($documents) === 1 ? '' : 's',
                \count($documents) === 1 ? 'was' : 'were',
                self::deferralList($documents),
            );
        }

        $skillBodies = self::skillBodyDeferrals($root, $skills);
        if ($skillBodies !== []) {
            $rows[] = sprintf(
                self::translated(self::SKILL_BUDGET_DEFERRAL_NOTICE_FORMAT),
                \count($skillBodies),
                \count($skillBodies) === 1 ? '' : 's',
                \count($skillBodies) === 1 ? 'is' : 'are',
                \count($skillBodies) === 1 ? 'its' : 'their',
                self::deferralList($skillBodies),
            );
        }

        self::warnLaunchRows($rows);
    }

    /**
     * The launch loader's instruction refusals, priced as a prompt build
     * prices them — see {@see reportPromptBudgetDeferrals()}.
     *
     * A root this launch cannot resolve, or a loader that throws, is no row
     * at all: the prompt route degrades the same way, and a notice about a
     * notice is not worth a failed launch.
     *
     * @return array<string, string> path as spelled => why it was left out
     */
    private static function instructionDeferrals(?string $root): array
    {
        if ($root === null) {
            return [];
        }

        try {
            $loader = self::instructionLoader($root);
            \SugarCraft\Code\Runtime::planInstructionDocuments($loader);
            $refused = $loader->refusedPaths();
        } catch (\Throwable) {
            return [];
        }

        // Named relative to the root when they are under it — `CLAUDE.md`, not
        // the checkout's absolute path twice over — so the transcript row, which
        // is clipped, spends its characters on the verdicts. A file outside the
        // root (an ancestor's CLAUDE.md, an out-of-checkout target) keeps the
        // full path, because there it IS the information.
        $prefixes = array_unique([rtrim($root, '/') . '/', rtrim((string) realpath($root), '/') . '/']);
        $named = [];
        foreach ($refused as $path => $why) {
            $path = (string) $path;
            foreach ($prefixes as $prefix) {
                if ($prefix !== '/' && str_starts_with($path, $prefix)) {
                    $path = substr($path, \strlen($prefix));
                    break;
                }
            }
            $named[$path] = $why;
        }

        return $named;
    }

    /**
     * The enabled skills whose bodies a prompt build defers for budget, keyed
     * by name — see {@see reportPromptBudgetDeferrals()}.
     *
     * Read only when `enabledSkills` is configured at all: with the key
     * absent no body is ever spliced, and the `-p` path would otherwise pay a
     * second skill scan to learn nothing. {@see promptEnabledSkills()} is the
     * backend's own resolution; its warnings are de-duplicated by the seam,
     * so a launch that already raised them raises nothing new here.
     *
     * @return array<string, string> skill name => why its body was deferred
     */
    private static function skillBodyDeferrals(?string $root, ?SkillRegistry $skills): array
    {
        if ($root === null || !\array_key_exists('enabledSkills', self::readUserConfig())) {
            return [];
        }

        try {
            $skills ??= self::skillRegistry($root);
            $deferred = [];
            foreach (\SugarCraft\Code\Runtime::planEnabledSkills(
                self::promptEnabledSkills($skills),
                \SugarCraft\Code\Context\CompactorConfig::new(),
            ) as $planned) {
                if ($planned['deferral'] !== null) {
                    $deferred[$planned['skill']->name] = $planned['deferral'];
                }
            }

            return $deferred;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * `subject (reason); subject (reason)` — the detail field of the two
     * deferral rows. Every entry, not a sample: the row is clipped at
     * {@see LAUNCH_NOTICE_MAX_CHARS} on the transcript and printed whole on
     * stderr, which is the split every other long launch row already takes.
     *
     * @param array<array-key, string> $entries subject => reason
     */
    private static function deferralList(array $entries): string
    {
        $items = [];
        foreach ($entries as $subject => $reason) {
            $items[] = $subject . ' (' . rtrim($reason, '.') . ')';
        }

        return implode('; ', $items);
    }

    /**
     * Tell the user, at launch, that `maxToolSteps` or `maxOutputTokens` holds
     * a value the resolvers will not use (audit 15d-16 residual R12).
     *
     * Both resolvers answer null for nonsense — a fraction of a step, zero, a
     * negative, a non-number, a value too large for an integer — and null is
     * the default's voice, so a typo silently became "no setting". That stays
     * the behaviour: neither resolver clamps, and nothing here changes what
     * they answer. What changes is that the operator hears about it, once,
     * before the first turn — the per-turn `EngineBackend` read of
     * `maxOutputTokens` has no channel that could say it.
     *
     * Unset is not nonsense: an absent key, JSON `null` and `""` (the shape a
     * set-but-empty value arrives in, which this lib reads as unset
     * everywhere) raise nothing. One row per bad key, so at most two.
     */
    public static function reportNonsenseLimits(): void
    {
        $config = self::readUserConfig();
        $verdicts = [
            self::MAX_TOOL_STEPS_CONFIG_KEY => [
                self::resolvedMaxToolSteps($config),
                self::translated(self::NONSENSE_STEPS_EXPECTED),
                self::translated(self::NONSENSE_STEPS_CONSEQUENCE),
            ],
            self::MAX_OUTPUT_TOKENS_CONFIG_KEY => [
                self::resolvedMaxOutputTokens($config),
                self::translated(self::NONSENSE_TOKENS_EXPECTED),
                self::translated(self::NONSENSE_TOKENS_CONSEQUENCE),
            ],
        ];

        $rows = [];
        foreach ($verdicts as $key => [$resolved, $expected, $consequence]) {
            if ($resolved === null && self::isNonsenseSetting($config, $key)) {
                $rows[] = sprintf(
                    self::translated(self::NONSENSE_LIMIT_NOTICE_FORMAT),
                    $key,
                    self::settingValueForNotice($config[$key]),
                    $expected,
                    $consequence,
                );
            }
        }

        // ISS-029: the top-level key audit rides the same rows — a misspelled
        // or refused setting is named at launch instead of left to the
        // tolerant reader's silence. User files only here: this notice fires
        // before any project root is in scope, and `doctor` covers the
        // project tier.
        self::warnLaunchRows([
            ...$rows,
            ...LayeredSettings::keyAuditRows(self::configDir()),
        ]);
    }

    /**
     * Tell the user when this TUI session's diagnostics are not going to the
     * home log (audit R16 follow-up, raised by w9-diag-usage).
     *
     * Since R16 the redirect never leaves `error_log()` on the tty: it walks
     * `~/.candy-code/logs/candy-code.log`, then a private temp-dir log, then
     * the null device. The two fallbacks are silent by construction — the
     * whole point is that nothing paints the frame — so the user who goes
     * looking in the documented home log finds nothing, or (on the null
     * device) finds that nothing was kept at all. One row says which.
     *
     * Nothing for a registration of null (the operator chose their own
     * destination, which is theirs to know) or of the home log itself.
     */
    public static function reportTuiErrorLogFallback(): void
    {
        if (LaunchContext::tuiErrorLog() === null) {
            return;
        }

        $home = HomeDirectory::owned();
        if ($home !== null && LaunchContext::tuiErrorLog() === rtrim($home, '/') . '/' . TuiErrorLog::RELATIVE_PATH) {
            return;
        }

        self::warnLaunchRows([self::discardsWhatIsWritten(LaunchContext::tuiErrorLog())
            ? sprintf(self::translated(self::TUI_ERROR_LOG_DISCARDED_NOTICE_FORMAT), '~/' . TuiErrorLog::RELATIVE_PATH)
            : sprintf(self::translated(self::TUI_ERROR_LOG_FALLBACK_NOTICE_FORMAT), '~/' . TuiErrorLog::RELATIVE_PATH, LaunchContext::tuiErrorLog())]);
    }

    /**
     * Whether an `error_log` destination keeps nothing — the null device
     * `TuiErrorLog::install()` falls back to last. Delegates to
     * {@see TuiErrorLog::isNullDevice()} so the spelling has one owner.
     */
    private static function discardsWhatIsWritten(string $destination): bool
    {
        return TuiErrorLog::isNullDevice($destination);
    }

    /**
     * Whether $key is SET to something — the precondition for calling its
     * value nonsense. See {@see reportNonsenseLimits()} for what counts as
     * unset.
     *
     * @param array<string, mixed> $config
     */
    private static function isNonsenseSetting(array $config, string $key): bool
    {
        $raw = $config[$key] ?? null;

        return $raw !== null && $raw !== '';
    }

    /**
     * A setting's value as the operator would recognise it from their own
     * file — its JSON spelling, so `"16x"` keeps its quotes and `1.0e+19`
     * reads as the number it was — clipped, because a hand-edited file can
     * hold a whole object under a numeric key.
     */
    private static function settingValueForNotice(mixed $raw): string
    {
        $json = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $json = $json === false ? get_debug_type($raw) : $json;

        return mb_strlen($json, 'UTF-8') > 60 ? mb_substr($json, 0, 59, 'UTF-8') . '…' : $json;
    }

    /**
     * The verdict {@see \SugarCraft\Code\Backend\EngineBackend}'s private
     * `maxOutputTokens()` resolver reaches, for {@see reportNonsenseLimits()}
     * only — that resolver runs per TURN, inside the engine, where no launch
     * channel exists, so the launch asks the same question here.
     *
     * A MIRROR, and a mirror can drift, so it is not trusted to stay one:
     * {@see \SugarCraft\Code\Tests\Cli\BootstrapLaunchNoticeRoutingTest::testTheTokenVerdictMirrorsTheEngineResolver()}
     * runs both over the same table of values, the engine's by reflection, and
     * reds on any disagreement. Accepted: a finite positive number below 2**63,
     * truncated toward zero — see the engine resolver for why.
     *
     * @param array<string, mixed> $config
     */
    private static function resolvedMaxOutputTokens(array $config): ?int
    {
        $raw = $config[self::MAX_OUTPUT_TOKENS_CONFIG_KEY] ?? null;

        if (is_string($raw)) {
            $raw = is_numeric($raw) ? $raw + 0 : null;
        }

        if (!is_int($raw) && !(is_float($raw) && is_finite($raw))) {
            return null;
        }

        if ($raw < 1 || (is_float($raw) && $raw >= (float) PHP_INT_MAX)) {
            return null;
        }

        return (int) $raw;
    }

    /**
     * Every `*.md` command file this process's command walks could not read,
     * keyed by path (E172).
     *
     * The seam that replaced silence for {@see \SugarCraft\Code\Commands\CommandLoader}'s
     * two per-FILE skips — a symlink leaving its directory and a file that
     * would not parse. Like {@see skillSkips()} this is the record a doctor
     * report or a debug pane can reach after the walk; unlike that map, whose
     * paths have no other reader while the debug gate stays off, every entry
     * here is ALSO counted on stderr verbatim behind
     * {@see \SugarCraft\Code\Commands\CommandLoader::DEBUG_REFUSALS_ENV} —
     * this accessor is the always-on answer to "how many", the gate is the
     * answer to "which".
     *
     * @return array<string, string> sourcePath => why it was skipped
     */
    public static function commandSkips(): array
    {
        return LaunchContext::commandSkips();
    }

    /**
     * Tell the user, once and in one line, that some command files were
     * skipped.
     *
     * THE COMMAND TWIN OF {@see reportSkillSkips()} (E172's drain half), and
     * it inherits that method's whole argument for existing: a `*.md` the
     * loader could not read is a `/command` the user typed and did not get,
     * ONE row regardless of how many — the per-entry flood the
     * {@see LaunchContext::commandSkips()} property names is exactly why this map was never
     * spread into {@see $projectTierRefusals}`'s one-line-per-entry reader —
     * and construction time only, before Program takes the terminal.
     *
     * PRIVATE unlike its skill twin, whose PUBLIC-ness was earned by two
     * extra construction paths that bypass {@see chat()}: the command loader
     * is built in exactly one place in `src/` (the {@see chat()} call this
     * method sits inside), so a second caller today would be a new seam to
     * report, not a route to reuse.
     */
    private static function reportCommandSkips(): void
    {
        // Only what has not been reported yet, keyed the same way
        // {@see reportSkillSkips()} keys its own guard: a process that builds
        // more than one Chat must not re-print, and a second walk that found
        // something NEW still says so.
        $new = array_diff_key(LaunchContext::commandSkips(), LaunchContext::reportedCommandSkips());
        if ($new === []) {
            return;
        }

        foreach (array_keys($new) as $path) {
            LaunchContext::markCommandSkipReported($path);
        }

        $count = \count($new);
        // BOTH CHANNELS — the aggregate's own safety argument is
        // reportSkillSkips()'s, and the refusal this row answers is the one
        // CommandLoader's property doc-block recorded in round 46: the count
        // belongs on the seam, the paths behind the debug gate.
        self::warnPermissionConfigInTranscript(sprintf(
            self::translated(self::COMMAND_SKIP_NOTICE_FORMAT),
            $count,
            $count === 1 ? '' : 's',
            $count === 1 ? 'was' : 'were',
            CommandLoader::DEBUG_REFUSALS_ENV,
            $count === 1 ? 'it' : 'them',
        ));
    }

    /**
     * Tell the user, at launch, which built-ins this launch's command files
     * replaced (ISS-036's drain half).
     *
     * THE OVERRIDE TWIN OF {@see reportCommandSkips()}, and it exists because
     * "a file replaced a command you thought you had" is the exact fact the
     * trust gate was built around — refusing the untrusted shadow loudly while
     * announcing the allowed one silently was half a story. The loader's
     * per-name sentences (built-in, tier, winning path) are the rows; ONE
     * aggregate row whatever the count, per {@see LAUNCH_NOTICE_LIMIT}'s
     * flood argument, and the seam's clip bound shapes the row the same way
     * it shapes the skip row's. The debug variable keeps its per-name lines,
     * which the seam no longer needs to duplicate; the seam gives the
     * transcript a row that stderr-only was hiding.
     *
     * PRIVATE like its skip twin: the loader is built in exactly one place in
     * `src/`. Construction time only, beside {@see reportCommandSkips()} and
     * for its reason.
     */
    private static function reportCommandOverrides(): void
    {
        // Only what has not been announced yet, keyed by command name —
        // {@see reportCommandSkips()}'s guard shape: a process that builds a
        // second Chat must not re-print the first's shadows, and a walk that
        // replaced something NEW still says so.
        $new = array_diff_key(LaunchContext::commandOverrides(), LaunchContext::reportedCommandOverrides());
        if ($new === []) {
            return;
        }

        foreach (array_keys($new) as $name) {
            LaunchContext::markCommandOverrideReported($name);
        }

        $count = \count($new);
        self::warnLaunchRows([sprintf(
            self::translated(self::COMMAND_OVERRIDE_NOTICE_FORMAT),
            $count,
            $count === 1 ? '' : 's',
            implode('; ', $new),
        )]);
    }

    /**
     * Tell the user, at launch, which frontmatter keys their agent presets,
     * skills, commands and rules declare that nothing acts on (X-37a).
     *
     * ONE ROW PER FORMAT, at most four, built by
     * {@see \SugarCraft\Code\Support\FrontmatterKeyAudit::notice()}: an
     * inert key (`context: fork`, a preset's `permissionMode:` under `Task`)
     * and an unknown one (a typo, with a did-you-mean) are grouped by label
     * with up to three file names each. A file is never refused for this — the row is how an author learns
     * that a key they wrote changes nothing. Only files not reported before in
     * this process, for {@see reportCommandSkips()}'s reason. Construction
     * time only, before Program takes the terminal.
     */
    private static function reportIgnoredFrontmatter(): void
    {
        $rows = [];
        foreach (LaunchContext::ignoredFrontmatter() as $format => $files) {
            $new = array_diff_key($files, LaunchContext::reportedIgnoredFrontmatterFor($format));
            foreach (array_keys($new) as $identity) {
                LaunchContext::markIgnoredFrontmatterReported($format, $identity);
            }

            $findings = [];
            foreach ($new as [$name, $labels]) {
                if ($labels !== []) {
                    $findings[$name] = [...($findings[$name] ?? []), ...$labels];
                }
            }

            $row = \SugarCraft\Code\Support\FrontmatterKeyAudit::notice($format, $findings);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        self::warnLaunchRows($rows);
    }

    /**
     * The launch's PreToolUse/PostToolUse chain: the built-in guards, then
     * whatever {@see hookFiles()} found on disk, then the permission gate.
     *
     * THE ORDER IS THE POINT. Built-ins first, config hooks second, the gate
     * LAST — a scan reports the FIRST refusal it meets
     * ({@see \SugarCraft\Code\Hooks\HookRegistry::executeHooks()} returns a
     * non-permitting result outright), so this puts the narrow, specific
     * hazard ("this touches a protected file", then the user's own rule) ahead
     * of the broad policy one ("mode plan does not allow Edit"). Loading the
     * config hooks BEFORE the gate also keeps the gate last on both
     * construction paths, since {@see EngineBackend}'s own
     * `resolveHookManager()` appends it to whatever manager it is handed.
     *
     * A config hook cannot use that position to WIN over a built-in DENY: a
     * pass stops at the first non-permitting result, and the built-ins are
     * ahead of it, so a config hook can only ever report on a call the guards
     * before it already permitted.
     *
     * What that same short-circuit costs, stated rather than implied: the gate
     * is LAST, so ANY earlier non-permitting result returns before
     * {@see PermissionGateHook::execute()} is reached and the refusal never
     * reaches {@see PermissionGate}'s Auto-mode 3-strike circuit breaker. That
     * is not a cost this wiring introduced and it is not about config hooks:
     * the three built-ins have always been ahead of the gate, so a
     * {@see \SugarCraft\Code\Hooks\BuiltIn\ConfirmRemoveHook} DENY — the
     * commonest refusal there is — was already uncounted before a hook FILE
     * could be loaded at all. Registering config hooks in between adds one
     * more source of the same uncounted refusal, not a new failure mode. It is
     * a MISCOUNT rather than a hole — the call is refused either way, and the
     * strike counter only escalates refusals it never saw — and closing it means
     * either running the gate on calls another hook already denied (giving a
     * denied call a second, weaker verdict) or teaching the registry to notify
     * skipped hooks. Both are larger than this wiring; the miscount is the
     * documented trade.
     *
     * @param PermissionGate|null $gate Install this gate as an additional
     *        PreToolUse layer, AFTER the built-ins — see {@see PermissionGateHook}
     *        for why that order. Pass the launch's single gate instance so the
     *        Auto-mode circuit breaker counts strikes once across every path
     *        that shares this manager; null keeps the built-ins-only chain
     *        every caller had before crush_code.md Phase 1 item 2.
     * @param string|null $root The project whose `.candy-code/hooks.yaml` and
     *        `.claude` settings files are read alongside the user's. Null
     *        reads only the user's files, which
     *        is what a caller with no root of its own to give should get
     *        rather than a hook file resolved against the process directory.
     *
     * Public for {@see NonInteractive::run()} (step 3.D-2), whose `SessionEnd`
     * chain is this launch's chain: a `-p` run's hooks live inside the engine
     * it built, so the chain is rebuilt here from the same once-per-launch
     * hook-file read rather than reached through the engine.
     *
     * @throws PermissionConfigException when a hook file is present and unusable
     */
    public static function hooks(?PermissionGate $gate = null, ?string $root = null): HookManager
    {
        // Script hooks spawn through ProcessContainment::scrubbedEnv(), so the
        // operator's credential allowlist must be in force before any runs.
        self::installSecretEnvAllowlist();
        $hooks = new HookManager(new HookRegistry());
        $hooks->registerBuiltIns(); // audit + confirm-rm + protect-files guards

        // Post-edit format (EDT-1): the user's `formatCommands` map rewrites
        // each edited file, and the launch's shared read ledger is told about
        // every KEPT rewrite so the formatter's change never reads as an
        // external edit. Registered AHEAD of the lint hook on purpose — the
        // linter then sees the bytes that actually stand on disk. Same
        // user-tier, ahead-of-files reasoning as the lint; unlike the lint it
        // registers ONLY when there is something to run: a command the map
        // names, or — C-TOOL-5.b — the armed `formatAuto` table. The trust
        // fold mirrors lspClient's lspAutoDetect read: the AUTO_TABLE rewrites
        // files with binaries a CLONE's extension picks, so it runs only where
        // the hooks-trust grant already says this root's code may run here.
        // Short-circuited on the key: with formatAuto off this stays the exact
        // pre-arm read, and a corrupt config costs nothing new here
        // ({@see projectHooksAreTrusted()} throws, readUserConfig() does not).
        $formatConfig = self::readUserConfig();
        $formatRunner = \SugarCraft\Code\Format\FormatRunner::new()->withCommands(
            $formatConfig[\SugarCraft\Code\Format\FormatRunner::SETTINGS_KEY] ?? null,
        );
        $formatAuto = ($formatConfig[\SugarCraft\Code\Format\FormatRunner::AUTO_SETTINGS_KEY] ?? false) === true;
        if ($formatAuto) {
            $formatRunner = $formatRunner->withAuto(true, $root !== null && self::projectHooksAreTrusted($root));
        }
        if ($formatRunner->commands() !== [] || $formatRunner->autoArmed()) {
            $hooks->register(new \SugarCraft\Code\Hooks\BuiltIn\PostEditFormatHook($formatRunner, self::readLedger($root)));
        }

        // Post-edit lint (step 3.E): `php -l` by default, plus the user's
        // `lintCommands` map. Read through readUserConfig(), whose project
        // tier is filtered to PROJECT_TIER_KEYS — a lint command is shell, so
        // only the user's own files may name one. Registered AHEAD of the
        // hook files so a file entry cannot take its event+name (the file
        // loader refuses a taken key), and it never refuses a call:
        // its report is a note on the edit's result.
        $hooks->register(new \SugarCraft\Code\Hooks\BuiltIn\PostEditLintHook(
            \SugarCraft\Code\Lint\LintRunner::new()->withCommands(
                self::readUserConfig()[\SugarCraft\Code\Lint\LintRunner::SETTINGS_KEY] ?? null,
            ),
        ));

        // Post-edit diagnostics (step 3.F): only when the user configured a
        // language server under `lsp` and one started — the same memoised
        // servers the `Lsp` tool asks. Registered here, ahead of the hook
        // files, for the lint hook's reasons: it needs configuration, and a
        // file entry must not be able to take its event+name. Never a
        // refusal; its report rides on the edit's result.
        $lsp = $root === null ? null : self::lspClient($root);
        if ($lsp !== null) {
            $hooks->register(new \SugarCraft\Code\Hooks\BuiltIn\PostEditDiagnosticsHook($lsp));
        }

        // Auto-commit, `edit` mode (step 3.G): every Write/Edit committed as
        // it lands. User tier only (the key is not project-settable) — a
        // checkout must not turn on commits into the operator's history. The
        // closure reads the checkpoint taken before the session's current
        // turn, which is what keeps the user's own earlier changes out of the
        // model's commit; it runs in whichever process the hook does.
        $userConfig = self::readUserConfig();
        $autoCommit = \SugarCraft\Code\Workspace\AutoCommitter::modeFrom($userConfig[\SugarCraft\Code\Workspace\AutoCommitter::SETTINGS_KEY] ?? null);
        if ($root !== null && $autoCommit === \SugarCraft\Code\Workspace\AutoCommitter::MODE_EDIT) {
            $hooks->register(new \SugarCraft\Code\Hooks\BuiltIn\AutoCommitHook(
                \SugarCraft\Code\Workspace\AutoCommitter::new($root)
                    ->withTrailer(\SugarCraft\Code\Workspace\AutoCommitter::trailerFor($userConfig['attribution'] ?? null)),
                static function (string $sessionId): ?array {
                    if ($sessionId === '') {
                        return null;
                    }
                    try {
                        $state = self::sessionStore(false)->listCheckpoints($sessionId, 1)[0]['state_data'] ?? null;
                    } catch (\Throwable) {
                        return null;
                    }
                    $workspace = \is_array($state) ? ($state[EnhancedSessionStore::CHECKPOINT_WORKSPACE_KEY] ?? null) : null;

                    return \is_array($workspace) ? $workspace : null;
                },
            ));
        }

        // Auto-test reflection (step 3.H): with `autoTest` on and a
        // `testCommand` set, a turn that edited a file runs the tests before
        // it ends and hands a failure back to the model (≤3 times). Both keys
        // are user tier only — the command is shell run with no tool call in
        // the path. The edit half notes Write/Edit on PostToolUse; the Stop
        // half runs the tests in the turn's process, beating through the
        // turn's idle ceiling (a run with no beat is kept inside it). Ahead of
        // the hook files, for the lint hook's reason.
        $testRunner = \SugarCraft\Code\Lint\TestRunner::new()
            ->withCommand($userConfig[\SugarCraft\Code\Lint\TestRunner::SETTINGS_KEY] ?? null)
            ->withinTurnIdleCeiling(EngineBackend::turnIdleTimeoutSeconds($userConfig));
        if ($root !== null && ($userConfig[\SugarCraft\Code\Lint\TestRunner::AUTO_TEST_SETTINGS_KEY] ?? false) === true && $testRunner->command() !== null) {
            $testEdits = \SugarCraft\Code\Hooks\BuiltIn\AutoTestEditHook::new();
            $hooks->register($testEdits);
            $hooks->register(new \SugarCraft\Code\Hooks\BuiltIn\AutoTestHook($testRunner, $testEdits));
        }

        // Shared-board notices (roadmap 4.5): a member of a parallel Task
        // batch hears of its peers' posts on its next tool result. On every
        // launch, because the chain is shared by every run of the session and
        // the hook itself tells a member's run from the rest (it is inert
        // outside a batch). Ahead of the hook files, for the lint hook's
        // reason: a file entry must not be able to take its event+name.
        $hooks->register(new \SugarCraft\Code\Hooks\BuiltIn\BoardNoticeHook());

        $quarantine = self::policyQuarantineActive();
        $userTierFiles = [self::trustedConfigDirPath() . '/hooks.yaml', self::userClaudeSettingsFile()];
        foreach (self::hookFiles($root) as $path) {
            try {
                $entries = self::quarantineNewHookEntries(
                    $path,
                    self::hookFileEntries($path),
                    $quarantine,
                    $userTierFiles,
                );

                $hooks->loadEntries($entries, $path);

                // ISS-040 (seam S1): a file entry may demand the unattended
                // answer the headless prompt would otherwise auto-allow under
                // bypass-permissions. Rostered here, beside the registration,
                // because the roster is the CLI prompt's contract and the
                // Hooks package must not reach into src/Cli. Disabled entries
                // never join the chain, so their opt-in is not honoured;
                // quarantined project entries were already filtered out of
                // $entries above, so an unseen-by-this-launch hook cannot
                // roster itself before the operator ever saw it.
                foreach ($entries as $entry) {
                    if (
                        ($entry['unattendedAsk'] ?? false) === true
                        && ($entry['disabled'] ?? false) !== true
                    ) {
                        HeadlessPermissionPrompt::requireUnattendedAnswer($entry['name']);
                    }
                }
            } catch (\InvalidArgumentException | \RuntimeException $e) {
                // Same refusal {@see permissionConfig()} makes, for the same
                // reason: this file is part of the launch's gating policy, so
                // "present but unusable" may not degrade into a shorter guard
                // chain nobody was told about. PermissionConfigException is
                // what `bin/candy-code`, {@see NonInteractive::run()} and
                // {@see \SugarCraft\Code\Background\BackgroundSessionRunner}
                // already turn into a clean exit-2 usage report.
                throw new PermissionConfigException(
                    Lang::t('cli.refuse.hook_chain', ['error' => $e->getMessage()]),
                    0,
                    $e,
                );
            }
        }

        if ($gate !== null) {
            $hooks->register(new PermissionGateHook($gate));
        }

        // Roadmap 4.6-2: the team task events (`TaskCreated`, `TaskCompleted`,
        // `TeammateIdle`) are raised by a team's TaskList, not by a tool
        // call, so they reach this chain through a dispatcher over the same
        // registry — handed to every TeamManager this process builds (the
        // `Team` tool opens one per call, in the forked turn child that
        // inherits this). Re-installed on each build, so a provider switch's
        // rebuilt chain is the one teams use.
        \SugarCraft\Code\Agents\TeamManager::useLaunchHooks($hooks->dispatcher(), $root);

        return $hooks;
    }

    /**
     * $path's hook entries, READ ONCE PER PROCESS.
     *
     * THE CHAIN A LAUNCH STARTS WITH IS THE CHAIN IT RUNS. A hook entry is a
     * shell command, and this method is reached on every hook-manager build —
     * the launch's two, plus one more for each Ctrl+P provider switch
     * ({@see \SugarCraft\Code\Chat::selectPaletteProvider()} =>
     * {@see backendFor()}). Re-reading the file on each of those meant the
     * SESSION ITSELF could install hooks into itself: `Bash` is deliberately
     * not path-jailed, so one `>> ~/.candy-code/hooks.yaml` followed by a
     * provider switch put attacker-authored shell in the guard chain
     * mid-session, with no relaunch and no prompt.
     * {@see \SugarCraft\Code\Hooks\BuiltIn\ProtectFilesHook} denies the write
     * itself, but its `command` half is best-effort by nature (see that
     * method), so the read side may not depend on the write side having caught
     * it. Freezing at first read is what makes the two independent.
     *
     * The cost is stated rather than implied: a hook file EDITED BY THE USER
     * mid-session no longer takes effect until the next launch. That is the
     * same "one config source for the whole launch" commitment
     * {@see agentManager()} already documents for the permission rules, and it
     * is the half of the trade that can be undone by pressing Ctrl+C.
     *
     * Keyed by path, so this does not entangle a project file with the user's,
     * and an absent file is memoised as `[]` — a file that appears mid-session
     * is exactly the case being closed.
     *
     * @return array<array{name: string, event: string, matcher: string, command: string, description: string, disabled: bool}>
     *
     * @throws PermissionConfigException when the user's own hook file is
     *         writable by other accounts, or belongs to one
     * @throws \RuntimeException|\InvalidArgumentException see {@see \SugarCraft\Code\Hooks\HookConfig::loadFromFile()}
     */
    private static function hookFileEntries(string $path): array
    {
        if (!LaunchContext::hasHookFileEntriesFor($path)) {
            // Only the USER's files. The project's are gated by
            // `trustedProjectHooks` and live in a checkout whose permissions
            // are the user's business; the user's files are the ones loaded
            // with no gate at all, on a premise that ownership is what makes
            // true — and the user's `.claude/settings.json` joins
            // `hooks.yaml` under that premise, since it now carries the same
            // class of payload (shell on a tool call).
            if ($path === self::trustedConfigDirPath() . '/hooks.yaml' || $path === self::userClaudeSettingsFile()) {
                self::requirePrivatePolicyFile($path);
            }

            LaunchContext::rememberHookFileEntries($path, ClaudeSettingsHooks::isClaudeSettingsPath($path)
                ? ClaudeSettingsHooks::fromFile($path, self::claudeSettingsScope($path))
                : HookConfig::loadFromFile($path));
        }

        return LaunchContext::hookFileEntriesFor($path);
    }

    /**
     * Which tier a CC settings path belongs to (C-EXT-1.b): the scope segment
     * of the generated `cc:{scope}:{event}:{index}` names. `~/.claude/
     * settings.json` — the one this launch loads ungated — is USER; under a
     * trusted project root, `settings.local.json` is LOCAL and the shared
     * settings file is PROJECT. Identity is by path, not by wish: the user
     * file reached through a project's `.claude` directory (HOME inside the
     * repo) keeps the USER tier, matching the dedup in {@see hookFiles()}.
     */
    private static function claudeSettingsScope(string $path): string
    {
        if ($path === self::userClaudeSettingsFile()) {
            return ClaudeSettingsHooks::SCOPE_USER;
        }

        return basename($path) === ClaudeSettingsHooks::LOCAL_FILE_NAME
            ? ClaudeSettingsHooks::SCOPE_LOCAL
            : ClaudeSettingsHooks::SCOPE_PROJECT;
    }

    /**
     * rv-sec-3 one-launch quarantine filter for one hook file's entries.
     *
     * While the policy snapshot says the user's config, hooks or
     * `.claude/settings.json` changed since the previous launch, PROJECT-tier entries that launch never saw do not
     * load this time. The user's own files — `hooks.yaml` and the
     * `.claude/settings.json` beside it — are never entry-filtered; the
     * launch notice IS the consent there; a repository's file is the one
     * that can change by nobody at this keyboard editing anything. Lives
     * beside {@see hookFileEntries()} rather than inline in `hooks()` so the
     * registration-order anchors SKILLS.md cites stay inside the excerpt
     * window the doc guard reads them from.
     *
     * @param list<array<string, mixed>> $entries
     * @param list<string> $userTierFiles
     * @return list<array<string, mixed>>
     */
    private static function quarantineNewHookEntries(
        string $path,
        array $entries,
        bool $quarantine,
        array $userTierFiles,
    ): array {
        if (!$quarantine || \in_array($path, $userTierFiles, true)) {
            return $entries;
        }

        $seenBefore = LaunchContext::policyPreviousProjectHooks()[\dirname(\dirname($path))] ?? [];

        return array_values(array_filter(
            $entries,
            static fn (array $e): bool => \in_array(
                $e['name'] . '|' . $e['event'] . '|' . $e['command'],
                $seenBefore,
                true,
            ),
        ));
    }

    /**
     * Refuse a policy file that is not exclusively this account's.
     *
     * The gate on the project hook file answers "did the user trust this
     * repository". This answers the question underneath it — "is the file that
     * records the answer actually the user's" — which nothing checked, and
     * which the whole `~/.candy-code` premise rests on. A `config.json` owned
     * by another uid, or sitting in a world-writable directory, is somebody
     * else's `permissionMode`, `permissionRules` and `trustedProjectHooks`.
     *
     * WORLD-WRITABLE, NOT GROUP-WRITABLE. Debian/Ubuntu ship umask 002 with
     * user-private groups — every file this CLI writes on such a box is
     * group-writable to a group with exactly one member — so refusing on the
     * group bit would be a launch failure for the default configuration of a
     * mainstream distribution rather than a finding about anything. The `o+w`
     * bit has no such benign reading, and it is the bit `/tmp`-style planted
     * directories carry.
     *
     * OWNERSHIP is checked where `ext-posix` allows: it is the half that
     * catches a file another account planted with sane permissions. It does
     * NOT catch the session writing its own home — same uid — which is what
     * {@see hookFileEntries()} and {@see trustedRootsForThisProcess()} freeze
     * against instead.
     *
     * @throws PermissionConfigException
     */
    private static function requirePrivatePolicyFile(string $path): void
    {
        foreach ([$path, \dirname($path)] as $target) {
            if (!file_exists($target)) {
                continue;
            }

            clearstatcache(true, $target);

            $mode = @fileperms($target);
            if ($mode !== false && ($mode & 0002) !== 0) {
                throw new PermissionConfigException(
                    Lang::t('cli.refuse.world_writable', ['path' => $target]),
                );
            }

            if (!\function_exists('posix_geteuid')) {
                continue;
            }

            $owner = @fileowner($target);
            if ($owner !== false && $owner !== posix_geteuid()) {
                throw new PermissionConfigException(
                    Lang::t('cli.refuse.foreign_owner', ['path' => $target, 'uid' => $owner]),
                );
            }
        }
    }

    /**
     * The hook files this launch reads, lowest-priority first: the user's
     * `~/.candy-code/hooks.yaml` always, the user's `~/.claude/settings.json`
     * always (its `hooks` block only — INT-4), the project's
     * `{root}/.candy-code/hooks.yaml`, and the project's
     * `{root}/.claude/settings.json` plus `settings.local.json` ONLY when the
     * user has said they trust that project.
     *
     * THE PROJECT FILE IS ARBITRARY CODE EXECUTION FROM CLONED CONTENT, and
     * that is the whole reason for the gate. A hook entry is a shell command;
     * a `matcher: '.*'` entry runs it on the model's first tool call. So
     * `git clone <untrusted> && cd <it> && candy-code` would run a stranger's
     * shell with no prompt and nothing in the transcript — and no permission
     * mode saves the user from it, because the config hooks are registered
     * BEFORE {@see PermissionGateHook} and a scan stops at the first refusal,
     * so the payload has already run by the time the gate would have refused.
     * `--permission-mode plan` was measured as `verdict=allow, attacker shell
     * ran: YES`. Nothing else in this codebase runs project-authored code:
     * {@see agentPresets()} lets a project WEAKEN a sub-agent's policy, which
     * is influence over policy, not execution.
     *
     * THE OPT-IN. `~/.candy-code/config.json` — the user's own file, which no
     * repository can write BY BEING CLONED — carries a `trustedProjectHooks`
     * list of project roots:
     *
     * ```json
     * { "trustedProjectHooks": ["/home/you/work/my-repo", "~/src/other"] }
     * ```
     *
     * Per-path rather than one global `true` because "I trust this one repo"
     * is the real need, and a global flag re-opens the hole in every other
     * checkout the moment it is set. Entries are compared by `realpath()`, so
     * a symlinked or trailing-slash spelling of a trusted root still matches
     * and a path that does not resolve simply never matches.
     *
     * WHAT "THE USER'S OWN FILE" CAN AND CANNOT MEAN. A clone cannot write it,
     * but the SESSION runs as the user: `Bash` is not path-jailed, and a
     * cloned README or `CLAUDE.md` prompting the model into appending one line
     * to `trustedProjectHooks` is the same threat class as the hook file
     * itself. Two things keep the grant the user's:
     * {@see \SugarCraft\Code\Hooks\BuiltIn\ProtectFilesHook} denies the tool
     * call, and {@see trustedRootsForThisProcess()} freezes the list for the
     * process so a write that got past it cannot take effect in the session
     * that made it. Neither can make a NEXT launch safe — see that method for
     * the boundary, stated rather than implied.
     *
     * THE GATE, NOT THE FEATURE, IS WHAT IS CONDITIONAL. The project-file code
     * path is intact and reachable; only the decision to walk it is gated.
     * That is deliberate room for the better answer: a per-directory trust
     * PROMPT (print the hooks, require an explicit yes, record the decision
     * keyed by real path + content hash, re-prompt when the hash changes)
     * belongs here as a SECOND way to satisfy {@see projectHooksAreTrusted()},
     * beside the config key rather than instead of it — a prompt needs a
     * terminal, and this method also runs on the non-interactive and
     * background-session paths where there is nobody to ask.
     *
     * The gate is also what has to stand between a lifecycle hook and
     * execution. {@see \SugarCraft\Code\Hooks\HookConfig::parse()} accepts
     * every {@see \SugarCraft\Code\Hooks\HookEvent} case, and those events
     * are live: `SessionStart` runs through {@see \SugarCraft\Code\Hooks\HookManager}
     * at launch, and {@see hooks()} installs the
     * {@see \SugarCraft\Code\Hooks\HookDispatcher} that
     * {@see \SugarCraft\Code\Hooks\HookManager::dispatcher()} builds for the
     * team events (`TaskCreated`, `TaskCompleted`, `TeammateIdle`). So a hook
     * file's payload runs at launch or on a team event, with no tool call in
     * the path — "the gate refused to load it" is the property that has to
     * hold.
     *
     * PATH SHAPE COPIED FROM {@see agentPresets()} — the project's
     * `.candy-code/<thing>` beside the user's, resolved off
     * {@see configDirPath()} rather than {@see configDir()} so reading the
     * hook chain is not what creates `~/.candy-code`. YAML because
     * {@see \SugarCraft\Code\Hooks\HookConfig} parses YAML (symfony/yaml is a
     * hard dependency of this package), so the extension names what the file
     * actually is — and the Claude Code settings pair is JSON because that is
     * what that ecosystem writes, read by
     * {@see \SugarCraft\Code\Hooks\ClaudeSettingsHooks}, whose flattened rows
     * go through the SAME {@see \SugarCraft\Code\Hooks\HookConfig::parse()}
     * validation as a native file. The `hooks` key of those files is the
     * whole of what is read; `permissions`, `env` and `model` stay the
     * layered settings authority's, so this file pair cannot fork that
     * authority into a second loader.
     *
     * WHEN BOTH ARE LOADED, neither overrides the other, and a name collision
     * between them is refused by {@see HookManager::loadFromFile()}. That is
     * deliberately NOT the "later source overrides earlier" precedence
     * {@see \SugarCraft\Code\Skills\SkillLoader::loadAll()} uses for skills,
     * because a hook chain is not a lookup table: overriding by name would
     * mean a checked-in project file could disarm a guard the user wrote for
     * themselves — by naming it — on the first repo they cloned, and the
     * reverse ordering would let a personal file disarm the project's. Adding
     * is the only thing a second file is allowed to do.
     *
     * @return list<string> paths that may or may not exist, de-duplicated by
     *         real path; an absent file is a no-op, see
     *         {@see \SugarCraft\Code\Hooks\HookConfig::loadFromFile()}
     *
     * @throws PermissionConfigException when a path cannot be reached, so
     *         whether a hook file is configured there is unknowable
     */
    private static function hookFiles(?string $root): array
    {
        // trustedConfigDirPath(), NOT configDirPath(): the user file is loaded
        // with no trust gate at all on the grounds that the user wrote it, and
        // that premise only holds in a directory only the user can write.
        $paths = [self::trustedConfigDirPath() . '/hooks.yaml', self::userClaudeSettingsFile()];

        if ($root !== null) {
            // The repository's hook file, not the launch subdirectory's (audit
            // 15d-13 (b)); trust is asked of the same root it is read from.
            $root = ProjectRoot::resolve($root);

            // CANONICAL, not as spelled. The trust decision below is made on
            // `realpath($root)`, so naming the file off the raw string would
            // leave the loaded path dependent on the process directory for a
            // decision that was not — and an in-process `chdir()` would then
            // re-point a path the launch had already vetted.
            $canonicalRoot = realpath($root);
            $baseDir = rtrim($canonicalRoot !== false ? $canonicalRoot : $root, '/');
            $projectFiles = array_merge(
                [$baseDir . '/.candy-code/hooks.yaml'],
                array_map(
                    static fn (string $name): string => $baseDir . '/.claude/' . $name,
                    ClaudeSettingsHooks::FILENAMES,
                ),
            );

            if ($canonicalRoot !== false && self::projectHooksAreTrusted($canonicalRoot)) {
                foreach ($projectFiles as $projectFile) {
                    $paths[] = $projectFile;
                }
            } else {
                foreach ($projectFiles as $projectFile) {
                    if (!is_file($projectFile)) {
                        continue;
                    }

                    // NOT a silent drop. Ignoring a file the repo's author expects
                    // to run changes what the session does, so it is reported the
                    // same way a skipped permission rule is — BOTH CHANNELS, see
                    // {@see warnPermissionConfigInTranscript()}. This runs during
                    // construction, before Program takes the terminal, so the
                    // stderr copy lands ahead of the alt screen rather than inside
                    // a frame — but "ahead of the alt screen" is exactly where the
                    // alt screen then paints OVER it, which is why the transcript
                    // copy exists as well.
                    //
                    // ONCE PER PATH PER PROCESS. {@see chat()} builds two hook
                    // managers (its own chain and the engine backend's), so an
                    // untrusted project printed this twice on every interactive
                    // launch — and a notice a user meets twice a run for doing
                    // nothing wrong is a notice they learn to scroll past.
                    //
                    // The advice names the CANONICAL path, which is the whole
                    // point of printing it: `--root .` used to print `Add "." to
                    // trustedProjectHooks`, and a literal `"."` entry is
                    // realpath()'d against the CWD on every launch exactly as the
                    // root is, so it always agrees — following the tool's own
                    // instruction turned a per-path allowlist into "trust every
                    // repository I cd into". {@see trustedProjectHookRoots()}
                    // refuses such an entry now; this makes sure the tool never
                    // suggests one.
                    if (!LaunchContext::hasReportedUntrustedHookFile($projectFile)) {
                        LaunchContext::markUntrustedHookFileReported($projectFile);

                        self::warnPermissionConfigInTranscript(Lang::t('cli.warn.hook_file_untrusted', [
                            'file' => $projectFile,
                            'root' => $baseDir,
                            'key' => self::TRUSTED_PROJECT_HOOKS_CONFIG_KEY,
                            'config' => self::userConfigPath(),
                        ]));
                    }
                }
            }
        }

        foreach ($paths as $path) {
            if (is_file($path)) {
                continue;
            }

            // The same ambiguity {@see permissionConfig()} draws the line on:
            // `is_file()` answers false both for "there is no hook file" and
            // for "a directory on the way to it cannot be searched", and only
            // the first is "nothing was configured". Reading the second as
            // absence would run the session with a guard chain shorter than
            // the configured one and say nothing.
            $unreachable = self::unreachableAncestor($path);
            if ($unreachable !== null) {
                throw new PermissionConfigException(
                    Lang::t('cli.refuse.hooks_unreachable', ['path' => $path, 'reason' => $unreachable]),
                );
            }
        }

        // DE-DUPLICATED BY REAL PATH, because the two candidates are not always
        // two files. Run `candy-code` in your own home directory — or with
        // `--root .` from it, or through a symlinked/trailing-slash alias of it
        // — and both entries name `~/.candy-code/hooks.yaml`. Loading it twice
        // hits {@see HookManager::loadFromFile()}'s already-registered guard and
        // kills the launch with exit 2 over a collision that does not exist,
        // and it does so only for users who actually wrote hooks.
        $unique = [];
        foreach ($paths as $path) {
            // `?: $path` keeps a not-yet-existing file (the fresh-install case,
            // where realpath() answers false) as its own entry rather than
            // collapsing every absent candidate onto one key.
            $unique[realpath($path) ?: $path] ??= $path;
        }

        return array_values($unique);
    }

    /**
     * The user's own `~/.claude/settings.json` — the home directory resolved
     * one level up from {@see trustedConfigDirPath()}, deliberately off that
     * method rather than `HomeDirectory::owned()` directly, so the two hook
     * tiers can never disagree about what "my home" means and an
     * undeterminable home raises the same `cli.refuse.no_home` refusal
     * before any path is built. Only `hooks.yaml`'s sibling `settings.json`
     * is read at user tier: `settings.local.json` is a per-checkout
     * override in Claude Code's own layering, and the project tier already
     * reads both spellings behind `trustedProjectHooks`.
     */
    private static function userClaudeSettingsFile(): string
    {
        return \dirname(self::trustedConfigDirPath()) . '/.claude/settings.json';
    }

    /**
     * Whether the user has opted this project root's `.candy-code/hooks.yaml`
     * in — see {@see hookFiles()} for the threat this answers and for the trust
     * PROMPT that belongs beside this check rather than instead of it.
     *
     * Fails closed on every uncertainty: an unresolvable root, an absent key, a
     * key of the wrong shape. The one thing it does not degrade quietly on is a
     * `config.json` that exists and cannot be parsed — {@see permissionConfig()}
     * stops the launch there, and this is the same file and the same class of
     * decision.
     *
     * @throws PermissionConfigException when the user config exists and is unusable
     */
    private static function projectHooksAreTrusted(string $root): bool
    {
        $canonical = realpath($root);
        if ($canonical === false) {
            return false;
        }

        return in_array($canonical, self::trustedRootsForThisProcess(), true);
    }

    /**
     * The trusted roots for this launch, RESOLVED ONCE AND NOT AGAIN.
     *
     * THE GRANT MAY NOT BE MADE BY THE THING IT GATES. `trustedProjectHooks`
     * lives in `~/.candy-code/config.json`; the shipped default permission
     * mode is {@see DEFAULT_PERMISSION_MODE}; and `Bash` is deliberately not
     * path-jailed ({@see \SugarCraft\Code\Tools\BuiltIn\Bash}). This method
     * used to re-read the file on every hook-manager build, so a repository
     * whose README prompt-injected the model into appending one line to that
     * list — then any Ctrl+P provider switch — had its own `.candy-code/hooks.yaml`
     * shell running mid-session, no relaunch and no prompt. That is precisely
     * the cloned-content threat {@see hookFiles()} describes, arriving through
     * the allowlist instead of around it.
     *
     * Freezing at first read is what breaks the loop: within a session the
     * answer to "which repositories did the user trust" is whatever it was
     * when the session started, so a write made DURING the session — however
     * it got past {@see \SugarCraft\Code\Hooks\BuiltIn\ProtectFilesHook} —
     * cannot take effect in that session. What it does not, and cannot, do is
     * make a NEXT launch safe: a session that can run arbitrary shell as this
     * user can leave anything behind in this user's home. The property claimed
     * here is narrower and is the one the gate needs to be worth having — the
     * trust decision is the user's, and is made before the untrusted content
     * is running, not by it and not while it runs.
     *
     * Keyed by the config path so a process that legitimately moves `HOME`
     * (the test suite) still reads each home once, rather than one home ever.
     *
     * @return list<string>
     *
     * @throws PermissionConfigException see {@see permissionConfig()}
     */
    private static function trustedRootsForThisProcess(): array
    {
        $path = self::trustedConfigDirPath() . '/config.json';

        if (!LaunchContext::hasTrustedRootsFor($path)) {
            $roots = self::trustedProjectHookRoots(self::permissionConfig());

            // rv-sec-3: during a quarantine launch, a root that only APPEARED
            // in trustedProjectHooks after the last snapshot is not trusted
            // yet either — adding a line to the user's config naming a fresh
            // clone is the same "first sight of a repository" event as the
            // clone shipping its own hooks.yaml. The launch after, honoured.
            if (self::policyQuarantineActive() && LaunchContext::policyPreviousTrustedRoots() !== []) {
                $roots = array_values(array_filter(
                    $roots,
                    static fn (string $root): bool => \in_array($root, LaunchContext::policyPreviousTrustedRoots(), true),
                ));
            }

            LaunchContext::rememberTrustedRoots($path, $roots);
        }

        return LaunchContext::trustedRootsFor($path);
    }

    /**
     * The project roots listed under `trustedProjectHooks`, canonicalised —
     * {@see trustedProjectRoots()} with this class's HOOK key. Kept as a named
     * method of its own arity because it is what {@see hookFiles()}'s trust chain
     * reads and what two tests invoke by reflection to assert the parsing rules
     * below.
     *
     * Item-wise tolerance copied from {@see permissionRules()}: one malformed
     * entry is skipped and reported rather than silently widening or narrowing
     * the whole list. An entry that does not RESOLVE is dropped without a
     * warning, though — it can never match anything, and the launch already
     * prints the far more useful "this project's hook file was not loaded"
     * line when that was what the user meant to opt in.
     *
     * REPORTED ONCE PER PROCESS, through {@see warnPermissionConfigOnce()} and
     * for the same reason the sibling notice in {@see hookFiles()} is latched:
     * a hook manager is built at launch and again on every Ctrl+P provider
     * switch, and by the second one the alt screen is up, so the line lands in
     * a frame the renderer believes it owns. It fires on exactly the upgrade
     * path this diff created — a user who followed the tool's older advice and
     * wrote `"."` is the one who now gets a warning — so it is the last
     * message that should be repainting somebody's transcript.
     *
     * @param array<string, mixed> $config the already-read user config
     * @return list<string>
     */
    private static function trustedProjectHookRoots(array $config): array
    {
        return self::trustedProjectRoots(
            $config,
            self::TRUSTED_PROJECT_HOOKS_CONFIG_KEY,
            Lang::t('cli.warn.untrusted_hooks'),
        );
    }

    /**
     * Whether the user has opted this project root's `.mcp.json` in — the
     * {@see projectHooksAreTrusted()} of {@see TRUSTED_PROJECT_MCP_CONFIG_KEY},
     * with the same fail-closed-on-every-uncertainty behaviour and the same
     * once-per-process freeze. See {@see mcpClient()} for why starting a server
     * needs the gate.
     *
     * @throws PermissionConfigException when the user config exists and is unusable
     */
    private static function projectMcpIsTrusted(string $root): bool
    {
        $canonical = realpath($root);
        if ($canonical === false) {
            return false;
        }

        $path = self::trustedConfigDirPath() . '/config.json';

        if (!LaunchContext::hasTrustedMcpRootsFor($path)) {
            LaunchContext::rememberTrustedMcpRoots($path, self::trustedProjectRoots(
                self::permissionConfig(),
                self::TRUSTED_PROJECT_MCP_CONFIG_KEY,
                Lang::t('cli.warn.untrusted_mcp'),
            ));
        }

        return in_array($canonical, LaunchContext::trustedMcpRootsFor($path), true);
    }

    /**
     * Whether the operator has opted this project root in to running the
     * `` !`cmd` `` form of a command file under
     * `<root>/.candy-code/commands` — {@see projectMcpIsTrusted()}'s shape for
     * {@see TRUSTED_PROJECT_COMMANDS_CONFIG_KEY}, with the same
     * fail-closed-on-every-uncertainty behaviour and the same once-per-process
     * freeze.
     *
     * PUBLIC, unlike its two siblings, because the consumer is not in this
     * class: the check happens when a `/name` is submitted, inside
     * {@see \SugarCraft\Code\Chat}, and {@see chat()} passes the ANSWER (a
     * bool) rather than the ability to ask. Handing Chat this method instead
     * would let it re-ask mid-session, which is precisely what the freeze exists
     * to prevent.
     *
     * @throws PermissionConfigException when the user config exists and is unusable
     */
    public static function projectCommandShellIsTrusted(string $root): bool
    {
        $canonical = realpath($root);
        if ($canonical === false) {
            return false;
        }

        $path = self::trustedConfigDirPath() . '/config.json';

        if (!LaunchContext::hasTrustedCommandRootsFor($path)) {
            LaunchContext::rememberTrustedCommandRoots($path, self::trustedProjectRoots(
                self::permissionConfig(),
                self::TRUSTED_PROJECT_COMMANDS_CONFIG_KEY,
                Lang::t('cli.warn.untrusted_commands'),
            ));
        }

        return in_array($canonical, LaunchContext::trustedCommandRootsFor($path), true);
    }

    /**
     * The project roots listed under $key in the user config, canonicalised.
     *
     * ONE PARSER FOR ALL FOUR TRUST KEYS, parameterised by the key name rather
     * than copied. This said "BOTH … `trustedProjectHooks` and
     * `trustedProjectMcp`" while there were already four callers, which is the
     * kind of count that rots silently — so it is written as the list, and the
     * list is asserted by
     * {@see \SugarCraft\Code\Tests\Config\TrustKeyDocumentationDriftTest}:
     *
     *  - {@see TRUSTED_PROJECT_HOOKS_CONFIG_KEY} via {@see projectHooksAreTrusted()}
     *  - {@see TRUSTED_PROJECT_MCP_CONFIG_KEY} via {@see projectMcpIsTrusted()}
     *  - {@see TRUSTED_PROJECT_COMMANDS_CONFIG_KEY} via {@see projectCommandShellIsTrusted()}
     *  - {@see \SugarCraft\Code\Config\LayeredSettings::PROJECT_SETTINGS_TRUST_KEY}
     *    via {@see projectSettingsTrusted()}
     *
     * They are four separate GRANTS ({@see TRUSTED_PROJECT_MCP_CONFIG_KEY} says
     * why they may not be one) but the same DATA SHAPE, and every rule below —
     * the relative-entry refusal, the `~` expansion, the item-wise tolerance,
     * the once-per-process warning — is a property of "a list of project roots
     * in the user's config", not of what the list authorises. A second copy
     * would be a second place for the `"."`-is-a-global-bypass refusal to be
     * forgotten.
     *
     * The four do NOT share caching or failure behaviour, only parsing: each
     * caller memoises into its own static, and {@see projectSettingsTrusted()}
     * alone swallows the {@see trustedConfigDirPath()} throw instead of
     * propagating it. See `PERMISSIONS.md` for that asymmetry.
     *
     * @param array<string, mixed> $config the already-read user config
     * @param string $key which trust list to read
     * @param string $nothingTrusted what a wrong-shaped value costs, for the
     *        warning — the only sentence that differs between the four keys
     * @return list<string>
     */
    private static function trustedProjectRoots(array $config, string $key, string $nothingTrusted): array
    {
        $raw = $config[$key] ?? null;
        if ($raw === null) {
            return [];
        }

        if (!is_array($raw)) {
            self::warnPermissionConfigOnce(
                Lang::t('cli.warn.trust_list_not_list', ['key' => $key, 'consequence' => $nothingTrusted]),
            );

            return [];
        }

        $roots = [];
        foreach ($raw as $index => $entry) {
            if (!is_string($entry) || trim($entry) === '') {
                self::warnPermissionConfigOnce(
                    Lang::t('cli.warn.trust_entry_not_path', ['key' => $key, 'index' => $index]),
                );
                continue;
            }

            $entry = trim($entry);
            $expanded = str_starts_with($entry, '~/') || $entry === '~'
                ? self::homePath() . substr($entry, 1)
                : $entry;

            // A RELATIVE ENTRY IS A GLOBAL BYPASS, not a narrow trust. This
            // list is realpath()'d fresh on every launch and so is the root it
            // is compared against, so `"."` resolves to whatever directory the
            // process was started in and therefore ALWAYS matches — one entry
            // that trusts every repository the user ever cd's into. `"../x"`
            // and `"src/repo"` are the same defect wearing a longer name.
            //
            // REFUSED rather than resolved-once-at-parse-time, because there
            // is nothing stable to resolve it against: this file is per-USER
            // and read on every launch, while the only thing a relative path
            // could be anchored to — the CWD — is per-INVOCATION. Any
            // anchoring choice would make "which repo did I trust?" depend on
            // where the user happened to be standing when they last edited
            // the config, which is the ambiguity, not a fix for it. And it is
            // refused LOUDLY: a silently dropped entry leaves the user
            // believing they opted in.
            if (!self::isAbsolutePath($expanded)) {
                self::warnPermissionConfigOnce(
                    Lang::t('cli.warn.trust_entry_relative', ['key' => $key, 'index' => $index, 'entry' => $entry]),
                );
                continue;
            }

            $canonical = realpath($expanded);
            if ($canonical !== false) {
                $roots[] = $canonical;
            }
        }

        return $roots;
    }

    /**
     * Whether $path names a location independently of the process directory.
     *
     * Windows spellings are recognised as well as POSIX ones because
     * {@see resolvedHomePath()} reads `$USERPROFILE`, so a `~`-rooted entry can
     * legitimately expand to `C:\Users\you\...` on the one platform where a
     * drive letter — not a leading slash — is what makes a path absolute.
     */
    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    /**
     * The launch's {@see PermissionGate} — crush_code.md Phase 1 item 2's
     * "constructed from the same config source as sub-agents".
     *
     * Same vocabulary the sub-agent path already speaks: the kebab-case
     * {@see PermissionMode} values an agent preset's `permissionMode:`
     * frontmatter uses (see {@see \SugarCraft\Code\Agents\AgentPresetRegistry}),
     * so `plan` means the same thing whether it is written in an agent preset
     * or in ~/.candy-code/config.json. Precedence: the env var is the
     * per-invocation override and wins over the persisted key.
     *
     * NOTHING HERE MAY FAIL OPEN. Every other setting this class reads
     * degrades quietly on a bad value because the cost of guessing wrong is a
     * default theme; here the fallback is the most PERMISSIVE mode, so the
     * same tolerance turns "I configured `plan` with a deny rule" plus one
     * stray comma into an ungated session the user has no way to notice. So
     * this method draws a hard line between ABSENCE and a PRESENT-BUT-UNUSABLE
     * input:
     *
     * - Absent (no config file, no `permissionMode`, no env var) => the
     *   documented default below. Nothing was configured, nothing is being
     *   overridden, and a fresh install must start.
     * - Present but unusable => {@see PermissionConfigException}, which
     *   `bin/candy-code` reports as an exit-2 usage error. That covers a
     *   config file that exists and cannot be read or parsed, and a
     *   `permissionMode` — from either source — naming no real mode
     *   (`paln`, `Plan`, `deny-all`).
     *
     * Hard-failing rather than warning-and-continuing on the corrupt-file case
     * is deliberate, and the asymmetry with a typo'd env value is only in the
     * MESSAGE, not the severity: a broken config file is precisely the input
     * whose intent cannot be recovered. `readUserConfig()` hands back `[]` for
     * it, so `permissionMode` and `permissionRules` both vanish at once and
     * there is no way to tell a file that only ever set a theme from one that
     * set a deny-everything policy. Continuing permissively is the reported
     * fail-open; continuing restrictively silently ignores what the file
     * actually said; refusing to start is the only outcome that is neither,
     * costs a one-line fix, and cannot be missed. The same file being
     * unreadable already silently drops the user's persisted provider and
     * theme, so this makes an existing quiet breakage loud rather than
     * inventing a new failure.
     *
     * THE DEFAULT DEPENDS ON THE PATH (decision D5), because what an ASK can
     * reach depends on it:
     *
     * - THE TUI starts in {@see PermissionMode::Default}
     *   ({@see INTERACTIVE_DEFAULT_PERMISSION_MODE}, `$interactive = true`,
     *   passed only by {@see chat()}). Every ASK there is put up as the y/n/a
     *   modal: Chat's own tool path always could, and since roadmap 1.C-2 an
     *   engine turn's forked child asks over its two-way frame channel too
     *   ({@see EngineBackend}'s `completeInteractive()`), with workspace
     *   checkpoints (3.A) behind `/rewind` for what was allowed. Those two
     *   were the conditions crush_report Part V set for moving the
     *   interactive default off `bypass-permissions`, and both have landed.
     *   A `Task` run in a PARALLEL batch asks too since roadmap 1.C-5: its
     *   grandchild relays the question to the turn child over
     *   {@see \SugarCraft\Code\Support\PermissionAskRelay}, which puts it to
     *   the same modal. Only a member the relay could not be opened for is
     *   still refused, with a reason the model reads
     *   (`ChildChannel::GRANDCHILD_REFUSAL`).
     * - `-p` AND THE BACKGROUND DAEMON keep {@see DEFAULT_PERMISSION_MODE},
     *   `bypass-permissions`. Their approver is
     *   {@see HeadlessPermissionPrompt}, which asks on stderr at a terminal
     *   and REFUSES without one — so an asking default would turn every
     *   unattended CI run's first `Edit` into a refusal on upgrade. They opt
     *   in to asking with `--permission-mode` or the config key, like before.
     *
     * Be honest about what the console default costs — the 2026-10-06 bypass
     * ruling flipped the old claim inside out: BypassPermissions is NOT equal
     * to having no gate; it is NARROWER than one. A floor still refuses under
     * bypass: the step-0 `rm -rf /` circuit breaker and every explicit `Deny`
     * rule are mode-independent inside the gate, the policy-self-grant rows of
     * {@see \SugarCraft\Code\Hooks\BuiltIn\ProtectFilesHook} still refuse so
     * the model cannot rewrite the files that set the mode, and the guards
     * mounted outside the gate — the opt-in worktree containment of
     * {@see \SugarCraft\Code\Hooks\BuiltIn\BashEscapeDenyHook} (when a jail
     * root is configured) and the per-turn
     * {@see \SugarCraft\Code\Hooks\BuiltIn\RepeatCallGuardHook} loop guard —
     * never read the mode at all. A bare chain with no gate enforces MORE than
     * bypass: it adds {@see \SugarCraft\Code\Hooks\BuiltIn\ConfirmRemoveHook}
     * refusing destructive `rm`s earlier and more broadly than the breaker, and
     * the secret/file-class refusals of ProtectFiles, which bypass stands down.
     * What the gate buys either way is that all of it is REACHABLE and
     * configurable: set `permissionMode`/`permissionRules` and it starts
     * deciding things.
     *
     * Either default is reported with the source `the built-in default`; a
     * configured mode (flag, env var, config key) wins on every path alike.
     *
     * Rules come from a `permissionRules` array of
     * `{"pattern": "Bash*", "action": "deny"}` objects. Unlike the mode, a
     * malformed entry is skipped individually rather than stopping the launch:
     * the list is item-wise, so one bad entry does not make the others
     * unreadable the way a JSON syntax error makes the whole file unreadable.
     * A skipped entry is reported on stderr, because a silently dropped `deny`
     * rule widens permission — and an entry whose `action` is not a real
     * {@see PermissionAction} is DROPPED, never coerced to allow.
     *
     * @param bool $interactive true for the TUI launch, whose default mode is
     *   {@see INTERACTIVE_DEFAULT_PERMISSION_MODE}; every console path leaves it
     *   false
     * @param bool $announceBypassMode false for READ-ONLY PROBES — `doctor`'s
     *   permission-policy row resolves the gate to DIAGNOSE a config, never to
     *   begin a session, and the bypass transcript row is a statement about the
     *   session about to run ("every tool call runs without a prompt"). A
     *   read-only diagnosis emitting it would advertise a launch that is not
     *   happening; real launch paths leave the flag at its default
     *
     * @throws PermissionConfigException when a present permission input cannot be used
     */
    public static function permissionGate(bool $interactive = false, bool $announceBypassMode = true): PermissionGate
    {
        // The LAYERS rather than the merge, so the refusal below can name the
        // file the bad value actually came from — see
        // {@see permissionConfigLayers()} for the error message that made this
        // necessary. Merged here rather than through `permissionConfig()` so
        // the two policy files are read once per launch, not twice.
        $layers = self::permissionConfigLayers();
        $config = array_merge(...array_values($layers));

        // An EMPTY env var is absence, not a bad value: `FOO=` is how a
        // wrapper script drops an inherited override. Anything else it says,
        // including "0", is a value the user meant.
        $env = getenv(self::PERMISSION_MODE_ENV);
        $envRaw = ($env === false || $env === '') ? null : $env;

        // A present-but-non-string `permissionMode` (a number, a bool, a
        // nested object) is stringified rather than ignored, so it reaches the
        // same "that is not a mode" refusal an unrecognised string does.
        // Silently skipping it would be the fail-open again, one type away.
        $configRaw = $config[self::PERMISSION_MODE_CONFIG_KEY] ?? null;
        $configRaw = match (true) {
            $configRaw === null => null,
            is_string($configRaw) => $configRaw === '' ? null : $configRaw,
            is_scalar($configRaw) => var_export($configRaw, true),
            default => get_debug_type($configRaw),
        };

        // --permission-mode is the highest-precedence source: an explicit flag on
        // THIS launch beats an inherited env var and beats a config file. It runs
        // through the same permissionModeFrom(), so a value that is not a mode
        // fails with the same message shape, naming the flag as its source.
        //
        // WALKED rather than written as a `??` chain, so that WHICH source won
        // survives the resolution instead of being thrown away one line after
        // it was known. `/permissions` reports it, and re-deriving it at
        // display time would be a second copy of this precedence — free to
        // disagree with this one, and reading files that may have been edited
        // since. The loop is behaviourally identical to the chain it replaces:
        // `??` short-circuits, so a later source's BAD value is still never
        // validated (and so never throws) once an earlier source has answered.
        $candidates = [
            '--permission-mode' => LaunchContext::permissionModeOverride(),
            '$' . self::PERMISSION_MODE_ENV => $envRaw,
            self::PERMISSION_MODE_CONFIG_KEY . ' in '
                . self::permissionKeySource($layers, self::PERMISSION_MODE_CONFIG_KEY) => $configRaw,
        ];

        $mode = null;
        $modeSource = Lang::t('cli.launch_source.built_in_default');
        foreach ($candidates as $source => $raw) {
            $resolved = self::permissionModeFrom($raw, $source);
            if ($resolved !== null) {
                $mode = $resolved;
                $modeSource = $source;
                break;
            }
        }
        $mode ??= $interactive ? self::INTERACTIVE_DEFAULT_PERMISSION_MODE : self::DEFAULT_PERMISSION_MODE;

        // rv-sec-1: bypass-permissions is the mode at which NO tool call is
        // ever asked about, and until now a launch that resolved to it — the
        // built-in headless default as much as a `permissionMode` in config —
        // said nothing. The statement rides the SAME launch-notices store the
        // `/notices` panel reads, so it reaches the TUI transcript and the
        // operator's stderr without touching DEFAULT_PERMISSION_MODE itself
        // (owner ruling: the default stands; the silence is what changes).
        // `warnPermissionConfigInTranscript` de-dups by message identity, so
        // the per-turn re-resolutions of this method cost one notice total.
        if ($announceBypassMode && $mode === PermissionMode::BypassPermissions) {
            self::warnPermissionConfigInTranscript(Lang::t('appLaunch.notice.bypass_mode'));
        }

        // Roadmap 5.11-2: `autoReview` in the user's own config.json — never a
        // project file, because every review is a paid model call — puts an
        // exec reviewer on the TITLE backend behind Auto's classifier. Built
        // whatever the mode, for the same shared-instance reason as the
        // classifier below. It reads the conversation of the session a call
        // belongs to from the session store (best effort: a session not yet
        // saved is reviewed on the call alone). No provider to review with is
        // said out loud: flagged calls then stay denied, as without the key.
        //
        // C-SEC-7.d adds the two companions, user-tier for the same money
        // reason: `autoReviewScope` says WHICH passed calls get reviewed
        // (read where it acts — PermissionGate::autoReviewsUnflagged()), and
        // `autoReviewModel` says WHAT reviews them — a `provider/model` pair
        // built tool-less exactly the way {@see titleBackend()} builds its
        // own, with the named provider instead of the selected one. A model
        // that cannot be spelled or built is said out loud once and leaves
        // the reviewer OFF: a review costs real money on somebody's
        // credentials, so a half-understood override must not silently pick
        // a different one to burn.
        $reviewer = null;
        $autoReview = self::readUserConfig()['autoReview'] ?? null;
        if ($autoReview !== null && !is_bool($autoReview)) {
            self::warnPermissionConfigOnce(Lang::t('cli.warn.auto_review_not_bool'));
        } elseif ($autoReview === true) {
            $reviewBackend = null;
            $reviewModel = self::readUserConfig()['autoReviewModel'] ?? null;
            if ($reviewModel !== null && !is_string($reviewModel)) {
                self::warnPermissionConfigOnce(Lang::t('cli.warn.auto_review_model_not_string'));
            } elseif (is_string($reviewModel) && trim($reviewModel) !== '') {
                $slash = strpos(trim($reviewModel), '/');
                if ($slash === false || $slash === 0 || $slash === strlen(trim($reviewModel)) - 1) {
                    self::warnPermissionConfigOnce(Lang::t('cli.warn.auto_review_model_bad_shape'));
                } else {
                    [$overrideProvider, $overrideModel] = array_map('trim', explode('/', trim($reviewModel), 2));
                    try {
                        $factory = new ProviderFactory();
                        $config = self::providerConfigFor($overrideProvider, $factory);
                        $config['model'] = $overrideModel;
                        // The reviewer titles nothing and summarises nothing:
                        // it answers one JSON question, so it runs without a
                        // thinking budget exactly as titleBackend() does —
                        // the empty string is what both parsers read as off.
                        $config['thinkingBudget'] = '';
                        $reviewBackend = new EngineBackend($factory->create($config), $overrideModel, hooksDisabled: true);
                    } catch (\Throwable) {
                        self::warnPermissionConfigOnce(Lang::t('cli.warn.auto_review_model_unusable'));
                        $reviewBackend = null;
                    }
                }
            } else {
                $reviewBackend = self::titleBackend();
                if ($reviewBackend === null) {
                    self::warnPermissionConfigOnce(Lang::t('cli.warn.auto_review_no_provider'));
                }
            }
            if ($reviewBackend !== null) {
                $reviewer = \SugarCraft\Code\Permissions\ExecReviewer::new($reviewBackend)->withTranscript(
                    static fn (string $sessionId): array => \SugarCraft\Code\Host\TranscriptStore::new(self::sessionStore(prune: false))->load($sessionId),
                );
            }
        }

        // The classifier is what Auto mode gates on, and PermissionGate fails
        // CLOSED (everything Asks) without one — so it is always supplied
        // rather than only when the mode currently happens to be Auto: this
        // gate instance is shared, and a mode read from config is not
        // something this method should have to predict.
        //
        // C-SEC-6.b: the project's narrowed rules ride BEFORE the user's.
        // PermissionGate answers first-match-wins, so this ordering is the
        // whole of the promise that a user `allow` cannot beat a project
        // `deny` — and it is why the project tier may contribute deny/ask
        // entries at all: prepended, they can only narrow what the session
        // does, never widen it.
        $userRules = self::permissionRules($config);

        return new PermissionGate($mode, [...self::projectNarrowingRules(), ...$userRules], new SafetyClassifier(), $modeSource, $reviewer);
    }

    /**
     * The trusted project's OWN deny/ask rules, C-SEC-6.b — the rules-axis
     * half of the self-grant floor.
     *
     * Under {@see projectSettingsTrusted()} the project's shared settings
     * file is read for `permissionRules` and NOTHING ELSE: `permissionMode`
     * is never consulted here or anywhere else from a project file (a
     * checked-in `bypass-permissions` would be an escape delivered by
     * `git clone`), and the local sibling is not read for rules either —
     * the local file's freedom is to override the SHARED file within the
     * project tier, not to reach the permission path at all. Of the entries
     * the shared file does carry, only deny/ask survive
     * ({@see ProjectNarrowingRules}); an allow here would be a project
     * granting its own session room, which is the exact thing every other
     * trust line in this file refuses.
     *
     * STRICT, at the same bargain as the user files: a shared settings file
     * that exists and cannot be used REFUSES the launch rather than silently
     * contributing no rules, because "absent" and "unparseable" look the
     * same to a tolerant reader and the difference is precisely whether the
     * project meant to narrow this launch. The one clause that does not
     * carry over from {@see readPolicyFile()} is the private-file ownership
     * check — a checked-out file belongs to whoever ran `git clone`, and the
     * trust grant is what stands in for ownership here. Absent-but-unreadable
     * (chmod'd away) stays silent, the existing house doctrine for invisible
     * files.
     *
     * Kept per-process so `/permissions` can say which tier each row rode
     * in on ({@see projectNarrowingRuleSignatures()}); the rules themselves
     * carry no provenance and a cloned `PermissionRule` cannot grow a field
     * into the user tier's identity.
     *
     * @return list<PermissionRule>
     *
     * @throws PermissionConfigException when the trusted shared file exists
     *         and cannot be used
     */
    private static function projectNarrowingRules(): array
    {
        $root = LaunchContext::projectRootForSettings();
        if ($root === null || trim($root) === '' || !self::projectSettingsTrusted($root)) {
            return [];
        }

        // The SAME containment walk {@see LayeredSettings::projectFiles()}
        // takes, so this reader cannot see a shared file the merge itself
        // would refuse to read — a symlinked `.candy-code` stays out.
        $shared = null;
        $decoded = [];
        foreach (LayeredSettings::projectFiles($root, true) as $path => $contents) {
            if (str_ends_with((string) $path, '/' . LayeredSettings::SHARED_PATH)) {
                $shared = (string) $path;
                $decoded = $contents;
                break;
            }
        }

        if ($shared === null) {
            return [];
        }

        if ($decoded === []) {
            $reason = LayeredSettings::unusableSettingsFileReason($shared);
            if ($reason !== null) {
                throw new PermissionConfigException(Lang::t('cli.refuse.project_settings_unusable', [
                    'path' => $shared,
                    'reason' => $reason,
                ]));
            }

            return [];
        }

        if (!array_key_exists(self::PERMISSION_RULES_CONFIG_KEY, $decoded)) {
            return [];
        }

        // The user tier's OWN strict item-wise parser — same complaints, same
        // Claude-dialect translation, same skip-with-a-named-index tolerance,
        // so one grammar is enforced from both tiers.
        $narrowing = new ProjectNarrowingRules(self::permissionRules($decoded));
        if ($narrowing->kept === [] && $narrowing->allowsDropped() === 0) {
            return [];
        }

        foreach ($narrowing->kept as $rule) {
            self::$projectNarrowingSignatures[$rule->action->value . ' ' . $rule->pattern] = true;
        }

        self::warnPermissionConfigInTranscript(Lang::t('appLaunch.notice.project_narrowing_rules', [
            'file' => $shared,
            'count' => $narrowing->narrowedRules(),
            'dropped' => $narrowing->allowsDropped(),
        ]));

        return $narrowing->kept;
    }

    /**
     * Signatures (`action pattern`) of the rules
     * {@see projectNarrowingRules()} loaded this process, for the tier column
     * of `/permissions`. Keyed-set so a repeated gate build is idempotent.
     *
     * @return list<string>
     */
    public static function projectNarrowingRuleSignatures(): array
    {
        return array_keys(self::$projectNarrowingSignatures);
    }

    /**
     * Drop the per-process project-rule signatures. Launch code never calls
     * this; the test suite does, because each test owns a different fake
     * project and the roster has to answer only for the current one.
     */
    public static function resetProjectNarrowingRulesForTesting(): void
    {
        self::$projectNarrowingSignatures = [];
    }

    /**
     * Resolve one permission-mode source: null when it said nothing, the mode
     * when it named one, and a throw when it named something that is not one.
     *
     * @param string|null $raw    the raw value, or null when the source is absent
     * @param string      $source how to name the source in the error message
     *
     * @throws PermissionConfigException
     */
    private static function permissionModeFrom(?string $raw, string $source): ?PermissionMode
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $mode = PermissionMode::tryFrom($raw);
        if ($mode !== null) {
            return $mode;
        }

        $valid = implode(', ', array_map(static fn(PermissionMode $m): string => $m->value, PermissionMode::cases()));

        throw new PermissionConfigException(
            Lang::t('cli.refuse.bad_mode', ['source' => $source, 'value' => $raw, 'valid' => $valid]),
        );
    }

    /**
     * {@see readUserConfig()}, minus the tolerance — see {@see permissionGate()}
     * for why the permission path may not share it.
     *
     * Kept separate rather than made the one reader for everything: the theme
     * and the persisted provider genuinely SHOULD survive a corrupt config,
     * because guessing wrong about them costs nothing.
     *
     * LAYERED SINCE Phase 6 item 4, over the user tier ONLY: the
     * `permissionMode`/`permissionRules` of `~/.candy-code/settings.json`
     * ({@see permissionSettingsLayer()}) beneath the whole of `config.json`.
     * Both files are read by {@see readPolicyFile()}, so the strictness is the
     * same for either — and no project file reaches THIS merge at any trust
     * level, which is the one thing {@see readUserConfig()} does differently
     * and the one thing it must never do here. The one project-side rule
     * reader is {@see projectNarrowingRules()}, which takes deny/ask entries
     * only from the trusted project's shared settings file and prepends them
     * to the merged result — the same policy, narrowed, never widened.
     *
     * @return array<string, mixed>
     *
     * @throws PermissionConfigException when either file exists and cannot be used
     */
    private static function permissionConfig(): array
    {
        // trustedConfigDirPath(), NOT userConfigPath(): the two resolve to the
        // same file whenever this user's home is knowable, and when it is not
        // this one refuses instead of reading a permission policy — and a
        // `trustedProjectHooks` list — out of a world-writable stand-in.
        //
        // Called for its THROW even when `--config` has named the file, on its
        // own line rather than as the right operand of `??` (which PHP would
        // not evaluate): naming the policy file explicitly says nothing about
        // whether the ~/.candy-code this process would go on to read hooks
        // and agent presets from is this user's, so the gate stays armed.
        return array_merge(...array_values(self::permissionConfigLayers()));
    }

    /**
     * The permission layers KEYED BY THE PATH THEY CAME FROM, lowest precedence
     * first — `settings.json` then `config.json`.
     *
     * THE KEYS ARE THE POINT, and they exist because an error message lied.
     * `permissionGate()` refuses a launch whose `permissionMode` names no real
     * mode, and it named the file as a literal `userConfigPath()` — hardcoded to
     * `config.json` — from the moment `settings.json` became a second source.
     * Measured: a `~/.candy-code/settings.json` of `{"permissionMode":"nope"}`
     * with no `config.json` at all refused the launch with "permissionMode in
     * …/config.json is 'nope'", sending the user to edit a file that does not
     * exist. {@see readPolicyFile()}'s own doc-block claims which file refused
     * the launch is always in the error; for that one branch it was not. So the
     * merge cannot be anonymous — provenance has to survive it, which is what
     * {@see permissionKeySource()} reads.
     *
     * `config.json` outranks `settings.json` for {@see readUserConfig()}'s
     * reason: it is the file the CLI WRITES, so it must also be the file that
     * decides. `array_merge`, later wins — and the ordering of this array IS
     * that precedence, for both the merge and the provenance lookup, so the two
     * cannot disagree about which file won.
     *
     * ONE EDGE, named rather than left to be discovered: `--config` pointed at
     * the user's own `settings.json` collapses the two entries into one, since
     * they are the same array key. The surviving entry is the
     * {@see readPolicyFile()} read — the whole file, not the
     * {@see PERMISSION_SETTINGS_KEYS} whitelist — which is the right answer for
     * a file the user has explicitly named as THE policy file, and provenance
     * still points at it.
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws PermissionConfigException when either file exists and cannot be used
     */
    private static function permissionConfigLayers(): array
    {
        $configDir = self::trustedConfigDirPath();
        $discovered = $configDir . '/config.json';
        $path = LaunchContext::configPathOverride() ?? $discovered;
        $settingsPath = rtrim($configDir, '/') . '/' . LayeredSettings::USER_FILE;

        return self::withoutEmptyPermissionOverrides([
            $settingsPath => self::permissionSettingsLayer($settingsPath),
            $path => self::readPolicyFile($path),
        ]);
    }

    /**
     * The layers with every EMPTY LATER VALUE dropped, so that a key written
     * blank in one file cannot throw away the value another file actually set.
     *
     * THE INVARIANT IS ABOUT DISPLACEMENT, NOT ABOUT ANY PARTICULAR OUTCOME.
     * Measured before this filter existed, with `{"permissionMode":"plan"}` in
     * `settings.json`:
     *
     *     config.json ABSENT                =>  plan
     *     config.json {}                    =>  plan
     *     config.json {"permissionMode":""} =>  bypass-permissions, silently
     *     config.json {"permissionMode":null} => bypass-permissions, silently
     *
     * It is tempting to describe those last two rows as "an empty key silently
     * grants the widest mode", and that is what they did — but it is not the
     * mechanism. The mechanism is that `array_merge` let a valueless key win the
     * precedence walk and the merged key then normalised to absent, one step too
     * late, so {@see permissionGate()} started from
     * {@see DEFAULT_PERMISSION_MODE}. That default merely HAPPENS to be
     * `bypass-permissions` today; tighten it and the identical bug would silently
     * lock the user out of the mode they configured instead. So what is fixed
     * here is "an empty value must not displace an earlier layer", and a fix
     * aimed at "must not reach bypass" would have gone stale the day the default
     * moved.
     *
     * EMPTY IS EXACTLY `null` AND `''`, and the narrowness is deliberate rather
     * than incidental. Those are the two spellings every downstream reader
     * already treats as "nothing was said" — {@see permissionGate()} normalises
     * `''` to null, and {@see permissionRules()} loads nothing from either. A
     * `"  "` names no mode and goes on refusing the launch by name, and a `[]`
     * is a well-formed rules list that goes on winning: both are VALUES, and
     * widening this test to cover them would convert a loud refusal and a
     * documented override into silent fallbacks.
     *
     * SCOPED TO {@see PERMISSION_SETTINGS_KEYS}. Today that scope is not
     * observable — the settings layer carries only those keys, so every other
     * key of the merged array comes from exactly one layer and has nothing to
     * displace — but the reasoning above is about the permission keys, and this
     * says so rather than quietly extending to keys it was never measured on.
     *
     * The drop is REPORTED, because the two spellings failed differently and
     * both failed the user: the mode said nothing at all, and the rules key said
     * "no rules were loaded" without ever mentioning that the rules it did not
     * load were configured in another file. That second half is the part a user
     * can act on. {@see warnPermissionConfigOnce()} rather than
     * {@see warnPermissionConfig()} because this runs once per
     * {@see permissionConfigLayers()} call and a launch makes more than one.
     *
     * @param array<string, array<string, mixed>> $layers lowest precedence first
     * @return array<string, array<string, mixed>>
     */
    private static function withoutEmptyPermissionOverrides(array $layers): array
    {
        // The path of the last layer that set each key to something real. Only
        // a key that has one of those can be DISPLACED — an empty value with
        // nothing beneath it is left in place, so the readers downstream go on
        // seeing it and go on saying what they said about it.
        $carried = [];

        foreach ($layers as $path => $data) {
            foreach (self::PERMISSION_SETTINGS_KEYS as $key) {
                if (!\array_key_exists($key, $data)) {
                    continue;
                }

                $value = $data[$key];
                if ($value !== null && $value !== '') {
                    $carried[$key] = $path;
                    continue;
                }

                if (!isset($carried[$key])) {
                    continue;
                }

                unset($layers[$path][$key]);
                self::warnPermissionConfigOnce(
                    Lang::t('cli.warn.empty_setting_ignored', ['key' => $key, 'path' => $path, 'carried' => $carried[$key]]),
                );
            }
        }

        return $layers;
    }

    /**
     * WHICH FILE last set a permission key, for an error message that has to
     * name it.
     *
     * The LAST layer carrying the key, matching `array_merge`'s later-wins, and
     * `array_key_exists` rather than `?? null` so that a layer setting a key to
     * an explicit null is still the layer that set it.
     *
     * The fallback is reached only for a key NO layer carries, and it is
     * deliberately not exercised by any caller today: a permission key absent
     * from every layer is read as absent and never reaches an error message
     * about its value. It returns the file the CLI writes because that is the
     * least wrong thing to point a user at, not because it has been observed.
     *
     * @param array<string, array<string, mixed>> $layers
     */
    private static function permissionKeySource(array $layers, string $key): string
    {
        $source = null;
        foreach ($layers as $path => $data) {
            if (\array_key_exists($key, $data)) {
                $source = $path;
            }
        }

        return $source ?? self::userConfigPath();
    }

    /**
     * The permission keys of `~/.candy-code/settings.json` — crush_code.md
     * Phase 6 item 4's `permission`/`permissionMode` settings block.
     *
     * WHY NOT THROUGH {@see \SugarCraft\Code\Config\LayeredSettings}, which
     * already reads this exact file: that class's reader is TOLERANT by
     * contract — a malformed file is the absence of a layer, because the keys it
     * carries are a theme and a model name and guessing wrong about those costs
     * nothing. The permission path may not have that tolerance; it is the whole
     * argument of {@see permissionGate()}. Routing `permissionMode` through the
     * tolerant merge would have meant one stray comma silently downgrading a
     * configured `plan` session to the permissive default, which is the
     * fail-open this method exists to close. So the same FILE is read twice by
     * two readers with different strictness, deliberately, and
     * `LayeredSettings::LAYERED_KEYS` documents its half of the split.
     *
     * NO PROJECT TIER, AT ANY TRUST LEVEL. `permissionMode` reaches
     * `bypass-permissions`, so a project-tier permission block would be a full
     * sandbox escape delivered by `git clone` — the operator's `chat()` would
     * come up ungated because a checked-in file said so. There is no trust flag
     * that makes that a reasonable grant, which is why this reads only the
     * home-owned directory and why the keys are absent from
     * `LayeredSettings::PROJECT_TIER_KEYS` and from `LAYERED_KEYS` entirely.
     *
     * FOLLOWS THE HOME, NOT `--config`. {@see useConfigPath()} names one file;
     * this is a different file, in the directory `trustedConfigDirPath()`
     * resolves — the same choice {@see userSettingsDirOrNull()} makes for the
     * tolerant reader, so both readers of `settings.json` agree on WHICH
     * `settings.json`.
     *
     * A NOTED BEHAVIOUR CHANGE: a `~/.candy-code/settings.json` that exists and
     * cannot be parsed now REFUSES THE LAUNCH, where before it was silently
     * skipped. That is the cost of the file becoming a policy source, and it is
     * the same bargain `config.json` already makes. It is also strictly louder
     * rather than newly broken: such a file already lost the user their theme
     * and provider without saying so.
     *
     * @return array<string, mixed>
     *
     * @throws PermissionConfigException when the file exists and cannot be used
     */
    private static function permissionSettingsLayer(string $path): array
    {
        $data = self::readPolicyFile($path);

        // array_key_exists, not `??`, and the reason had to be CORRECTED: an
        // earlier version of this comment claimed an explicit
        // `"permissionRules": null` "has to reach permissionRules()' own
        // handling", which was a behavioural claim about code that did not
        // behave that way — `permissionRules()` opened with `?? null` and read
        // explicit-null and absent identically, so the two spellings of this
        // filter were exactly equivalent and a mutation between them was
        // unkillable. The claim is now true because `permissionRules()` was
        // changed to make it true: a present-but-null rules key is REPORTED on
        // stderr, since a user who wrote it believes they configured rules and
        // has none. `array_key_exists` is also what
        // {@see permissionKeySource()} needs, for the same reason — a layer
        // that set a key to null is still the layer that set it.
        $kept = [];
        foreach (self::PERMISSION_SETTINGS_KEYS as $key) {
            if (\array_key_exists($key, $data)) {
                $kept[$key] = $data[$key];
            }
        }

        return $kept;
    }

    /**
     * One policy file, read with no tolerance at all — extracted verbatim from
     * {@see permissionConfig()} so that `config.json` and the user's
     * `settings.json` cannot diverge into two differently-strict readers of the
     * same kind of file. Every message below still names `$path`, so which file
     * refused the launch is always in the error.
     *
     * @return array<string, mixed>
     *
     * @throws PermissionConfigException when the file exists and cannot be used
     */
    private static function readPolicyFile(string $path): array
    {
        if (!is_file($path)) {
            // SOMETHING IS THERE AND IT IS NOT A READABLE REGULAR FILE — a
            // directory named `config.json`, a dangling symlink. The walk
            // below answers "nothing is configured" for it, because every
            // ancestor really is searchable, and this method then starts on
            // the permissive default. That is the same present-but-unusable
            // fail-open the rest of this method exists to close, and the same
            // distinction {@see \SugarCraft\Code\Hooks\HookConfig::loadFromFile()}
            // draws for the hook file, so it is drawn the same way here.
            if (file_exists($path) || is_link($path)) {
                throw new PermissionConfigException(is_dir($path)
                    ? Lang::t('cli.refuse.config_is_directory', ['path' => $path])
                    : Lang::t('cli.refuse.config_dangling', ['path' => $path]));
            }

            // `is_file()` answers false for two things that are not the same
            // question: the file is ABSENT, or a directory on the way to it
            // cannot be SEARCHED, in which case a policy may be sitting right
            // there and this process simply cannot see it. Only the first is
            // "nothing was configured". Reading the second as absence was the
            // fail-open this method exists to close, and it needs no change to
            // the config file at all to reach — a different euid, `sudo`
            // without `-E`, an NFS/autofs blip. It was also exactly backwards
            // from what the docblock promises: an unreadable FILE hard-failed
            // while an unreadable DIRECTORY silently ran bypass-permissions.
            $unreachable = self::unreachableAncestor($path);
            if ($unreachable !== null) {
                throw new PermissionConfigException(
                    Lang::t('cli.refuse.config_unreachable', ['path' => $path, 'reason' => $unreachable]),
                );
            }

            return [];
        }

        // The file exists, so "whose is it" is now an answerable question and
        // has to be answered before its contents become this launch's policy.
        self::requirePrivatePolicyFile($path);

        // `@`-silenced for the reason readUserConfig() is: the false branch
        // below IS the handling, and the raw warning would land in the middle
        // of the TUI's own output.
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new PermissionConfigException(
                Lang::t('cli.refuse.config_unreadable', ['path' => $path]),
            );
        }

        // A zero-byte (or whitespace-only) file is definitionally "nothing was
        // configured", and refusing to start on it bricks the CLI on a state
        // the CLI can produce ITSELF: {@see writeUserConfig()} replaces the
        // file atomically now, but a config truncated by an older build, a
        // full disk, or an editor is still out there, and there is no way to
        // fix it from inside a binary that will not launch.
        if (trim($contents) === '') {
            return [];
        }

        $data = json_decode($contents, true);

        // The second half of the test is on the JSON TEXT, for exactly the
        // reason {@see \SugarCraft\Code\Hooks\ScriptHook::modifyOrDeny()}
        // tests it there: `json_decode()` throws away the distinction being
        // made, since `{}` and `[]` both decode to `[]`. `is_array()` alone
        // therefore called a top-level JSON LIST a usable config, dropped
        // every key in it, and started on the permissive default — with the
        // branch below still claiming, unreachably, that it would have
        // reported "the top level is not a JSON object".
        if (!is_array($data) || !str_starts_with(ltrim($contents), '{')) {
            // A BOM is named rather than left to `json_last_error_msg()`'s bare
            // "Syntax error": it is a common editor artifact, it is INVISIBLE
            // in every editor that wrote it, and the file it fails on is
            // otherwise character-for-character correct — so the generic
            // message sends the user hunting for a stray comma that is not
            // there. Still a hard failure: JSON does not permit a BOM, and
            // this path may not guess at a policy.
            $error = match (true) {
                str_starts_with($contents, "\xEF\xBB\xBF") => Lang::t('cli.refuse.config_bom'),
                json_last_error() === JSON_ERROR_NONE => Lang::t('cli.refuse.config_not_object'),
                default => json_last_error_msg(),
            };

            throw new PermissionConfigException(
                Lang::t('cli.refuse.config_not_json', ['path' => $path, 'error' => $error]),
            );
        }

        return $data;
    }

    /**
     * Why $path could not be reached, phrased for the exception message, or
     * null when the whole chain is traversable and the file is simply not
     * there.
     *
     * Walks UPWARD rather than testing `dirname($path)` alone because that
     * test is itself ambiguous: `is_dir()` on a directory whose own parent is
     * unsearchable also answers false. The first ancestor that answers true to
     * `is_dir()` was successfully stat'ed, which proves everything above it
     * was searchable — so its own `is_executable()` is the whole answer.
     *
     * The SYMLINK probe is the second half, and without it the walk re-opened
     * the very fail-open it exists to close. `dirname()` is LEXICAL, so it
     * cannot follow the real chain past a link: point `~/.candy-code` at a
     * directory sitting behind an unsearchable one and `is_dir()` on the link
     * answers false, the walk steps up to a perfectly searchable `~`, and a
     * policy nobody can read is reported as "nothing configured". A component
     * that `lstat()`s but does not resolve to a directory is therefore its own
     * terminal answer: whether it dangles or its target is merely out of
     * reach, what is behind it is unknowable, and unknowable fails closed.
     *
     * Why not a `realpath()`-based walk: `realpath()` returns false for BOTH
     * "does not exist" and "cannot be reached", which is precisely the
     * distinction this method exists to draw — it would have to fall back to a
     * lexical walk anyway, and would lose the "reached the root, nothing is
     * there" terminal state that keeps a fresh install launching.
     */
    private static function unreachableAncestor(string $path): ?string
    {
        $dir = \dirname($path);

        while (true) {
            if (is_dir($dir)) {
                return is_executable($dir) ? null : Lang::t('cli.refuse.not_searchable', ['dir' => $dir]);
            }

            if (is_link($dir)) {
                return Lang::t('cli.refuse.dangling_directory', ['dir' => $dir]);
            }

            $parent = \dirname($dir);
            if ($parent === $dir) {
                // Reached the filesystem root without finding anything that
                // exists. Nothing is hiding a config; there is no config.
                return null;
            }

            $dir = $parent;
        }
    }

    /**
     * EVERY COMPLAINT HERE GOES TO BOTH CHANNELS — see
     * {@see warnPermissionConfigInTranscript()}. A dropped rule is a SILENT
     * WIDENING: {@see PermissionRule} degrades a malformed pattern to a name no
     * real tool matches, so a `Deny` typo'd this way denies NOTHING and the
     * session runs wide open for as long as it lasts. That is the one thing on
     * this path a user has to learn while the session is still running, and
     * stderr under the alternate screen is precisely where they cannot.
     *
     * AND IT WAS SAID TWICE. MEASURED before the migration: {@see chat()}
     * reaches this method through {@see permissionGate()} AND through
     * {@see agentManager()}, and these were raw {@see warnPermissionConfig()}
     * calls with no de-duplication at all — a config with two bad rules printed
     * four stderr lines on every launch. Routing them through the transcript
     * seam picks up {@see warnPermissionConfigOnce()}'s per-process map on the
     * stderr side as well, so the count is now one apiece. That is a change to
     * stderr, and it is the fix, not a side effect.
     *
     * PUBLIC, AND THE REPORT IS INJECTABLE, so the settings view's writer
     * judges a `permissionRules` value with THIS parser rather than a copy
     * ({@see \SugarCraft\Code\Config\Settings\SettingsWriter}'s type check,
     * roadmap N-P5): it passes `$report` to collect each complaint instead of
     * announcing it, and refuses the save on the first. A value the writer
     * accepts is therefore one the next launch loads every rule of — the
     * editor can never write a rule this method would skip.
     *
     * @param array<string, mixed> $config the already-read user config
     * @param ?\Closure(string): void $report where each complaint goes; null = both channels
     * @return list<PermissionRule>
     */
    public static function permissionRules(array $config, ?\Closure $report = null): array
    {
        // ABSENT AND EXPLICITLY NULL ARE DIFFERENT THINGS HERE, and they were
        // not before: `?? null` collapsed them, so `"permissionRules": null`
        // loaded no rules and said nothing — the same silence that let the
        // argument-scoped grammar deny nothing for as long as it did. A user
        // who typed the key believes they configured rules. Absence is not an
        // error (a fresh install has no key at all); presence with no usable
        // value is, at the same report-and-continue level as every other
        // item-wise rules complaint.
        if (!\array_key_exists(self::PERMISSION_RULES_CONFIG_KEY, $config)) {
            return [];
        }

        $raw = $config[self::PERMISSION_RULES_CONFIG_KEY];
        if ($raw === null) {
            $complaint = Lang::t('cli.warn.rules_null', ['key' => self::PERMISSION_RULES_CONFIG_KEY]);
            if ($report !== null) {
                $report($complaint);
            } else {
                self::warnPermissionConfigInTranscript($complaint);
            }

            return [];
        }

        if (!is_array($raw)) {
            $complaint = Lang::t('cli.warn.rules_not_list', ['key' => self::PERMISSION_RULES_CONFIG_KEY]);
            if ($report !== null) {
                $report($complaint);
            } else {
                self::warnPermissionConfigInTranscript($complaint);
            }

            return [];
        }

        $rules = [];
        foreach ($raw as $index => $entry) {
            if (!is_array($entry) || !is_string($entry['pattern'] ?? null)) {
                $complaint = Lang::t('cli.warn.rule_no_pattern', ['key' => self::PERMISSION_RULES_CONFIG_KEY, 'index' => $index]);
                if ($report !== null) {
                    $report($complaint);
                } else {
                    self::warnPermissionConfigInTranscript($complaint);
                }
                continue;
            }

            $action = is_string($entry['action'] ?? null)
                ? PermissionAction::tryFrom($entry['action'])
                : null;
            if ($action === null) {
                $complaint = Lang::t('cli.warn.rule_no_action', [
                    'key' => self::PERMISSION_RULES_CONFIG_KEY,
                    'index' => $index,
                    'pattern' => $entry['pattern'],
                ]);
                if ($report !== null) {
                    $report($complaint);
                } else {
                    self::warnPermissionConfigInTranscript($complaint);
                }
                continue;
            }

            // A pattern the grammar cannot parse is reported and skipped here
            // rather than handed to the gate, for the same reason a bad
            // `action` is: {@see PermissionRule} degrades a malformed pattern to
            // a name that matches no real tool, so a `Deny` typo'd this way
            // would silently deny NOTHING. That is the exact failure the
            // argument-scoped grammar was rewritten to end, and the two
            // channels this method's doc-block names are the only places it can
            // be noticed.
            //
            // THE REASON COMES FROM THE GRAMMAR, not from this call site. This
            // warning used to assert "has an unbalanced parenthesis" for every
            // rejection, and measured, that was false for half of the grammar's
            // rejections: `""` contains no parenthesis and `"(rm *)"` contains a
            // balanced pair — both are refused for having no tool-name half.
            // A diagnostic that misnames the mistake is worse than a generic
            // one, so the wording lives in
            // {@see PermissionRule::patternRejectionReason()} beside the check
            // that produces it.
            // ISS-012: a Claude-migrated `(prefix:*)` tail is translated to
            // the native form HERE, at the load boundary, before the grammar
            // ever sees it — the raw spelling passes well-formedness and
            // matches nothing, which is the silent no-op this closes. The
            // complaint below still names the user's own spelling.
            $pattern = PermissionRule::fromClaudePrefixDialect($entry['pattern']);
            $rejection = PermissionRule::patternRejectionReason($pattern);
            if ($rejection !== null) {
                $complaint = Lang::t('cli.warn.rule_bad_pattern', [
                    'key' => self::PERMISSION_RULES_CONFIG_KEY,
                    'index' => $index,
                    'pattern' => $entry['pattern'],
                    'reason' => $rejection,
                ]);
                if ($report !== null) {
                    $report($complaint);
                } else {
                    self::warnPermissionConfigInTranscript($complaint);
                }
                continue;
            }

            $rules[] = new PermissionRule($pattern, $action);
        }

        return $rules;
    }

    /**
     * Report a permission-config problem the launch survived.
     *
     * stderr, matching the precedent {@see backend()} already sets for a
     * configured-but-unusable provider: stdout belongs to the TUI, and a
     * dropped rule has to be visible somewhere or it is the silent widening
     * this whole path exists to avoid.
     *
     * NOT AN UN-MIGRATED CALL SITE, and it was re-examined in round 42 (E78)
     * on the theory that it was one. This `fwrite(STDERR, …)` is the STDERR
     * CHANNEL ITSELF, not a warning that never made it onto the transcript
     * seam: {@see warnPermissionConfigInTranscript()} records the transcript
     * row and then delegates HERE for the stderr half, so migrating this line
     * onto that seam would be a cycle — the seam calling the seam — and would
     * remove the only unclipped copy the design leans on when it advertises
     * "the full text is on stderr". The question worth asking of a warning is
     * which of the three entry points it uses (this one, {@see
     * warnPermissionConfigOnce()}, or the transcript seam); the question is
     * never whether this line should stop writing to stderr.
     */
    private static function warnPermissionConfig(string $message): void
    {
        fwrite(STDERR, sprintf(self::STDERR_LINE_FORMAT, $message));
    }

    /**
     * {@see warnPermissionConfig()}, but at most once per process per message.
     *
     * For the warnings raised from a path that runs MORE THAN ONCE A LAUNCH.
     * Keyed by the message rather than by a call site because the same
     * malformed entry reported twice is what the user experiences as noise,
     * whichever line printed it — and because the message already carries the
     * entry's index, so two genuinely different bad entries still both get
     * said. Static for the reason {@see LaunchContext::$reportedUntrustedHookFiles} is: the
     * duplication is a property of the launch, not of any instance.
     */
    private static function warnPermissionConfigOnce(string $message): void
    {
        if (LaunchNotices::reported($message)) {
            return;
        }

        LaunchNotices::markReported($message);
        self::warnPermissionConfig($message);
    }

    /**
     * {@see warnPermissionConfigOnce()}, AND a row in the transcript the launch
     * is about to build.
     *
     * FOR THE WARNINGS THAT HAVE TO BE SEEN WHILE THE SESSION RUNS, rather than
     * before it or after it. stderr is not being taken away from any of them —
     * `-p` reads it, and so does the scrollback a user gets back when
     * they quit. But an interactive launch enters the alternate screen roughly
     * half a second later (MEASURED: 0.47s on a real pty run) and paints over
     * it, and the primary buffer does not come back until the session is over.
     * A warning that says "this checkout cut your tools to `Bash`" arriving
     * after the Bash-only session is not the warning.
     *
     * BOTH CHANNELS, never one instead of the other. See
     * {@see Chat::withLaunchNotices()} for why the transcript is the surface.
     *
     * WHAT IS ROUTED HERE, and the rule that decided it: a warning reaches the
     * transcript iff it names something the session can no longer DO — a
     * provider that became {@see EchoProvider}, agent presets that did not
     * load, a hook file that was refused, permission rules that were dropped, a
     * tool set that was cut. Warnings about the user's config being MALFORMED
     * rather than the session being DIMINISHED stay on stderr:
     * {@see trustedProjectRoots()}'s per-entry complaints (whose consequence —
     * an untrusted project — is already reported here by
     * {@see hookFiles()}/{@see reportProjectTierRefusals()} with the actionable
     * path) and {@see withoutEmptyPermissionOverrides()}'s "an empty key was
     * ignored" (which reports a change that was DECLINED, so nothing about the
     * session differs).
     *
     * THIS LIST USED TO HAVE A THIRD ENTRY: {@see reportPrunedSessions()},
     * "about history already deleted, not about this session's capabilities".
     * WHAT IS TRUE NOW: its SUMMARY is routed here (E78 round 42) and only its
     * per-session detail rows stayed behind. A prune takes away resuming,
     * branching and rewinding the rows it names, which is a capability this
     * launch removed and therefore the rule's own "iff" case; the reason it
     * reads as an exception is the fan-out, and the fan-out is a property of
     * the DETAIL, not of the fact. See that method for the split.
     * WHY THE REST OF THE LIST STILL EARNS ITS PLACE: the two entries above
     * report a change the launch DECLINED to make, and re-examining them in
     * round 42 did not move either — nothing about the running session differs
     * because of them, so a transcript row would spend tokens every turn to say
     * that nothing happened.
     *
     * The stderr half keeps {@see warnPermissionConfigOnce()}'s per-process
     * de-duplication and the transcript half keeps its own per-LAUNCH one, so a
     * second {@see chat()} in one process still seeds its transcript even
     * though stderr has already said it. Recording BEFORE the delegation rather
     * than after is what makes that true — the Once() call returns early on the
     * repeat.
     *
     * BOUNDED ON BOTH AXES, and only on the transcript side. See
     * {@see LAUNCH_NOTICE_LIMIT} for why an unbounded list is a per-token cost
     * for the whole session rather than a scrolling nuisance, and
     * {@see LAUNCH_NOTICE_MAX_CHARS} for the per-message clip. $message reaches
     * stderr whole and unclipped either way: that channel is the complete
     * record, which is what makes the clip safe to advertise.
     */
    private static function warnPermissionConfigInTranscript(string $message): void
    {
        LaunchNotices::record($message, self::launchNoticeLimit());

        self::warnPermissionConfigOnce($message);
    }

    /**
     * Alias of {@see LaunchNotices::NARROWED_GRANT_PAIR_PATTERN}: the pair
     * regex belongs with the packer that runs it; the name stays reachable
     * through this class for the drain test's reflection reads.
     */
    public const NARROWED_GRANT_PAIR_PATTERN = LaunchNotices::NARROWED_GRANT_PAIR_PATTERN;

    /**
     * Seed the transcript with the narrowed-grant report E653: stderr gets
     * every warning whole and once (the complete record), the transcript gets
     * at most two aggregate rows (the token-budgeted summary).
     *
     * BOTH CHANNELS, never one instead of the other — the collector was
     * pull-only precisely because the naive both-channels drain flooded the
     * transcript; this is the aggregation decision that round 62 reported as
     * missing. Called from {@see chat()} only, after every other warning has
     * been recorded, because the header counts the grants of THIS launch and
     * the row budget is shared with the rest of the list.
     */
    private static function drainNarrowedGrantWarnings(AgentManager $manager): void
    {
        $warnings = $manager->narrowedGrantWarnings();

        if ($warnings === []) {
            return;
        }

        foreach ($warnings as $warning) {
            self::warnPermissionConfigOnce($warning);
        }

        foreach (self::narrowedGrantNoticeRows($warnings) as $row) {
            self::warnPermissionConfigInTranscript($row);
        }
    }

    /**
     * The transcript rows for a narrowed-grant flood — at most two aggregate
     * rows, however wide the fan-out; the packer and its row-shape prose live
     * in {@see LaunchNotices::grantRows()}.
     *
     * @param list<string> $warnings
     *
     * @return list<string>
     */
    private static function narrowedGrantNoticeRows(array $warnings): array
    {
        return LaunchNotices::grantRows($warnings);
    }

    /**
     * The launch warnings {@see chat()} seeds the transcript with — see
     * {@see warnPermissionConfigInTranscript()}.
     *
     * Public so a test can read what a launch decided to say without capturing
     * stderr, and so an embedder building its own Chat can route them into
     * whatever surface it has. The rows, the cap tail and its synthesis law
     * live in {@see LaunchNotices::rows()}.
     *
     * @return list<string>
     */
    public static function launchNotices(): array
    {
        return LaunchNotices::rows();
    }

    /**
     * The clipped messages that fell past {@see LAUNCH_NOTICE_LIMIT} this
     * launch — the sentences behind the synthesised "...and N more" row.
     * The store and its set-not-counter law live with the shelf in
     * {@see LaunchNotices::droppedMessages()}.
     *
     * @return list<string>
     */
    public static function launchNoticesDropped(): array
    {
        return LaunchNotices::droppedMessages();
    }

    /**
     * The project's MCP config file name — the SAME file Claude Code reads, and
     * the same `mcpServers` key {@see McpClient::loadConfig()} already parsed
     * before anything built one.
     */
    public const MCP_CONFIG_FILENAME = '.mcp.json';

    /** {@see mcpConfigDecision()} status: no `.mcp.json` at the project root. */
    public const MCP_ABSENT = 'absent';

    /** {@see mcpConfigDecision()} status: present, but it resolves outside the checkout. */
    public const MCP_OUTSIDE_TREE = 'outside-tree';

    /** {@see mcpConfigDecision()} status: present and contained, but this root is not trusted. */
    public const MCP_UNTRUSTED = 'untrusted';

    /** {@see mcpConfigDecision()} status: present, contained and trusted — servers may be started. */
    public const MCP_TRUSTED = 'trusted';

    /**
     * {@see mcpConfigDecision()} status: project MCP is switched off for this
     * launch ({@see mcpDisabled()}, ISS-093(a)).
     *
     * The consumers' law is unchanged — every non-{@see self::MCP_TRUSTED}
     * status starts nothing — so this exists purely so the SURFACE can say why
     * nothing started. Before it the gate answered {@see self::MCP_ABSENT},
     * which made a project that has a `.mcp.json` report "this project
     * declares no .mcp.json" while the file sat right there, and taught the
     * operator to go hunt a file question when the answer was a switch.
     */
    public const MCP_DISABLED = 'disabled';

    /**
     * E737 — the opt-out hatch. Env var that forces the MCP decision to
     * `MCP_DISABLED` regardless of what the project root holds.
     *
     * WHY AN ENV VAR AND NOT A SETTING: the consumers that need it most are
     * automated environments that have no settings file to edit and must not
     * inherit one — sharded test runs (the E737 mechanism: the shard runner
     * sits at the checkout root, where an operator's own `.mcp.json` is real,
     * and any sibling that reaches {@see mcpClient()} through the root-less
     * funnel used to spawn those servers as a side effect of a test run;
     * `scripts/parallel-tests.sh` now sets this variable on the shard launch
     * so that side effect is gone), scripted hosts, and any operator who
     * wants the TUI fully MCP-off.
     *
     * THE TRUTHY VOCABULARY IS NARROWER THAN THE FLAG-STYLE SWITCHES: only
     * `1`, `true` and `yes` (case-insensitive) disable. Unset, `0`, empty and
     * every other value keep MCP enabled — exactly what the flag-style
     * variables do for their OFF-side — so a test, a CI matrix or a wrapper
     * script that clears its own environment can trust the default: only an
     * explicit opt-out turns the gate off. {@see McpDecision::mcpDisabledByEnvironment()}
     * owns the spelling.
     */
    public const MCP_DISABLE_ENV = 'CANDY_CODE_MCP_DISABLE';

    /**
     * MCP-3: the USER tier's file name — `~/.candy-code/mcp.json`, the operator's
     * own server roster, read beside every other `~/.candy-code` file and gated
     * the same way (the {@see \SugarCraft\Code\Support\HomeDirectory::owned()}
     * ownership check IS the gate; there is no second ceremony for it). The
     * project tier keeps the Claude Code filename; this tier deliberately does
     * not shadow-copy that name into the home directory's own corner.
     */
    public const MCP_USER_CONFIG_FILENAME = 'mcp.json';

    /** The settings key {@see mcpDisabled()} reads (roadmap N-P4g). */
    public const MCP_ENABLED_SETTING = 'mcp.enabled';

    /**
     * MCP-7: the settings key holding the per-server schema-token floor. A
     * server whose advertised schemas cost more than this gets no
     * `mcp__server__tool` bridges; its tools ride the `mcp_list`/`mcp_call`
     * dispatcher pair instead (see {@see \SugarCraft\Code\MCP\McpToolShape}).
     */
    public const MCP_LAZY_SETTING = 'mcp.lazySchemaTokens';

    /**
     * The floor when the setting is unset: a few ordinary servers' worth of
     * schemas, so the pair only appears next to a genuine schema bomb. Zero
     * disables deferral; the settings range refuses negatives.
     */
    public const DEFAULT_LAZY_SCHEMA_TOKENS = 8000;

    /**
     * Where the project's MCP config is, what it declares, and whether this
     * launch may act on it — the routing seam for {@see McpDecision}, which
     * computes the verdict from paths and trust records this class still owns.
     */
    public static function mcpConfigDecision(?string $root = null): array
    {
        return McpDecision::mcpConfigDecision(
            $root,
            static fn(?string $candidate): string => self::requireRoot($candidate),
            static fn(string $candidate): bool => self::projectMcpIsTrusted($candidate),
            static function (string $path, string $reason): void {
                self::$projectTierRefusals[$path] = $reason;
            },
        );
    }

    /**
     * Whether MCP starts at all this launch — the environment switch and the
     * user-tier setting, read through {@see McpDecision::mcpDisabled()}.
     */
    public static function mcpDisabled(): bool
    {
        return McpDecision::mcpDisabled();
    }

    /**
     * The panel's table of declared servers, computed by
     * {@see McpDecision::mcpServerInventory()} from this launch's decision.
     */
    public static function mcpServerInventory(?string $root = null): array
    {
        return McpDecision::mcpServerInventory(self::mcpConfigDecision($root));
    }

    /**
     * Opt one repository's project-tier MCP servers in, through
     * {@see McpDecision::trustProjectMcp()}, recording the choice in the
     * operator's own settings file.
     */
    public static function trustProjectMcp(?string $root = null): array
    {
        return McpDecision::trustProjectMcp(
            self::mcpConfigDecision($root),
            static fn(): array => self::permissionConfig(),
            static fn(): string => self::trustedConfigDirPath(),
            static fn(array $config, string $key, string $nothingTrusted): array => self::trustedProjectRoots($config, $key, $nothingTrusted),
        );
    }

    /**
     * C-SEC-5.a: which trust targets this root declares but does not yet hold
     * — the census behind the one prompt. Reads the four trust doors and the
     * memo client's pin refusals through {@see ProjectTrustCensus}; never
     * launches, never writes. The declares probes are deliberately narrow:
     * `Commands` counts only a file with a `!` form that COULD run
     * ({@see \SugarCraft\Code\Commands\CommandSpec::hasShellSubstitution()}),
     * or every repository shipping a plain command file would be asked.
     *
     * @return list<TrustTarget>
     */
    public static function pendingProjectTrust(?string $root): array
    {
        $configRoot = self::configRoot($root);
        if ($configRoot === null) {
            return [];
        }

        $canonical = realpath($configRoot);
        $base = rtrim($canonical !== false ? $canonical : $configRoot, '/');
        $decision = self::mcpConfigDecision($root);

        return ProjectTrustCensus::pending(
            static fn (TrustTarget $target): bool => match ($target) {
                TrustTarget::Mcp, TrustTarget::McpPin => self::pendingTrustMcpDeclared($decision),
                TrustTarget::Hooks => self::pendingTrustHooksDeclared($base),
                TrustTarget::Commands => self::pendingTrustCommandsDeclared($base),
                TrustTarget::Settings => is_file($base . '/' . LayeredSettings::SHARED_PATH)
                    || is_file($base . '/' . LayeredSettings::LOCAL_PATH),
            },
            static fn (TrustTarget $target): bool => match ($target) {
                TrustTarget::Mcp => self::projectMcpIsTrusted($configRoot),
                // The pin row is answered by re-recording, so its honest
                // "granted" is "no refusal stands" — see the class doc-block
                // of ProjectTrustCensus. A cold memo refuses nothing.
                TrustTarget::McpPin => self::mcpPinRefusals($root) === [],
                TrustTarget::Hooks => self::projectHooksAreTrusted($configRoot),
                TrustTarget::Commands => self::projectCommandShellIsTrusted($configRoot),
                TrustTarget::Settings => self::projectSettingsTrusted($configRoot),
            },
        );
    }

    /**
     * C-SEC-5.a: the servers this process's memo client refused — pins that
     * drifted from what `mcp trust` fingerprinted. Read through
     * {@see McpDecision::mcpPinRefusals()}, memo-only: a cold process has
     * nothing to refuse and this never builds a client to find out.
     *
     * @return list<string>
     */
    public static function mcpPinRefusals(?string $root = null): array
    {
        return McpDecision::mcpPinRefusals(
            self::mcpConfigDecision($root),
            static fn (string $key): ?McpClient => LaunchContext::mcpClientFor(getmypid() ?: 0, $key),
        );
    }

    /**
     * C-SEC-5.a: THE ONE GRANT DOOR for one census row. `mcp` and `mcp-pin`
     * go through {@see trustProjectMcp()} — grant and pin record land
     * together, because a pin cannot be approved apart from the list it keys
     * on; every other target goes through
     * {@see \SugarCraft\Code\Config\Settings\SettingsWriter::grantTrust()},
     * the settings editor's already-wired confirmed action. Both doors write
     * the OPERATOR's own config only — a project can never grant itself
     * through this (or any) path, the property
     * {@see \SugarCraft\Code\Protocol\TrustAsks} is built on.
     *
     * NEVER throws: its consumers (the prompt, the settings view, a later
     * CLI verb) must be able to show the user WHY an answer failed rather
     * than swallow it.
     *
     * @return array{granted: bool, persisted: bool, error: string|null}
     */
    public static function grantProjectTrust(TrustTarget $target, string $root, ?\SugarCraft\Code\Config\Settings\SettingsWriter $writer = null): array
    {
        if ($target->grantsThroughMcpDoor()) {
            try {
                $report = self::trustProjectMcp($root);
            } catch (\Throwable $e) {
                return ['granted' => false, 'persisted' => false, 'error' => $e->getMessage()];
            }

            $error = $report['error'];
            if (!$report['granted'] && null === $error) {
                // The early returns (ABSENT/OUTSIDE_TREE/DISABLED) answer
                // "not granted" without a reason of their own; the docblock
                // forbids swallowing one, so the not-granted row speaks for
                // them — the root asked for, and the operator config the
                // grant would have had to land in.
                $error = Lang::t('cli.mcp.trust.not_granted', [
                    'root' => $root,
                    'config' => self::userConfigPath(),
                ]);
            }

            return [
                'granted' => $report['granted'],
                'persisted' => $report['granted'] && $report['recorded'],
                'error' => $error,
            ];
        }

        try {
            ($writer ?? LaunchContext::settingsWriter($root))->grantTrust($target->configKey(), $root);
        } catch (\Throwable $e) {
            return ['granted' => false, 'persisted' => false, 'error' => $e->getMessage()];
        }

        return ['granted' => true, 'persisted' => true, 'error' => null];
    }

    /**
     * The project-tier half of the MCP declares probe: does the file the
     * decision located NAME at least one server? Stat and decode only — the
     * same pre-read {@see McpDecision::trustProjectMcp()} performs; listing
     * never launches.
     *
     * @param array{status: string, path: string} $decision
     */
    private static function pendingTrustMcpDeclared(array $decision): bool
    {
        if ($decision['status'] !== self::MCP_UNTRUSTED && $decision['status'] !== self::MCP_TRUSTED) {
            return false;
        }

        $contents = @file_get_contents($decision['path']);
        if (!is_string($contents)) {
            return false;
        }

        $data = json_decode($contents, true);

        return is_array($data) && is_array($data['mcpServers'] ?? null) && $data['mcpServers'] !== [];
    }

    /**
     * Whether ANY project-tier hook file exists — the project half of the
     * path list {@see hookFiles()} would load under a grant. `is_file` only:
     * the refusal collector is NOT re-run through this, because a census
     * that announces itself on stderr is a notice wearing a question's
     * clothes.
     */
    private static function pendingTrustHooksDeclared(string $base): bool
    {
        foreach (array_merge(
            [$base . '/.candy-code/hooks.yaml'],
            array_map(
                static fn (string $name): string => $base . '/.claude/' . $name,
                ClaudeSettingsHooks::FILENAMES,
            ),
        ) as $file) {
            if (is_file($file)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any project command file carries a `!` form that could run —
     * the declaration the risk note says to mean. Parses with the production
     * loader, reporting forced off; the loader only reads.
     */
    private static function pendingTrustCommandsDeclared(string $base): bool
    {
        foreach ((new CommandLoader(false))->loadProjectCommands($base) as $spec) {
            if ($spec->hasShellSubstitution()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the servers this launch's decision names are actually live, read
     * from the client memo this class keeps — computed by
     * {@see McpDecision::mcpLivenessSnapshot()}.
     */
    public static function mcpLivenessSnapshot(?string $root = null): ?array
    {
        return McpDecision::mcpLivenessSnapshot(
            self::mcpConfigDecision($root),
            static fn(string $key): ?McpClient => LaunchContext::mcpClientFor(getmypid() ?: 0, $key),
        );
    }

    /**
     * The client-memo key for one decision — {@see McpDecision::mcpConfigMemoKey()}.
     */
    private static function mcpConfigMemoKey(array $decision): string
    {
        return McpDecision::mcpConfigMemoKey($decision);
    }

    /**
     * Whether either MCP tier's bytes drifted since this launch built its
     * client, read through {@see McpDecision::mcpConfigChangedSinceLaunch()}
     * against the per-tier digests this class stores at client-build time.
     */
    public static function mcpConfigChangedSinceLaunch(?string $root = null): ?bool
    {
        return McpDecision::mcpConfigChangedSinceLaunch(
            self::mcpConfigDecision($root),
            static fn(string $tierPath): ?array => LaunchContext::mcpConfigDigestFor(getmypid() ?: 0, $tierPath),
        );
    }

    /**
     * A cheap identity for one config file's current bytes —
     * {@see McpDecision::mcpConfigDigest()}.
     */
    private static function mcpConfigDigest(string $path): ?array
    {
        return McpDecision::mcpConfigDigest($path);
    }

    /**
     * The operator's `claude`-server MCP grant, read through
     * {@see McpDecision::claudeMcpGrant()}.
     */
    public static function claudeMcpGrant(): ?array
    {
        return McpDecision::claudeMcpGrant(self::permissionConfig());
    }

    /**
     * The launch's MCP client, with its configured servers STARTED, or null when
     * this project has no usable `.mcp.json`.
     *
     * TWO LOCATIONS (MCP-3): `$root/.mcp.json` behind the project trust
     * ceremony, and `~/.candy-code/mcp.json` behind the ownership gate at
     * {@see HomeDirectory::owned()}. The precedence the old revision of this
     * paragraph said was deliberately unchosen is now chosen, here and in
     * {@see McpClient::startServers()}: the PROJECT owns a shared name, at
     * launch and in the listing, because the project tier is the one consented
     * to per server. The user tier widens DISCOVERY only — its entries carry
     * no project pin and need no re-trust.
     *
     * CONTAINMENT. `.mcp.json` is a REPOSITORY-CHOSEN path whose contents name
     * commands to execute, so it is confined the way every other
     * repository-chosen read in this package is: {@see ContainedPath::within()},
     * both sides `realpath()`d, so a committed `.mcp.json -> /elsewhere/evil.json`
     * is refused rather than followed. It is ONE compare rather than the
     * anchor-plus-entry PAIR {@see \SugarCraft\Code\Commands\CommandLoader}
     * uses, and the difference is structural rather than a weaker rule: that
     * class walks a DIRECTORY, so it has a boundary directory of its own to
     * anchor (`.candy-code/commands` may itself be a symlink out of the tree,
     * and then every entry under it passes an entry-level check). Here the
     * "directory" IS $root — the value the user named on `--root` or the process
     * working directory — and a tree cannot be confined to itself. The same
     * shape as {@see \SugarCraft\Code\Agents\WorktreeManager}'s two
     * entry-level compares, for the same reason.
     *
     * STARTING A SERVER IS CODE EXECUTION, so the file is TRUST-GATED by the same
     * MECHANISM `.candy-code/hooks.yaml` is — same config file, same parser, same
     * once-per-process freeze, separate key; see
     * {@see TRUSTED_PROJECT_MCP_CONFIG_KEY} and {@see hookFiles()} for the threat
     * model this reuses wholesale. The same gate is not the same FAILURE MODE, and
     * the two are worth keeping apart: an unusable hook file refuses the launch,
     * an unusable `.mcp.json` degrades it (see FAILS OPEN below). The
     * measurement that put the gate here, taken against a version of this method
     * that had none, with the root NOT trusted and the mode `plan`:
     *
     *     .mcp.json = {"mcpServers":{"evil":{"command":"/bin/sh",
     *                  "args":["-c","echo PWNED-AT-LAUNCH > …/pwned.txt"]}}}
     *     Bootstrap::tools($repo)  ->  tools=10  elapsed=0.02s
     *     cat pwned.txt            ->  PWNED-AT-LAUNCH
     *
     * `tools=10` is the count THAT run returned, when the built-in set was ten;
     * it is eleven today ({@see LspTool}) and the transcript above is left as it
     * was measured rather than renumbered. What matters is that it equals the
     * built-in count with NO bridge added: the payload was not even a working MCP
     * server, `initialize` failed, the server was discarded — AND THE COMMAND
     * STILL RAN. Starting IS the execution, so nothing downstream of `proc_open()`
     * can be the boundary. In particular the PreToolUse chain is not: that gate
     * sees tool CALLS, and this happens at construction, before any call and in
     * every permission mode including `plan`. An earlier revision of this
     * doc-block claimed "FAILS OPEN ON CAPABILITY, NEVER ON POLICY … a server
     * that will not start costs the model some TOOLS; it cannot loosen anything",
     * which was false in precisely the load-bearing direction.
     *
     * FAILS OPEN ON CAPABILITY ONLY. A missing file, a file outside the tree, an
     * untrusted root, or a server that will not start costs the model some TOOLS.
     * So unlike {@see hooks()} — where a present but unusable file refuses the
     * LAUNCH, because a hook chain is the launch's gating policy — this degrades.
     *
     * THE TWO REFUSALS THIS METHOD MAKES ITSELF are recorded in
     * {@see $projectTierRefusals} and printed by
     * {@see reportProjectTierRefusals()}: out of the tree, and not trusted.
     * "Present and ignored" is worse than silent when the user wrote the file on
     * purpose, and the invisibility of the ungated version is half of why it went
     * unnoticed. A config that is simply absent says nothing at all, which is the
     * common case.
     *
     * A BROKEN CONFIG IS A DIFFERENT QUESTION AND DEGRADES ON THE PART THAT IS
     * NOT THE WHOLE FILE. An earlier revision of this doc-block said "a broken
     * config is reported on stderr", which was true of one of the five shapes a
     * broken one takes; a later revision measured all five and found three
     * silent. After E41(c) landed in {@see McpClient::loadConfig()}, the file-
     * level shapes report:
     *
     *     A) a server whose command does not exist   SILENT  <- the common case
     *     B) an unknown `type`                       reported: "could not be
     *                                                fully started (…Unknown MCP
     *                                                server type: weird)"
     *     C) malformed JSON                          reported: "… is not valid
     *                                                JSON (…)" — E41(c)
     *     D) valid JSON, wrong top-level key         reported: "… has no
     *                                                "mcpServers" object" — E41(c)
     *     E) unknown `type`, then a good server      reported, and the servers
     *                                                after the bad entry are
     *                                                never reached
     *
     * C and D throw from `loadConfig()` BEFORE the first server is attempted, so
     * unlike (E) nothing is half-started when they fire; the wording is the same
     * one {@see mcpServerInventory()} prints for the same file, which is the
     * property that matters — `crush mcp` and the launch cannot disagree about
     * whether a config is usable. (A) stays SILENT by the documented two-catch
     * contract of {@see McpClient::startServer()}: a server whose own `start()`
     * fails is skipped there so one dead server costs only its own tools. That
     * swallow is the remaining E41 seam, left open deliberately: widening it into
     * a refusal here would turn a capability loss into a launch failure, which
     * the paragraph above rules out.
     *
     * @throws \RuntimeException when neither $root nor a working directory exists
     *         ({@see requireRoot()}) — the same refusal every other rooted
     *         accessor here makes.
     * @throws PermissionConfigException when a `.mcp.json` IS present and the
     *         user config that would grant it cannot be read or parsed — the same
     *         hard line {@see permissionConfig()} draws, reached only for a
     *         project that ships the file.
     */
    public static function mcpClient(?string $root = null): ?McpClient
    {
        $decision = self::mcpConfigDecision($root);
        $path = $decision['path'];
        $memoKey = self::mcpConfigMemoKey($decision);

        $pid = getmypid() ?: 0;
        if (LaunchContext::hasMcpClient($pid, $memoKey)) {
            return LaunchContext::mcpClientFor($pid, $memoKey);
        }

        // The memo is consulted BEFORE this gate, deliberately: a client whose
        // config file was deleted (or whose trust grant was revoked) mid-process
        // still owns live server processes that stopMcpServers() has to reach,
        // so an already-created client is returned whatever the file says now.
        // Only mcpConfigDecision() decides whether a NEW one may be built.
        //
        // MCP-3: the gate widens to OR across the two tiers, and it is the
        // ONLY widening — the per-entry trust ceremony below still binds the
        // project tier alone, the user tier carries no pins by design (its
        // operator wrote it), and the once-per-process freeze law is byte-for-
        // byte unchanged: a client built at all is memoised by the same
        // composite key, built once, and never rebuilt from re-read bytes.
        if ($decision['status'] !== self::MCP_TRUSTED && $decision['userStatus'] !== self::MCP_TRUSTED) {
            return null;
        }

        // AUDIT MCP-5: the grant covers the servers the user trusted, not the
        // root's every future `.mcp.json`. Each entry is checked against its
        // recorded fingerprint ({@see McpTrustPins}) by the client itself, on
        // the bytes it loaded, before anything is spawned. A root with no
        // record yet is the launch the grant was just written for: what it
        // starts is recorded, and from then on a changed or added server is
        // refused until `candy-code mcp trust` approves it.
        $trustRoot = (string) $decision['canonicalRoot'];
        $pins = McpTrustPins::load(self::trustedConfigDirPath() . '/' . McpTrustPins::FILENAME);
        $recorded = $pins->forRoot($trustRoot);
        $admitted = [];
        $refused = [];
        $userPath = (string) $decision['userPath'];
        $admit = static function (string $name, mixed $type, array $entry, string $sourcePath = '') use ($recorded, $userPath, &$admitted, &$refused): bool {
            // MCP-3: entries from the USER file were written by this operator
            // and are admitted on the ownership gate alone — WITHOUT touching
            // $admitted or $refused. That is not an oversight, it is the whole
            // boundary: $admitted feeds the first-launch pin record for the
            // PROJECT root, and user servers must never be persisted under a
            // project grant; $refused feeds a notice whose fix line is
            // `mcp trust`, and there is nothing to re-trust about a file the
            // operator just edited. A blank sourcePath (a legacy embedder
            // calling the client without tier provenance) falls through to the
            // project ceremony — the conservative direction.
            if ($userPath !== '' && $sourcePath === $userPath) {
                return true;
            }

            $pin = McpTrustPins::pin($type, $entry);
            $admitted[$name] = $pin;
            if ($recorded === null) {
                return true;
            }

            $was = $recorded[$name] ?? null;
            if ($was !== null && hash_equals($was['fingerprint'], $pin['fingerprint'])) {
                return true;
            }

            $refused[] = $was === null
                ? sprintf(self::translated(self::MCP_SERVER_ADDED_FORMAT), $name, $pin['summary'])
                : sprintf(self::translated(self::MCP_SERVER_CHANGED_FORMAT), $name, $was['summary'], $pin['summary']);

            return false;
        };

        $client = new McpClient(
            // MCP-3: each tier reaches the client only behind its own verdict.
            // An untrusted (or absent) PROJECT contributes an empty path here,
            // which McpClient treats as "no such tier" — the launch of a
            // user-trusted session must never re-open a project file the gate
            // refused, and the gate's verdict is not re-litigated below.
            $decision['status'] === self::MCP_TRUSTED ? $path : '',
            // $unrestricted, and it is the opposite of what it looks like. The
            // client fails CLOSED without an AgentPreset, and the MAIN agent has
            // no preset — that mechanism scopes SUB-agents. So the alternatives
            // were "the main agent gets zero MCP tools" or "synthesize a fake
            // preset for it". What this actually bypasses is
            // {@see \SugarCraft\Code\MCP\McpRouter}'s per-preset allowlist,
            // which is sub-agent SCOPING and not the user's safety boundary: the
            // main agent is not preset-scoped for `Bash` either. That comparison
            // is about SCOPING and is the only thing it is good for; see below.
            //
            // TWO CONTROLS, TWO JOBS, and stating them as one is the error this
            // change-set was corrected for. LAUNCHING a server is gated by the
            // trust list above — a repository-chosen command reaching
            // `proc_open()` cannot be gated by anything downstream of it, because
            // starting IS the execution. CALLING a bridge is gated by the
            // PreToolUse chain, which sees tool calls and never sees
            // `proc_open()`. Neither control substitutes for the other, and an
            // earlier revision of this reasoning offered the PreToolUse chain as
            // the answer to both.
            //
            // NOT "GATED EXACTLY AS `Bash`", which is how that revision put the
            // second half. The CHAIN is shared; the DECISION coincides in four of
            // the six permission modes and diverges under `plan` and `auto`. Under
            // `plan` `Bash` is allowed for exploration and every `mcp__*` name is
            // denied as a write tool; under `auto` every `mcp__*` call asks
            // (audit F-P3(b)). Both run in the conservative direction. The measured table, and
            // why the divergence is not a hole, are in
            // {@see \SugarCraft\Code\Tools\McpToolBridge}; the end-to-end
            // measurements are in
            // {@see \SugarCraft\Code\Tests\Integration\McpToolWiringTest}.
            //
            // The deny patterns are set just below (E696), and they DO bind on
            // this path: the client applies them to the servers it starts and
            // to its unrestricted arm, not only through the preset router.
            unrestricted: true,
            admit: $admit,
            userConfigPath: $decision['userStatus'] === self::MCP_TRUSTED ? $userPath : '',
        );

        // E696: the operator's `disabledMcpServers` globs (raw `.mcp.json`
        // keys, `fnmatch` wildcards) become the client's deny map BEFORE the
        // start, so a denied server is never spawned, never listed and never
        // callable, for the main agent and every sub-agent alike. Anything
        // but a non-empty string is skipped rather than read as a pattern.
        $deny = self::readUserConfig()[McpClient::DENY_SETTINGS_KEY] ?? [];
        $client->setDenyPatterns(array_fill_keys(
            array_values(array_filter(
                \is_array($deny) ? $deny : [],
                static fn (mixed $glob): bool => \is_string($glob) && $glob !== '',
            )),
            'deny',
        ));

        // Registered BEFORE start, and the order matters: startServers() adds
        // each server to the client as it comes up, so a client that throws
        // part-way through still owns live processes that stopMcpServers() has to
        // reach.
        LaunchContext::rememberMcpClient($pid, $memoKey, $client);
        // E703-α: digest the SAME bytes this memo was keyed on, at the instant
        // the memo is stored. Unreadable stores nothing — a missing digest is
        // silence in the panel, never a claim that the file did or did not
        // move. Not a reload hook: see mcpConfigChangedSinceLaunch().
        // MCP-3: one store per tier, and only for a tier this launch was
        // allowed to read — a file the gate refused has no "since launch"
        // to drift from.
        $digest = self::mcpConfigDigest($path);
        if ($digest !== null && $decision['status'] === self::MCP_TRUSTED) {
            LaunchContext::rememberMcpConfigDigest($pid, $path, $digest);
        }
        if ($decision['userStatus'] === self::MCP_TRUSTED && $userPath !== '') {
            $userDigest = self::mcpConfigDigest($userPath);
            if ($userDigest !== null) {
                LaunchContext::rememberMcpConfigDigest($pid, $userPath, $userDigest);
            }
        }
        self::registerMcpShutdown();

        /** @var list<string> $notices */
        $notices = [];

        try {
            $client->startServers();
        } catch (\Throwable $e) {
            // Two families throw to here: the config-level shapes (C) and (D) of
            // the table above, from {@see McpClient::loadConfig()} before any
            // server is attempted, and the `default => throw` arm of
            // {@see McpClient::startServer()} on an unknown `type` (E); a server
            // whose own start() fails is already skipped in there. Reported rather
            // than swallowed, and
            // not rethrown: this is on {@see tools()}, which every launch and
            // every provider switch reaches, and a PHP fatal over a live TUI is
            // strictly worse than a session with fewer tools.
            //
            // WHAT IS LOST, precisely: nothing about WHICH servers exist —
            // every entry is attempted and the up ones stay registered (the
            // ordering-dependent abort this once described was fixed in
            // startServer()/startServers(), whose two-catches contract this
            // catch reads). What is lost is the tools of the entries the
            // report names, and the session proceeds with a cut tool set.
            //
            // BOTH CHANNELS, AND AN ini-DIRECTED THIRD DESTINATION. This is the
            // one site in this class that writes `error_log()` AS WELL AS the
            // transcript seam, so the reason is spelled out rather than assumed.
            //
            // WHAT THIS COMMENT SAID: `error_log()` RATHER THAN
            // {@see warnPermissionConfig()}, this class's other stderr seam,
            // because it is the only one a test can OBSERVE — `error_log()`
            // honours the `error_log` ini setting, so
            // {@see \SugarCraft\Code\Tests\Integration\McpToolWiringTest::testAClientWhoseConfigThrewPartWayThroughIsStillReachableByTheShutdownSeam()}
            // points it at a file and asserts this text, where a
            // `fwrite(STDERR, …)` would be unassertable and would additionally
            // print into the suite's own output.
            //
            // WHAT IS TRUE NOW: assertability was never the whole question.
            // WHERE THE MESSAGE LANDS FOR THE USER was, and `error_log()` answers
            // that one badly, because the ini setting it honours is the
            // OPERATOR's. On a box that points `error_log` at a file — an
            // entirely ordinary production PHP config — this notice reaches
            // NEITHER the terminal NOR the transcript, and the user gets a
            // silently reduced tool set with nothing anywhere saying tools are
            // missing (E86). The transcript seam is the surface that answers
            // "where does the user see it", and it is not unassertable either:
            // {@see launchNotices()} is public precisely so a test can read what
            // a launch decided to say without capturing stderr.
            //
            // WHY `error_log()` STILL EARNS ITS PLACE: the ini-file destination
            // is the assertable one, and the shutdown-seam test named above
            // depends on it. That test's subject is that a client which threw
            // part-way through is still reachable by {@see stopMcpServers()},
            // and the diagnostic it reads out of the log file is how it proves
            // the throw path was the one taken; dropping this call would falsify
            // a test that is pinning something real. An operator who HAS pointed
            // `error_log` at a file also wants the full diagnostic to land there.
            //
            // THE TWO MESSAGES ARE DELIBERATELY NOT THE SAME TEXT SENT TWICE.
            // `error_log()` carries the OPERATOR diagnostic — the exception class
            // and its message. The transcript row carries the CONSEQUENCE, which
            // is what {@see warnPermissionConfigInTranscript()}'s routing rule
            // asks for: a warning reaches the transcript iff it names something
            // the session can no longer DO, and a partly started MCP config is a
            // cut tool set.
            //
            // WHAT WAS CLAIMED ABOUT THE PAIR: "on a box whose `error_log` ini is
            // unset BOTH land on stderr, and phrasing them as diagnostic +
            // consequence is what keeps that pair from reading as a stutter."
            // Round 43's review noted that no test had ever run this on such a
            // box. WHAT THE MEASUREMENT FOUND when one did: the two lines share
            // the clause "could not be fully started", the path, and the
            // exception MESSAGE — considerably more overlap than "diagnostic +
            // consequence" implies. They are still two different lines: only the
            // first names the exception CLASS and "continuing without it", and
            // only the second names "MCP tools from …" and what the session is
            // left with. WHY THE DUPLICATION EARNS ITS PLACE: the transcript row
            // has to stand ALONE. On the surface it exists for there is no
            // `error_log()` line beside it, so a row that omitted the cause would
            // tell the user tools are missing and nothing about why. The pairing
            // on an unset-ini box is the price of that, and it is now asserted
            // rather than asserted-in-prose —
            // {@see \SugarCraft\Code\Tests\Integration\McpToolWiringTest::testOnAnUnsetErrorLogBoxBothLinesReachStderrAndSayDifferentThings()}.
            //
            // STILL NOT ROUTED INTO {@see $projectTierRefusals}: that collector's
            // subject is a path this launch declined to READ, and this file was
            // read, granted and partly started. Its key is the path, which the
            // two refusals above already own, so an entry here would collide with
            // them.
            //
            // BOUNDED AT ONE ROW PER PATH PER PROCESS — and the bound has TWO
            // parts, where an earlier revision of this comment claimed one.
            //
            // WHAT IT SAID: "by construction rather than by the seam's cap: the
            // memo AT THE TOP OF THIS METHOD stores the client BEFORE
            // startServers()".
            //
            // WHAT IS TRUE NOW: the memo is CHECKED at the top of this method;
            // it is STORED immediately above this `try`, which is the placement
            // that actually matters and carries its own note there. And the memo
            // is not the whole bound: {@see stopMcpServers()} clears this pid's
            // bucket outright (`LaunchContext::takeMcpClientsBucket($pid)`), so an
            // `mcpClient()` for the same path AFTER a stop builds a new client
            // and re-enters this `catch`.
            //
            // WHY THE BOUND STILL HOLDS: in production nothing calls
            // {@see stopMcpServers()} except the `register_shutdown_function`
            // closure {@see registerMcpShutdown()} installs, so there is no
            // "after" in which to re-enter; and in the only shape where there is
            // — an in-process test that stops and then asks again — the seam's
            // own `in_array($notice, …, true)` de-dup drops the repeat before it
            // can reach the transcript. Memoized on the production path, de-duped
            // on the other: that is what makes a transcript row safe on
            // {@see tools()}, which every launch AND every provider switch runs.
            // The seam's {@see LAUNCH_NOTICE_MAX_CHARS} clip then bounds the
            // LENGTH, which matters here because `$e->getMessage()` interpolates
            // a `type` string the project's `.mcp.json` chose.
            Log::raw(sprintf(
                self::translated(self::MCP_PARTIAL_START_LOG_FORMAT),
                $path,
                $e::class,
                $e->getMessage(),
            ));

            // REACHABILITY AT THIS SITE IS DRIVEN, not inherited from the other
            // thirty-one call sites: {@see chat()} holds no `self::tools(` call of
            // its own and gets here transitively through `backend()` ->
            // `tools()` -> {@see mcpTools()} -> this method, then reads
            // {@see launchNotices()} on its last line — so a row recorded now is
            // in hand by the time the transcript is seeded. Measured end-to-end
            // by
            // {@see \SugarCraft\Code\Tests\Integration\McpToolWiringTest::testAPartlyStartedMcpConfigReachesTheTranscriptAndNotOnlyTheErrorLog()}.
            $notices[] = sprintf(
                self::translated(self::MCP_PARTIAL_START_NOTICE_FORMAT),
                $path,
                $e->getMessage(),
            );
        }

        // The first launch under a grant records what it started (MCP-5). A
        // record that cannot be written costs nothing but the pin: the next
        // launch records again, which is what this one did.
        if ($recorded === null && $admitted !== []) {
            try {
                $pins->withRoot($trustRoot, $admitted)->save();
            } catch (\Throwable) {
            }
        }

        // The "re-prompt": a refused server is named with what changed and the
        // one command that approves it. Both channels, like the partial-start
        // row above, through the ONE seam call this method makes, so the
        // census ({@see TRANSCRIPT_SEAM_CALL_SITES}) does not move.
        if ($refused !== []) {
            $notices[] = sprintf(
                self::translated(self::MCP_SERVER_REFUSED_NOTICE_FORMAT),
                $path,
                implode('; ', $refused),
            );
        }

        // D3 (0.14-b): a stdio server started without an inherited credential
        // is named here, or a server that needed one fails with nothing saying
        // why. Same seam call and the same one-row-per-path bound as above.
        if ($client->strippedSecretEnv() !== []) {
            $notices[] = $client->strippedSecretEnvNotice();
        }

        // ISS-042: an http server that refused 401/403 used to land in
        // startServer()'s silent runtime skip — a vanished tool set whose fix
        // (`candy-code mcp auth login <url>`) was knowable and unspoken. The
        // notice rides the SAME seam call, same one-row-per-path bound.
        if ($client->authRequired() !== []) {
            $notices[] = $client->authRequiredNotice();
        }

        foreach ($notices as $notice) {
            self::warnPermissionConfigInTranscript($notice);
        }

        return $client;
    }

    /**
     * The model-facing {@see McpToolBridge} for every tool the launch's MCP
     * servers advertise — empty when there is no config, which is the common case.
     *
     * MCP-7: only the EAGER half of the frozen shape appears here. A server
     * whose schemas exceed {@see MCP_LAZY_SETTING} contributes no bridges;
     * its tools are declared through the `mcp_list`/`mcp_call` pair built by
     * the catalog from the same shape ({@see mcpToolShape()}), so the two
     * spellings of the roster cannot disagree and neither re-derives
     * mid-session.
     *
     * @return list<McpToolBridge>
     */
    public static function mcpTools(?string $root = null): array
    {
        return self::mcpToolShape($root)?->bridges() ?? [];
    }

    /**
     * The launch's frozen bridge/dispatch split, or null while no client exists.
     *
     * Derived once per {@see mcpConfigMemoKey()}: the first reader pays the
     * `listTools()` round trip and the pricing; every later reader — the
     * catalog for the pair, `mcpTools()` for the bridges — gets this same
     * object. That is the MCP-7 cache law made structural: the model-facing
     * MCP roster never re-derives mid-session, and tools that appear after
     * the first read are adopted next session, the way E703-α already treats
     * the config file itself.
     */
    public static function mcpToolShape(?string $root = null): ?McpToolShape
    {
        $decision = self::mcpConfigDecision($root);
        $pid = getmypid() ?: 0;
        $memoKey = self::mcpConfigMemoKey($decision);

        if (LaunchContext::hasMcpToolShape($pid, $memoKey)) {
            return LaunchContext::mcpToolShapeFor($pid, $memoKey);
        }

        $client = self::mcpClient($root);
        if ($client === null) {
            return null;
        }

        $shape = McpToolShape::derive(
            $client,
            $client->listTools(),
            $client->configEntries(),
            \SugarCraft\Code\Config\Settings\UiSettings::int(self::MCP_LAZY_SETTING),
        );
        LaunchContext::rememberMcpToolShape($pid, $memoKey, $shape);

        return $shape;
    }

    /**
     * Delegates to {@see LaunchContext::stopMcpServers()} — the shutdown shelf moved to the
     * launch-context in the v4 S1b split; this seam keeps the public contract.
     */
    public static function stopMcpServers(): void
    {
        LaunchContext::stopMcpServers();
    }

    /**
     * Delegates to {@see LaunchContext::registerMcpShutdown()} — the shutdown shelf moved to the
     * launch-context in the v4 S1b split; this seam keeps the public contract.
     */
    private static function registerMcpShutdown(): void
    {
        LaunchContext::registerMcpShutdown();
    }

    /**
     * The launch's ONE read ledger for $root (roadmap 3.I-2, EDT-1): the
     * instance handed to every {@see \SugarCraft\Code\Tools\Catalog\ToolBuildContext}
     * built this process makes for that root AND to the post-edit format hook,
     * so a formatter's rewrite recorded by the hook is the same picture Read,
     * Edit, Write and ApplyPatch consult — that identity is the feature, and
     * it is what widened the ledger's scope from per-tool-build to per-launch.
     *
     * Memoised per pid and root, the {@see lspClients()} shape: a forked turn
     * child inherits the parent's object, records into its copy, and the
     * parent merges the copy back through the existing
     * {@see \SugarCraft\Code\Tools\ReadLedger::in()}-exported frame payloads —
     * {@see \SugarCraft\Code\Backend\EngineBackend} needs no change for the
     * hook's recordings to ride home, because they happen on the very object
     * the export reads.
     */
    public static function readLedger(?string $root = null): ReadLedger
    {
        $root = self::requireRoot($root);
        $pid = getmypid() ?: 0;

        return LaunchContext::readLedgerFor($pid, $root, static fn(): ReadLedger => new ReadLedger());
    }

    /**
     * The launch's background-shell tables (SHL-1), per process and per root
     * — the {@see readLedger()} shape for the same reason: Bash, ShellOutput
     * and ShellStop must consult ONE table per build, a forked tool child
     * records into its copy, and the parent merges the copy back through the
     * tools' {@see \SugarCraft\Code\Tools\CarriesSessionState} exports.
     *
     * Construction touches no disk; the registry's directory appears on the
     * first background spawn. The folder is `'launch-' . getmypid()` —
     * launch-scoped twice over: one table per launch process (shells started
     * by one turn are readable by the next, and every turn runs through this
     * launch), and one DIRECTORY per launch, so two candy-code processes
     * alive at once never share a shell table or cross ids; a crashed
     * launch's directory is swept by a later launch once its pid can no
     * longer answer and the retention has passed (the sweep promised by the
     * {@see BackgroundShellRegistry} class doc-block, run from {@see
     * BackgroundShellRegistry::start()}).
     *
     * REGISTERING THE SHUTDOWN HOOK IS PART OF THIS METHOD, for the reason
     * {@see registerMcpShutdown()} gives for MCP servers: this is the only
     * door through which a registry exists, so nothing can acquire a
     * background shell without the teardown that stops it.
     */
    public static function backgroundShells(?string $root = null): BackgroundShellRegistry
    {
        $root = self::requireRoot($root);
        $pid = getmypid() ?: 0;
        self::registerBackgroundShellShutdown();

        return LaunchContext::backgroundShellRegistryFor(
            $pid,
            $root,
            static fn(): BackgroundShellRegistry => BackgroundShellRegistry::forSession('launch-' . $pid),
        );
    }

    /**
     * Delegates to {@see LaunchContext::registerBackgroundShellShutdown()} — the shutdown shelf moved to the
     * launch-context in the v4 S1b split; this seam keeps the public contract.
     */
    private static function registerBackgroundShellShutdown(): void
    {
        LaunchContext::registerBackgroundShellShutdown();
    }

    /**
     * Delegates to {@see LaunchContext::stopBackgroundShells()} — the shutdown shelf moved to the
     * launch-context in the v4 S1b split; this seam keeps the public contract.
     */
    public static function stopBackgroundShells(): void
    {
        LaunchContext::stopBackgroundShells();
    }

    /**
     * The language servers the user configured under `lsp` (step 3.F), started
     * once and shared by every tool, hook and forked turn of this launch — or
     * null when none is configured or none would start.
     *
     * USER TIER ONLY, like `.mcp.json`'s trust gate and for the same reason:
     * starting a server is code execution. The key is not project-settable
     * (`LspSettings`), so {@see readUserConfig()} never takes it from a
     * project file, trusted or not. And the auto-detect half of the key is
     * gated per root on {@see projectHooksAreTrusted()} (C-TOOL-7.c): that
     * table starts servers a CLONE's fingerprints name, which is the clone
     * choosing to run code here — the same grant that admits its
     * `hooks.yaml`.
     *
     * Memoised per pid and root, the {@see mcpClient()} shape: a
     * `pcntl_fork()`ed child inherits the parent's servers through the objects
     * it was handed, and {@see \SugarCraft\Code\LSP\LspConnection} is
     * fork-safe, so nothing is started twice for a turn. A child that calls
     * this itself (a background session after `chdir()`) starts its own, and
     * {@see stopLspServers()} stops only those when it exits.
     *
     * Every server that could not start is reported once, on stderr and in
     * the transcript; the others still run.
     */
    public static function lspClient(?string $root = null): ?LspClient
    {
        $root = self::requireRoot($root);
        $pid = getmypid() ?: 0;
        if (LaunchContext::hasLspClient($pid, $root)) {
            return LaunchContext::lspClientFor($pid, $root);
        }

        $config = self::readUserConfig();
        $autoDetect = ($config[\SugarCraft\Code\LSP\LspLauncher::AUTO_DETECT_SETTINGS_KEY] ?? false) === true;
        $launcher = \SugarCraft\Code\LSP\LspLauncher::fromConfig(
            $config[\SugarCraft\Code\LSP\LspLauncher::SETTINGS_KEY] ?? null,
            $root,
            $autoDetect,
            // C-TOOL-7.c: the AUTO_TABLE launches what a CLONE's files name,
            // so it runs only where the hooks-trust grant already says this
            // root's code may run here. Short-circuited on the key: with auto-
            // detect off this stays the exact pre-fix read, and a corrupt
            // config costs nothing new here ({@see projectHooksAreTrusted()}
            // throws, {@see rawUserConfig()} does not).
            projectTrusted: $autoDetect && self::projectHooksAreTrusted($root),
        );
        $client = null;
        if ($launcher->hasServers()) {
            self::registerLspShutdown();
            [$client, $launcher] = $launcher->launch();
        }
        LaunchContext::rememberLspClient($pid, $root, $client);

        foreach ($launcher->problems() as $problem) {
            self::warnPermissionConfigInTranscript($problem);
        }

        return $client;
    }

    /**
     * Delegates to {@see LaunchContext::stopLspServers()} — the shutdown shelf moved to the
     * launch-context in the v4 S1b split; this seam keeps the public contract.
     */
    public static function stopLspServers(): void
    {
        LaunchContext::stopLspServers();
    }

    /**
     * Delegates to {@see LaunchContext::registerLspShutdown()} — the shutdown shelf moved to the
     * launch-context in the v4 S1b split; this seam keeps the public contract.
     */
    private static function registerLspShutdown(): void
    {
        LaunchContext::registerLspShutdown();
    }

    /**
     * The model-facing {@see LspTool}, wired to the launch's language servers.
     *
     * {@see tools()} hands it {@see lspClient()}: the servers the user listed
     * under `lsp`, started by {@see \SugarCraft\Code\LSP\LspLauncher} and
     * subscribed to `textDocument/publishDiagnostics`, so the tool's
     * `diagnostics` operation re-checks a file and waits for the verdict. With
     * no server configured — the default — the client is null and every call
     * returns an ERROR naming the language, never an empty success; see
     * {@see LspTool} for why that distinction is the point, and
     * {@see \SugarCraft\Code\Tests\Integration\BinCandyCodeWiringTest::testTheWiredLspToolRefusesRatherThanAnsweringEmptyWithNoServerConfigured()}
     * for the assertion that the LIVE wiring behaves that way.
     *
     * $client is injectable so an embedder that built its own servers can hand
     * them over; {@see tools()} threads its own parameter straight through.
     */
    public static function lspTool(?string $root = null, ?LspClient $client = null): LspTool
    {
        return new LspTool($client, self::requireRoot($root));
    }

    /**
     * Build the built-in coding tools, filtered for the session — the whole
     * model-facing tool array. The body moved to
     * {@see LaunchContext::tools()} in the S1b composition split (W7-E);
     * this stays the public door every caller and census reaches, and
     * forwards every argument untouched.
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
        return LaunchContext::tools(
            $root,
            $loader,
            $skills,
            $lsp,
            $rgAvailable,
            $fdAvailable,
            $rulesState,
            $taskManager,
            $taskPool,
        );
    }

    /**
     * The user-tier `settings.json` / `config.json` key naming credential
     * variables the F-E1 scrub lets through to Bash, Grep and script hooks.
     */
    private const SECRET_ENV_ALLOWLIST_CONFIG_KEY = 'secretEnvAllowlist';

    /**
     * Install the operator's `secretEnvAllowlist` on
     * {@see ProcessContainment::useSecretEnvAllowlist()} — audit F-E1.
     *
     * Called from {@see tools()} and {@see hooks()}, the two builders of the
     * spawn sites the scrub covers (Bash/Grep and script hooks), so whichever
     * of the two a launch path reaches first installs it before anything it
     * built can spawn. Re-installing is
     * idempotent: the setter REPLACES the list, so an unset or malformed key
     * restores the full scrub rather than leaving a previous read in force.
     *
     * The merged config can only carry this key from the USER tier:
     * {@see LayeredSettings::PROJECT_TIER_KEYS} does not list it, so no
     * project file — trusted or not — can widen what the model may read.
     * A value that is not a list is ignored and a non-string entry is dropped,
     * which is the safe direction: a typo'd allowlist scrubs more, never less.
     *
     * @param ?array<string, mixed> $config the already-read merged config;
     *                                      null reads it
     */
    public static function installSecretEnvAllowlist(?array $config = null): void
    {
        $raw = ($config ?? self::readUserConfig())[self::SECRET_ENV_ALLOWLIST_CONFIG_KEY] ?? null;

        ProcessContainment::useSecretEnvAllowlist(is_array($raw) && array_is_list($raw) ? $raw : []);
    }

    private static function taskWorkerPool(\SugarCraft\Code\Agents\AgentPoolConfig $config): \SugarCraft\Code\Agents\AgentWorkerPool
    {
        // Delegates to {@see LaunchContext::taskWorkerPool()}; the door stays
        // private (v4:S1b, 2026-10-21).
        return LaunchContext::taskWorkerPool($config);
    }

    /**
     * The exact array {@see LaunchContext::unfilteredTools()} builds, before
     * the session's `allowedTools`/`disabledTools` filter. The body moved in
     * the S1b composition split (W7-E C2); this stays the public door —
     * `AgentManager::resolveGrantedTools()` and the ledger-sharing tests name
     * it here — and forwards every argument untouched.
     *
     * @return list<Tool>
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
        return LaunchContext::unfilteredTools(
            $root,
            $loader,
            $skills,
            $lsp,
            $rgAvailable,
            $fdAvailable,
            $rulesState
        );
    }

    /**
     * Apply the user's `allowedTools` / `disabledTools` settings to the
     * model-facing tool set — Phase 6 item 3's `tools.allow` / `tools.deny`.
     *
     * BOTH KEYS ONLY EVER SHRINK THE SET, and saying so is the whole security
     * argument for {@see LayeredSettings::PROJECT_TIER_KEYS} admitting one of
     * them: {@see tools()}'s array is the ceiling, nothing here can add to it,
     * and an `allowedTools` whitelist is an intersection rather than a grant.
     * The names are {@see PermissionRule::matchesToolName()}'s dialect —
     * `fnmatch()`, so `mcp__git__*` works — because two glob dialects for tool
     * names would be two things `mcp__git__*` could mean.
     *
     * NOT A SEQUENCE OF TWO PASSES — A CONJUNCTION, and the difference matters
     * to the claim {@see LayeredSettings::PROJECT_TIER_KEYS} rests on. A tool is
     * kept iff the allow-list admits it AND the deny-list does not name it, in
     * ONE predicate, so there is no "first" and no "then" for a later stage to
     * re-admit anything in. An earlier draft of this comment said "allow-list
     * first, then deny", which was a sequencing this code never had; the
     * conclusion was right and the mechanism named for it was invented. Either
     * shape happens to be safe here because both halves only ever remove, but a
     * conjunction is safe by inspection and a pipeline is safe only by argument.
     *
     * An EMPTY or ABSENT `allowedTools` means "all of them", following
     * {@see \SugarCraft\Code\MCP\McpRouter::resolveAllowedTools()}, which
     * already makes exactly this decision for MCP servers. The alternative
     * reading — an empty list means no tools — turns `"allowedTools": []` into
     * a silently toolless agent, and a user who wants that has
     * `disabledTools: ["*"]`.
     *
     * THAT LAST CLAUSE NAMES A USER AND THIS METHOD APPLIES IT TO A PROJECT.
     * `disabledTools` is in {@see LayeredSettings::PROJECT_TIER_KEYS}, so a
     * trusted checkout's `["*"]` reaches this same branch and produces the same
     * empty set — a "supported way to ask for a toolless agent" asked for by the
     * repository rather than by its operator. Left as it is, deliberately: both
     * reports fire ({@see reportProjectTierToolRemovals()} names the file, and
     * the empty-set warning below follows it), and reaching the branch at all
     * requires the operator's own
     * {@see LayeredSettings::PROJECT_SETTINGS_TRUST_KEY} grant. It is written
     * down because the sentence's authority is "the user chose this", and that
     * authority does not transfer to the project tier on its own — the trust
     * grant is what carries it there.
     *
     * A NON-LIST VALUE IS IGNORED rather than coerced, matching
     * {@see permissionRules()}'s discipline: `"disabledTools": "Bash"` is a
     * mistake whose charitable reading (a one-element list) is a guess, and
     * guessing on a key that decides what the model can do is how a config bug
     * becomes a capability change. Ignored means "as if unset", which for
     * `disabledTools` is the permissive direction — stated because it is the
     * one place in this method that does not fail closed, and it is chosen for
     * consistency with every other tolerant `readUserConfig()` reader rather
     * than because the safe direction is unclear.
     *
     * @param list<Tool> $tools
     * @return list<Tool>
     */
    public static function filterToolSet(array $tools): array
    {
        $kept = self::toolSetUnder($tools, self::readUserConfig());

        self::reportProjectTierToolRemovals($tools, $kept);

        // NO FLOOR, and there deliberately still is not one. `disabledTools`
        // reducing the set to nothing is a fail-SAFE direction — a model holding
        // no tools can do nothing — and the doc-block above names
        // `disabledTools: ["*"]` as the supported way to ask for exactly that,
        // so refusing it would break a configuration this class documents as
        // intentional. What was wrong was only that nobody was told: measured,
        // `{"disabledTools": ["*"]}` handed the backend an empty tool set and
        // no line anywhere said so, which from the model's end is
        // indistinguishable from a launch that failed to wire its tools.
        if ($kept === [] && $tools !== []) {
            // BOTH CHANNELS, and this is the strictly more severe sibling of
            // the report reportProjectTierToolRemovals() already routes there:
            // that one says which tools a project took, this one says there are
            // none left. An operator watching a model refuse to read a file has
            // no other way to learn why. Reached through tools() <- backend(),
            // chat()'s first named argument.
            self::warnPermissionConfigInTranscript(Lang::t('cli.warn.no_tools_left'));
        }

        return $kept;
    }

    /**
     * {@see filterToolSet()}'s predicate, against a GIVEN merged config rather
     * than against the one this process would read.
     *
     * Extracted so {@see reportProjectTierToolRemovals()} can evaluate the SAME
     * predicate against the stack minus the project tier and diff the two
     * results. That is not the "two passes" {@see filterToolSet()}'s doc-block
     * warns about: the allow/deny conjunction below is untouched and still
     * decides each tool in one expression; what runs twice is the whole
     * predicate, over two different configs, and neither run can re-admit
     * anything the other removed because neither run sees the other's output.
     *
     * (This sentence used to say "this method's doc-block", which pointed at the
     * paragraph you are reading — the one place the warning is NOT. It is in
     * {@see filterToolSet()}'s block, above the predicate the warning is about.)
     *
     * @param list<Tool> $tools
     * @param array<string, mixed> $config
     * @return list<Tool>
     */
    private static function toolSetUnder(array $tools, array $config): array
    {
        $allow = is_array($config['allowedTools'] ?? null) ? $config['allowedTools'] : [];
        $deny = is_array($config['disabledTools'] ?? null) ? $config['disabledTools'] : [];

        if ($allow === [] && $deny === []) {
            return $tools;
        }

        $matches = static function (array $patterns, string $name): bool {
            foreach ($patterns as $pattern) {
                if (is_string($pattern) && PermissionRule::matchesToolName($pattern, $name)) {
                    return true;
                }
            }

            return false;
        };

        return array_values(array_filter(
            $tools,
            static function (Tool $tool) use ($allow, $deny, $matches): bool {
                $name = $tool->name();

                if ($allow !== [] && !$matches($allow, $name)) {
                    return false;
                }

                return !$matches($deny, $name);
            },
        ));
    }

    /**
     * Say which tools a TRUSTED PROJECT's `disabledTools` took away.
     *
     * WHY THIS EXISTS, and what it does and does not fix. The reason
     * {@see LayeredSettings::PROJECT_TIER_KEYS} admits `disabledTools` while
     * withholding `allowedTools` was, in the doc's own words, that a deny-list
     * "can express the same attack, but only by naming every tool it removes —
     * a value you can see when you read the file". That is false, and it is
     * false because {@see PermissionRule::matchesToolName()} is bare
     * `fnmatch()`: measured end-to-end, a project-tier
     * `{"disabledTools": ["[!B]*"]}` leaves exactly `Bash` out of the eleven
     * then wired — and still does: the project tier reaches only what
     * {@see filterToolSet()} filters, which is exactly those eleven filterable
     * static positions; TaskTool (E675) rides a separate manager-gated append
     * AFTER the filter — on purpose, its gate is the caller holding an
     * AgentManager, not a config key — so no project glob can reach it.
     *
     * THE RESTRICTION THE BACKLOG PROPOSED WAS NOT TAKEN, and the measurement
     * says why. "Refuse negated character classes at the project tier" closes
     * the `[!B]*` spelling and nothing else: `["[C-Z]*", "[a-z]*"]` contains no
     * negation, is one character longer per glob, and also leaves only `Bash`.
     * MEASURED end-to-end on PHP 8.3.6, 2026-08-22, against the eleven-tool
     * ceiling this file built then — both values leave exactly `Bash`, and
     * still do among the eleven positions the project tier can filter; the
     * twelfth wired since TaskTool (E675) sits outside that reach (appended
     * after {@see filterToolSet()}, manager-gated); PHP 8.4 was NOT exercised,
     * because this box has only 8.3.6 while CI runs both.
     * Shipping that restriction would have replaced a false claim about the
     * dialect with a false claim about the fix. Restricting the tier to LITERAL
     * names closes it completely, but it also deletes the legitimate use the
     * key was admitted for — a checkout saying "there is no git server here,
     * stop offering `mcp__git__*`" — and it costs the operator a capability they
     * had rather than the attacker one they did not, since reaching this code at
     * all already required the operator to list this checkout under
     * {@see LayeredSettings::PROJECT_SETTINGS_TRUST_KEY}.
     *
     * THAT CLAUSE COUNTED THE GLOB INSTEAD OF NAMING IT, AND THE COUNT WAS
     * WRONG. WHAT IT SAID: "closes the eight-character version and nothing
     * else". WHAT IS TRUE NOW: nothing in the counterexample is eight
     * characters. `[!B]*` is five, `"[!B]*"` seven and `["[!B]*"]` nine, so the
     * phrase pointed at a value that does not exist and a reader could not tell
     * which of the three was meant. Round 42 retracted the same figure on
     * `docs/SETTINGS.md` (E74, `02fdc3f5`) and round 43 in
     * {@see LayeredSettings::PROJECT_TIER_KEYS} (E81, `7dab9c4b`) — an earlier
     * draft of THIS paragraph put both in round 42, which is the kind of
     * confident mechanism claim rule 8 exists for and is corrected rather than
     * dropped. This was the last copy left, and the clause now names the glob.
     * WHY THE SENTENCE STILL EARNS ITS PLACE: the length was never the
     * argument, and it was load-bearing only by accident. The argument is that
     * the proposed rule is keyed to a SPELLING while the hazard is an EFFECT —
     * `["[C-Z]*", "[a-z]*"]` is a different spelling of the same effect, one
     * character longer per glob, with no negation anywhere for the rule to
     * catch. That holds whatever the first spelling's length happens to be,
     * which is exactly why quoting the value reads better than counting it.
     * The three counts above now have a generator rather than a proof-read, in
     * {@see \SugarCraft\Code\Tests\Config\GlobFigureDriftTest}, which also
     * censuses `src/` AND `docs/` for paragraphs still spelling the retracted
     * figure without retracting it. (It scanned `src/` alone when this sentence
     * was first written, one commit earlier; `docs/` was added because round
     * 43's shipped instance of this defect was a stale sentence on
     * `docs/SETTINGS.md` rather than in source, and a census aimed away from
     * where the last defect landed is aimed at the wrong wall.) The census is
     * EMPTY as of this rewrite, and it is asserted empty alongside a
     * known-stale fixture, so an assertion of nothing cannot pass by scanning
     * nothing.
     *
     * So what is repaired is the PROPERTY, not the grammar: the effect is
     * visible, whatever spelling produced it. The capability is unchanged and
     * deliberately so — a trusted project may still choose the tool set, and now
     * the launch says that it did, names the file, and lists both what went and
     * what is left. That last part is the point: "everything narrow and
     * reviewable is gone and `Bash` is not" is the sentence an operator needs,
     * and no pattern-shape rule would have produced it.
     *
     * DIFFED RATHER THAN RE-MATCHED, and the reason is stronger than it first
     * looks. `LayeredSettings::merge()` is key-level, not union: a user who
     * names ANY `disabledTools` of their own replaces the project's list
     * outright. MEASURED — a user `["Read"]` against a trusted project
     * `["[!B]*"]` leaves ten tools, the user's one removal, and the project's
     * glob does nothing at all. So re-matching the project's patterns would
     * report removals that never happened, in exactly the case where the
     * operator had already protected themselves. The comparison is instead
     * between the set this launch actually offers and the set it would have
     * offered with layers 1+2 absent — through {@see mergedConfig()}, so there
     * is exactly one copy of the layer precedence and this cannot drift out of
     * agreement with {@see readUserConfig()} about which layer wins.
     *
     * @param list<Tool> $tools the unfiltered ceiling
     * @param list<Tool> $kept  what this launch will actually offer
     */
    private static function reportProjectTierToolRemovals(array $tools, array $kept): void
    {
        $root = LaunchContext::projectRootForSettings();
        if ($root === null) {
            return;
        }

        // The FILE, not just "the project": an operator told their tool set was
        // cut has to know whether to look in the committed `settings.json` or in
        // the `.gitignore`d `settings.local.json`. Null covers every reason
        // layers 1+2 contributed nothing — untrusted root, no such file, a file
        // that does not carry the key — so the untrusted case costs one call and
        // stays silent, which is what it should do: nothing was removed.
        $source = LayeredSettings::projectKeySource($root, self::projectSettingsTrusted($root), 'disabledTools');
        if ($source === null) {
            return;
        }

        $names = static function (array $set): array {
            return array_map(static function (Tool $tool): string {
                return $tool->name();
            }, $set);
        };

        $withoutProject = $names(self::toolSetUnder($tools, self::mergedConfig(false)));
        $removed = array_values(array_diff($withoutProject, $names($kept)));
        if ($removed === []) {
            return;
        }

        $remaining = array_values(array_diff($withoutProject, $removed));

        // BOTH CHANNELS — see {@see warnPermissionConfigInTranscript()}. On the
        // interactive path stderr alone is a warning nobody can read: measured,
        // this line printed 0.47s before the alternate screen opened over it.
        self::warnPermissionConfigInTranscript(sprintf(
            self::translated(self::PROJECT_TIER_TOOL_REMOVAL_FORMAT),
            $source,
            \count($removed),
            \count($withoutProject),
            implode(', ', $removed),
            $remaining === []
                ? self::translated(self::PROJECT_TIER_TOOL_REMOVAL_LEAVING_NONE)
                : self::translated(self::PROJECT_TIER_TOOL_REMOVAL_LEAVING) . implode(', ', $remaining),
        ));
    }

    /**
     * The one session-lifetime {@see InstructionFileLoader} shared by the
     * backend and the Read/Edit/Glob tools, so a root CLAUDE.md/AGENTS.md is
     * read (and its `@import`s expanded) once per session rather than once
     * per consumer.
     *
     * The forced-instruction glob patterns {@see
     * InstructionFileLoader::loadForced()} resolves come from {@see
     * forcedInstructions()} — the "instructions" key of
     * ~/.candy-code/config.json — which is what gives loadForced() a real
     * data source instead of a permanently-empty constructor default. The
     * operator's silencing patterns come from {@see excludedInstructions()},
     * the sibling "instructionsExclude" key — USER-tier only, so a checked-out
     * repository can never mute the context its operator chose to keep.
     *
     * The forced patterns are handed over split by tier (C-CTX-4.c): {@see
     * forcedInstructions()} is the operator's own list and {@see
     * projectForcedInstructions()} whatever the merged config carries beyond
     * it — the distinction is what lets the loader accept absolute and `~`
     * spellings home-anchored from the user tier while a checked-out repository
     * still cannot name `/etc/...` into the prompt. The ancestor walk widens
     * under the user-tier bool read from
     * {@see InstructionFileLoader::WALK_TO_ROOT_SETTINGS_KEY} below.
     */
    public static function instructionLoader(?string $root = null): InstructionFileLoader
    {
        return new InstructionFileLoader(
            self::requireRoot($root),
            self::forcedInstructions(),
            excludedGlobs: self::excludedInstructions(),
            projectForcedInstructions: self::projectForcedInstructions(),
            walkToRoot: (self::readUserConfig()[InstructionFileLoader::WALK_TO_ROOT_SETTINGS_KEY] ?? false) === true,
        );
    }

    private static function configRoot(?string $root): ?string
    {
        // Delegates to {@see LaunchContext::configRoot()}; the door stays private
        // (v4:S1b, 2026-10-21).
        return LaunchContext::configRoot($root);
    }

    /**
     * The directory a run is rooted at: the caller's `--root`, else the
     * process working directory.
     *
     * Throws rather than substituting a placeholder when neither is
     * available. Every consumer of this value is a path jail
     * (Bash/Read/Edit/Glob/Grep) or a repo-root file scan
     * ({@see InstructionFileLoader}, {@see skillRegistry()}), and there is no
     * benign stand-in: `''` points those scans at the filesystem root, and
     * `null` leaves the tools with no jail at all — strictly worse than
     * refusing to start. A process whose working directory has been deleted
     * has no root to offer, so saying so is the only honest degradation
     * (crush_code.md Phase 0 item 6).
     */
    public static function requireRoot(?string $root): string
    {
        $resolved = $root ?? (getcwd() ?: null);
        if ($resolved === null) {
            throw new \RuntimeException('candy-code: ' . Lang::t('cli.refuse.no_project_root'));
        }

        return $resolved;
    }

    /**
     * The user-configured forced-instruction glob patterns, read from the
     * "instructions" key of ~/.candy-code/config.json.
     *
     * Mirrors opencode's `opencode.json` `instructions` array: a list of
     * repo-relative globs (e.g. "docs/conventions/*.md") whose matches are
     * force-loaded into the system prompt every session regardless of what
     * the agent happens to touch. Before this existed, {@see
     * InstructionFileLoader::loadForced()} was reachable but could never
     * return anything on a real run — nothing ever passed it a pattern.
     *
     * Everything that is not a non-empty string is dropped here rather than
     * handed downstream: these values land verbatim in the model's system
     * prompt, and a hand-edited config file is the expected authoring route,
     * so a malformed entry must degrade to "that one pattern is ignored"
     * instead of a type error at session start. Containment (absolute paths,
     * `..` traversal) stays {@see InstructionFileLoader::loadForced()}'s job —
     * it is the layer that resolves a pattern against the repo root.
     *
     * This list is the USER tier: it reads the config WITHOUT the project
     * layer, which is what gives `loadForced()` a provenance-tagged pair of
     * lists (C-CTX-4.c). The `instructions` key is not project-settable today,
     * so this and the merged read agree byte-for-byte; the split is wired NOW
     * so the trust rule holds no matter which later step admits the key to the
     * project tier.
     *
     * @return list<string>
     */
    public static function forcedInstructions(): array
    {
        return self::instructionPatterns(self::readUserConfigWithoutProjectTier()['instructions'] ?? null);
    }

    /**
     * Forced-instruction patterns that reached the merged config BEYOND the
     * user-tier list {@see forcedInstructions()} reads — i.e. anything a
     * PROJECT-tier settings file could contribute. User-tier-only today:
     * `instructions` is not in PROJECT_TIER_KEYS, so this is empty on every
     * real launch; it exists so the loader's refusal of absolute and `~`
     * patterns FROM THE PROJECT TIER is a wired rule rather than dead code
     * (C-CTX-4.c), and it is the seam {@see
     * InstructionFileLoader::loadForced()} consumes.
     *
     * @return list<string>
     */
    public static function projectForcedInstructions(): array
    {
        $user = self::forcedInstructions();
        $merged = self::instructionPatterns(self::readUserConfig()['instructions'] ?? null);

        return array_values(array_filter(
            $merged,
            static fn (string $pattern): bool => !in_array($pattern, $user, true),
        ));
    }

    /**
     * Shared shape filter for both forced-pattern lists — malformed entries
     * degrade to "that one pattern is ignored" rather than a type error.
     *
     * @return list<string>
     */
    private static function instructionPatterns(mixed $configured): array
    {
        if (!is_array($configured)) {
            return [];
        }

        $patterns = [];
        foreach ($configured as $pattern) {
            if (is_string($pattern) && trim($pattern) !== '') {
                $patterns[] = $pattern;
            }
        }

        return $patterns;
    }

    /**
     * The operator's `instructionsExclude` patterns — every instruction file
     * whose checkout-relative spelling matches one of them is skipped by
     * {@see InstructionFileLoader} and RECORDED in its refusedPaths rather
     * than silently dropped. Malformed entries degrade to "that one pattern is
     * ignored" exactly as {@see forcedInstructions()} does, because both lists
     * are hand-authored in a config file; a pattern that will not compile
     * matches nothing, so exclusion fails OPEN — this key removes context the
     * operator asked to remove and never removes more on a parse ambiguity.
     *
     * User-tier only by ruling (roadmap 6.4): the project tier cannot set it,
     * because a repository that can silence which instruction files are read
     * can silence the operator.
     *
     * @return list<string>
     */
    public static function excludedInstructions(): array
    {
        $configured = self::readUserConfig()['instructionsExclude'] ?? null;
        if (!is_array($configured)) {
            return [];
        }

        $patterns = [];
        foreach ($configured as $pattern) {
            if (is_string($pattern) && trim($pattern) !== '') {
                $patterns[] = $pattern;
            }
        }

        return $patterns;
    }

    /**
     * Real, on-disk session store backing /branch, /rename, and /rewind.
     *
     * EnhancedSessionStore wraps SessionStore via composition and delegates
     * every one of its methods (see its class docblock), making it a strict
     * superset of what Chat's sessionStore()/withSessionStore() accept
     * (`SessionStore|EnhancedSessionStore|null`). Constructing the enhanced
     * variant here means /rewind's checkpoint commands work out of the box
     * instead of degrading with "Session store does not support
     * checkpoints."
     *
     * Retention is applied here too — `pruneSessions()` had been written and
     * unit-tested but never called from anywhere — but only when the user
     * OPTED IN with `CANDY_CODE_SESSION_RETENTION_DAYS`. It is off by default,
     * because the session a silent 30-day default deletes is precisely the one
     * the user came back for: coming back after a month means the row is old,
     * and it is unnamed because auto-titling fires at most once and fails
     * silently without a title backend. Startup is nevertheless the right
     * moment to run it when it IS on — it is the one point in the process
     * where no session is open, it runs before {@see seedSession()} picks the
     * row to resume, and it costs one indexed DELETE rather than anything on
     * the render or turn path.
     *
     * Two further guards, since this deletes conversations: the row
     * `seedSession()` would resume is passed in as exempt (so retention can
     * never eat the session the user is about to be handed, whatever its age),
     * and everything actually deleted is reported rather than going
     * unmentioned — one summary row on BOTH the transcript and stderr and the
     * per-session ids on stderr, see {@see reportPrunedSessions()} for the
     * split. (This sentence said "reported on stderr"; that was the whole truth
     * until E78 round 42 routed the summary onto the transcript seam, on the
     * ground that a launch which deleted conversations has taken a capability
     * away and the stderr copy is painted over by the alternate screen 0.47s
     * later.) A failure is swallowed: an unprunable database is not a
     * reason to refuse to start.
     *
     * `prune: false` is for the READ-ONLY callers. Retention is a property of
     * a LAUNCH, not of the store: it is safe here because this is the one
     * point where no session is open. A diagnostic that merely COUNTS rows —
     * {@see \SugarCraft\Code\Cli\Subcommands::doctorProbes()}'s `session
     * store` check — must not delete conversations on the way to the count,
     * and MEASURED it did: two rows aged to 2020 with
     * CANDY_CODE_SESSION_RETENTION_DAYS=7 became one, with the removal
     * reported on stderr, purely from running `candy-code doctor`. The default
     * stays `true` so the launch path in {@see chat()} is unchanged.
     *
     * @see \SugarCraft\Code\Session\SessionStore::pruneSessions() for why
     *      named sessions are exempt from retention entirely.
     */
    public static function sessionStore(bool $prune = true): EnhancedSessionStore
    {
        $store = new EnhancedSessionStore(self::configDir() . '/session.db');

        $retentionDays = self::sessionRetentionDays();
        if ($prune && $retentionDays > 0) {
            try {
                $resumable = $store->listSessions(1)[0]['id'] ?? null;
                $pruned = $store->pruneSessions(
                    $retentionDays,
                    is_string($resumable) && $resumable !== '' ? $resumable : null,
                );
                if ($pruned > 0) {
                    self::reportPrunedSessions($store->pruneReport(), $retentionDays);
                }
            } catch (\Throwable) {
                // Best effort — see the docblock above.
            }
        }

        // Sub-agent transcript logs and mailboxes outlive nothing: those of a
        // session the store no longer has (retention above, `/sessions`
        // delete) go with it (roadmap P-D1). Here, so every pruning launch —
        // the TUI, `serve`, a workspace host — sweeps, not only the TUI's own
        // App::init() Cmd. Only beside THIS store's session.db, so a store
        // never judges another directory's sessions gone. Best effort, as above.
        if ($prune) {
            try {
                \SugarCraft\Code\Agents\AgentManager::pruneSessionArtifacts(
                    static fn (string $sessionId): bool => $store->session($sessionId) !== null,
                    self::configDir(),
                );
            } catch (\Throwable) {
            }
        }

        return $store;
    }

    /**
     * Tell the user which conversations retention just deleted.
     *
     * Silence was the worst part of the original wiring: a launch destroyed a
     * month-old session and its whole transcript, printed nothing, logged
     * nothing, and the caller threw the count away. stderr before the TUI
     * takes the screen is where this class already reports provider fallbacks.
     *
     * WHAT THIS DOC-BLOCK USED TO SAY, and what the routing rule in
     * {@see warnPermissionConfigInTranscript()} used to say about it: stderr is
     * the whole answer here, because this notice is "about history already
     * deleted, not about this session's capabilities".
     * WHAT IS TRUE NOW: the SUMMARY is on both channels (E78 round 42), because
     * the second half of that sentence does not survive being read next to the
     * rule it was decided under. The rule is "a warning reaches the transcript
     * iff it names something the session can no longer DO", and after a prune
     * the session can no longer resume, branch, rename or rewind the rows this
     * line names — the `/resume` picker and `session list` are simply shorter
     * than the user left them. That is a capability the launch removed, not a
     * complaint about a malformed config, and it is the ONLY entry on this
     * class's stderr-only list that destroys data. The 0.47s window
     * {@see warnPermissionConfigInTranscript()} measures applies to it exactly
     * as it applies to a provider fallback: an interactive launch paints the
     * alternate screen over this line before it can be read, and the primary
     * buffer does not come back until the session is over.
     * WHY THE PER-SESSION ROWS STILL EARN THEIR PLACE ON STDERR ALONE: the
     * summary is ONE row per launch whatever the prune deleted, where the
     * detail is one row per session — an unbounded per-ENTRY fan-out into a
     * list that is sent to the model on every turn, which is the exact shape
     * {@see LAUNCH_NOTICE_LIMIT} exists to refuse. stderr is the complete,
     * unclipped record; the transcript carries the fact and the count.
     *
     * ORDERING is what makes the summary reachable at all: {@see chat()} calls
     * {@see sessionStore()} early and reads {@see launchNotices()} last, so a
     * notice recorded here is in hand by the time the transcript is seeded.
     *
     * THE NON-CHAT CALLERS, surveyed in full — an earlier version of this
     * paragraph named only the doctor probe, which made the survey look
     * complete when it was one of three. {@see sessionStore()} is NOT memoized;
     * it builds a fresh store and prunes on every call, so every caller is a
     * caller of this method. There are four:
     *
     *  - {@see chat()} — the production path, and the only one that reads
     *    {@see launchNotices()} back.
     *  - {@see \SugarCraft\Code\Cli\Subcommands::doctorProbes()} — passes
     *    `prune: false`, so it never reaches this method at all.
     *  - {@see \SugarCraft\Code\Cli\Subcommands::sessionList()} and
     *    {@see \SugarCraft\Code\Cli\Subcommands::sessionDelete()} — both take
     *    the default `prune: true`, so on a retention-enabled box
     *    `candy-code session list` DOES record a transcript row here, into a
     *    static list that subcommand never reads and the process then discards.
     *    Inert rather than wrong — the stderr half of the seam still prints,
     *    which is the half a subcommand's user is reading, and the orphaned row
     *    costs one bounded string — but it is the shape to watch: if a
     *    subcommand ever grows a transcript of its own, this is where a launch
     *    notice about a prune would arrive in it uninvited. Not fixed here
     *    because suppressing it means either memoizing the store or threading a
     *    "who is asking" flag through the accessor, and both are larger changes
     *    than the thing they would prevent.
     *
     * The summary's trailing `:` is gone with the migration — the seam formats
     * every message as `candy-code: <message>.` — and the detail rows below
     * still read as its list because they follow it immediately and name
     * themselves. A repeat launch in one process is the one case where they can
     * be orphaned: {@see warnPermissionConfigOnce()} de-duplicates the summary
     * per PROCESS while these rows are unconditional, so an identical second
     * prune prints its rows under no header. Self-describing rows rather than
     * a second de-dup map, because the alternative is dropping the complete
     * record on the channel that exists to hold it.
     *
     * @param array<int, array{id: string, name: ?string, updated_at: string, messages: int}> $report
     */
    private static function reportPrunedSessions(array $report, int $retentionDays): void
    {
        $count = count($report);
        self::warnPermissionConfigInTranscript(sprintf(
            self::translated(self::SESSION_RETENTION_SUMMARY_FORMAT),
            $count,
            $count === 1 ? 'session' : 'sessions',
            $retentionDays,
        ));
        foreach ($report as $row) {
            fwrite(STDERR, sprintf(
                self::translated(self::SESSION_RETENTION_DETAIL_FORMAT),
                $row['id'],
                $row['updated_at'],
                $row['messages'],
                $row['messages'] === 1 ? 'message' : 'messages',
            ));
        }
    }

    /** The settings key {@see sessionRetentionDays()} falls back to. */
    public const SESSION_RETENTION_DAYS_SETTING = 'sessionRetentionDays';

    /**
     * How many days an unnamed session survives without being touched, from
     * `CANDY_CODE_SESSION_RETENTION_DAYS`, else the `sessionRetentionDays`
     * setting (roadmap N-P4g).
     *
     * THE VARIABLE WINS WHENEVER IT IS SET AT ALL — a non-empty value decides,
     * including a value that reads as `0` — so a wrapper that exports
     * `CANDY_CODE_SESSION_RETENTION_DAYS=0` keeps every session whatever a
     * settings file says. The setting is the operator's own (user tier only: a
     * checkout must not be able to delete the operator's history), read when
     * the store is built at launch, so a saved change applies at the next one.
     * Out of its `0..`{@see SessionStore::MAX_RETENTION_DAYS} range it reads as
     * the default `0`, never as a guess at a cutoff.
     *
     * **Retention is opt-in: the default is `0`, which disables it.** An
     * unset variable cannot mean "delete my history", and the only signal
     * distinguishing a session worth keeping from an abandoned one — a name —
     * is weak enough (auto-titling runs once per session, needs a working
     * title backend, and fails silently) that a session holding a month of
     * work can easily still be unnamed.
     *
     * `0` and any value that is not a plain positive integer — negative,
     * fractional, suffixed, wordy — also disable it rather than guessing at an
     * intent. A value above {@see SessionStore::MAX_RETENTION_DAYS} is clamped
     * rather than rejected: `ctype_digit()` accepts `99999999999999999999`,
     * `(int)` saturates it to `PHP_INT_MAX`, and `strtotime("-PHP_INT_MAX
     * days")` overflows to a cutoff in the year 2343668 — a FUTURE cutoff, at
     * which "older than the cutoff" is every session there is.
     */
    public static function sessionRetentionDays(): int
    {
        $raw = getenv('CANDY_CODE_SESSION_RETENTION_DAYS');
        $trimmed = $raw === false ? '' : trim($raw);
        if ($trimmed === '') {
            return \SugarCraft\Code\Config\Settings\UiSettings::int(self::SESSION_RETENTION_DAYS_SETTING);
        }

        if (!ctype_digit($trimmed)) {
            return 0;
        }

        return min((int) $trimmed, SessionStore::MAX_RETENTION_DAYS);
    }

    /**
     * Give the CLI a real session row to run against, resuming the most
     * recent one when the store already has sessions and creating one when
     * it does not (crush_feat.md §5 E1's sketch).
     *
     * Since `-c`/`--continue` this is THAT flag's path, reached through
     * {@see openSession()}; a launch with no session flag opens a new row
     * instead, for the two-terminals reason on {@see useSessionLaunch()}.
     *
     * This is the capstone gap that whole feature area was blocked on: no
     * production path — not this method's caller, not `Chat::init()`, not
     * any `bin/` entry point — ever called {@see
     * \SugarCraft\Code\Session\SessionStore::createSession()}, so on a real
     * run `listSessions()` returned `[]` for the whole process lifetime.
     * Everything keyed off `currentSessionId` was therefore dead in
     * production while passing its own unit tests: `/sessions` rendered an
     * empty picker, `Renderer::renderSessionTabStrip()` self-suppressed
     * below two rows, `Chat::cycleSessionTab()` early-returned, `/branch`
     * had nothing to fork, and `Chat::scheduleTitleGeneration()` bailed on
     * its `currentSessionId === null` guard so the auto-title call could
     * never fire.
     *
     * Resume-most-recent (rather than always creating) is what §5 E1
     * sketches, and it is also what makes the run's `/rewind` checkpoints
     * survive a restart. The existing row's `name` is handed back with it
     * so the caller can latch `Chat::currentSessionName()`: without that,
     * a resumed-but-already-titled session would look unnamed to
     * `scheduleTitleGeneration()` and get re-titled — and the store's name
     * overwritten — on the first turn of every subsequent launch.
     *
     * @param string $provider Provider name to record on a newly created
     *        row (see {@see selectedProviderLabel()}); ignored when an
     *        existing session is resumed.
     * @param string $model Model name to record alongside it.
     *
     * @return array{0: string, 1: ?string} session id, and its existing
     *         name when resuming a named session (null otherwise)
     */
    public static function seedSession(
        \SugarCraft\Code\Session\SessionStore|EnhancedSessionStore $store,
        string $provider = 'candy-code',
        string $model = 'unknown',
    ): array {
        $row = $store->listSessions(1)[0] ?? null;
        if (is_array($row) && is_string($row['id'] ?? null) && $row['id'] !== '') {
            $name = $row['name'] ?? null;

            return [$row['id'], is_string($name) && $name !== '' ? $name : null];
        }

        $sessionId = bin2hex(random_bytes(8));
        // Same origin record as openSession()'s fresh row (audit B1).
        $cwd = getcwd() ?: null;
        $store->createSession($sessionId, $provider, $model, null, null, $cwd, $cwd === null ? null : SessionStore::gitBranchAt($cwd));

        return [$sessionId, null];
    }

    /**
     * Delegates to {@see LaunchContext::selectedProviderLabel()} — the body moved in the S1b
     * composition split (W7-E C3); this keeps the door (and its visibility)
     * every caller and the settings-schema reader citations name.
     */
    public static function selectedProviderLabel(): array {
        return LaunchContext::selectedProviderLabel();
    }

    /**
     * Delegates to {@see LaunchContext::selectedProviderName()} — the body moved in the S1b
     * composition split (W7-E C3); this keeps the door (and its visibility)
     * every caller and the settings-schema reader citations name.
     */
    public static function selectedProviderName(): ?string {
        return LaunchContext::selectedProviderName();
    }

    /**
     * Whether this run is on one of {@see backend()}'s two shell-out tiers —
     * `$CANDY_CODE_BACKEND_CMD` or `$CANDY_CODE_BACKEND_CMD_STREAM`.
     *
     * Both selection helpers above ask exactly this question and both have to
     * get the same answer: {@see selectedProviderName()} returns null so a
     * stale persisted provider does not outrank a shell-out that
     * {@see backend()} really is about to construct, and
     * {@see selectedProviderLabel()} labels the run 'command' so
     * {@see \SugarCraft\Code\Cli\NonInteractive} does not announce "no
     * provider configured" to someone who configured one. When the streaming
     * variable was added, reading only the first of the two here is precisely
     * how those two answers would have drifted apart.
     */
    public static function backendCommandTierIsSelected(): bool
    {
        foreach (['CANDY_CODE_BACKEND_CMD', 'CANDY_CODE_BACKEND_CMD_STREAM'] as $var) {
            if (self::backendCommandEnv($var) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The command one of the two shell-out variables names, or null when that
     * variable is ABSENT — which includes unset, empty, and WHITESPACE-ONLY.
     *
     * The whitespace case is not pedantry: `export CANDY_CODE_BACKEND_CMD='   '`
     * (or the same on the streaming variable) used to select the shell-out tier,
     * spawn `sh -c '   '`, exit 0 and return an EMPTY assistant message, while
     * {@see selectedProviderLabel()} labelled the run 'command' so nothing
     * warned about it — a run with no model, no answer and no complaint. There
     * is no command a caller could mean by a string of spaces, and absence is
     * the only reading that leaves the next tier reachable.
     *
     * Both selection sites go through here so they cannot disagree: this method
     * defines what "the variable is set" MEANS, and {@see backend()} choosing a
     * tier while {@see backendCommandTierIsSelected()} denied one existed is
     * exactly the drift that would attribute a `-p` answer to the wrong
     * backend. The value is returned UNTRIMMED — leading whitespace is
     * harmless to `sh -c` and trimming would silently rewrite the command a
     * caller actually exported.
     */
    private static function backendCommandEnv(string $var): ?string
    {
        $value = getenv($var);
        if ($value === false || trim($value) === '') {
            return null;
        }

        return $value;
    }

    /**
     * A deliberately cheap Backend for {@see Chat}'s one-shot session-title
     * call, or null when this run has no provider to build one from.
     *
     * Chat falls back to the MAIN conversation backend when this is null —
     * which on the provider paths means naming a session costs a second
     * full tool-capable agent turn, complete with the tool schemas, skill
     * registry and root CLAUDE.md/AGENTS.md instruction preamble, for a
     * request whose entire output is a handful of words. This backend is
     * the same provider with none of that attached: no tools, no hooks, no
     * skill registry, no instruction loader, so the title request carries
     * only the title prompt and the transcript.
     *
     * The model is `$CANDY_CODE_TITLE_MODEL`, else a `titleModel` key in
     * ~/.candy-code/config.json, else — ISS-077 — the provider type's cheap
     * auxiliary default ({@see ProviderFactory::AUXILIARY_MODEL_BY_TYPE}:
     * Haiku-class on the Anthropic family, a mini on openai), else the
     * provider's own default — and
     * deliberately NOT `$CANDY_CODE_MODEL`, which names the big
     * conversation model. Null on any construction failure: an unnamed
     * session is a non-event (same silent-failure stance {@see
     * \SugarCraft\Code\Chat::scheduleTitleGeneration()} takes), and
     * `backend()` has already warned about an unusable provider.
     *
     * C-PRV-5.b: and it is built WITHOUT the configured `thinkingBudget` —
     * block-tier and user-tier alike, switched off in
     * {@see toollessBackend()} — because a title is a handful of words and
     * never sends a `thinking` arm. {@see summaryBackend()} is the sole
     * exception, and keeps the budget on purpose.
     */
    public static function titleBackend(): ?Backend
    {
        return self::toollessBackend('CANDY_CODE_TITLE_MODEL', 'titleModel');
    }

    /**
     * The session's summary backend (crush_code.md Phase 5 item 6; roadmap
     * 2.4-2), or null when this run has no provider to build one from.
     *
     * Given the launch's main engine, it is a
     * {@see \SugarCraft\Code\Backend\CacheReusingSummaryBackend}: `/compact`'s and
     * the 85% tier's summaries go to that engine's
     * {@see EngineBackend::summariseAsync()} — the conversation's own system
     * prompt, tools and history plus one "do not call tools" instruction, so
     * the request reuses the prefix the provider cached on the last turn — and
     * every other completion (auto-memory consolidation) goes to the tool-less
     * half. Without one (an embedder, a test) it is the tool-less backend alone.
     *
     * THE MODEL defaults to the conversation's own: a cache hit needs the same
     * model, and a compaction summary is what the model is shown of the earlier
     * conversation from then on, so a bad one is permanent context loss.
     * `$CANDY_CODE_SUMMARY_MODEL` / `summaryModel` ({@see summaryModel()}) name
     * another for a user who would rather trade quality for cost. Separate from
     * {@see titleBackend()}'s variable because a title is a handful of words and
     * the smallest model will do.
     *
     * The tool-less half shares {@see toollessBackend()} with the titler: no
     * tools, no hooks, no skill registry, no instruction preamble — and,
     * uniquely among the tool-less engines, it KEEPS the configured
     * `thinkingBudget` (C-PRV-5.b), because a summary is permanent context.
     * {@see \SugarCraft\Code\Chat}'s $summaryBackend docblock spells out why
     * a plain completion through the tool-capable main backend would let it
     * raise a permission prompt; the cache-reusing half runs no tool either.
     *
     * Null on any construction failure, and that is not an error path: `/compact`
     * falls back to the heuristic summarizer it has always used.
     */
    public static function summaryBackend(?Backend $main = null): ?Backend
    {
        $toolless = self::toollessBackend(
            'CANDY_CODE_SUMMARY_MODEL',
            'summaryModel',
            $main instanceof EngineBackend ? $main->model() : null,
            // C-PRV-5.b: the ONE caller that keeps the configured thinking
            // budget. A summary becomes permanent context for every later
            // turn, so it is worth what the user paid reasoning for; a title
            // is a handful of words and is not (titleBackend() and every
            // other auxiliary consumer run without it).
            keepThinking: true,
        );
        if ($toolless === null || !$main instanceof \SugarCraft\Code\Backend\SummarisesWithCache) {
            return $toolless;
        }

        return \SugarCraft\Code\Backend\CacheReusingSummaryBackend::new(
            $toolless,
            $main instanceof EngineBackend ? $main->withSummaryModel(self::summaryModel()) : $main,
        );
    }

    /**
     * Delegates to {@see LaunchContext::summaryModel()} — the body moved in the S1b
     * composition split (W7-E C3); this keeps the door (and its visibility)
     * every caller and the settings-schema reader citations name.
     */
    public static function summaryModel(): ?string {
        return LaunchContext::summaryModel();
    }

    /**
     * The construction {@see titleBackend()} and {@see summaryBackend()} share:
     * this run's selected provider with NOTHING attached — no tools, no hooks,
     * no skill registry, no instruction loader — under whichever model
     * $modelEnvVar, then the $modelConfigKey key of ~/.candy-code/config.json,
     * then $defaultModel (the summary backend passes the conversation's model),
     * then the type's cheap auxiliary default (ISS-077 — the titler's whole
     * point is a handful of words, so it must not bill the conversation-class
     * model), then the provider's own default names.
     *
     * One builder rather than two near-copies, because the tool-less part is
     * the load-bearing part: a second copy is a second place for a `withTools()`
     * to be added by mistake, and on the summarization path that would mean a
     * compaction that can run Bash.
     *
     * `hooksDisabled: true` is passed EXPLICITLY, and the reason is that the
     * "no hooks" half of the sentence above was otherwise false. Left at its
     * default, {@see EngineBackend::resolveHookManager()} calls
     * `registerBuiltIns()` and the backend carries `ProtectFilesHook`,
     * `ConfirmRemoveHook` and `AuditHook`. All three are `PreToolUse`/
     * `PostToolUse` only, so with no tools attached none of them could ever
     * fire and the safety conclusion held anyway — but it held as a
     * TWO-STEP argument resting on a second fact, and this is asserted as a
     * safety property at four sites. One flag makes the sentence true on its
     * own terms.
     *
     * C-PRV-5.b: a tool-less completion is a handful of words, so it must not
     * pay for extended thinking. Unless `$keepThinking` is set — and only
     * {@see summaryBackend()} sets it, because a compaction summary is judged
     * on the conversation's own reasoning quality — the builder writes the
     * parsers' OFF SPELLING over `thinkingBudget` instead of merely unsetting
     * the block key: every thinking arm resolves the setting as
     * `$config['thinkingBudget'] ?? userTierSetting('thinkingBudget')`, so a
     * removed key would let a user-tier `thinkingBudget` ride the fallback
     * straight into the titler's requests. The empty string is a present
     * non-null key — it beats the fallback — and each parser already maps it
     * to "off" by both of the `thinkingBudget` parsers in `ProviderFactory`,
     * the strict Anthropic one and the tolerant Gemini one), so the wire
     * carries no `thinking` arm at all. Anthropic's budget surviving
     * `withModel()` is then moot: there is no budget to carry.
     */
    private static function toollessBackend(string $modelEnvVar, string $modelConfigKey, ?string $defaultModel = null, bool $keepThinking = false): ?Backend
    {
        $providerName = self::selectedProviderName();
        if ($providerName === null) {
            return null;
        }

        try {
            $factory = new ProviderFactory();
            $config = self::providerConfigFor($providerName, $factory);

            $model = getenv($modelEnvVar);
            if ($model === false || $model === '') {
                $configured = self::readUserConfig()[$modelConfigKey] ?? null;
                $model = is_string($configured) && $configured !== ''
                    ? $configured
                    // ISS-077: with no explicit choice, an auxiliary call
                    // takes the type's cheap default (Haiku-class/mini)
                    // before the block's conversation-class model - but only
                    // when the caller named no default of its own. The
                    // summary backend passes the conversation's model as
                    // $defaultModel ON PURPOSE: cache reuse requires it.
                    : ($defaultModel ?? (ProviderFactory::auxiliaryModelFor((string) ($config['type'] ?? ''))
                        ?? (string) ($config['model'] ?? '')));
            }
            if ($model === '') {
                return null;
            }
            $config['model'] = $model;

            if (!$keepThinking) {
                // C-PRV-5.b: overwrite rather than unset — see the method
                // docblock. A deleted key loses to the factory's user-tier
                // fallback; the empty string is what both parsers read as off.
                $config['thinkingBudget'] = '';
            }

            return new EngineBackend($factory->create($config), $model, hooksDisabled: true);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The spend ceiling for this launch, in US dollars — from the
     * `--max-cost` flag ({@see useMaxCost()}), else `$CANDY_CODE_MAX_COST`,
     * else the `maxCostUsd` setting ({@see persistedMaxCostUsd()}) — or null
     * for no cap (crush_code.md Phase 5 item 7, flag half of ISS-006).
     *
     * ABSENCE AND A BAD VALUE ARE DIFFERENT ANSWERS, exactly as they are for
     * `$CANDY_CODE_PERMISSION_MODE` (see {@see permissionGate()}), and for the
     * same reason spelled out there: every fallback in this chain ends somewhere
     * MORE PERMISSIVE, so silently discarding a value the user set on purpose is
     * a fail-open.
     *
     * - An ABSENT flag, an unset/empty/whitespace variable, and no settings key —
     *   absence. No cap, like every other source this chain reads.
     * - A positive finite number, optionally with a leading `$` and surrounding
     *   whitespace — the cap. `$5` is what a human types and `/budget $5` already
     *   accepts it, so refusing it here would be an inconsistency, not a
     *   safeguard. The flag and the variable are text and take that spelling;
     *   the setting is JSON and carries a plain number.
     * - Anything else PRESENT — {@see PermissionConfigException}, naming its own
     *   source, which `bin/candy-code` turns into an exit-2 usage report. That
     *   covers `5USD`, `five dollars`, `0`, `-5` and `1e309`.
     *
     * The previous behaviour — read a bad value as unset, "matching the refusal
     * `/budget 0` gives" — conflated two things that are not alike. `/budget 0`
     * answers IN THE TRANSCRIPT, so the user learns immediately that no cap was
     * set; this path is read once at launch and said nothing, so a user who typed
     * `CANDY_CODE_MAX_COST=5USD` got an uncapped session and no hint of it. The
     * argument for tolerance applies to a theme or a persisted provider, where
     * guessing wrong costs nothing.
     *
     * @throws PermissionConfigException when the flag or the variable is present
     *   and unusable (or, below, the setting)
     */
    public static function maxCostUsd(): ?float
    {
        if (LaunchContext::maxCostOverride() !== null) {
            return self::parseSpendCap(LaunchContext::maxCostOverride(), 'cli.refuse.max_cost_flag');
        }

        $raw = getenv('CANDY_CODE_MAX_COST');
        if ($raw === false || trim($raw) === '') {
            return self::persistedMaxCostUsd();
        }

        return self::parseSpendCap($raw, 'cli.refuse.max_cost_env');
    }

    /**
     * The one numeric judgement the flag and the environment variable share —
     * trim, tolerate a leading `$` and surrounding whitespace, require a
     * positive finite dollar figure by {@see Chat::isUsableSpendCap()}.
     * `$messageKey` names the SOURCE in the refusal, because "your env var is
     * broken" is the wrong advice when the flag was typed on this command line.
     */
    private static function parseSpendCap(string $raw, string $messageKey): float
    {
        $trimmed = trim($raw);
        $amount = ltrim($trimmed, '$');
        $value = is_numeric($amount) ? (float) $amount : null;

        if ($value === null || !Chat::isUsableSpendCap($value)) {
            throw new PermissionConfigException(
                Lang::t($messageKey, ['value' => $trimmed]),
            );
        }

        return $value;
    }

    /** The settings key {@see maxCostUsd()} falls back to (roadmap N-P4g). */
    public const MAX_COST_SETTING = 'maxCostUsd';

    /**
     * The `maxCostUsd` setting, for a launch with no `$CANDY_CODE_MAX_COST`:
     * the persistent spend ceiling, or null when no settings file sets one.
     *
     * USER TIER ONLY, so a checkout can neither impose a cap that refuses the
     * operator's turns nor be the reason one is missing. Read when the
     * workspace is built, and again by {@see Chat::applySettings()} when a
     * save names the key; `/budget` still changes the running launch's cap and
     * never writes it back.
     *
     * HELD TO THE VARIABLE'S STANDARD, not to the "a bad hand edit costs that
     * one knob" tolerance other settings readers have: a ceiling the user wrote
     * down and that is silently dropped is an uncapped session nobody chose —
     * the fail-open {@see maxCostUsd()} refuses for the variable. So a value
     * that is present and not a positive finite number of dollars refuses the
     * launch the same way (the settings view refuses to save one).
     *
     * @throws PermissionConfigException when the setting is present and unusable
     */
    private static function persistedMaxCostUsd(): ?float
    {
        $value = self::readUserConfig()[self::MAX_COST_SETTING] ?? null;
        if ($value === null) {
            return null;
        }

        if ((!\is_int($value) && !\is_float($value)) || !Chat::isUsableSpendCap((float) $value)) {
            throw new PermissionConfigException(
                Lang::t('cli.refuse.max_cost_setting', ['value' => var_export($value, true)]),
            );
        }

        return (float) $value;
    }

    /**
     * The settings key holding user keybinding overrides (ISS-047). Its shape
     * is owned by {@see \SugarCraft\Code\Config\Settings\Validator\KeyBindingsValidator}
     * — a map of binding id to a chord (`Ctrl+<key>`, `Alt+<key>`,
     * `Shift+<key>`, or a lone function key, widened from the bare-`Ctrl+<letter>`
     * grammar by TUI-4c) — and the definition lives in
     * the Interface settings family as a user-tier, restart-applied entry.
     */
    public const KEYBINDINGS_SETTING = 'keybindings';

    /**
     * The `keybindings` override map as the launch should apply it.
     *
     * Same machinery as {@see maxCostUsd()}: absence is the shipped bindings
     * (`[]`), a present-but-unusable value is a
     * {@see PermissionConfigException} that `bin/candy-code` turns into an
     * exit-2 usage report — running with silently dropped key overrides is a
     * fail-open on muscle memory, and a typo'd id means the user believed a
     * binding moved when it did not.
     *
     * @return array<string,string|null> binding id => override chord, or null for an unbound binding
     *
     * @throws PermissionConfigException when the key is present and unusable
     */
    public static function keybindingsSetting(): array
    {
        [$map, $reason] = self::keybindingsParse();
        if ($reason !== null) {
            throw new PermissionConfigException(
                Lang::t('cli.refuse.keybindings', ['reason' => $reason]),
            );
        }

        return $map;
    }

    /**
     * The `keybindings` setting parsed WITHOUT the launch verdict: the map
     * beside the validator's raw reason (reason `null` when the key is
     * absent, usable, or both). {@see keybindingsSetting()} wraps this pair
     * in the exit-2 launch refusal; `/keys reload` consumes the pair
     * directly — plan_comparison w5j: a reload quotes the validator, never
     * the launch speech it would have made (nothing can refuse a start
     * mid-session, and the launch tail promised a refusal that cannot
     * happen).
     *
     * @return array{0: array<string,string|null>, 1: ?string}
     */
    private static function keybindingsParse(): array
    {
        $raw = self::readUserConfig()[self::KEYBINDINGS_SETTING] ?? null;
        if ($raw === null) {
            return [[], null];
        }

        $reason = KeyBindingsValidator::new()->validate($raw);
        if ($reason !== null) {
            return [[], $reason];
        }

        /** @var array<string,string|null> $raw */
        return [$raw, null];
    }

    /**
     * Announce every registered `keybindings` override that landed on
     * another binding's SHIPPED chord (rv6 follow-up to ISS-047).
     *
     * Reads the REGISTERED map, not the setting, so it can only name a
     * collision that dispatch will actually honour — and runs directly after
     * {@see KeyBindingRegistry::useOverrides()} for the same reason. The
     * funnel's behavior is correct (the override wins its chord); the row it
     * steals loses its key with no other signal anywhere, which is what these
     * rows are the signal for. Drained through {@see warnLaunchRows()} so a
     * new report does not move the transcript-seam call-site census, the way
     * every other {@code report*()} launch drain holds that line. Suppressed
     * bindings take part on neither side (plan_comparison TUI-4b): they are
     * not in {@see KeyBindingRegistry::overrides()}, and a victim the user
     * already unbound has no key to lose.
     */
    public static function reportKeybindingCollisions(): void
    {
        self::warnLaunchRows(
            KeyBindingsValidator::new()->collisionNotices(KeyBindingRegistry::overrides()),
        );
    }

    /**
     * Drain the skip-and-record accumulators: an event a foreign
     * Claude Code settings file names that {@see \SugarCraft\Code\Hooks\HookEvent}
     * has no case for is skipped at parse
     * ({@see ClaudeSettingsHooks}), and since C-EXT-1.b so are entries of an
     * unsupported string `type` and entries carrying keys this dialect does
     * not speak. All of it is announced HERE as ONE aggregate row whatever
     * the count — the {@see reportSkillSkips()} contract. Reached
     * from chat()'s drain block, which runs AFTER workspace() construction
     * read every hook file, so one call sees every parse the launch made.
     * Supersedes the old hard refusal for the CC dialect ONLY (R3's native
     * `hooks.yaml` refusal is untouched — a foreign vendor's evolving event
     * list must not brick every launch; that blast radius WAS the defect).
     */
    public static function reportSkippedClaudeSettingsEvents(): void
    {
        $events = ClaudeSettingsHooks::skippedEvents();
        $entries = ClaudeSettingsHooks::skippedEntries();
        if ($events === [] && $entries === []) {
            return;
        }

        $clauses = [];
        $files = [];

        // The group figure is a DISTINCT-GROUP census
        // ({@see \SugarCraft\Code\Hooks\ClaudeSettingsHooks::skippedGroups()}),
        // not a sum of the per-name `groups` counters: one group holding a
        // `prompt` entry and an `if` key carried two skips but is one group
        // that contributes no guard (C-EXT-1.b, round 2).
        $groups = \count(ClaudeSettingsHooks::skippedGroups());

        if ($events !== []) {
            $names = [];
            foreach ($events as $event => $seen) {
                $names[] = $event;
                $files += $seen['files'];
            }

            $clauses[] = 'no candy-code hook event matches ' . "'" . implode("', '", $names) . "'";
        }

        $types = [];
        $keys = [];
        foreach ($entries as $skipped => $seen) {
            $files += $seen['files'];

            if ($seen['kind'] === 'type') {
                $types[] = $skipped;
            } else {
                $keys[] = $skipped;
            }
        }

        if ($types !== []) {
            $clauses[] = 'candy-code cannot run hook entries typed ' . "'" . implode("', '", $types) . "'";
        }

        if ($keys !== []) {
            $clauses[] = 'candy-code cannot run hook entries carrying ' . "'" . implode("', '", $keys) . "'";
        }

        self::warnLaunchRows([
            \sprintf(
                self::CLAUDE_SETTINGS_SKIPPED_EVENTS_NOTICE_FORMAT,
                implode('; ', $clauses),
                $groups,
                \count($files),
            ),
        ]);
    }

    /**
     * v1:m-1: say it out loud ONCE when this launch resolved `~` through the
     * temp-directory stand-in
     * ({@see \SugarCraft\Code\Support\HomeDirectory::standInUsed()}). The
     * stand-in itself is unchanged — the loud paths that REFUSE on it
     * (`cli.refuse.no_home`) stay exactly as they were; what was missing is
     * that every NON-refusing reader kept working off `/tmp/.candy-code`
     * with no signal that the home resolution had degraded. One row whatever
     * the call count: the seam de-dupes by message identity.
     */
    public static function reportHomeDirectoryStandIn(): void
    {
        if (!HomeDirectory::standInUsed()) {
            return;
        }

        self::warnLaunchRows([self::HOME_STAND_IN_NOTICE_TEXT]);
    }

    /**
     * The dumb-family test, split out as a pure function so the gate is
     * pinned by a table instead of a fake terminal. THE FAMILY: `''`
     * (TERM unset — nothing was advertised), and any `dumb…`/`unknown…`
     * prefix, case- and whitespace-insensitively. `xterm`, `screen`,
     * `vt100` and friends get nothing — a normal launch stays
     * byte-unchanged, which is the polarity the existing transcript pins
     * keep asserting.
     */
    public static function terminalGuidanceNotice(string $term, bool $tty): ?string
    {
        if (!$tty) {
            return null;
        }

        $family = strtolower(trim($term));

        if ($family === '' || str_starts_with($family, 'dumb') || str_starts_with($family, 'unknown')) {
            return self::TERMINAL_GUIDANCE_NOTICE_TEXT;
        }

        return null;
    }

    /**
     * v2:a11y-F3: guide a launch on a TTY that can NOT speak TUI — before,
     * `TERM=dumb` users met a wall of bytes with no pointer at the paths
     * that work for them. Gated on the tty as well as the family: the
     * -p/scripted shapes answer on pipes all day and need no advice.
     * The `$tty` seam defaults to reading stdout for real (the sugar-readline
     * q3 idleSleeper pattern — an injectable observation point so the
     * positive half is pinned without minting a pty in the suite).
     */
    public static function reportTerminalGuidance(?bool $tty = null): void
    {
        $notice = self::terminalGuidanceNotice(
            (string) (getenv('TERM') ?: ''),
            $tty ?? \stream_isatty(STDOUT),
        );

        if ($notice === null) {
            return;
        }

        self::warnLaunchRows([$notice]);
    }

    /**
     * C-EXT-4.d: the marketplace bundles that fit the project being opened,
     * named at launch, once per project per marketplace pin, from local bytes
     * only.
     *
     * The matching itself is {@see BundleRecommender}'s (C-EXT-4.b); this is
     * its launch drain. It joins the catalog of every ADDED marketplace into
     * the recommender's shape — first offering wins, registration order, the
     * same join the `bundles recommend` verb performs — drops the names
     * already installed under the home bundle root, and raises AT MOST ONE
     * aggregate row naming the survivors as `name@marketplace`. Nothing is
     * installed or updated by the notice, and the wording says so; adopting
     * one stays a deliberate `candy-code bundles install`.
     *
     * Three silences, in order: no marketplace has been added (the pinned
     * empty catalog answers with nothing to walk); the project carries no
     * signal the catalog answers; or every marketplace that contributed to
     * the recommendation has ALREADY been shown for this project at its
     * current pin. That last gate is a stamp written into the registry's own
     * `marketplaces.json` under a top-level `launchRecommendations` key —
     * `{projectRoot: {marketplace: sha shown}}`. The registry's loader
     * ignores foreign top-level keys, and its writer drops the stamp on the
     * next registry mutation — exactly when a re-show is owed, because an
     * add, remove or repin changed what the last shown row promised.
     *
     * NEVER network at launch: the walk is one `scandir` of the project root
     * against the recommender's FIXED signal list, one local read per added
     * marketplace's catalog, and one `scandir` of the install root. A stamp
     * read or write that fails skips the stamp, never the row — launch
     * notices are the fail-open family, and a re-shown row is bounded at one
     * line either way.
     *
     * @param string|null $root workspace root; null falls back to the process cwd
     * @param MarketplaceRegistry|null $registry added-marketplace view; null for the home-backed default
     * @param string|null $stampFile record file carrying the stamp; null for the home-backed default — a registry read from other paths passes ITS own path here
     * @param string|null $installedRoot installed-bundle directory; null for the home-backed default, '' for none
     */
    public static function reportBundleRecommendations(
        ?string $root = null,
        ?MarketplaceRegistry $registry = null,
        ?string $stampFile = null,
        ?string $installedRoot = null,
    ): void {
        if ($root === null) {
            $cwd = \getcwd();
            $root = $cwd === false ? null : $cwd;
        }

        if ($root === null || $root === '') {
            return;
        }

        $registry ??= MarketplaceRegistry::loadDefault();

        if ($registry->names() === []) {
            return; // no marketplace added: fully silent, nothing is walked
        }

        $home = HomeDirectory::owned();
        $stampFile ??= $home === null ? '' : rtrim($home, '/') . '/' . MarketplaceRegistry::RECORD_RELATIVE_PATH;
        $installedRoot ??= $home === null ? '' : $home . '/.candy-code/bundles';

        $catalog = [];
        $offeredBy = [];

        foreach ($registry->names() as $marketplaceName) {
            $marketplace = $registry->marketplace($marketplaceName);

            if ($marketplace === null) {
                continue;
            }

            foreach ($marketplace->entries as $entry) {
                if (isset($offeredBy[$entry->name])) {
                    continue; // first offering wins, registration order
                }

                $offeredBy[$entry->name] = $marketplaceName;
                $catalog[] = $entry;
            }
        }

        if ($catalog === []) {
            return;
        }

        $recommended = (new BundleRecommender(
            $root,
            $catalog,
            $installedRoot === '' ? [] : self::installedBundleNames($installedRoot),
        ))->recommend();

        if ($recommended === []) {
            return;
        }

        $envelope = self::readMarketplaceEnvelope($stampFile);
        $shown = is_array($envelope['launchRecommendations'] ?? null) ? $envelope['launchRecommendations'] : [];
        $stamped = is_array($shown[$root] ?? null) ? $shown[$root] : [];

        // The pin gate: a contributing marketplace already shown for this
        // project AT THIS SHA owes no repeat; one un-shown pin opens the row.
        $involved = [];
        $fresh = false;

        foreach ($recommended as $entry) {
            $marketplaceName = $offeredBy[$entry->name];
            $record = $registry->record($marketplaceName);
            $sha = is_array($record) && is_string($record['sha'] ?? null) ? $record['sha'] : '';
            $involved[$marketplaceName] = $sha;

            if ((string) ($stamped[$marketplaceName] ?? '') !== $sha) {
                $fresh = true;
            }
        }

        if (!$fresh) {
            return;
        }

        $labelled = [];

        foreach ($recommended as $entry) {
            $labelled[] = $entry->name . '@' . $offeredBy[$entry->name];
        }

        // Lang::t(), not sprintf(): this file's launch-format census counts
        // sprintf call sites, and this notice must not move it.
        self::warnLaunchRows([Lang::t('bundles.recommend.launch', [
            'names' => implode(', ', $labelled),
        ])]);

        self::stampBundleRecommendations($stampFile, $envelope, $root, $involved);
    }

    /**
     * The registry file's raw envelope, or `[]` when there is nothing to
     * carry a stamp: absent, unreadable, or not the object shape. This reads
     * the bytes without re-deriving rows — the drain is a pure passenger of
     * `marketplaces.json`, and the registry's own loader stays the only
     * interpreter of its `marketplaces` array.
     *
     * @return array<string, mixed>
     */
    private static function readMarketplaceEnvelope(string $stampFile): array
    {
        if ($stampFile === '' || !is_file($stampFile)) {
            return [];
        }

        try {
            $document = AtomicJsonFile::new($stampFile)->read();
        } catch (\Throwable) {
            return [];
        }

        return is_array($document) ? $document : [];
    }

    /**
     * Merge the pins shown this launch into the envelope read moments before
     * and rewrite the file through the SAME 0600 atomic writer the registry
     * itself uses — only ever over a file that already exists, so this never
     * mints a registry file the registry did not write. Every failure skips
     * the stamp and says nothing: the row has already ridden, and a lost
     * stamp costs one re-shown line at the next launch, not a silent one.
     *
     * @param array<string, mixed> $envelope
     * @param array<string, string> $involved marketplace name => pin shown
     */
    private static function stampBundleRecommendations(string $stampFile, array $envelope, string $root, array $involved): void
    {
        if ($envelope === [] || !is_array($envelope['marketplaces'] ?? null) || !is_file($stampFile)) {
            return;
        }

        $shown = is_array($envelope['launchRecommendations'] ?? null) ? $envelope['launchRecommendations'] : [];
        $stamped = is_array($shown[$root] ?? null) ? $shown[$root] : [];

        foreach ($involved as $marketplaceName => $sha) {
            $stamped[$marketplaceName] = $sha;
        }

        $shown[$root] = $stamped;
        $envelope['launchRecommendations'] = $shown;

        try {
            AtomicJsonFile::new($stampFile)->withPermissions(0o600)->write($envelope);
        } catch (\Throwable) {
            // Fail-open per the doc-block: no stamp, not no notice.
        }
    }

    /**
     * The installed-bundle names under an install root — directories only,
     * dot entries skipped; the same list the `bundles` verb reads for its own
     * exclusions, copied rather than reached into because that walk is the
     * verb's private helper and this drain must not grow a dependency on an
     * argument parser to name one row.
     *
     * @return list<string>
     */
    private static function installedBundleNames(string $installedRoot): array
    {
        $entries = @\scandir($installedRoot);

        if ($entries === false) {
            return [];
        }

        $names = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || \str_starts_with($entry, '.')) {
                continue;
            }

            if (is_dir($installedRoot . '/' . $entry)) {
                $names[] = $entry;
            }
        }

        return $names;
    }

    /**
     * Note the applied keybinding map (TUI-4a) — the LAST step of the boot
     * pair, so the digest always describes the map the LIVE registry holds.
     * Digesting the registry state rather than file bytes is what keeps an
     * absent key and an empty map from reading as a change, and it needs no
     * file watcher or mtime: the reload compares this against the freshly
     * parsed setting, exactly the applied-vs-desired half of the MCP
     * `mcpConfigChangedSinceLaunch()` shape (E703-α).
     */
    private static function noteKeybindingsApplied(): void
    {
        LaunchContext::rememberKeybindingsAppliedDigest(self::keybindingsDigest(
            KeyBindingRegistry::overrides(),
            KeyBindingRegistry::suppressed(),
        ));
    }

    /**
     * Whether the setting behind `/keys reload` CANNOT BE READ, as opposed to
     * being read and refused: one of the user's OWN two files — the
     * `config.json` of layer 4 and the `settings.json` of layer 3, the files
     * that are the documented loud half of the settings read — is present and
     * its bytes do not decode as JSON AT ALL (a torn save; an empty map, a
     * scalar-root file that merely costs the key at the tolerant read, stays
     * readable). {@see rawUserConfig()} and
     * {@see LayeredSettings::readFile()} answer `[]` to exactly this shape for
     * every other reader (a corrupt config must cost a setting, never a
     * session), and the reload lane must not mistake that `[]` for "the
     * user removed every override". Null when every present user file
     * decodes; a raw reason (the shape `cli.keys.reload_refused` quotes) when
     * one does not.
     *
     * The PROJECT files are deliberately not in this census: a corrupt
     * project file drops the project tier's values at read, which is the
     * silent-half doctrine untouched, and the preset and every documented
     * `keybindings` producer live in the user's own files.
     *
     * @return ?string the refusal reason, or null when the reload may read
     */
    private static function keybindingsReloadParseFailure(): ?string
    {
        $paths = [self::userConfigPath()];
        $userDir = self::userSettingsDirOrNull();
        if ($userDir !== null) {
            $paths[] = \rtrim($userDir, '/') . '/' . LayeredSettings::USER_FILE;
        }

        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue; // absent is absent: the tolerant reading is correct
            }

            $contents = @file_get_contents($path);
            if ($contents === false) {
                return "the file '{$path}' is present but cannot be read";
            }

            if (json_decode($contents) === null && json_last_error() !== JSON_ERROR_NONE) {
                return "the file '{$path}' is present but is not valid JSON — a half-written save?";
            }
        }

        return null;
    }

    /**
     * `/keys reload`: re-read the `keybindings` setting and re-apply it to
     * the LIVE registry — plan_comparison TUI-4a. NO file watcher: the user
     * edits the file and says the word, the same stance the MCP digest
     * detection takes, and for the same reason — re-applying a repository-
     * adjacent choice without being asked re-arms surprises the freeze
     * closed. Absent launch digest (an embedder that never booted through
     * chat()) means the first reload APPLIES rather than claiming anything.
     *
     * Every announcement rides the RUNTIME sink, never the launch buffer:
     * after boot nobody drains `launchNotices()` again, so a reload row put
     * there is a row no one will read — and the launch-cap census roster
     * ({@see LAUNCH_NOTICE_BOUNDED_SOURCES}) stays byte-untouched by design.
     *
     * Fail-loud-keep-live: a present-but-unusable re-read costs the NEW map,
     * never the running one — the keymap a user pressed into is more true
     * than one half-typed into a file — with the refusal said out loud, in
     * the validator's own words (w5j: the RAW reason, not the launch
     * exception's "refusing to start" speech, which at reload time describes
     * a verdict no longer on the table).
     */
    public static function reloadKeybindings(): void
    {
        // Fail-loud-keep-live, the PARSE half (plan_comparison TUI-4a): the
        // tolerant reader costs the whole `keybindings` key when a user's own
        // file is half-written, and a reload that applied that absence would
        // announce "reloaded — 0 override(s) live" over the top of a save
        // torn mid-write. A corrupt file is not an empty map, and at reload
        // time the live keymap outranks both readings of it. (LAUNCH keeps its
        // silence: a corrupt config costs a setting, not the session — the
        // reload lane is the loud half of that doctrine, the same half "your
        // OWN two files" already belongs to.)
        $parseFailure = self::keybindingsReloadParseFailure();
        if ($parseFailure !== null) {
            RuntimeNoticeSink::warn(Lang::t('cli.keys.reload_refused', ['reason' => $parseFailure]));

            return;
        }

        [$map, $reason] = self::keybindingsParse();
        if ($reason !== null) {
            \SugarCraft\Code\Diagnostics\RuntimeNoticeSink::warn(
                Lang::t('cli.keys.reload_refused', ['reason' => $reason]),
            );

            return;
        }

        // useOverrides() splits the map the same way the reload must judge it.
        $overrides = [];
        $suppressed = [];
        foreach ($map as $id => $chord) {
            if ($chord === null) {
                $suppressed[] = $id;
                continue;
            }
            $overrides[$id] = $chord;
        }

        $digest = self::keybindingsDigest($overrides, $suppressed);
        if ($digest === LaunchContext::keybindingsAppliedDigest()) {
            \SugarCraft\Code\Diagnostics\RuntimeNoticeSink::warn(Lang::t('cli.keys.reload_unchanged'));

            return;
        }

        KeyBindingRegistry::useOverrides($map);
        LaunchContext::rememberKeybindingsAppliedDigest($digest);
        \SugarCraft\Code\Diagnostics\RuntimeNoticeSink::warn(
            Lang::t('cli.keys.reloaded', ['count' => \count($map)]),
        );

        // The rv6 warning half of ISS-047, on the live channel: an override
        // onto another row's shipped chord is a silent steal — the user must
        // hear it at the moment it happens, not at a launch already passed.
        foreach (KeyBindingsValidator::new()->collisionNotices(KeyBindingRegistry::overrides()) as $row) {
            \SugarCraft\Code\Diagnostics\RuntimeNoticeSink::warn($row);
        }
    }

    /**
     * The canonical hash of the applied keybinding state. Sorted so the
     * digest answers to the MAP, not to the order it was typed in.
     *
     * @param array<string, string> $overrides
     * @param list<string>          $suppressed
     */
    private static function keybindingsDigest(array $overrides, array $suppressed): string
    {
        \ksort($overrides);
        \sort($suppressed);

        return \hash('sha256', \json_encode([$overrides, $suppressed]) ?: '');
    }

    /**
     * plan_comparison TUI-5 stage 1: the Option-as-Meta hint for macOS
     * launches. PURE so every quadrant is pinned on a Linux host.
     *
     * WHY THE WORDING STAYS HONEST-UNCERTAIN: `App::init()` PUSHES
     * `CoreCmd::pushKittyKeyboard()` fire-and-forget — the app never sends
     * the query whose reply would prove negotiation, and the reply family has
     * no consumer anywhere in `src/`. So `kittyCapable` is an ENV probe
     * ({@see terminalSpeaksKittyKeyboard()}), not a receipt, and the row says
     * "may be" because on a Mac that asked the question badly, nobody knows
     * more than that.
     *
     * C-TUI-7.b: eligibility itself moved to {@see MacAltNotice::belongs()}
     * (the tmux-aware probe lives with the stamp it shares a subject with);
     * this method keeps the public shape and the translation step.
     *
     * @param string $osFamily the {@see PHP_OS_FAMILY} value
     */
    public static function macAltNotice(string $osFamily, bool $kittyCapable): ?string
    {
        if (!MacAltNotice::belongs($osFamily, $kittyCapable)) {
            return null;
        }

        return self::translated(self::MAC_ALT_NOTICE_TEXT);
    }

    /**
     * Whether the ambient terminal environment names a kitty-speaking
     * emulator — delegated to {@see MacAltNotice::terminalSpeaksKittyKeyboard()},
     * which since C-TUI-7.b also reads the emulator identity variables that
     * survive tmux, so a Mac inside tmux-on-kitty is no longer misread as
     * needing the Option-as-Meta lecture.
     */
    public static function terminalSpeaksKittyKeyboard(): bool
    {
        return MacAltNotice::terminalSpeaksKittyKeyboard();
    }

    /**
     * Record the Mac hint on the TUI channel when the launch is one it
     * belongs to. `record()` — not `warn()`: a headless run must not grow an
     * unsolicited stderr row, and an unarmed sink drops the row anyway
     * (which is the correct answer for a surface with no one to read it).
     *
     * C-TUI-7.b adds the once-per-version law: eligibility is judged FIRST
     * (a non-Mac launch never touches the stamp file), and the stamp is
     * consumed only when the row actually records — this method is called
     * after the sink is armed, so a headless launch whose sink is unarmed
     * shows nothing AND burns nothing. {@see MacAltNotice::showsOnThisLaunch()}
     * re-arms on every version change.
     */
    private static function announceMacAltFallback(): void
    {
        $row = self::macAltNotice(PHP_OS_FAMILY, self::terminalSpeaksKittyKeyboard());
        if ($row === null) {
            return;
        }

        if (!MacAltNotice::showsOnThisLaunch()) {
            return;
        }

        \SugarCraft\Code\Diagnostics\RuntimeNoticeSink::record($row);
    }

    /**
     * Real, on-disk memory store backing /memory. MemoryStore's constructor
     * requires its directory to already exist and be writable, so the
     * directory is created here first.
     */
    public static function memoryStore(?string $root = null): MemoryStore
    {
        $dir = self::configDir() . '/memory';
        self::ensureDir($dir);

        // KEYED BY THE PROJECT (audit 15d-05): the home store's `project` scope
        // is `project/<key>/` for the canonical project root, so a note written
        // with `--scope project` here never reaches another repository's
        // prompt. The root is the one `.candy-code/*` resolves against (audit
        // 15d-13 (b)), so a subdirectory launch reads its repository's notes. A
        // launch with no root keeps the unkeyed store.
        return $root === null || $root === ''
            ? new MemoryStore($dir)
            : MemoryStore::forProject($dir, ProjectRoot::resolve($root));
    }

    /**
     * {@see memoryStore()}, or null if it cannot be opened.
     *
     * The prompt-side consumer (crush_code.md Phase 5 item 9) must never be
     * able to stop a session starting. `memoryStore()` throws when
     * `~/.candy-code/memory` cannot be created or is not writable — a
     * read-only home, a tmpfs quota, an existing file where the directory
     * belongs — and while that is a real reason for `/memory` to report a
     * failure when the user invokes it, it is not a reason to refuse to launch.
     * So the *store* keeps throwing for `/memory` to surface, and the prompt
     * route degrades to "no memory block", which is exactly the prompt every
     * session had before this item.
     *
     * Same shape as {@see \SugarCraft\Code\Backend\EngineBackend::userConfig()}'s
     * guard: a broken optional input costs the feature, never the turn.
     */
    public static function memoryStoreOrNull(?string $root = null): ?MemoryStore
    {
        try {
            return self::memoryStore($root);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * ~/.candy-code — the same per-user config directory convention every
     * other stateful store in this codebase already uses (e.g.
     * Skills\SkillDiscovery, Agents\TeamManager, Workflows\WorkflowEngine).
     */
    private static function configDir(): string
    {
        $dir = self::configDirPath();
        self::ensureDir($dir);

        return $dir;
    }

    /**
     * Where {@see configDir()} would be, without creating it — the resolver on
     * its own, so read-only callers can name the directory without the naming
     * having a side effect on disk.
     */
    private static function configDirPath(): string
    {
        return self::homePath() . '/.candy-code';
    }

    /**
     * This user's home directory, or the least-surprising stand-in when
     * nothing can say — the one resolution every `~`-rooted path in this class
     * goes through, so the config directory and a `trustedProjectHooks` entry
     * written `~/src/repo` can never disagree about where `~` is.
     *
     * WHICH READERS STILL GET THE STAND-IN, stated exactly, because the older
     * wording here ("everything that is POLICY goes through
     * trustedConfigDirPath()") was not true of `~/.candy-code/agents`, whose
     * presets carry `permissionMode:` and `tools:`. That directory now resolves
     * through {@see trustedConfigDirPath()} as well, alongside `config.json`
     * and `hooks.yaml`. What is left on this path is the state whose worst
     * outcome is a lost setting or a lost transcript: the theme, the persisted
     * provider, the session store, the memory store, and the skill trees.
     *
     * Those are not policy, but they are not nothing either — a skill body is
     * prompt context — so the ORDER is what protects them: {@see chat()},
     * {@see backend()} and {@see backendFor()} each resolve
     * {@see trustedConfigDirPath()} before they build anything, so a launch
     * that cannot determine this user's home refuses before a store is created
     * or a skill tree is scanned, rather than after. Direct callers of the
     * individual store factories still get the stand-in; that is the documented
     * boundary, not an oversight.
     */
    private static function homePath(): string
    {
        return HomeDirectory::path();
    }

    /**
     * This user's home directory when it can actually be DETERMINED, or null.
     *
     * The environment failing to say where home is does not mean nobody knows:
     * a cron entry, a systemd unit, `env -i` and `sudo` without `-E` all drop
     * `HOME` while the process still has a real uid with a real passwd entry,
     * so the passwd database is consulted before giving up. That is what keeps
     * the answer the user's OWN directory on every path that previously fell
     * through to a shared one.
     *
     * Null — nothing in the environment, no passwd entry, or no ext-posix — is
     * "this process cannot tell whose home this is", which is a different
     * answer from any directory and is why this returns null rather than
     * inventing one.
     */
    private static function resolvedHomePath(): ?string
    {
        return HomeDirectory::resolved();
    }

    /**
     * {@see configDirPath()} for the two files that are SECURITY POLICY rather
     * than preference — `config.json` (permission mode, permission rules,
     * `trustedProjectHooks`) and `hooks.yaml` (shell commands run on tool
     * calls) — and therefore the one path resolution that may not fall back to
     * a directory anybody else can write.
     *
     * `/tmp` is mode 1777. With `HOME` unset and no passwd entry,
     * {@see homePath()}'s stand-in made `/tmp/.candy-code/hooks.yaml` the
     * "user's own file, which no repository can write" that
     * {@see hookFiles()} loads WITHOUT the project-trust gate — so any local
     * user could pre-create that directory and get arbitrary shell on the
     * session's first tool call, plus a `config.json` setting
     * `permissionMode` and `trustedProjectHooks`. The ungating is sound; the
     * premise it rests on ("you wrote it") is what stopped being true.
     *
     * Refusing is the only outcome that is neither of the two wrong ones: a
     * launch that reads a stranger's policy, or one that silently ignores the
     * policy the user did write. It costs one exported variable to fix and
     * says so.
     *
     * TWO REFUSALS, not one, and the second is new because the first was being
     * CITED as the second. This method used to ask {@see resolvedHomePath()},
     * which answers "can a home be NAMED" — a question `HOME=/tmp` passes. The
     * `/tmp` stand-in the paragraph above describes as the thing being defended
     * against was therefore reachable simply by pointing `HOME` at it, and
     * {@see agentPresets()} carried a comment asserting this method "refuses a
     * home this process cannot establish ownership of" when no `stat` was
     * performed anywhere in the package. MEASURED before {@see HomeDirectory::owned()}:
     *
     *     HOME=<mode 1777 dir>  ->  trustedConfigDirPath() = <that dir>/.candy-code
     *                           ->  user-tier presets read from it = ["notmine"]
     *
     * The second refusal is that measurement closed: the resolved home must
     * exist, must not be world-writable, and must be owned by this process's
     * effective uid. Its bounds — group-writable homes accepted, ownership
     * clause skipped on non-POSIX hosts — are written where the check is.
     *
     * @throws PermissionConfigException when this user's home cannot be determined
     *         or cannot be established as this user's
     */
    public static function trustedConfigDirPath(): string
    {
        $home = self::resolvedHomePath();
        if ($home === null) {
            throw new PermissionConfigException(Lang::t('cli.refuse.no_home'));
        }

        $owned = HomeDirectory::owned();
        if ($owned === null) {
            throw new PermissionConfigException(sprintf(
                'the home directory this process resolved (%s) cannot be established as yours — it does not '
                . 'exist, or it is world-writable, or it is owned by another account. The permission policy '
                . 'and hook chain in ~/.candy-code would then be whatever the last local user to write '
                . 'there put in them, so they are not read. Export HOME to the account this session belongs '
                . 'to, or fix that directory\'s ownership and mode.',
                $home,
            ));
        }

        return $owned . '/.candy-code';
    }

    /**
     * The throw IS the report (audit CLI-1). An unsilenced mkdir() warning
     * goes wherever `display_errors` points, which is STDOUT on a php-cli with
     * no php.ini, so it lands ahead of the `--output-format json` document.
     * Every caller here either surfaces the exception or degrades on it, so the
     * warning is caught here and its text goes into the message instead.
     *
     * A scoped handler rather than `@` + error_get_last(): when the host has
     * an error handler of its own that returns true (PHPUnit's does), PHP
     * never records the error for error_get_last(), and the reason would be
     * lost.
     */
    private static function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        $reason = null;
        set_error_handler(static function (int $errno, string $message) use (&$reason): bool {
            $reason = $message;

            return true;
        });
        try {
            $made = mkdir($dir, 0700, true);
        } finally {
            restore_error_handler();
        }

        if (!$made && !is_dir($dir)) {
            throw new \RuntimeException(
                "Failed to create directory: {$dir}" . ($reason !== null ? " ({$reason})" : ''),
            );
        }
    }
}
