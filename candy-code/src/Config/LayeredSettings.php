<?php

declare(strict_types=1);

namespace SugarCraft\Code\Config;

use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\Settings\SettingType;
use SugarCraft\Code\Config\Settings\SettingsSchema;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Support\ContainedPath;

/**
 * The settings-file layers behind {@see \SugarCraft\Code\Cli\Bootstrap::readUserConfig()}.
 *
 * Until this class there was ONE settings file — `~/.candy-code/config.json` —
 * and a project had no way to say anything at all. The layers now are, LOWEST
 * PRECEDENCE FIRST:
 *
 *   1. `<root>/.candy-code/settings.json`       project, meant to be committed
 *   2. `<root>/.candy-code/settings.local.json` project, meant to be ignored
 *   3. `~/.candy-code/settings.json`            user, hand-authored
 *   4. `~/.candy-code/config.json`              user, WRITTEN BY THE CLI
 *
 * Merged key by key, highest present key wins. Two rules make that ordering
 * statable in one sentence each, and both are choices with a cost:
 *
 * THE USER'S FILES OUTRANK THE PROJECT'S — the reverse of the convention most
 * editors use, where a project overrides a user. Taken deliberately: layer 1
 * ARRIVED WITH A CLONE. If the project outranked the user, a checkout could
 * change a setting the user had already made for themselves, and the user's own
 * file would look broken. Here a project can only fill in what the user left
 * unsaid, which is the whole of what a project legitimately knows.
 *
 * `config.json` OUTRANKS `settings.json`, and it is the OLDER of the two names
 * rather than a deprecated one — nothing in `src/` marks it deprecated, and it
 * is still the file the CLI writes, so calling it deprecated (as an earlier
 * version of this paragraph did) tells a reader to migrate off the only file
 * that gets written. It is the file
 * {@see \SugarCraft\Code\Cli\Bootstrap::writeUserConfig()} writes, and
 * EXACTLY TWO KEYS reach it through the chat's config-change door: `provider`
 * and `theme`. (The shell writes `layout` through its own hook, and the
 * settings editor writes whatever key it is told to through
 * {@see \SugarCraft\Code\Config\Settings\SettingsWriter}, its own censused
 * door.) Those two are the values `onConfigChange` is ever invoked with, and
 * EACH HAS TWO PRODUCERS — a palette row and a slash command:
 *
 *  - `provider`: the Ctrl+P palette's "Switch Model" row, and `/model <name>`.
 *  - `theme`:    the Ctrl+P palette's "Switch Theme" row, and `/theme <name>`.
 *
 * AN EARLIER VERSION OF THIS SENTENCE CREDITED THE PALETTE ALONE FOR
 * `provider`, and the omission is the interesting half rather than a typo.
 * WHAT IT SAID: "`provider`, from the Ctrl+P palette's 'Switch Model' action".
 * WHAT IS TRUE NOW: `/model <name>` reaches the identical write. FOLLOWED, not
 * inferred: {@see \SugarCraft\Code\Chat}'s `handleModelCommand()` ends in
 * `selectPaletteProvider($args[0])`, and `selectPaletteProvider()` is the sole
 * site that calls `$onConfigChange('provider', …)` — the palette row and the
 * command are not two writers, they are one writer with two doors. WHY THIS
 * STILL EARNS ITS PLACE: the palette is still the discoverable door, and a
 * reader who only ever presses Ctrl+P is not misled by naming it first. What
 * the old wording cost was the reader hunting for where a `/model` choice went,
 * on a page that had just told them exhaustively which keys are written.
 * `README.md`'s layer table already credited `/model`; two documents disagreed
 * and the less-read one was right. Pinned by
 * {@see \SugarCraft\Code\Tests\Config\ConfigWriteProducerDocumentationDriftTest}.
 *
 * None of the four persists a MODEL — the palette row is named for what a user
 * thinks they are choosing, not for the key it writes. The model choice is the
 * per-provider `models` key (decision D9), and the settings editor is what
 * writes it.
 *
 * Ranked the other way round, a `settings.json` that names `theme` would break
 * PERSISTENCE, not the visible command, and the distinction is the whole
 * diagnosis. MEASURED on PHP 8.3.6 on 2026-08-22 (8.4 was NOT exercised on this
 * box; nothing in the path is believed version-sensitive): `/theme dracula`
 * against a `Chat` constructed with `themeName: 'dark'` returns a `Chat` whose
 * `theme()->name` is already `dracula` — `/theme` mutates the live `Chat`, so
 * it repaints at once whatever any file says — and fires
 * `onConfigChange('theme', 'dracula')` in the same update. Only the NEXT launch
 * reads the merge, so under the reversed ordering the session would repaint,
 * then silently revert, with no error anywhere and nothing in the UI pointing
 * at the file responsible. AN EARLIER VERSION OF THIS PARAGRAPH said every
 * `/theme` would "appear to do nothing", which is the coarser and wrong
 * version: it sends a reader looking for a broken command instead of a
 * settings file. It is rewritten rather than dropped because the CONCLUSION it
 * reached is still the right one — the file that WRITES has to be the file
 * that DECIDES — and a reader who finds only the conclusion re-derives the
 * wrong reason for it. `docs/SETTINGS.md` carried the persistence framing
 * first; this is the doc-block catching up. Pinned by
 * {@see \SugarCraft\Code\Tests\Config\ThemePersistenceFramingTest}.
 *
 * WHAT IS NOT LAYERED, and this is the security boundary rather than a scoping
 * shortcut: any key absent from {@see LAYERED_KEYS} is answered by layer 4
 * ALONE, exactly as before this class existed. So `permissionMode`,
 * `permissionRules`, `trustedProjectHooks`, `trustedProjectMcp`,
 * `trustedProjectCommands` and {@see PROJECT_SETTINGS_TRUST_KEY} itself cannot
 * be written, weakened or self-granted from any lower layer — a project cannot
 * add itself to the list of trusted projects.
 *
 * `permissionMode`/`permissionRules` being absent from {@see LAYERED_KEYS} does
 * NOT mean they are unreadable from `~/.candy-code/settings.json`: they are, as
 * of Phase 6 item 4. It means they do not come through THIS class, whose reader
 * is deliberately tolerant. {@see LAYERED_KEYS} carries the full argument.
 * Since C-SEC-6.b the SAME is true one layer down: the trusted project's
 * shared settings file contributes its `permissionRules` — deny/ask entries
 * only, allow entries dropped, prepended so they can narrow and never widen —
 * through `Bootstrap::projectNarrowingRules()`, again never through this
 * class's tolerant merge, and `permissionMode` is still read from NO project
 * file at any trust level.
 *
 * The whitelist is also why nothing decorative can slip
 * in: every key in it is named by a real reader, cited on
 * {@see LAYERED_KEYS}, and a key nothing reads is worse than a missing one
 * because it looks configurable.
 *
 * RE-READ EVERY TURN, unlike the trust list that gates it, and the asymmetry is
 * worth knowing before relying on either. `EngineBackend` calls
 * {@see \SugarCraft\Code\Cli\Bootstrap::readUserConfig()} once per turn, so
 * all four files are opened again each time: MEASURED, writing
 * `.candy-code/settings.local.json` mid-session flips `disabledSkills` on the
 * next turn. The TRUST list is frozen per process
 * ({@see \SugarCraft\Code\Cli\Bootstrap::$trustedSettingsRoots}), so a
 * project cannot become trusted mid-session — but once it is trusted, a tool
 * call inside a turn can change what its own next turn reads. Defensible,
 * because the operator opted this repository in and the project tier cannot
 * reach `provider`, `instructions` or any permission key; stated because
 * "trusted, frozen" would otherwise read as covering both.
 *
 * `.candy-code/settings.local.json` GETS THE SAME GATE AS THE TRACKED FILE,
 * which is worth stating because the convention says "local" and `.gitignore`
 * says it is not committed. Neither is a property of a repository someone else
 * wrote: `.gitignore` is advice to the committer, `git add -f` overrides it,
 * and a hostile checkout can therefore ship a `settings.local.json` as readily
 * as a `settings.json`. The two differ in PRECEDENCE only. Treating "local" as
 * "mine, therefore trusted" would have been the whole gate defeated by a
 * filename.
 */
final class LayeredSettings
{
    /**
     * The project's shared settings file, relative to the project root.
     *
     * ONE LITERAL, not `'.candy-code' . '/' . 'settings.json'`, and the reason
     * is an instrument rather than taste:
     * {@see \SugarCraft\Code\Tests\Cli\ProjectTierRefusalInventoryTest}
     * derives its census of repository-chosen dot-paths from string literals in
     * `src/`, and a path assembled from fragments is its stated blind spot. A
     * project-tier read path spelled in halves would have been invisible to the
     * one instrument that exists to enumerate project-tier read paths — the same
     * trap {@see \SugarCraft\Code\Agents\WorktreeConfig::readConfig()} names
     * in its own comment. {@see dir()} splits it back with `dirname()`.
     */
    public const SHARED_PATH = '.candy-code/settings.json';

    /**
     * The project file meant to be `.gitignore`d — see the class doc-block on
     * why that is not a trust signal. Spelled in full for {@see SHARED_PATH}'s
     * reason; {@see testTheTwoProjectFilesLiveInOneDirectory()} pins that the two
     * literals name the same directory so they cannot drift apart.
     */
    public const LOCAL_PATH = '.candy-code/settings.local.json';

