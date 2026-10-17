# **TRANSPORT NOTE (read first):...

**TRANSPORT NOTE (read first):** the write to `/home/sites/prompt-step-P24/prompt_kit/findings/P11-premise-scout.md` is **impossible in this session...

**ID:** informal-azure-scallop
**Agent:** researcher
**Status:** complete
**Session:** ses_f7b64bb71ffelaUXNLwkq6OenA
**Started:** 2026-09-09T05:19:23.280Z
**Completed:** 2026-09-09T05:31:15.401Z

---



**TRANSPORT NOTE (read first):** the write to `/home/sites/prompt-step-P24/prompt_kit/findings/P11-premise-scout.md` is **impossible in this session** — the bash policy denies every write-capable command (`ls`, `mkdir`, `cat`, `tee`, redirection-free tools only; allow-list is `grep/awk/wc/head/tail/sort/uniq/cut/tr/jq/gh/...`), I have no Write/Edit tool, and the `findings/` dir exists but is unwritable from here. Per the researcher contract ("NEVER modify the filesystem") I did **not** smuggle content in via `awk > file`. Therefore **this response IS the artifact** (delegation-persisted), complete, not a 40-line pointer — a pointer with no file on disk would reproduce the empty-report death. All line numbers verified byte-identical in **both** trees (`/home/sites/sugarcraft` ↔ `/home/sites/prompt-step-P24`, 0 differing lines for every file cited, measured with awk line-diff), so any `file:line` below resolves in the P24 worktree.

# Phase-11 Premise Scout Report

## Q1 — Current line spans + section skeletons

**False premise killed: `docs/PROMPT_ENGINEERING.md` does not exist anywhere** — `**/PROMPT_ENGINEERING*` and `**/*ngineer*` return zero files in both trees; nor does root `docs/ARCHITECTURE.md`. Both files P11.S1/S2 name live under `candy-code/docs/`, and PROMPT_ENGINEERING.md is to be **created** (`prompt_plan.md:3124` says `(new)`). The P24 copy already shows it was not yet created in that worktree either.

### `candy-code/docs/ARCHITECTURE.md` — 516 lines
`# Architecture` 1–11 (incl. lead-in) · `## The one-screen version` 12–41 · `## bin/candy-code` 42–68 · `## Cli\Bootstrap` 69–101 · `## Chat` 102–129 · `### App hosts Chat` 130–170 · `## The Backend seam` 171–188 · `### EngineBackend forks` 189–206 · `## Runtime` 207–228 · **`### The system prompt, in assembly order` 229–265 ★ (the exact region P11.S2 targets — plan:3131 cites `:229-265` and it matches)** · `### Parallel tool dispatch` 266–281 · `## The gate chain` 282–305 · `## Tools` 306–332 · `## Providers` 333–384 · `## Sessions and state` 386–401 · `## The TUI layer` 402–471 · `## Dependencies` 472–485 · `## Recurring shapes` 486–509 (note :505–508 "Numbers … several derived by a test") · `## See also` 510–516 (links 10 pages; **PROMPT_ENGINEERING.md would need adding here**).

### `candy-code/README.md` — 1160 lines (prompt-relevant regions)
1–35 header · **57–115 non-interactive `-p`** · **116–329 `### Settings files`** — incl. the trusted-project refusal prose 180–185 and the **five-never-project-keys enumeration 187–~210** (`disabledRules` rationale at 193–198: "a rule pack is prompt prose the operator wrote"); exit-code table 320–324 · 330–538 subcommands · 539–613 shell-out · **622–672 Keys** (chat-owned chords; pinned) · **682–770 Slash commands** (`/rules` present at :686) · **771–870 Your own slash commands** (:778 "is the prompt that gets sent") · **871–919 What you see while a turn runs** — compaction tiers at 883–889 ("at 70% a system-role reminder rides along … at 85% older exchanges are summarized … at 95% the turn is refused"), model-summarizer prose 899–913 · 920–1008 Providers · **1009–1027 The agent loop** · **1028–1061 Capabilities** — Tools roster :1030, Skills :1043, Context files :1059 · 1062–1081 Architecture (ASCII diagram; Runtime at :1075) · 1082–1102 Limitations · 1103–1122 Custom provider · 1123–1160 Tests.