    /**
     * The user-tier hand-authored file's basename, joined to a directory by
     * {@see userLayer()} — whose choice of directory is the caller's, and in
     * production is {@see \SugarCraft\Code\Cli\Bootstrap::userSettingsDirOrNull()}:
     * `trustedConfigDirPath()`, the home-OWNED `~/.candy-code`.
     *
     * NOT a dot-path literal, unlike the two above, because there is no
     * repository-chosen component in the name. The DIRECTORY is where the
     * gating lives, and the first cut of this doc-block named the wrong one: it
     * said `configDirPath()`, "rooted at `~` and already gated by the
     * home-ownership check", while `Bootstrap::readUserConfig()` was in fact
     * passing `dirname(userConfigPath())` — which follows `--config` and is
     * therefore neither rooted at `~` nor gated by anything. Two wrong halves
     * that read as one right sentence. Both are corrected: the caller now
     * resolves the home-owned directory, and this names the method it resolves
     * it with. See `Bootstrap::readUserConfig()` for the measurement.
     */
    public const USER_FILE = 'settings.json';

    /**
     * The directory both project files live in, relative to the project root —
     * `dirname()` of the literals above rather than a third constant that could
     * disagree with them.
     */
    public static function dir(): string
    {
        return \dirname(self::SHARED_PATH);
    }

    /**
     * The user-config key listing project roots whose settings files may be
     * read at all — a FOURTH key of the shape
     * {@see \SugarCraft\Code\Cli\Bootstrap::trustedProjectRoots()} already
     * parses for hooks, MCP and command files, and separate from all three for
     * their reason: trusting a repository to start a server is not the same
     * grant as trusting it to choose a model, and one key covering both would
     * make the narrower grant unavailable.
     */
    public const PROJECT_SETTINGS_TRUST_KEY = 'trustedProjectSettings';

    /**
     * Every key a lower layer may contribute, each with the reader that makes
     * it real. Nothing is listed here speculatively.
     *
     *  - `provider`   {@see \SugarCraft\Code\Cli\Bootstrap::selectedProviderName()},
     *                 and `backend()`'s persisted-provider tier.
     *  - `theme`      `Bootstrap::chat()`, as `Chat`'s `themeName`.
     *  - `models`     {@see \SugarCraft\Code\Cli\Bootstrap}'s `selectedModelName()`:
     *                 `{"<provider>": "<model id>"}`, the model a provider runs
     *                 when neither `--model` nor `$CANDY_CODE_MODEL` names one
     *                 (decision D9), read for the provider being built at
     *                 launch and at every `/model` switch. USER-TIER ONLY on the
     *                 money axis `titleModel` below explains: a model choice is
     *                 a price choice.
     *  - `titleModel` `Bootstrap::titleBackend()` via `toollessBackend()`.
     *  - `summaryModel` `Bootstrap::summaryBackend()`, same helper.
     *  - `instructions` {@see \SugarCraft\Code\Cli\Bootstrap::forcedInstructions()}.
     *  - `disabledSkills` `Bootstrap::skillRegistry()`.
     *  - `disabledRules` `Bootstrap::rulePacksToDisable()`, whose list `Bootstrap::chat()`
     *                 hands to {@see \SugarCraft\Code\Context\RulesState::new()} so the
     *                 user-tier packs named there are out of the prompt from the first
     *                 turn. INERT OUTSIDE THE USER TIER, and the qualifier goes here
     *                 rather than only further down because this list is what readers
     *                 scan: a name pointing at `<repo>/.candy-code/rules` or the
     *                 repository root's `RULES.md` selects nothing a session may
     *                 silence, since {@see \SugarCraft\Code\Context\RulesState}'s
     *                 `TOGGLEABLE_TIER` is `'user'` — the argument is below.
     *  - `parallelToolCalls` / `parallelToolDeadlineSeconds`
     *                 {@see \SugarCraft\Code\Backend\EngineBackend}'s per-turn
     *                 dispatch settings, which read through `readUserConfig()`.
     *  - `maxOutputTokens`
     *                 {@see \SugarCraft\Code\Backend\EngineBackend}'s per-request
     *                 OUTPUT ceiling, read on the same per-turn path and threaded
     *                 onto {@see \SugarCraft\Code\Providers\CompleteRequest::$maxTokens}
     *                 (E707); unset means the key is absent from the request and
     *                 each provider's own default applies.
     *  - `modelPrices`
     *                 the OpenAI, Vertex and Bedrock providers' rate tables —
     *                 USD per 1M tokens per model (`{"input": …,
     *                 "output": …}`), read by
     *                 {@see \SugarCraft\Code\Providers\ProviderFactory::createOpenAI()},
     *                 `createVertex()` and `createBedrock()` (the last two since
     *                 audit A15) off this merged view and passed as each
     *                 provider's price overrides. It is USER-TIER ONLY on the same money axis as
     *                 `maxOutputTokens`, mirrored: that key can raise what ONE
     *                 request bills, this one can lower what EVERY token bills —
     *                 a project-supplied map could zero a rate and silently blind
     *                 the spend cap and `/budget` totals, which is the exact
     *                 failure the unpriced-model notice exists to make loud.
     *  - `allowedTools` / `disabledTools`
     *                 {@see \SugarCraft\Code\Cli\Bootstrap::tools()}, which
     *                 filters the model-facing tool set before any of its three
     *                 `withTools()` call sites sees it.
     *  - `statusLine` {@see \SugarCraft\Code\Config\StatusLineCommand::fromSettings()},
     *                 installed by `Bootstrap::chat()` and painted by
     *                 {@see \SugarCraft\Code\Renderer::renderStatusBar()}.
     *  - `lintCommands`
     *                 `Bootstrap::hooks()`, into the post-edit lint hook's
     *                 runner (step 3.E): file extension => lint command, or
     *                 `false` to turn a built-in default off. USER-TIER ONLY
     *                 for `statusLine`'s reason below — every value is a
     *                 shell command the hook RUNS after an edit, so a
     *                 project-tier one would be code execution on
     *                 clone-and-launch.
     *  - `formatCommands`
     *                 `Bootstrap::hooks()`, into the post-edit format hook's
     *                 runner (EDT-1): file extension => format command, or
     *                 `false` to turn one off. USER-TIER ONLY for
     *                 `lintCommands`' reason directly above it — every value
     *                 is a shell command, and unlike the lint this one
     *                 WRITES the file (which is why `FormatRunner` hashes
     *                 before and after and puts the bytes back when a
     *                 formatter corrupts them).
     *  - `lspAutoDetect` {@see \SugarCraft\Code\LSP\LspLauncher::fromConfig()},
     *                 reached from `Bootstrap::lspClient()` (EDT-2): lets the
     *                 project FINGERPRINTS start their language servers. USER-
     *                 TIER ONLY for `lsp`'s reason — detecting is reading, but
     *                 the detection's output is a LAUNCH, and a checkout that
     *                 could switch this on would name servers through its
     *                 files after all.
     *  - `layout`   {@see \SugarCraft\Constraints\Dock\DockLayout::fromArray()},
     *                 loaded by {@see \SugarCraft\Code\Cli\Bootstrap::app()}
     *                 onto {@see \SugarCraft\Code\App\App::$dock} and saved
     *                 back by the App's `onLayoutChange` hook through
     *                 `Bootstrap::writeUserConfig()` — the shell's pane
     *                 docking geometry, which sidebars exist within.
     *  - `maxToolSteps`
     *                 {@see \SugarCraft\Code\Cli\Bootstrap}'s per-turn provider
     *                 call ceiling, resolved once at backend construction
     *                 (`resolvedMaxToolSteps()`) and threaded onto
     *                 {@see \SugarCraft\Code\Backend\EngineBackend::$maxSteps}
     *                 (F2); unset keeps the shipped default of 8, and a turn
     *                 that exhausts the ceiling now says so in the transcript
     *                 instead of stopping silently. USER-TIER ONLY on the same
     *                 money axis as `maxOutputTokens`: this key multiplies how
     *                 many billed calls ONE turn may fan out, so a checked-out
     *                 repository raising it would spend the operator's
     *                 credential on the project's behalf — the argument
     *                 `maxOutputTokens`'s bullet above already makes, with the
     *                 multiplier pointed the other way.
     *  - `secretEnvAllowlist`
     *                 {@see \SugarCraft\Code\Support\ProcessContainment::useSecretEnvAllowlist()},
     *                 installed by `Bootstrap::tools()` and `Bootstrap::hooks()`
     *                 (`installSecretEnvAllowlist()`): the credential-shaped
     *                 variable names (or globs) that Bash, Grep and script hooks
     *                 still inherit after the audit F-E1 scrub
     *                 ({@see \SugarCraft\Code\Support\ProcessContainment::scrubbedEnv()}).
     *                 USER-TIER ONLY because its whole effect is to hand the
     *                 operator's credentials to model-visible processes: a
     *                 checked-out repository that could set it could write
     *                 `["*"]` and read every key in the operator's shell back
     *                 through one `env` call — the exfiltration the scrub exists
     *                 to close, re-opened by a file that arrived with a clone.
     *
     *  - `contextWindow`
     *                 {@see \SugarCraft\Code\Providers\OpenAIProvider::contextWindow()}'s
     *                 override (audit A13), read by
     *                 {@see \SugarCraft\Code\Providers\ProviderFactory::createOpenAI()}:
     *                 a token count for whatever model the provider runs, or
     *                 `{"<model>": tokens}` per model. USER-TIER ONLY because it
     *                 is the denominator of every context tier: a checkout that
     *                 inflated it would switch automatic compaction and the
     *                 blocking refusal off, so requests grow until the server
     *                 rejects them - on the operator's bill.
     *  - `extraBody`
     *                 top-level request fields for the `custom` provider (audit
     *                 A10), read by `ProviderFactory::createCustom()` and
     *                 validated by {@see \SugarCraft\Code\Providers\CustomProvider}.
     *                 USER-TIER ONLY on the money axis: a field such as `n`
     *                 multiplies what every request bills, and the value is sent
     *                 to whichever host the operator's provider names.
     *  - `thinkingBudget`
     *                 Gemini's `thinkingConfig.thinkingBudget` on the `vertex`
     *                 provider (audit A21 b) and the `budget_tokens` of
     *                 Anthropic extended thinking on the `anthropic` provider
     *                 (CTX-1 §6.3, floor 1024 - values the arm cannot use are
     *                 refused at construction, not dropped), read by
     *                 `ProviderFactory::createVertex()` / `createAnthropic()`.
     *                 Thinking tokens bill as
     *                 output, so this is `maxOutputTokens`'s argument on the
     *                 reasoning half of the reply: USER-TIER ONLY.
     *  - `promptCache`
     *                 whether the `vertex` (Anthropic arm) and `bedrock`
     *                 providers mark prompt-cache breakpoints (audit A15), read
     *                 by `ProviderFactory::createVertex()` / `createBedrock()`;
     *                 on unless `false`, and `CANDY_CODE_DISABLE_PROMPT_CACHE`
     *                 outranks it. USER-TIER ONLY on the money axis: switching
     *                 caching off makes every request bill its whole prompt at
     *                 the full input rate, a bill a checkout may not choose.
     *  - `includeGitInstructions`
     *                 whether the Bash tool's generic `<git_commits>` guidance
     *                 rides the system prompt (step 0.3), read by
     *                 {@see \SugarCraft\Code\Cli\Bootstrap::tools()} into
     *                 {@see \SugarCraft\Code\Tools\BuiltIn\Bash::withGitGuidance()};
     *                 on unless `false`. A project MAY set it - see
     *                 {@see PROJECT_TIER_KEYS}.
     *  - `attribution`
     *                 `{"commit": "…", "pr": "…"}`, the trailer that guidance
     *                 tells the model to end every commit message (and the line
     *                 to end every pull-request description) with; an empty or
     *                 missing string adds none. Same reader. USER-TIER ONLY:
     *                 it is text the model is instructed to stamp, verbatim,
     *                 on every commit it makes under the operator's identity,
     *                 and a checkout choosing that text would be a repository
     *                 writing into the operator's own history.
     *  - `enabledSkills`
     *                 the skills whose FULL BODIES ride the system prompt every
     *                 turn, read by
     *                 {@see \SugarCraft\Code\Cli\Bootstrap::promptEnabledSkills()}
     *                 at both composition sites. It was answered by `config.json`
     *                 alone until settings step N-DOC-2 — a surprise beside its
     *                 sibling `disabledSkills`, which IS layered — and is now
     *                 read from `~/.candy-code/settings.json` too. USER-TIER
     *                 ONLY, on `instructions`' argument: a name here makes a
     *                 skill's body standing, authoritative prompt text, so a
     *                 project value would let a checkout promote any skill it
     *                 ships into every turn. `disabledSkills`, which a project
     *                 MAY set, only ever removes one.
     *
     * `statusLine` WAS THE ONLY KEY HERE WHOSE VALUE IS A COMMAND, and that is
     * why it is user-tier only ({@see PROJECT_TIER_KEYS} does not list it).
     * `lintCommands` (step 3.E) is the second, and is user-tier only for the
     * same reason: each of its values is a command the post-edit lint hook
     * runs. `formatCommands` (EDT-1) is the third and needs no new argument:
     * its values run after an edit too, and they WRITE the file. Every other key on this list names a preference, a model, a glob
     * or a tool name — a value some later reader interprets. This one names a shell
     * command that {@see \SugarCraft\Code\Config\StatusLineCommand::run()}
     * executes on a timer, with no tool call and no permission gate anywhere
     * in the path. The argument `provider` and `instructions` make below
     * applies here in its strongest form: a project-tier `statusLine` would be
     * arbitrary code execution on clone-and-launch. Those two let a checkout
     * choose where a prompt is sent and which text is authoritative; this one
     * would let it choose what RUNS.
     *
     * It is a NESTED object — `{"type": "command", "command": "…"}` — which is
     * the shape the `allowedTools`/`disabledTools` note below says was refused
     * for the tool keys, so the difference is worth stating rather than
     * looking like an inconsistency. That refusal is about {@see merge()}
     * being KEY-WISE across TIERS: `tools.allow` and `tools.deny` belong to
     * different tiers, so one key could not carry both. `statusLine`'s two
     * fields belong to the SAME tier and are meaningless apart — a `type` with
     * no `command` runs nothing — so there is no merge for them to lose. The
     * shape is also Claude Code's, which is what makes a `settings.json`
     * written for that tool carry over unchanged.
     *
     * `layout` is user-tier only for the plain reason that frame geometry is
     * the OPERATOR's habit, not the repository's business: nothing a checked-out
     * project could say about it would be more authoritatively "theirs" than the
     * placement the human just dragged into place. It is also the second nested
     * value on this list, and the `statusLine` note above applies to it unchanged
     * — every field of the manifest belongs to the SAME tier and is meaningless
     * spread across two, so there is no key-wise merge for it to lose. Unlike
     * `statusLine` it carries no executable string at all: the value is the
     * versioned {@see \SugarCraft\Constraints\Dock\DockLayout::toArray()} manifest
     * (slot ids, weights, column shares, minimums), a reader that interprets and
     * never runs.
     *
     * `allowedTools` / `disabledTools`, NOT a nested `tools: {allow, deny}`,
     * and the shape is forced rather than chosen: {@see merge()} is KEY-WISE,
     * so a single `tools` key would be replaced whole by whichever layer set it
     * — and the two halves belong to DIFFERENT TIERS
     * ({@see PROJECT_TIER_KEYS} takes `disabledTools` and refuses
     * `allowedTools`). Nested under one key, a project's `tools.deny` and a
     * user's `tools.allow` could not coexist, and gating one half without the
     * other would be unexpressible. The spelling follows `disabledSkills`,
     * which is the same idea one layer up.
     *
     * `disabledRules` LOOKS LIKE IT SHOULD FOLLOW `disabledSkills` AND IT DOES
     * NOT, so the difference is stated here rather than left for a reviewer to
     * ask about. Both are disable-lists, and a disable-list holds NAMES, not
     * contents — so the `instructions` argument ("its file contents become
     * prompt text", {@see userTierOnlyKeys()}) cannot decide this one either
     * way on its own. What decides it is what the names SELECT: `disabledSkills`
     * subtracts from a capability set the harness enforces, and "this repo has
     * no use for the terraform skill" is a thing the checkout genuinely knows
     * better than the operator. A rule pack is the OPERATOR'S OWN PROMPT TEXT —
     * the user tier's `rules/` and `rulebooks/` directories — and
     * {@see \SugarCraft\Code\Context\RulesState} draws the same line from the
     * other side: `TOGGLEABLE_TIER` is `'user'`, so not even the interactive
     * `/rules` command may silence a repository-authored pack. A project value
     * here would be a checkout silencing prose the operator wrote about their
     * own working style, under a trust grant whose stated meaning is "start my
     * servers and pick my theme".
     *
     * `maxOutputTokens` IS USER-TIER ONLY (not listed on {@see PROJECT_TIER_KEYS}),
     * and the reason is the shape of the value rather than what it names: it is
     * the ONLY layered key whose meaningful direction is UP. Every other
     * project-adjacent key either REMOVES capability (`disabledSkills`,
     * `disabledTools` — they shrink a set the harness already bounded), or
     * BOUNDS throughput
     * (`parallelToolDeadlineSeconds` — a smaller number is the failure mode a
     * repository could pick, and it costs time, not money). A larger output
     * ceiling is a bigger paid request on the user's credential, repeated every
     * step of the agentic loop; a checkout that could raise it is a checkout
     * choosing how much of the operator's bill to run up per turn. That is the
     * `provider` argument restated on the spend axis — the money decision stays
     * with whoever pays it — and it is why the key lands beside the two
     * dispatch settings it shares a reader with, NOT inside the project tier
     * they were admitted to. (E707, round 81.)
     *
     * NO PERMISSION KEY HERE, and this is the one omission a reader of Phase 6
     * item 4 will come looking for. `permissionMode` and `permissionRules` ARE
     * readable from `~/.candy-code/settings.json` as of that item — but NOT
     * through this class, because this class's reader is TOLERANT by contract
     * (a malformed file is the absence of a layer, {@see readFile()}) and the
     * permission path may not be: `Bootstrap::permissionGate()` refuses to
     * start on a policy file it cannot parse, precisely so a stray comma cannot
     * silently downgrade a session to the permissive default. Routing the
     * permission keys through {@see merge()} would have handed them that
     * tolerance. They are read instead by
     * {@see \SugarCraft\Code\Cli\Bootstrap::permissionSettingsLayer()},
     * which applies the strict reader to the same user-tier file, and whose
     * result `Bootstrap::permissionConfigLayers()` puts BENEATH `config.json`.
     * C-SEC-6.b added the project tier's shared settings file to the STRICT
     * side of this door — but beside it, not inside it:
     * `Bootstrap::projectNarrowingRules()` reads that one file's
     * `permissionRules` under the trust grant, keeps deny/ask entries only,
     * and prepends them to the merged result, so the project can narrow the
     * session and cannot grant itself anything. `permissionMode` remains
     * user-tier-only at every trust level, exactly as this paragraph has
     * always argued. (`permissionPolicy()` is what this line said
     * before, and no such method has ever existed — a `{@see}` naming a symbol
     * that is not there is the same defect as a decorative config key, one
     * layer up: it reads as a reader and is not one.) Listing these keys here
     * would have been the opposite of the no-decorative-surface rule: a key
     * with a reader that ignores it.
     *
     * NO TOP-LEVEL `model` KEY, deliberately, and it is the one name a reader
     * expects to find here. Nothing reads a top-level `model` out of the user
     * config: `Subcommands::models()`'s `$config['model']` is a PROVIDER config
     * out of `Bootstrap::availableProviders()`. The session's model IS persisted
     * now (decision D9), but as `models`, keyed by provider, because one model
     * id is meaningless to every other provider — a flat `model` would send the
     * last provider's model to the next one a `/model` switch picked. Adding
     * `model` beside it would be surface with no reader — configurable-looking
     * and inert.
     *
     * GENERATED FROM THE SCHEMA (DH-KEYS): the list between the two marker
     * comments is written by `tools/gen-settings-doc.php --write` from
     * {@see \SugarCraft\Code\Config\Settings\SettingsSchema::layeredKeys()}, in
     * schema order, and never edited by hand. A key joins by gaining a
     * `withLayered()` row in its category's file under `Config/Settings/Definitions/`;
     * {@see \SugarCraft\Code\Tests\Config\Settings\SettingsSchemaDocDriftTest}
     * reds until the generator has been re-run, so the constant the merge filters
     * on and the schema the docs are generated from cannot disagree. The reader
     * list above is the argument for each key, and it is still hand-written.
     *
     * @var list<string>
     */
    public const LAYERED_KEYS = [
        // settings:layered-keys:begin — generated by tools/gen-settings-doc.php from SettingsSchema; never hand-edit
        'provider',
        'models',
        'providers',
        'titleModel',
        'summaryModel',
        'advisorModel',
        'maxOutputTokens',
        'modelPrices',
        'extraBody',
        'thinkingBudget',
        'reasoningEffort',
        'serviceTier',
        'promptCache',
        'parallelToolCalls',
        'parallelToolDeadlineSeconds',
        'maxToolSteps',
        'maxCostUsd',
        'compaction.reminderPercent',
        'compaction.autoPercent',
        'compaction.blockPercent',
        'compaction.keepRecent',
        'compaction.summaryUserChars',
        'compaction.summaryAssistantChars',
        'compaction.toolOutputChars',
        'compaction.reminderTokens',
        'compaction.autoTokens',
        'compaction.blockTokens',
        'compaction.modelTokenCaps',
        'compaction.idleOfferSeconds',
        'compaction.mode',
        'compaction.refillLimit',
        'compaction.idleAuto',
        'promptCache.keepWarm',
        'contextPruning.minContextTokens',
        'contextPruning.maxContextTokens',
        'contextPruning.nudgeFrequency',
        'contextPruning.iterationNudgeThreshold',
        'repoMap.enabled',
        'repoMap.maxBytes',
        'env.gitDiffAfterWrites',
        'env.diffMaxBytes',
        'contextWindow',
        'autoReview',
        'autoReviewScope',
        'autoReviewModel',
        'permissions.autoStrikeLimit',
        'permissions.autoTotalLimit',
        'permissions.autoAllowReadOnly',
        'redactToolOutput',
        'secretEnvAllowlist',
        'allowedTools',
        'disabledTools',
        'references',
        'bashSandbox',
        'testCommand',
        'autoTest',
        'toolOutputCapBytes',
        'mcpResultCapBytes',
        'readMaxBytes',
        'readPageLines',
        'readPageBytes',
        'toolSpillWindowPercent',
        'toolInstructionCapBytes',
        'toolSpillCaptureBytes',
        'toolSpillMinCapBytes',
        'globMaxMatches',
        'webFetchMaxBytes',
        'webFetchTimeoutSeconds',
        'webSearchMaxResults',
        'webSearchTimeoutSeconds',
        'webSearchEndpoint',
        'webSearchBackend',
        'bashInteractiveIdleSeconds',
        'bashTimeoutSeconds',
        'bashMaxTimeoutSeconds',
        'chatToolTimeoutSeconds',
        'grepTimeoutSeconds',
        'requireReadBeforeWrite',
        'sd.baseUrl',
        'sd.apiKey',
        'sd.timeoutSeconds',
        'sd.defaultModel',
        'ui.imageRenderMode',
        'sd.presets',
        'ui.mediaDisplayOverrides',
        'sd.savePattern',
        'skills.pathNudges',
        'instructions',
        'instructions.walkToRoot',
        'instructionsExclude',
        'disabledRules',
        'embeddingModel',
        'disabledSkills',
        'enabledSkills',
        'pluginPaths',
        'subagentModel',
        'subagentMaxTurns',
        'subagentMaxConcurrent',
        'subagentMaxDepth',
        'subagentMaxActive',
        'includeGitInstructions',
        'attribution',
        'lsp',
        'lspAutoDetect',
        'autoCommit',
        'theme',
        'statusLine',
        'layout',
        'notify',
        'watchFiles',
        'queueMode',
        'terminalBackground',
        'mouse',
        'mouseClicks',
        'scrollWheelLines',
        'doubleEscSeconds',
        'resumeCostWarnUsd',
        'paletteMru',
        'diffPreviewRows',
        'diffStyle',
        'toolOutputPreviewLines',
        'sessionRetentionDays',
        'sessions.autoTitle',
        'promptSuggestions',
        'promptSuggestionHistory',
        'sessions.awayRecap',
        'expandToolOutput',
        'maxCheckpoints',
        'keybindings',
        'vimMode',
        'transcriptJsonl',
        'lintCommands',
        'formatCommands',
        'formatAuto',
        'disabledMcpServers',
        'mcp.enabled',
        'mcp.lazySchemaTokens',
        'connectTimeoutSeconds',
        'providerRetryAttempts',
        'providerRetryBaseBackoffMs',
        'debug.skills',
        'debug.commands',
        'debug.rules',
        'debug.stream',
        // settings:layered-keys:end
    ];