### `candy-code/docs/SETTINGS.md` — 560 lines
1–9 · `## The four layers` 10–83 · `## Opting a project in` 84–110 · **`## Which keys are layered at all` 111–265** — the layer-reading table row `disabledSkills|…|yes` / `disabledRules|…|**no**` at 152–153, the `disabledRules` prose block 182–210 ("a name is a path", list-not-map), `permissionMode/permissionRules` 257 · `### Why allowedTools is user-tier only` 266–474 · `## When a change takes effect` 475–495 (:485–486 `disabledSkills`/`skillRegistry()` launch-time) · `## When a file is ignored` 496–520 · `### The loud half` 521–548 · `## See also` 549–560 (keys without config path :557–558). **P11.S3 will edit this file → see guard tripwires in Q2.**

### `candy-code/docs/ENVIRONMENT.md` — 231 lines
1–24 intro (the "EVERY … IN ONE PLACE" roster claim) · **`## App variables` 25–60** — roster table 30–53; **`CANDY_CODE_DISABLE_PROMPT_CACHE` row :47** (full text quoted in Q6); flag-style `unset/empty/0` paragraph 55–60 · `### The two shell-out variables` 62–137 · `## Deprecated aliases` 138–157 · `## Provider credential variables` 158–185 · `## Variables read from any config file` 186–221 · `## OS variables` 222–231.

## Q2 — Which doc-drift guard polices which file (from each guard's own derivation)