    /**
     * The subset of {@see LAYERED_KEYS} a PROJECT may contribute, even a trusted
     * one. Narrower than the user tier's, because "I trust this repository" and
     * "this repository may choose that particular thing" are different
     * questions and the second one has a different answer per key:
     *
     *  - `theme` is pixels.
     *  - `disabledSkills` REMOVES a capability rather than adding one, and "this
     *    repo has no use for the terraform skill" is the archetypal thing a
     *    repository knows and a user does not. Gated all the same, because
     *    removing a capability can also mean removing a check the user relies on.
     *  - `parallelToolCalls` / `parallelToolDeadlineSeconds` are throughput. A
     *    repository whose test suite serialises badly has a real reason.
     *  - `disabledTools` names tools to REMOVE from the set
     *    {@see \SugarCraft\Code\Cli\Bootstrap::tools()} built, which is the
     *    `disabledSkills` argument one layer down: "there is no MCP server in
     *    this checkout, stop offering WebSearch" is a thing a repository knows.
     *    Gated for `disabledSkills`' reason too — removing a capability can
     *    remove a check — and it CANNOT widen anything, because that set is the
     *    ceiling and both tool keys only ever shrink it.
     *  - `includeGitInstructions` only ever REMOVES prompt text: a repository
     *    whose AGENTS.md states its own commit process has a real reason to
     *    drop the generic block beside it, and the worst a hostile value does
     *    is put back the defaults the user would get anyway or take away
     *    guidance, never add any. Its sibling `attribution` is the opposite
     *    case and stays user-tier ({@see LAYERED_KEYS}).
     *
     * `titleModel` / `summaryModel` ARE ABSENT (audit 15d-24), and they used to
     * be here, on the argument that they "name a model WITHIN the provider the
     * user already chose, so the cost of a hostile value is tokens and a wrong
     * title". That bound does not hold. Within one provider the price spread
     * is more than 100x, and these two keys pick the model for every session
     * title, every per-turn prompt suggestion and every `/compact` summary, on
     * the operator's credential. Worse, a model missing from the provider's
     * price table bills as the $0 lower bound, so a project naming one blinds
     * the spend cap for every one of those calls - the exact failure
     * `modelPrices` is refused to projects for. A model choice is a money
     * decision, and the money decision stays with whoever pays.
     *
     * `allowedTools` IS ABSENT, and it is the interesting one, because on
     * capability alone it looks as safe as its sibling: a whitelist intersected
     * with an existing set cannot add a tool either. Two reasons it is still
     * user-tier only.
     *
     * First, a whitelist's effect is defined by what it OMITS, so it is the one
     * shape in which a small, innocuous-looking value deletes almost
     * everything. In one line, `allowedTools: ["Bash"]` removes ALL TEN of the
     * others: `Read`, `Edit`, `Glob`, `Grep`, `Write`, `WebFetch`, `WebSearch`,
     * `doctor`, `Skill` and `Lsp`. (This sentence named five of the ten. The
     * `doctor` is lower-case — matching is case-sensitive `fnmatch()`, so the
     * capitalised spelling matches no tool.) And what the model does next is
     * not "less work", it is the SAME work through `Bash`, which reaches the
     * permission gate as opaque shell text instead of as a reviewable path.
     * That is a privilege escalation by degradation: strictly fewer tools,
     * strictly coarser review.
     *
     * AN EARLIER VERSION OF THIS PARAGRAPH ENDED WITH A FALSE CLAIM, and it is
     * corrected here rather than deleted because it was the stated reason for
     * the tiering: it said `disabledTools` "can express the same attack, but
     * only by naming every tool it removes, which is a value an operator
     * reading the file can see". It cannot be relied on.
     * {@see \SugarCraft\Code\Cli\Bootstrap::filterToolSet()} matches with
     * {@see \SugarCraft\Code\Permissions\PermissionRule::matchesToolName()},
     * which is bare `fnmatch()`, and `fnmatch()` honours NEGATED character
     * classes. MEASURED end-to-end on PHP 8.3.6, 2026-08-22 — PHP 8.4 was NOT
     * exercised, because this box has only 8.3.6 while CI runs both; nothing in
     * this path is believed version-sensitive (`fnmatch()`'s `[!…]` is not a
     * recent addition and no ICU is involved), and the version is recorded as
     * provenance rather than as a caveat. A project-tier
     * `{"disabledTools": ["[!B]*"]}` — FIVE characters of glob, in one key this
     * tier is allowed — leaves exactly `Bash` and removes everything else,
     * which is the same tool set `allowedTools: ["Bash"]` produces.
     *
     * THE COUNT SAID "eight characters" until `docs/SETTINGS.md` re-derived it,
     * and this doc-block was named there as one of the two places still
     * carrying the stale figure. WHAT IT SAID: eight. WHAT IS TRUE NOW: `[!B]*`
     * is five, `"[!B]*"` seven and `["[!B]*"]` nine — nothing in the example is
     * eight. WHY THE SENTENCE STILL EARNS ITS PLACE: the number was never the
     * argument. The point is that a value this short names NONE of the ten
     * tools it removes, which is exactly the auditability the retracted claim
     * promised, so the sentence is corrected rather than dropped.
     *
     * AND THE CORRECTION ONCE CITED THE WRONG GENERATOR, which is worth more
     * space than the number was. WHAT IT SAID: the figure "is re-derived every
     * run by `ReadmeSettingsTierClaimTest::testOneShortDenyGlobRemovesEveryToolButOneWithoutNamingAnyOfThem()`,
     * so it reds instead of rotting". WHAT IS TRUE NOW: that test derives the
     * TOOL SET this glob leaves. It never measures the glob's LENGTH — the glob
     * is a class constant there, and no `strlen()` in `tests/` is applied to it
     * (VERIFIED at `8416d98e`, and again at round 44 with the command the
     * sentence used to omit — `grep -rl 'strlen(' tests --include='*.php'`,
     * cross-checked against the looser `grep -rl 'strlen'`: no file either
     * command reports applies it to `COUNTEREXAMPLE_GLOB` or to any spelling
     * of `[!B]*`. An earlier draft said "no `strlen()` appears anywhere in
     * `tests/`", which is plainly false and is corrected here rather than
     * dropped, since a doc-block that catches a false claim by writing a second
     * one has learnt nothing) — so the citation was a number written down
     * beside the name of something that does not produce it.
     *
     * AND THE REPAIR CARRIED TWO CARDINALITIES THAT WERE STALE BEFORE THE ROUND
     * CLOSED. WHAT IT SAID: those two commands "count 66 files and 68". WHAT IS
     * TRUE NOW: 71 and 73 at `98d59bfb`. Both were right when round 44's lane
     * `a` measured them in its own worktree, and both were wrong by the time
     * that worktree merged, because two sibling lanes added test files
     * containing `strlen` in the same round. WHY THIS STILL EARNS ITS PLACE:
     * the claim the paragraph actually makes is a NEGATIVE — that no such call
     * reaches this constant — and that survived the merge untouched. The
     * cardinalities never supported it; they only looked like evidence. A count
     * taken over `tests/` in one lane's worktree is invalidated by any sibling
     * lane's merge, so the generator is kept and the two totals are dropped
     * rather than re-derived into the next round's staleness. This is E131.
     *
     * THE MEASUREMENT OFFERED IN ITS PLACE HAD NO GENERATOR EITHER, and it is
     * rewritten for the same reason. WHAT IT SAID: on PHP 8.3.6, restoring
     * "eight" to this very paragraph left that test and its five sibling
     * doc-drift suites at `OK (80 tests, 297 assertions)`. WHAT IS TRUE NOW: no
     * command in the tree produces that pair. The nearest reproducible
     * selection,
     * `vendor/bin/phpunit --filter 'Drift|ReadmeSettingsTierClaim|ThemePersistenceFraming'`,
     * reports 231 tests at round 44 on PHP 8.3.6, and its ASSERTION count is
     * deliberately not quoted, because widening the census scope moved it by
     * tens of thousands inside a single commit. WHY THE SENTENCE STILL EARNS
     * ITS PLACE: the OBSERVATION reproduces even though the figure does not,
     * and it is the entire reason the generator below exists — the stale figure
     * could be put back inside the sentence claiming it could not, and nothing
     * went red. It goes red now.
     *
     * THE COUNT HAS A GENERATOR AT LAST.
     * {@see \SugarCraft\Code\Tests\Config\GlobFigureDriftTest} derives every
     * number in this paragraph from `strlen()` of the glob the paragraph
     * quotes, at all THREE sites that carry the retraction — this doc-block,
     * `docs/SETTINGS.md` and
     * {@see \SugarCraft\Code\Cli\Bootstrap::reportProjectTierToolRemovals()} —
     * and the spelled length in the paragraph above at the two sites that spell
     * one. (This said "on both pages that carry it" while its own generator
     * already listed three: `Bootstrap` joined in round 44, the sentence did
     * not, and the phrase naming the spelled count is deliberately not repeated
     * here — the generator locates that paragraph by those very words, and a
     * second copy of them makes its window ambiguous. It reds on that, which is
     * how this sentence was caught.)
     * The BEHAVIOUR — that this value leaves exactly `Bash` — is the half
     * {@see \SugarCraft\Code\Tests\Config\ReadmeSettingsTierClaimTest::testOneShortDenyGlobRemovesEveryToolButOneWithoutNamingAnyOfThem()}
     * generates, and the two are not interchangeable.
     *
     * `Bootstrap::reportProjectTierToolRemovals()` WAS THE OTHER SITE, AND THIS
     * PARAGRAPH WAS WRONG ABOUT IT IN THREE WAYS AT ONCE. WHAT IT SAID: that
     * Bootstrap "is the other site `SETTINGS.md` named"; that its being the
     * ONLY remaining one was itself asserted; and that the assertion was a
     * `GlobFigureDriftTest` method named for the settings page naming exactly
     * the source files that still carried the stale figure. (That method name
     * is DESCRIBED here rather than spelled, and deliberately: a phantom
     * symbol written in citation form is indistinguishable — to a reader and
     * to {@see \SugarCraft\Code\Tests\SymbolCitationDriftTest} alike — from
     * a citation that resolves, which is the whole subject of this paragraph.
     * Do not "restore" the literal name; quoting a dead symbol in citation
     * form is the defect, not the record of it.)
     * WHAT IS TRUE NOW: `SETTINGS.md` names no sites at all — it states a
     * CARDINALITY instead, because a list of filenames is exactly what went
     * stale in round 43; the number of sites still carrying the figure is ZERO,
     * `Bootstrap` having been rewritten in round 44; and no test method
     * answering that description exists TODAY — one was declared at
     * `4ba28fe3f` and removed at `579a6bf15`, the commit that emptied the
     * census and wrote a differently-named successor, which `1f10b6224` then
     * renamed to today's
     * {@see \SugarCraft\Code\Tests\Config\GlobFigureDriftTest::testNothingInScopeStillCarriesTheStaleFigureAndTheSettingsPageAgrees()}.
     * (The earlier claim here read "has ever existed", which is false in the
     * direction that matters: the symbol WAS real, which is exactly why a
     * reader would try to restore it. The shas above are the record, and
     * they are shas rather than a name for the reason the parenthesis above
     * gives.) All three sentences
     * were falsified by the commit that wrote them. WHY THIS STILL EARNS ITS
     * PLACE: the cross-reference is the only thing telling a reader that the
     * claim above is checked rather than proof-read, and a DANGLING one is
     * worse than none, because it reads as a citation and resolves to nothing.
     * That failure has its own guard now:
     * {@see \SugarCraft\Code\Tests\SymbolCitationDriftTest} reds when `src/`
     * or `docs/` cites a test symbol that does not exist, and this paragraph is
     * the defect it was built from.
     *
     * So the shape argument DOES NOT survive on its own, and the honest
     * statement of where this key stands is: a project-tier `disabledTools`
     * glob CAN reduce the model to a single tool of the project's choosing,
     * and the second reason below is what the split actually rests on.
     *
     * TWO THINGS NARROW IT, both measured and neither previously written down.
     * An UNTRUSTED project's `disabledTools` never reaches the merge at all, so
     * this needs the operator's own {@see PROJECT_SETTINGS_TRUST_KEY} grant
     * first. And {@see merge()} is KEY-LEVEL rather than a union, so a user who
     * names any `disabledTools` of their own REPLACES the project's list
     * outright: a user `["Read"]` against a project `["[!B]*"]` removes exactly
     * `Read`. The gap is open only for an operator who trusted a repository and
     * set no `disabledTools` themselves.
     *
     * THAT CAPABILITY IS STILL THERE. What changed is that it is no longer
     * SILENT: {@see \SugarCraft\Code\Cli\Bootstrap::reportProjectTierToolRemovals()}
     * reports a trusted project's tool removals at launch, naming this file,
     * the tools it took and the tools it left. Neither of the two restrictions
     * this comment used to propose was taken, and the measurement is why —
     * refusing negated classes closes `[!B]*` and not `["[C-Z]*", "[a-z]*"]`,
     * which is the same attack without a negation, and restricting the tier to
     * literal names would delete the `mcp__git__*` use the key was admitted
     * for. Reaching any of this requires the operator to have listed the
     * checkout under {@see PROJECT_SETTINGS_TRUST_KEY} first, so the property
     * worth restoring was the auditability the quoted claim promised, not the
     * grammar it was reasoning about.
     *
     * Second, a whitelist is what a user reaches for when they want a CEILING,
     * and a ceiling a checkout can rewrite is not one. What makes that hold when
     * both keys are in play is not an ordering:
     * {@see \SugarCraft\Code\Cli\Bootstrap::filterToolSet()} keeps a tool iff
     * the allow-list admits it AND the deny-list does not name it — one
     * conjunction, no stages — so there is no later step in which a project's
     * `disabledTools` could re-admit what the user's `allowedTools` excluded.
     *
     * GENERATED like {@see LAYERED_KEYS}: the list is
     * {@see \SugarCraft\Code\Config\Settings\SettingsSchema::projectTierKeys()} —
     * the layered rows marked `withProjectSettable()` — written by
     * `tools/gen-settings-doc.php --write`. The arguments above stay here; the
     * membership is the schema's.
     *
     * @var list<string>
     */
    public const PROJECT_TIER_KEYS = [
        // settings:project-tier-keys:begin — generated by tools/gen-settings-doc.php from SettingsSchema; never hand-edit
        'parallelToolCalls',
        'parallelToolDeadlineSeconds',
        'compaction.reminderPercent',
        'compaction.autoPercent',
        'compaction.blockPercent',
        'compaction.keepRecent',
        'compaction.summaryUserChars',
        'compaction.summaryAssistantChars',
        'compaction.toolOutputChars',
        'compaction.reminderTokens',
        'compaction.autoTokens',
        'compaction.blockTokens',
        'compaction.modelTokenCaps',
        'compaction.idleOfferSeconds',
        'compaction.mode',
        'contextPruning.minContextTokens',
        'contextPruning.maxContextTokens',
        'contextPruning.nudgeFrequency',
        'contextPruning.iterationNudgeThreshold',
        'repoMap.enabled',
        'env.gitDiffAfterWrites',
        'permissions.autoAllowReadOnly',
        'disabledTools',
        'toolSpillCaptureBytes',
        'toolSpillMinCapBytes',
        'globMaxMatches',
        'webFetchMaxBytes',
        'webFetchTimeoutSeconds',
        'webSearchMaxResults',
        'webSearchTimeoutSeconds',
        'bashInteractiveIdleSeconds',
        'bashTimeoutSeconds',
        'bashMaxTimeoutSeconds',
        'chatToolTimeoutSeconds',
        'grepTimeoutSeconds',
        'skills.pathNudges',
        'disabledSkills',
        'includeGitInstructions',
        'theme',
        'notify',
        'queueMode',
        'terminalBackground',
        'mouse',
        'mouseClicks',
        'scrollWheelLines',
        'doubleEscSeconds',
        'resumeCostWarnUsd',
        'paletteMru',
        'diffPreviewRows',
        'diffStyle',
        'toolOutputPreviewLines',
        'sessions.autoTitle',
        'promptSuggestions',
        'sessions.awayRecap',
        'expandToolOutput',
        'disabledMcpServers',
        'connectTimeoutSeconds',
        'providerRetryAttempts',
        'providerRetryBaseBackoffMs',
        // settings:project-tier-keys:end
    ];

    /**
     * {@see LAYERED_KEYS} minus {@see PROJECT_TIER_KEYS} — the keys no project
     * file may contribute, at any trust level.
     *
     * DERIVED, not written out, so the two lists above cannot drift apart into a
     * third list that agrees with neither. Today it is `provider`, `models`,
     * `titleModel`, `summaryModel`, `maxOutputTokens`, `modelPrices`,
     * `extraBody`, `thinkingBudget`, `serviceTier`, `promptCache`, `maxToolSteps`,
     * `contextWindow`, `secretEnvAllowlist`, `allowedTools`, `references`,
     * `instructions`,
     * `instructionsExclude`, `disabledRules`, `enabledSkills`, `attribution`,
     * `statusLine`, `layout`, `lintCommands` and `formatCommands`, in {@see LAYERED_KEYS} order — named rather than numbered
     * here, because the
     * ordinals this sentence used to carry went stale the moment a fifth key
     * joined the list. `allowedTools`'s argument is on {@see PROJECT_TIER_KEYS},
     * next to the sibling key that IS allowed, since that is where the two have
     * to be compared; `statusLine`'s is on {@see LAYERED_KEYS}, because what
     * makes it user-tier is not a comparison with anything on this list — its
     * value is a COMMAND (as is `lintCommands`', below it on that list). `disabledRules`'s is on
     * {@see LAYERED_KEYS} too, against its lookalike `disabledSkills`, and the
     * short form of it is that the value holds pack NAMES whose referents are the
     * operator's own prompt text, so the "file contents become prompt text"
     * wording below does not decide it — what decides it is
     * {@see \SugarCraft\Code\Context\RulesState::TOGGLEABLE_TIER}: a session may
     * silence a user pack and no session may silence a repository one, so a
     * checkout choosing which user packs are off is outside the grant even
     * "I trust this repository" does not make.
     * The set is asserted, not believed, by
     * {@see \SugarCraft\Code\Tests\Config\LayeredSettingsTest::testTheUserTierOnlyKeysAreExactlyTheLayeredKeysNoProjectMaySet()},
     * so this sentence going stale reds a test rather than misleading a reader:
     *
     * `provider` is not "which of my accounts". The value is a NAME, and
     * {@see \SugarCraft\Code\Providers\ProviderFactory::defaultConfig()}
     * resolves a name it does not recognise against the `providers` map of a
     * `config.dev.json` — a map whose entries carry `base_uri`. So the set a
     * project-chosen name resolves against is not one the user enumerated, and
     * the miss path is a file read. Switching provider also switches which API
     * key is spent and which host every prompt in the session is sent to, which
     * is the one decision that should never be made by a file that arrived with
     * a clone.
     *
     * `instructions` is a list of globs whose FILE CONTENTS
     * ({@see \SugarCraft\Code\Context\InstructionFileLoader::loadForced()})
     * become part of the system prompt. Containment keeps those globs inside the
     * checkout, so a project value cannot read outside it — the harm is not a
     * file-read primitive, it is that "forced" means the user declared this text
     * authoritative. A project value would let a repository force any file it
     * ships, under any name, which is precisely the review a user performs by
     * looking at the instruction files they know about.
     *
     * `instructionsExclude` is the MIRROR of that argument and lands on the
     * same side: it names globs whose instruction files are SKIPPED. A
     * repository that could set it could mute whichever context files it does
     * not like — including the operator's forced ones, because exclusion runs
     * before every repository-tier read. Context the operator chose is theirs
     * to silence and nobody else's, so the key is user-tier only; the loader
     * consults it for the four repository tiers and never for the personal
     * file.
     *
     * NO `src/` CONSUMER, DELIBERATELY, and it is a seam rather than dead code.
     * {@see projectLayer()} filters on {@see PROJECT_TIER_KEYS} — the positive
     * list — because that is the direction a filter has to run in; the
     * COMPLEMENT is what the security argument is stated over, in this
     * doc-block, in `candy-code/README.md` and in
     * {@see \SugarCraft\Code\Tests\Config\LayeredSettingsTest::testTheUserTierOnlyKeysAreExactlyTheLayeredKeysNoProjectMaySet()}.
     * Deriving it rather than writing a third list is what stops the two
     * constants drifting into a claim that matches neither. A reader
     * (`/doctor`, a settings pane) that wants to tell a user why their
     * project's `provider` was ignored has the answer here already.
     *
     * @return list<string>
     */
    public static function userTierOnlyKeys(): array
    {
        return array_values(array_diff(self::LAYERED_KEYS, self::PROJECT_TIER_KEYS));
    }