| Guard (file:lines) | Polices | Derivation / generators it reads |
|---|---|---|
| `tests/Config/ReadmeRosterDriftTest.php` (674 ln) | **README.md only** (`private const README` :74) | Tool roster vs `Cli\Bootstrap::tools()` (:144, count-spelled-out :196); slash commands vs `CommandRegistry::slashCommands()` (:237); parenthesised aliases vs `Chat::dispatchCommand()` unadvertised arms (:264); subcommand fence vs `ParsedArgs::SUBCOMMANDS` (:291); layer table vs `LayeredSettings` layers (:321); permission modes vs `PermissionMode` (:371); always-chat chords vs `KeyBindingRegistry` (:425); launch-report sample vs the launcher line (:570); roster-integrity meta-test (:664) |
| `tests/Config/EnvRosterDriftTest.php` (986 ln) | **docs/ENVIRONMENT.md** (`ENVIRONMENT_DOC` :90) **bidirectionally**, plus **README.md + docs/*.md** mentions census (`$paths = array_merge([$root.'/README.md'], glob($root.'/docs/*.md'))` :794) | Oracle = `tests/Config/Support/EnvReadScanner.php` token-scan of `src/` (call shapes :158–408). Tests: read-but-untabulated :613; export-but-untabulated :657; tabulated-but-unread :670; other-page mention must be code-read :867; other-page mention must have roster row :912; census-sanity :955. **A new `docs/PROMPT_ENGINEERING.md` naming any `SUGARCRUSH_*` var is auto-scraped (:794 glob) and must match a roster row.** |
| `tests/Config/TrustKeyDocumentationDriftTest.php` (537 ln) | **docs/PERMISSIONS.md** trust-key table (:67, :137); **docs/SETTINGS.md** (:286 exists+gate, :302 every `LayeredSettings::LAYERED_KEYS` has a row, :320 agrees with `PROJECT_TIER_KEYS`); **README.md** (:406 "Only these … keys are layered" roster vs `LAYERED_KEYS`, :494 "never taken from a project file" vs `userTierOnlyKeys()`, both with spelled-in-words counts `NUMBER_WORDS` :356); **every docs/*.md page** via glob: :169 no stale "three trust grants" count, :195 every deep-link to the PERMISSIONS heading still resolves (→ renaming an ARCHITECTURE heading someone links to reddens this) | Trust keys scanned from `src/` text (:111, :240 src doc-block miscounts) |
| `tests/Config/ConfigWriteProducerDocumentationDriftTest.php` (729 ln) | **docs/SETTINGS.md** (:69) + **README.md** (:70) + `LayeredSettings` class doc-block | Derives from `Chat::handleModelCommand()`→`selectPaletteProvider()` `$onConfigChange` census: config.json receives exactly two keys (:280); no alias call-site (:305); slash+palette same write (:334); the "two producers" enumeration (`ENUMERATION_LEAD_IN` :430, `DOORS` :438) names both doors per page (:444, provider feeds `settings` + README :421); retracted provider-credit only inside its retraction (:610); README counterfactuals :670, :716 (layer table credits both slash commands). Paragraph-scoped via `DocumentParagraphs` (:13). **P11.S3's SETTINGS.md edits must keep these paragraph structures.** |
| `tests/Commands/KeyBindingDriftTest.php` (1893 ln) | **NO markdown file.** Polices the in-app reference: `KeyBindingRegistry::live()` rows driven through `Chat::update()`, `KeyboardHandler`, `MenuBar::handleKey()` (docblock :45–81). Only `.md` hits are sandbox fixtures (:1582). Methods :119,:200,:300,:343,:435,:494,:539,:580,:625 (9 tests). README's chord *prose* is policed instead by ReadmeRosterDriftTest:425. |
| `tests/Config/ReadmeSettingsTierClaimTest.php` | **README.md** + **docs/SETTINGS.md** | `disabledTools` auditability claim vs `Bootstrap::filterToolSet()`/`PermissionRule::matchesToolName()` fnmatch mechanism (:168); built-in tool count word (:253); launch-report byte (:271) and the SETTINGS.md copy of the same line byte-for-byte (:305); `SPELLED_COUNTS` map (:402); retracteds only on `>` lines (:43–49 rule). |
| `tests/Config/GlobFigureDriftTest.php` (895 ln) | **docs/SETTINGS.md** (:82) + `src/Config/LayeredSettings.php` doc-block | Measures `strlen` of `COUNTEREXAMPLE_GLOB '[!B]*'` (:92) against the spelled length word (`WORDS` :101) on both pages: :212 both pages argue from this glob, :244 provider over `settings`+`LayeredSettings` (:240), retraction arithmetic :296; stale-figure census across `src/`, `docs/`, `tests/`, `README.md` (:592 scope sanity names `LayeredSettings.php`, `Cli/Bootstrap.php`, `SETTINGS.md`), alphabet widening :740–:797, :835 known-stale paragraph, :854 retraction exemption, **:876 nothing in scope carries the stale figure and SETTINGS.md states the site-count the census finds**. |
| `tests/Config/DocumentParagraphsTest.php` (551 ln) + `Support/DocumentParagraphs.php` (335 ln) | Meta: the shared paragraph window used by the guards above. Scope builder reads **README.md + `docs/*.md` + `src/**.php`** (:514–538, incl. `assertArrayHasKey('docs/SETTINGS.md')` :538). Fence-aware split (`of()` :141, table/list item rules :210/:326/:331); **:386 `testNoDocumentInScopeLeavesAFenceOpen`** — a new docs page with an unbalanced code fence reddens it automatically via the glob. |
| Adjacent, unasked but triggered by Phase-11 edits | `ReadmeJsonErrorContractDriftTest` (README JSON error contract), `Chat/ChatConfigChangeDoorsDocumentationDriftTest` (4th `paragraphs()` copy, un-routed — Support docblock :16–24), **`tests/SymbolCitationDriftTest.php`** — resolves `{@see}` + backticked symbols in `src/`, `tests/` **and `docs/*.md`** (docblock :9–45): every test/class name Phase-11 docs cite must exist. |
<!-- table not formatted: invalid structure -->

**Not policed by anything:** `ARCHITECTURE.md` prose (only the trust-grant count :169, deep-links :195, and citation resolution touch it). Its prompt-assembly section has **zero derived guard** today → P11.S2's "Done when a test … confirms the documented order matches the assembled order" (plan:3137–3139) is genuinely net-new work, no existing harness to extend.

## Q3 — `tests/Integration/`: conventions, PromptEndToEndTest, sibling to mirror

Contents (22 files): `BinSugarcrushAutoloadGuardTest`, `BinSugarcrushDispatchTest`, `BinSugarcrushWiringTest`, `ContextWindowWiringTest`, `DeepResearchWorkflowTest`, `FanOutResearchTest`, `FeatWiringReachabilityTest`, `ForeignAgentPresetWiringTest`, `ForeignSkillWiringTest`, `GitMcpServerTest`, `McpToolWiringTest`, `MemoryPromptWiringTest`, `MultiAgentRefactorTest`, `ParallelToolCallsTest`, `ProviderRetryWiringTest`, `SkillPathScopingWiringTest`, `StreamingWiringTest`, `SystemPromptWiringTest`, `TeamLifecycleTest`, `UsageWiringTest`, `WorkflowExecutionTest`, `WorkflowResumptionTest`.

**`PromptEndToEndTest.php` does not exist — it is new exactly as plan:3163 says.** No other file in the tree carries that class name.

**Mirror = `SystemPromptWiringTest.php` itself.** Conventions it establishes, all reusable verbatim:
- `final class … extends TestCase`, `use HomeSandboxTrait;` with **BOTH HOME spellings** rationale (`getenv` vs `$_SERVER['HOME']` — comment :64–71).
- Construction mirrors production: `backend()` :1013–1020 = `new EngineBackend($provider,$provider->name())->withTools(Bootstrap::tools($tempDir,$loader))->withInstructionLoader(Bootstrap::instructionLoader($tempDir))`; class docblock :43–46 names `BinSugarcrushWiringTest` as the same stub seam.
- **Provider doubles:** `capturingProvider()` :1034–1099 — anonymous class implementing `ProviderInterface`, pushes every `CompleteRequest` into `public array $requests`; `supportsStreaming()=false` on purpose (:1026–1028, only `runBatch()` gives deterministic per-step responses); `toolCallOnFirstStep` answers step 0 with `ToolCall('call_sysprompt_1','no_such_tool',[])` to force a genuine second lap (:1081–1086). `echoingProvider()` :1110–1163 returns `content: (string)$request->systemPrompt` — the **only channel that survives `EngineBackend::completeAsync()`'s `pcntl_fork()`** (:1104–1108).
- **Fixture repo:** `sys_get_temp_dir().'/sugarcrush_sysprompt_'.uniqid()` + `.candy-code/skills/<name>/SKILL.md` via `SkillManager(new SkillLoader(), new SkillRegistry())->loadAll($tempDir)` (:970–983); deterministic-git trick: bare `mkdir($tempDir.'/.git')` (:336–381, exit codes 128/129 rationale); `writeSandboxUserConfig()` (:704–712) writes `<home>/.candy-code/config.json`; skill-body dedupe driven through private `Bootstrap::promptEnabledSkills()` via `ReflectionMethod` (:720–728). Second fixture class: `tests/Prompt/PromptFixture.php` (256 ln) — controls root/memory/composer/skill halves and calls private `Runtime::buildSystemPrompt()` through a **scoped `Closure::bind`** (:212–213). A related convention: `MemoryPromptWiringTest` :48–50 uses `BackendSelectionEnvSandboxTrait` to neutralize dev-shell `CANDY_CODE_BACKEND_CMD*` (docblock :41–46) — **any P11.S4 test that touches `Bootstrap::backend()` needs that trait too.**
- Keystroke-turn pattern for the e2e proof: `new Chat(backend: …)` → reflected `withInputBuf` → `update(new KeyMsg(KeyType::Enter,''))` → run the returned `AsyncCmd` promise on `React\EventLoop\Loop::get()` with a 10 s stop-timer (:482–510).

## Q4 — `SystemPromptWiringTest` roster + Phase-10 staleness + DO-NOT-TOUCH quote

14 test methods: `:94 testRootAgentsMdReachesTheProviderSystemPrompt` · `:110 testRootClaudeMdArrivesWithItsAtImportsAlreadyExpanded` · `:128 testEnvironmentBlockReachesTheProviderSystemPrompt` · `:168 testBothHalvesLandInOneSystemPromptWithEnvironmentLast` · `:313 testEveryStepOfOneTurnGetsAByteIdenticalPromptExceptTheTwoGitDiffSectionsWhichAreTheOnlyLicensedDifference` · `:478 testARealChatKeystrokeTurnDeliversBothHalves` · `:545 testDiscoveredSkillsAreListedInTheProviderSystemPrompt` · `:594 testAnEnabledSkillBodyLandsInTheProviderPromptExactlyOnceAndNotAlsoInTheListing` · `:658 testADuplicatedEnabledSkillNameSplicesItsBodyOnceThroughTheAssembly` · `:736 testEnablingOneSkillKeepsItsUnenabledNeighboursInTheListing` · `:778 testAnEmptyRegistryAddsNothingToTheSystemPrompt` · `:831 testTheFixtureAssemblesEveryControlledHalfInTheRealOrder` · `:932 testTheFixturePinsDatePlatformAndRootIntoThePrompt` · `:951 testAFixtureWithoutMemoryOrSkillsAddsNeitherBlock`. Helpers: :704, :720, :970, :989, :1013, :1034, :1110, :1165 (`soleSystemPrompt`), :1175.

**What P10.S1 makes stale** (`Runtime.php:1106–1120`: `[$systemPrompt,$systemBlocks]=self::assembleSections($this->systemPromptSections($app));` fed into `new CompleteRequest(systemPrompt:…, systemBlocks:…)`):

1. **Class docblock :39–40** — "every assertion here reads the `CompleteRequest::$systemPrompt` a real provider is handed." Production Anthropic-shaped providers now read the **block arm**: `BedrockProvider.php:165,216,338` (`private function systemBlocks(CompleteRequest)`) and `VertexProvider.php:546,591,631,650` (`foreach ($request->systemBlocks as $block)`). The block arm of the transmitted prompt has **no wire-level assertion anywhere** — including the by-construction identity `implode('', $blocks) === $prompt` documented at `Runtime.php:2882–2885` and `CompleteRequest.php:125`. P11.S4's new test should pin it; this file stays green without it today.
2. **`:150–158` (inside testBothHalvesLandInOneSystemPromptWithEnvironmentLast's docblock)** — MEASURED paragraph quotes production anchor `` `$systemPrompt = $this->buildSystemPrompt($app);` `` "in `Runtime::run()`". **That statement no longer exists in `run()`**; `buildSystemPrompt()` survives only as the string-only reflection arm (`Runtime.php:2493–2496, 2864–2869` + §17.2 invariant 1). `SymbolCitationDriftTest` will not redden it (the symbol resolves). Stale prose to fix under P11.S4 scope.
3. **`:831 testTheFixtureAssemblesEveryControlledHalfInTheRealOrder` + `:932/:951`** — not broken (their docblocks at :826–829 correctly say the fixture calls `buildSystemPrompt()`), but they pin **only the string arm**; the block list `run()` actually transmits is untested for order/emptiness (`assembleSections` folds empty renders out of *both* arms :2906–2908). Frame for P11.S4: extend alongside, never edit :478.

**DO NOT TOUCH — verbatim, `candy-code/tests/Integration/SystemPromptWiringTest.php:470–515`** (per plan:3165–3167 "never skip it, never weaken it"; extend alongside, do not edit):

```php
    /**
     * Top of the production chain: a keystroke. `Chat::update(Enter)` ->
     * `Chat::submit()` -> `EngineBackend::completeAsync()` (pcntl_fork(), the
     * same boundary every live bin/candy-code turn crosses) ->
     * `Runtime::run()`. The stub provider echoes the system prompt back as its
     * answer because only the returned Message survives the fork -- state
     * recorded on a provider inside the child dies with the child.
     */
    public function testARealChatKeystrokeTurnDeliversBothHalves(): void
    {
        file_put_contents($this->tempDir . '/AGENTS.md', 'CHAT TURN INTEGRATION MARKER');

        $chat = new Chat(backend: $this->backend($this->echoingProvider()));

        $withInput = new \ReflectionMethod($chat, 'withInputBuf');
        $withInput->setAccessible(true);
        $chat = $withInput->invoke($chat, 'what are this project conventions?');

        [$afterSubmit, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceOf(\Closure::class, $cmd);

        $asyncCmd = $cmd();
        $this->assertInstanceOf(\SugarCraft\Core\AsyncCmd::class, $asyncCmd);

        $loop = \React\EventLoop\Loop::get();
        $resolved = null;
        $asyncCmd->promise->then(function ($msg) use (&$resolved, $loop): void {
            $resolved = $msg;
            $loop->stop();
        });

        if ($resolved === null) {
            $safety = $loop->addTimer(10.0, static function () use ($loop): void { $loop->stop(); });
            $loop->run();
            $loop->cancelTimer($safety);
        }

        $this->assertInstanceOf(\SugarCraft\Code\AssistantMsg::class, $resolved, 'the completion did not finish within the test timeout');

        [$final] = $afterSubmit->update($resolved);
        $answer = $final->history[array_key_last($final->history)]->content;

        $this->assertStringContainsString('CHAT TURN INTEGRATION MARKER', $answer);
        $this->assertStringContainsString('<env>', $answer);
        $this->assertStringContainsString('Model: echo-sysprompt', $answer);
    }
```
**[DO-NOT-TOUCH — this method and its doc-block]**

## Q5 — Phase 11 steps, quoted (prompt_plan.md:3114–3188; identical in both trees)

> **3114:** `## Phase 11 — Docs, sweep, final audit`
> **3116:** `### P11.S1 — docs/PROMPT_ENGINEERING.md`
> **3118–3121:** Goal — "Ship the rationale as docs alongside the prompts … the section order and why, the stability classes, the fence/provenance rules, the cache-breakpoint contract, and the register rules (what goes in, what was deliberately left out and why)."
> **3122:** Source §9.15, §12 seam 12 · **3123–3124:** Files — `candy-code/docs/PROMPT_ENGINEERING.md` (new)
> **3126–3127:** Done when — "explains all nine of §9.12's 'do not do this' items with their reasons".
> **3129:** `### P11.S2 — Update docs/ARCHITECTURE.md` — **3131–3132:** "Its prompt-assembly section (`:229-265`) documented the seven layers and matched the code exactly. After phases 3 and 5 it does not. Fix it." **3137–3139:** Done when — "a test — or a documented manual check recorded in the worklog — confirms the documented order matches the assembled order… if a cheap assertion can pin it, add one."
> **3141:** `### P11.S3 — Update the affected feature docs` — **3143–3151:** `HOOKS.md` (additionalContext, new dispatch sites), `SKILLS.md` (canonical skill→prompt path), `MEMORY.md` (`/memory import`), `SETTINGS.md` (rules keys, user-tier-only rule), `COMMANDS.md` (`/rules`). **3153–3154:** five concurrent, one per agent.
> **3156:** `### P11.S4 — The end-to-end proof` — **3158–3159:** "One test that starts from a real keystroke turn and asserts the model receives all seven layers. Not a stub recording a DTO — the payload." **3162–3163:** `SystemPromptWiringTest.php` + `tests/Integration/PromptEndToEndTest.php` (new). **3165–3167:** Hard constraint — the keystroke method is standing DO-NOT-TOUCH, "Extend alongside it; do not edit it." **3168–3170:** Done when — new test would have failed pre-Phase-1; "A regression test that would not have caught the original bug is not a regression test."
> **3172:** `### P11.S5 — Final plan audit` — walk all 12 phases vs `prompt_expand.md` §9; **3182–3183:** every §9.1–§9.15 item dispositioned (landed/declined/deferred). Files: `prompt_worklog.md`, `prompt_resume.md`, plan stamps only.
> **3185–3188:** Batch 1 = P11.S3 five-way; Batch 2 = P11.S1, S2, S4 three-way (disjoint); Batch 3 = P11.S5 alone.

## Q6 — Docs sentences contradicting shipped behaviour

Shipped order of record — `Runtime::systemPromptSections()` :2519–2749: base(:2522) → `MaximsSection`(:2533) → tool-guidance-if-nonempty(:2548–2552) → repo-map(:2563) → `<user-rules>`(:2630) → `<project-instructions>` docs(:2652) → project-tier rules(:2687) → memory(:2701) → enabled skill bodies(:2712) → skill listing(:2731) → **`<env>` LAST(:2747)** — i.e. ~11 section slots, not seven layers.

1. **CONTRADICTION — `ARCHITECTURE.md:233–244`:** lists `EnvironmentBlock` as item 2 and omits maxims, tool-guidance and both rules tiers. Directly false against :2747 + :2533. (This is the region P11.S2 already knows about.)
2. **CONTRADICTION — `ARCHITECTURE.md:261–264`:** "EnvironmentBlock::render() polls `git status --porcelain` and sits **ahead** of everything else, so the first edit of a session voids the cacheable prefix for everything downstream of it anyway." P3.S1 inverted this — env is last precisely so nothing sits downstream to be voided (`Runtime.php:2499–2500` "volatile `<env>` block last (the P3.S1 ordering invariant)"; :2737–2742). The sentence's cache reasoning now argues *for* the deleted layout.
3. **CONTRADICTION, in `src/` — `Context/MemoryBlock.php:52–53`:** "…polls `git status --porcelain` on every call and **sits AHEAD of this block**, so the first edit of a session voids everything downstream of it anyway." Stale the same way, and ARCHITECTURE:261 *cites "MemoryBlock's own source"* — fixing only the doc leaves it quoting a false source. P11.S2's edit must span both files (mind §2.2 hot-file rules for `src/`).
4. **systemBlocks — docs SILENT (no contradiction, but the claim gap P11.S1 must fill):** zero mentions of `systemBlocks`/blocks anywhere in `docs/*.md`+README. `CompleteRequest.php:53,107,125,140` defines the field and the implode identity; `Runtime.php:2871–2897` the two-arm fold. P11.S1's "cache-breakpoint contract" section is the register site.
5. **CacheBreakpoints unwired — already honest, keep honest:** `ENVIRONMENT.md:47` full row: "unset — breakpoints are enabled | Set to any value other than empty or `0` and `CacheBreakpoints::disabledFromEnvironment()` reports the switch on; a disabled instance runs `apply()`'s full input audit … and then adds **zero** new `cache_control` breakpoints … **Dormant by design at this tree**: nothing in `src/` or `bin/` consults it yet. `CacheBreakpoints` itself ships unwired pending the licensing decision …". Verified: `-l` scan shows `CacheBreakpoints` referenced only by `src/Providers/CacheBreakpoints.php` + `tests/Providers/CacheBreakpointsTest.php`; the class docblock :113–126 records "ships WITHOUT a production caller by orchestration adjudication … the exact P6.S1 Triggers precedent". The default-column phrase "breakpoints are enabled" is misleading in isolation but self-corrected in-row — **do not "fix" it without keeping EnvRosterDriftTest's roster/prose rules green** (`testTheScan…`/mention census :867/:912 also police any new page naming the var).
6. **CANDY_CODE_DISABLE_PROMPT_CACHE:** roster row + flag-shape paragraph (`ENVIRONMENT.md:55–60`) exist and match the single `getenv` reader (`CacheBreakpoints.php:235`, `DISABLE_ENV` :186). Consistent; EnvRosterDriftTest is the tripwire if P11 docs re-mention it (they will, per Q5).
7. **Session affinity — shipped but dormant; docs SILENT:** `Concerns/SessionAffinity.php:65` `X-CandyCode-Session`, trait used by `CustomProvider.php:30,47` and `SglangProvider.php:452`; **no production call site passes an id** (zero hits in `ProviderFactory`, `Bootstrap`, `Runtime`, `EngineBackend`; null ⇒ "byte-identical to the pre-P10.S4 shape", `CustomProvider.php:43–45`). No doc sentence claims otherwise — nothing to correct, but P11.S1's register rule ("what was deliberately left out and why") is where dormancy gets written down.
8. **E38 reminder decline:** disposition per `prompt_plan.md:2804` ("E38 skipped-with-reason … E38 + PromptFence roster-widen scheduled after Phase 10") and `prompt_expand.md:3904` ("Do NOT fix by widening `isContextReminder()`"). Only doc mention is `README.md:884–885` "at 70% a system-role reminder rides along with the turn" — true; no page claims the reminder is stripped by compaction. **No contradiction; P11.S5 must carry the disposition row.**
9. **PromptFence 8-tag roster:** `src/Context/PromptFence.php:136–145` = `env, project-memory, repo-map, project-instructions, system-reminder, user-rules, prior-summary, harness-injected` (+ `tags()` :157 escape hatch, regex :178). No doc enumerates fences (only fence-tag live in `Runtime.php:2588–2596`'s P6.S2 widen note and tests). Docs neither miss nor misstate it → P11.S1 introduces it; `Runtime.php:2598–2604` "TRIGGERS ARE NOT YET APPLIED (named so, not hidden) … recorded as a row in prompt_plan §18" belongs in the same register.

**Phase-11 edit tripwire summary:** edits to README §Settings/§Capabilities/§Keys → ReadmeRoster + TrustKey + SettingsTierClaim + ConfigWriteProducer (+ GlobFigure if "eight/ten/eleven" words move); SETTINGS.md → TrustKey :286/:302/:320, ConfigWriteProducer, GlobFigure, SettingsTierClaim :305; ENVIRONMENT.md → EnvRoster (both directions); new docs pages auto-enter three globs (`docs/*.md` at EnvRoster :794, TrustKey :175/:202, DocumentParagraphs fence test); every symbol any page backticks must resolve (SymbolCitationDriftTest).