    /**
     * The merged view: `$userConfig` with {@see LAYERED_KEYS} backfilled from
     * the lower layers wherever `$userConfig` is silent about them.
     *
     * `$userConfig` arrives ALREADY READ and is passed through UNFILTERED, which
     * is what makes this safe to drop under an existing reader: for every key
     * this class does not name, the return value is `$userConfig` itself.
     *
     * @param array<string, mixed> $userConfig layer 4, the CLI-written file
     * @param array<string, mixed> $userSettings layer 3, already filtered by {@see userLayer()}
     * @param array<string, mixed> $projectSettings layers 1+2, already filtered by {@see projectLayer()}
     * @return array<string, mixed>
     */
    public static function merge(array $userConfig, array $userSettings, array $projectSettings): array
    {
        // array_merge, not `+`: later wins on collision, which is the direction
        // stated in the class doc-block. Written lowest-first so the reading
        // order of this line is the precedence order.
        return array_merge($projectSettings, $userSettings, $userConfig);
    }

    /**
     * Layer 3 — `<userConfigDir>/settings.json`, filtered to {@see LAYERED_KEYS}.
     *
     * FILTERED even though this is the user's OWN file, for a reason that is not
     * defence: `permissionRules` and the `trustedProject*` keys are read by
     * {@see \SugarCraft\Code\Cli\Bootstrap}'s permission path, which opens
     * `config.json` directly and does not come through here at all. Accepting
     * them in `settings.json` would produce a key that parses, validates, sits
     * in the file the user is looking at, and does nothing.
     *
     * @return array<string, mixed>
     */
    public static function userLayer(string $userConfigDir): array
    {
        return self::only(
            self::readFile(rtrim($userConfigDir, '/') . '/' . self::USER_FILE),
            self::LAYERED_KEYS,
        );
    }

    /**
     * Layers 1+2 — the project's two settings files, filtered to
     * {@see PROJECT_TIER_KEYS}, or `[]` when this project may not contribute.
     *
     * `$trusted` IS A REQUIRED ARGUMENT AND NOT AN INTERNAL LOOKUP, so that a
     * caller cannot reach the project tier without having answered the trust
     * question. The check itself belongs to
     * {@see \SugarCraft\Code\Cli\Bootstrap}, which is where the user's config
     * and the home-ownership gate live; this class must not grow a second,
     * differently-behaved copy of it. Passing `false` returns `[]` rather than
     * throwing: "not trusted" is an ordinary state, and it is the state a fresh
     * install is in.
     *
     * TWO CONTAINMENT BOUNDARIES, the same pair every repository-chosen read in
     * this package carries and for the same reason
     * ({@see \SugarCraft\Code\Agents\WorktreeConfig::readConfig()} is the
     * closest sibling):
     *
     *  - `<root>/.candy-code` must resolve STRICTLY inside `<root>`
     *    ({@see ContainedPath::below()}), so a committed
     *    `.candy-code -> /elsewhere` relocates the whole settings directory
     *    instead of tripping the per-file check;
     *  - each settings file must resolve inside that directory
     *    ({@see ContainedPath::within()}), so `settings.json -> ~/.ssh/config`
     *    is refused on its own even when the directory is genuine.
     *
     * A refused file is the ABSENCE of a layer, not an error. There is no
     * channel to report through — the caller is a tolerant config read that
     * `EngineBackend` performs once per turn — and every key here has a working
     * answer without it. Silence in the safe direction; the noisy direction
     * would be a warning per turn.
     *
     * @param string $projectRoot the repository being operated on
     * @param bool $trusted whether the operator listed this root under
     *        {@see PROJECT_SETTINGS_TRUST_KEY}
     * @return array<string, mixed>
     */
    public static function projectLayer(string $projectRoot, bool $trusted): array
    {
        $layers = [];
        foreach (self::projectLayerPaths($projectRoot, $trusted) as $path) {
            $layers[] = self::only(self::readFile($path), self::PROJECT_TIER_KEYS);
        }

        return array_merge(...[[], ...$layers]);
    }

    /**
     * The project settings files this root actually contributes, lowest
     * precedence first — shared before local, since local is the higher of the
     * two and the order of this list IS that precedence.
     *
     * EXTRACTED FROM {@see projectLayer()} rather than copied beside it. Two
     * callers now need the same walk — the merge, and
     * {@see projectKeySource()}, which has to name the file a key came from —
     * and a second copy of a trust check, a containment pair and a precedence
     * order is exactly the kind of duplicate that drifts into disagreeing with
     * the original about which file wins.
     *
     * @return list<string>
     */
    private static function projectLayerPaths(string $projectRoot, bool $trusted): array
    {
        if (!$trusted || trim($projectRoot) === '') {
            return [];
        }

        $dir = rtrim($projectRoot, '/') . '/' . self::dir();
        if (!ContainedPath::below($dir, $projectRoot)) {
            return [];
        }

        $paths = [];
        foreach ([self::SHARED_PATH, self::LOCAL_PATH] as $relative) {
            $path = rtrim($projectRoot, '/') . '/' . $relative;
            if (!ContainedPath::within($path, $dir)) {
                continue;
            }

            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * WHICH project settings file last set `$key`, or null when neither did.
     *
     * The mirror of `Bootstrap::permissionKeySource()` and written the same
     * way — the LAST file carrying the key, matching `array_merge`'s
     * later-wins — because a diagnostic that names the wrong file is worse than
     * one that names none. `array_key_exists` rather than `?? null` so a file
     * that set the key to an explicit null is still the file that set it.
     *
     * Only {@see PROJECT_TIER_KEYS} can answer: a key this tier may not set is
     * dropped by {@see only()} before the merge, so reporting a file as its
     * source would name a file whose value never reached anything.
     */
    public static function projectKeySource(string $projectRoot, bool $trusted, string $key): ?string
    {
        if (!\in_array($key, self::PROJECT_TIER_KEYS, true)) {
            return null;
        }

        $source = null;
        foreach (self::projectLayerPaths($projectRoot, $trusted) as $path) {
            if (\array_key_exists($key, self::readFile($path))) {
                $source = $path;
            }
        }

        return $source;
    }

    /**
     * The project-local settings file (layer 2) a writer may publish to, or null
     * when this project may not contribute settings at all.
     *
     * The SAME walk {@see projectLayer()} reads through
     * ({@see projectLayerPaths()}): the trust gate and both containment
     * boundaries. So the settings editor can write only the file the merge would
     * read back, never a `settings.local.json` reached through a symlinked
     * `.candy-code`, and never for a project the operator has not trusted.
     */
    public static function projectLocalPath(string $projectRoot, bool $trusted): ?string
    {
        foreach (self::projectLayerPaths($projectRoot, $trusted) as $path) {
            if (str_ends_with($path, '/' . self::LOCAL_PATH)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Layers 1 and 2 SEPARATELY, keyed by the file each came from (shared
     * first), each UNFILTERED — or `[]` when this project may not contribute.
     *
     * For {@see \SugarCraft\Code\Config\Settings\SettingsResolver}, which has
     * to say which file set a key and, for a key this tier may not set, that the
     * file named it and was ignored — both invisible after {@see projectLayer()}'s
     * filter and merge. The trust gate and the containment pair are the SAME
     * walk {@see projectLayer()} takes ({@see projectLayerPaths()}), so the two
     * cannot disagree about which files a project contributes.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function projectFiles(string $projectRoot, bool $trusted): array
    {
        $files = [];
        foreach (self::projectLayerPaths($projectRoot, $trusted) as $path) {
            $files[$path] = self::readFile($path);
        }

        return $files;
    }

    /**
     * One settings file decoded with this class's tolerance and NO key filter
     * — `[]` for a missing, unreadable or non-object file, as {@see readFile()}.
     *
     * @return array<string, mixed>
     */
    public static function decodedFile(string $path): array
    {
        return self::readFile($path);
    }

    /**
     * ISS-029: sentences about what this decoded settings file holds that NO
     * reader consumes from its slot, and about known keys whose value their
     * own definition rejects.
     *
     * The tolerant reader makes both mistakes silent: `only()` drops a key it
     * does not name without a word, and a value the validator refuses falls
     * back to its default at the resolver. A `permisionMode` in `config.json`
     * therefore ran the session in a different mode than the user believes
     * they chose. The sentences say what will happen, at launch and from
     * `doctor`, instead of leaving the discovery to the incident.
     *
     * @param list<string> $allowed the keys this file IS read for
     * @param array<string, mixed> $decoded the file as written
     * @return list<string>
     */
    public static function auditTopLevelKeys(string $label, array $decoded, array $allowed): array
    {
        $schema = SettingsSchema::keys();
        $rows = [];

        foreach ($decoded as $key => $value) {
            $key = (string) $key;

            if (in_array($key, $allowed, true)) {
                $definition = SettingsSchema::byKey($key);
                if ($definition !== null && $value !== '' && $value !== null) {
                    // The FILE reader is more lenient than the schema validator
                    // in exactly the two ways that matter (ISS-029 review): a
                    // set-but-empty `''` and a `null` read as unset through the
                    // whole cascade, and numbers arrive in numeric-string and
                    // integral-float spelling because hand-typed JSON keeps
                    // producing them — `maxToolSteps: "16"` is a documented,
                    // honoured setting. The audit mirrors the reader's leniency
                    // and claims the DEFAULT-FALLBACK sentence only for values
                    // both the resolver and the validator refuse.
                    $error = $definition->validate(self::lenientProbe($value, $definition->type), $decoded);
                    if ($error !== null) {
                        $rows[] = Lang::t('cli.settings.rejected_value', [
                            'key' => $key,
                            'file' => $label,
                            'error' => $error,
                        ]);
                    }
                }

                continue;
            }

            if (in_array($key, $schema, true)) {
                $rows[] = Lang::t('cli.settings.wrong_tier', ['key' => $key, 'file' => $label]);

                continue;
            }

            $guess = self::suggestKey($allowed, $key) ?? self::suggestKey($schema, $key);
            $rows[] = Lang::t('cli.settings.unknown_key', [
                'key' => $key,
                'file' => $label,
                'hint' => $guess === null ? '' : Lang::t('cli.settings.did_you_mean', ['key' => $guess]),
            ]);
        }

        return $rows;
    }

    /**
     * The audit sentences for the files a launch's settings cascade can see:
     * the user tier's `config.json` and `settings.json`, and — when `$root`
     * is given — the two project-tier files too.
     *
     * The project files ARE read for the diagnosis even when their root is
     * untrusted. Reading is not applying: whether those files contribute
     * settings stays the decision of the trust grant
     * ({@see \SugarCraft\Code\Cli\Bootstrap::projectSettingsTrusted()}), and a
     * health report that pretends a cloned repo's typos are invisible is
     * worse than one that names them.
     *
     * @return list<string>
     */
    public static function keyAuditRows(string $configDir, ?string $root = null): array
    {
        $configPath = $configDir . '/config.json';
        $settingsPath = $configDir . '/' . self::USER_FILE;

        $rows = self::auditTopLevelKeys($configPath, self::decodedFile($configPath), SettingsSchema::keys());
        $rows = [...$rows, ...self::auditTopLevelKeys($settingsPath, self::decodedFile($settingsPath), self::LAYERED_KEYS)];

        if ($root !== null && trim($root) !== '') {
            foreach (self::projectFiles($root, true) as $path => $decoded) {
                // C-SEC-6.b: the shared file IS read for `permissionRules`
                // now — deny/ask entries only, under the trust grant, by
                // `Bootstrap::projectNarrowingRules()` — so for this one file
                // the key is allowed and its value shape gets the schema
                // check; the local sibling stays unread for rules and still
                // earns the wrong-tier row.
                $allowed = str_ends_with((string) $path, '/' . self::SHARED_PATH)
                    ? [...self::PROJECT_TIER_KEYS, 'permissionRules']
                    : self::PROJECT_TIER_KEYS;
                $rows = [...$rows, ...self::auditTopLevelKeys((string) $path, $decoded, $allowed)];

                // v1 F-4: a corrupt project-tier file used to fail open with
                // no word while a corrupt USER file is an exit-2 fatal. The
                // audit walk already reads these files (the doc-block above
                // argues why: reading is not applying), so this tier's
                // nonsense now surfaces at launch and from `doctor` — for
                // the TRUSTED-root walk, which is what this call takes.
                // Nothing is recorded for an untrusted root's apply path
                // ({@see projectLayer()} never consults this), which is the
                // existing trust model kept honest.
                if ($decoded === []) {
                    $reason = self::unusableSettingsFileReason((string) $path);
                    if ($reason !== null) {
                        $rows[] = Lang::t('cli.settings.corrupt_file', [
                            'file' => (string) $path,
                            'error' => $reason,
                        ]);
                    }
                }
            }
        }

        // v1 F-6 residual: an explicit --config file REPLACES the user-tier
        // config.json the first line above reads, but configDir() ignores the
        // override — so provider nonsense in the file actually in use fell
        // back to defaults silently while the same value in a user file
        // warns. Audit the override too when it is not the file already
        // covered (`doctor` passes dirname(Bootstrap::userConfigPath()), so
        // there the two coincide and this adds nothing).
        $explicit = Bootstrap::userConfigPath();
        if ($explicit !== '' && $explicit !== $configPath) {
            $rows = [...$rows, ...self::auditTopLevelKeys($explicit, self::decodedFile($explicit), SettingsSchema::keys())];
        }

        return $rows;
    }

    /**
     * Why this settings file is present but unusable, or null when it is
     * simply absent, unreadable, or a valid (even empty) JSON object.
     *
     * Re-reads the raw bytes on purpose: {@see readFile()}'s tolerance is the
     * very thing being diagnosed — it folds missing, unparseable, and empty
     * all into `[]`, and this has to tell `{}` (a valid file that
     * contributes nothing) from `[` (a corrupt one). A file whose bytes
     * cannot be read at all stays silent: the existing doc-block in
     * {@see readFile()} argues that chmod'd-away invisibility is deliberate,
     * and v1 F-4 is about announcing corruption in files we can see.
     *
     * PUBLIC since C-SEC-6.b: `Bootstrap::projectNarrowingRules()` uses it to
     * tell an absent shared file (contributes nothing, silent) from a corrupt
     * one (refuses the launch) without re-implementing the byte-read judgment.
     */
    public static function unusableSettingsFileReason(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return json_last_error_msg();
        }

        if ($decoded instanceof \stdClass) {
            return null; // a valid object, `{}` included: empty is not corrupt
        }

        if (is_array($decoded)) {
            // json_decode's array shape only exists for `[...]` and `[]`;
            // objects came back as stdClass above.
            return 'a JSON array, not an object';
        }

        return 'not a JSON object'; // null, or a bare scalar
    }

    
    /**
     * The value as the tolerant readers will hand it to the validator: a
     * numeric STRING arrives as its number (`"16"` behaves as 16 through the
     * cap resolvers), and an integral float satisfies an Int definition the
     * same way `resolvedMaxToolSteps()` lets 20.0 through. 1.5 stays 1.5 —
     * the resolvers refuse it too, and a row is exactly what ISS-029 asks
     * for there.
     */
    private static function lenientProbe(mixed $value, SettingType $type): mixed
    {
        if (is_string($value) && is_numeric($value)) {
            $value = $value + 0;
        }

        if ($type === SettingType::Int && is_float($value) && is_finite($value) && $value === floor($value)) {
            return (int) $value;
        }

        return $value;
    }

/**
     * The nearest key an audit can guess was meant, or null when nothing is
     * near. {@see \SugarCraft\Code\Support\FrontmatterKeyAudit::suggestion()}'s
     * walk — case, `-` and `_` folded away, Levenshtein within two (one for
     * four-character keys or shorter, so `os` does not approach everything) —
     * over a roster passed in rather than over the four frontmatter formats'
     * fixed KNOWN table.
     *
     * @param list<string> $roster
     */
    private static function suggestKey(array $roster, string $key): ?string
    {
        $fold = static fn (string $s): string => strtolower(str_replace(['-', '_'], '', $s));
        $folded = $fold($key);

        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($roster as $known) {
            $candidate = $fold($known);
            if ($candidate === $folded) {
                return $known;
            }

            $limit = strlen($candidate) <= 4 ? 1 : 2;
            $distance = levenshtein($folded, $candidate);
            if ($distance <= $limit && $distance < $bestDistance) {
                $best = $known;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * A settings file decoded, or `[]` for anything this class may not use — a
     * missing file, an unreadable one, invalid JSON, or valid JSON that is not
     * an object.
     *
     * `@`-silenced for {@see \SugarCraft\Code\Cli\Bootstrap::readUserConfig()}'s
     * reason: the `false` branch below IS the handling for an unreadable file,
     * and without the silence a chmod'ed-away settings file leaks a
     * `Permission denied` into the middle of the TUI's own output — and fails
     * any suite running with `failOnWarning`.
     *
     * @return array<string, mixed>
     */
    private static function readFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);

        // A top-level JSON array is not a settings file, and this refuses it
        // HERE rather than relying on the filter downstream.
        //
        // REDUNDANT TODAY, AND SAID SO BECAUSE A MUTATION PROVED IT. Removing
        // this clause changes no observable behaviour: a JSON list decodes to
        // integer keys, {@see only()} keeps only the named string keys, so a
        // list already contributes nothing. The first draft of this comment
        // claimed the list "would merge by integer key into the settings map",
        // which was simply false — nothing merges before `only()` runs. Kept
        // anyway, and not as decoration: `only()`'s whitelist is a scoping
        // decision that a future round could widen (a "pass unknown keys
        // through" change is exactly the kind that gets made), and this clause
        // is the one that does not depend on it. What must not happen is a test
        // written to "cover" it — no test can distinguish the two versions
        // through this class's public surface, and one that appeared to would be
        // pinning `only()`.
        return \is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
    }

    /**
     * `$data` restricted to `$keys`, preserving ABSENCE.
     *
     * `array_key_exists`, not `isset`/`??`: a key present with a `null` value is
     * a statement ("I do not want a forced instruction list") and has to
     * outrank a lower layer the same way any other value does. Filling it in
     * from below would make `null` mean "unset", and then no layer could ever
     * turn a lower one off.
     *
     * @param array<string, mixed> $data
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private static function only(array $data, array $keys): array
    {
        $kept = [];
        foreach ($keys as $key) {
            if (\array_key_exists($key, $data)) {
                $kept[$key] = $data[$key];
            }
        }

        return $kept;
    }
}
