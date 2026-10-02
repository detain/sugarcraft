# 15d — Audit: prompt/context assembly, memory, skills, configuration

Auditor scope: `src/Context/`, `src/Memory/`, `src/Skills/`, `src/Config/`, the config/trust half of `src/Cli/Bootstrap.php`, `src/Util/PathGlob.php`, `src/Compactor.php`, plus `docs/{SETTINGS,MEMORY,SKILLS,PROMPT_ENGINEERING}.md`.
Baseline: `99-synthesis.md` Part II. Nothing below repeats an item listed there; where a finding touches a known item, the new part is called out.

Tree state: master @ `05db616f3` (the working tree has unrelated uncommitted `candy-palette` edits). Vendor mode: `sugar-crush linked 20/20 symlinked` (`php scripts/refresh-deps.php --status`).
Repro scripts are in `/home/sites/crush-research-repos/_audit-scratch/15d/` (`rN.php`). Each takes about 1 s with `php rN.php` (`r14.php` is a timed race and takes as many seconds as its argument).

This report is final. What was read, what was only skimmed, and how each open lead was settled is listed under **Coverage** at the end.

---

## A. Skills (loader, registry, listing, nudges)

Its last open finding, 15d-03, was fixed in wave 9; see **Fixed since audit**.

---

## B. Memory store

Its only open finding, 15d-05, was fixed in wave 8B; see **Fixed since audit**.

---

## C. Instruction files and prompt-wide encoding/size

Its only open finding, 15d-25, was fixed in wave 8A; see **Fixed since audit**.

---

## D. Environment block (git)

Its only open finding, 15d-13, was fixed in waves 1 and 8B; see **Fixed since audit**.

---

## E. Configuration and trust

Both findings here (15d-15, 15d-16) were fixed in wave 6; see **Fixed since audit**.

---

## F. Lower-priority observations (Verified by reading; nondeterminism and caps)

Its only entry, 15d-17, was fixed in wave 3; see **Fixed since audit**.

---

## G. Findings from the resumed pass

Its only open finding, 15d-24, was fixed in wave 8B; see **Fixed since audit**.

---

## Summary table (by severity)

No finding in this appendix remains open: the last, 15d-03, was fixed in wave 9. See **Fixed since audit**.

---

## Coverage

**Status:** final. No source was modified, nothing was committed, and the only repository file written is this report. Every process started for the audit (repro scripts, the `r14.php` race, the phpunit run and its watchdog) was waited for or killed.

### Read end to end
- **Context:** `PromptFence.php`, `MemoryBlock.php`, `InstructionFileLoader.php`, `ImportResolver.php`, `RuleLoader.php`, `Rule.php`, `RulesState.php`, `RulePathNudge.php`, `Triggers/{Trigger,KeywordTrigger,PathTrigger,IntentTrigger}.php`, `EnvironmentBlock.php`, `RepoMapBlock.php`, `ProjectMemoryWriter.php`, `CompactorConfig.php`, `Sections/MaximsSection.php`, `ContextWindow.php`, `IdleCompactionPolicy.php`, `Stability.php`, `PromptSection.php` (all under `src/Context/`)
- **Memory:** `src/Memory/{MemoryStore,ForeignMemoryImporter,MemoryEntry}.php`
- **Skills:** `src/Skills/{Skill,SkillLoader,SkillRegistry,SkillManager,SkillMatcher,ForeignSkillDiscovery,SkillPathNudge,SkillDiscovery,SkillSource}.php`; frontmatter of all 12 `src/Skills/BuiltIn/*/SKILL.md`, plus the bodies of `worktree-workflow`, `matchups-sync`, `mcp-authoring` and `explore-codebase`
- **Config:** `src/Config/LayeredSettings.php`, `src/Config/StatusLineCommand.php`
- **Support and util:** `src/Support/Frontmatter.php`, `src/Util/PathGlob.php`, `src/Util/Exporter.php`, `src/Util/TokenTracker.php`, `src/Compactor.php`, `src/CompactedGroup.php`, `src/Registry/{Tool,ToolSignature}.php`
- **`src/Cli/Bootstrap.php`:** `:2945-2970`, `:3000-3040` (`availableProviders`), `:3040-3680` (config paths, readUserConfig/mergedConfig/rawUserConfig, writeUserConfig), `:3758-3900`, `:4216-4247`, `:4636-4712`, `:4790-5330` (permissionGate, permissionConfigLayers, withoutEmptyPermissionOverrides, permissionSettingsLayer, readPolicyFile), `:6835-6900` (nudge and tool wiring), `:6995-7300` (filterToolSet, toolSetUnder, reportProjectTierToolRemovals, instructionLoader, forcedInstructions), `:7619-7800` (selectedProviderName, backend-command tier, title/summary toollessBackend), `:7830-8042`
- **Elsewhere:** `src/Providers/ProviderFactory.php:60-330` (config.dev.json path and reader); `src/Agents/WorktreeConfig.php` (config read); `src/Chat.php:12860-13040` (`/memory` locate/delete/clear/edit); `src/Runtime.php:2806-3260`, `:3312-3370`, `:3580-3625`, `:1185-1215`, `:1780-1800`; `src/Backend/EngineBackend.php:740-800`, `:1114-1269`
- **Docs:** `docs/SETTINGS.md` and `docs/ENVIRONMENT.md` in full (the env tables were compared against `src/`), `docs/SKILLS.md` (all), `docs/MEMORY.md` (all except 275-end), `docs/PROMPT_ENGINEERING.md` (1-275)
- **Root `.sugar-crush/`:** `config.json`, `config.dev.json`, `agents/{coder,reviewer,security-auditor}.md`

### Skimmed, or deliberately left to other reports
- **`src/Context/ContextCompactor.php`:** read `:100-200`, `:448-760`, `:1037-1200`. The rest is the compaction-quality surface already covered by known items #15-#19.
- **`src/Chat.php` and `Bootstrap::chat()/app()`:** only the wiring ranges above. The TUI and turn loop belong to other audit slices.

### How each lead was settled
1. **15d-10 special tokens:** still Suspected. skynet2 was unreachable from the audit host (curl `000`), so the tokenizer probe could not run. The exact probe to run is written into 15d-10.
2. **`index.lock` contention:** **confirmed and widened** (`r14.php`). Folded into 15d-12. `git diff` takes the lock even with `GIT_OPTIONAL_LOCKS=0`.
3. **`filterToolSet()` non-string entries:** dropped. `toolSetUnder()` checks `is_string($pattern)` before `PermissionRule::matchesToolName()` (`Bootstrap.php:7055-7057`) for both `allowedTools` and `disabledTools`.
4. **`forcedInstructions()`:** dropped as stated. Non-list values and non-string or blank entries are filtered (`:7281-7296`). The key is user-tier only. PHP `glob()` has no `**` globstar, and every match is containment-checked (`InstructionFileLoader.php:527`). **New finding instead:** the unescaped root prefix passed to `glob()` (15d-22).
5. **`LayeredSettings::merge()` null-vs-absent:** dropped. Only layer 4 (`config.json`) passes through unfiltered, and the CLI writes only string `provider`/`theme` and the `layout` manifest there, so a JSON `null` can come only from the user's own hand edit. The permission keys, where null-vs-absent matters for safety, are already guarded by `withoutEmptyPermissionOverrides()` (`:5036-5069`), and `[]` outranking `settings.json` is documented and deliberate (SETTINGS.md "Only those two spellings count as empty").
6. **`MemoryStore::update()` id mismatch:** the `/memory edit` call site (`Chat.php:13023`) passes the id the user typed and the entry read from that file, so the literal mismatch cannot happen there. The broader frontmatter-id vs filename split is real and is reported as 15d-23.
7. **`boundHeadAssistantForSummary()` `## Skill: ` exemption:** dropped. The authors document it as an accepted residual in the method's docblock (`ContextCompactor.php:686-700`, "ACCEPTED AS A DOCUMENTED RESIDUAL").
8. **Drift-test coverage:**
   - `TrustKeyDocumentationDriftTest` pins the SETTINGS.md key table to `LAYERED_KEYS`/`PROJECT_TIER_KEYS`, and `EnvRosterDriftTest` pins the env roster.
   - "Native always wins" (foreign vs native) is pinned by `SkillManagerTest:246` and `:366`, but native project vs native user is not pinned (added to 15d-03).
   - "A bad skill is a bounded notice, never a launch crash" (SKILLS.md:132) is scoped to `enabledSkills` entries and holds there. It does not cover frontmatter typing, so it does not contradict 15d-01.
   - The three stale sentences in 15d-19 are unpinned.

### Other checks with no finding
- **Root `.sugar-crush/` (step 4):**
  - `config.dev.json` is read only from the **package** root (`ProviderFactory::readableDefaultConfigPath()`, `dirname(__DIR__, 2)` plus containment), never from a project, so a cloned repo cannot point the provider at its own endpoint. ENVIRONMENT.md:32 calls it "a project `.sugar-crush/config.dev.json`", which reads as the cwd project but means the package (wording only).
  - The monorepo root's `.sugar-crush/config.json` carries `"trustedProjectMcp": ["/home/sites/sugarcraft"]`. That key is inert there: trust keys are read only from the user's layer-4 file, and project files are filtered to `PROJECT_TIER_KEYS`. It is misleading but harmless.
  - The agent presets' `model`/`permissionMode`/`isolation` keys are known item #23.
- **`KeywordTrigger`:** keywords with a non-word edge (`.env`, `C++`, `--force`, `@deprecated`) can never fire, and a prompt containing one invalid UTF-8 byte fires no keyword (`r15.php`). Both are documented in the class docblock, and the matcher has no consumer today (15d-19). When it is wired, `Rule::new()` should refuse such keywords loudly (known #37).
- **`RulePathNudge`:** budget, escape and clip logic are correct. Deferral pointers are bounded at 1,024 B and escaped.
- **`Exporter::toJson()`:** returns `json_encode()`'s `false` through a `: string` return type on invalid UTF-8 (a TypeError), but its only caller is `ShareSession` (`/share`, a stub per known #35).
- **Guard suites:** `vendor/bin/phpunit --filter 'SkillManager|SkillLoader|MemoryStore|PromptSection|EnvironmentBlock|ForeignMemoryImporter|RulePathNudge|KeywordTrigger|LayeredSettings'` passed with 356 tests and 1,377 assertions (vendor mode `linked`). No test pins any reported defect as intended behaviour, except the colour control in `PromptStabilityTest` noted under 15d-12.

### Repro scripts and fixtures (`/home/sites/crush-research-repos/_audit-scratch/15d/`)
- **Viewer:** `code.php` strips comments and keeps the original line numbers (`php code.php <file> [from] [to]`).
- **15d-01:** `r1.php`, `r1b.php`, `proj0..4/`, `proj1/` (live `bin/sugarcrush -p` fatal), `proj12/` (non-string `paths` → nudge TypeError).
- **15d-02:** `r5.php` + `proj5/`.
- **15d-03:** `r6.php`, `r6b.php` + `home6/`, `proj6/`, `empty/`.
- **15d-04:** `r3.php` + `proj3/`, `proj4/`, `umem/` (live per-turn failure in `proj3/`).
- **15d-05:** `r10.php` + `homemem10/`, `projA/`, `projB/`.
- **15d-06:** `r8.php` + `ch8/`, `mem8/`.
- **15d-08:** `r2.php` + `proj2/`.
- **15d-09:** `r9.php` + `proj9/`.
- **15d-12:** `r4.php` + `gitrepo/`, `gitcfg`, `extdiff.sh`; `r14.php` + `lockrepo/` (index.lock race: `php r14.php 8 &` while looping `git -C lockrepo add f1`).
- **15d-13:** `gitrepo/sub`.
- **15d-15:** `cfg7/`.
- **15d-20:** `r16.php` + `proj16/`.
- **15d-21:** `r17.php` + `empty17/`.
- **15d-22:** `r18.php` + `br[1]/`.
- **15d-23:** `r13.php` + `mem13/`.
- **Dropped KeywordTrigger lead:** `r15.php`.
- **Scratch HOMEs:** `home1/`, `home11/`.

---

## Fixed since audit

These findings were fixed on master after the audit. Their sections and table rows were removed; the coverage and repro lists above still name them.

- **15d-01** Mistyped SKILL.md frontmatter crashed sugar-crush at launch — fixed on master in `d1c1822a9` (mistyped skills are skipped and reported).
- **15d-04** One malformed memory file broke every turn — fixed on master in `d26c38cdd`. Residual (skipped notes announced only in the prompt, with no user-facing display) fixed later in `0171ed120` (launch notice plus an "Unreadable notes" section in `/memory list`/`/memory search`); the `-p` path does not raise the launch notice (see 15d-02).
- **15d-08** A non-UTF-8 byte in an instruction, rule, memory or skill file made every request throw — fixed on master in `218384747` (scrub at load time).
- **15d-02** Repo skill descriptions reached the system prompt unfenced, uncapped, multi-line and in harness voice — fixed on master in `a2d3dfcf3` (each line escaped, one-line, capped; enabled bodies escaped) and `da2930fbd` (the listing is fenced as `<available-skills>` under `Runtime::SKILL_LISTING_AUTHORITY_PREAMBLE`, the ninth `PromptFence` tag, and each line carries a provenance badge `[built-in]`/`[user]`/`[project]` (+ `foreign: claude|opencode`) from the new `Skills\SkillOrigin`; untiered skills default to project), with the memory follow-up `0171ed120` (unreadable memory notes shown to the user: `MemoryStore::unreadable()`, `Memory\UnreadableNotes`, `Bootstrap::reportMemorySkips()` launch notice, and an "Unreadable notes" section in `/memory list`/`/memory search`). `SkillPathNudge` lines are deliberately not badged. Residual: the `-p` path (`src/Cli/NonInteractive.php`) did not call `reportMemorySkips()`, so non-interactive runs raised no unreadable-memory launch notice (R10); fixed since in `17a50bbeb` (wave 9: `reportMemorySkips()` is public, resolves a null root to the cwd, and `NonInteractive` calls it beside `reportSkillSkips()`; `docs/MEMORY.md` notes it).
- **15d-10** PromptFence let attribute-bearing roster tags through and did nothing about chat-template control tokens — fixed on master in `161d60881` (the roster pattern is a lookahead on the `<` alone, so attribute lists, split lists and unterminated openers are matched; the same rewrite defangs `<|` and `<｜` openers, listed in `PromptFence::CONTROL_TOKEN_PIPES`). The special-token half was never confirmed live (the tokenizer probe stays unrun). Residual: the docs do not describe the defang (15d-25).
- **15d-21** The built-in skills shipped SugarCraft-monorepo procedures to every project — fixed on master in `cadba57fd` + `d70017a2f` (the four monorepo skills moved to `<repo root>/.sugar-crush/skills/`, the project tier, so they load only in this monorepo; `worktree-workflow`'s dirty-tree step now stops and reports instead of discarding; `docs/SKILLS.md` and `README.md` list eight built-ins; `BuiltInSkillsTest` scans both trees for discard-work commands, walking directories instead of globbing). Residual: `CHANGELOG.md` still lists them as built-ins (15d-26).
- **15d-18** `skillKeyFor()` broke on a trailing slash or a foreign path spelling — fixed on master in `0f971f954` (the base is `rtrim`med, a leftover leading `/` is trimmed, and a path not spelled under the base is keyed through both realpaths, then by the skill's own name).
- **15d-17** Count caps were applied in readdir order, and the repo-map walk was unbounded for non-PHP files — fixed on master in `7e2e08492` (`RuleLoader` and `RepoMapBlock` walk each directory with `scandir()` + `sort()` and cap after sorting; a visit budget counts every entry, `MAX_WALK_ENTRIES = 4096` for rules and 100,000 for the repo map, and a cut is recorded).
- **15d-14** The git subprocesses in prompt assembly had no time bound — fixed on master in `6e2df9a26` (`runCaptured()` gains an optional 4th `?float $timeoutSeconds`, default null so Bash and Grep are unchanged; on expiry the group gets SIGTERM then SIGKILL, the result carries `timedOut` and exit 124; the env block bounds each git read at 2 s, renders `unavailable (git timed out after 2s)`, and one timeout skips the rest of the git section; the branch read is bounded through `runCaptured()` too, keeping the `shell_exec` fallback).
- **15d-12** Env-block git calls honoured the user's git config and took `index.lock` — fixed on master in `da6674686` (every call runs as `git --no-pager --no-optional-locks -c color.ui=false -c core.quotepath=false`; `log` adds `--no-color --no-show-signature`; diffs use the plumbing `diff-index --cached` and `diff-files` with `--no-ext-diff --no-textconv --no-color`, so the index is never written; an unborn HEAD diffs against the empty tree; `GIT_OPTIONAL_LOCKS=0` is set through a new optional 5th `runCaptured()` parameter, `array $envOverrides`; `PromptStabilityTest`'s colour control is rewritten so its fixture must yield zero escapes). Residual: a stat-dirty binary file still counts in the shortstat, and with no stat refresh a heavily stat-dirty tree renders slower (about 0.5 s per 3,000 files) until the user's own git refreshes the index.
- **15d-20** A `paths:`-scoped rule added or edited mid-session was never delivered: the splice skipped it and the boot-time nudge never learned of it — fixed on master in `d1157349b` (`RulePathNudge::fromLoader()` re-walks the rules on each consult; the announced ledger holds a digest of each rule's body, so a rule added mid-session is delivered, an edited rule is re-announced with its current body, and a rule whose `paths:` is removed is no longer nudged; Bootstrap changes only the construction line).
- **15d-09** CLAUDE.md, AGENTS.md and forced instruction files had no size cap (a 3 MB file went into every request whole), and enabled skill bodies were uncapped — fixed on master in `dd8be915d` (with 15a C3: instruction documents are priced in framed, escaped bytes against 64 KiB per document and 128 KiB combined; a document or `@import` that does not fit becomes a pointer line and is recorded in `InstructionFileLoader::refusedPaths()`; every document read is stat-checked and bounded at 60 KiB; enabled skill bodies are held to `CompactorConfig`'s per-skill and combined budgets via `TokenEstimate`; a prompt under budget is byte-identical; `ContextCompactor::filterSkills()` is left dormant). Residual: there was no user-visible notice for a deferred instruction file, skill deferrals were recorded nowhere, and skill budgets used the `CompactorConfig` defaults because App carried no compactor config (R1); `SkillLoader` read skill files uncapped (15d-27, fixed in wave 8A). R1 is partly fixed since in `d1416fb86` (wave 9: launch notices for deferred instruction files and over-budget skill bodies through shared `Runtime` planners, `Runtime::skillDeferrals()`, `App::withCompactorConfig()`; see 15a C3). **Remaining (R1):** `EngineBackend`'s per-turn `App` has no compactor config (no settings key feeds one), and files `loadForPath()` refuses mid-session are not surfaced.
- **15d-19** Stale, unpinned doc statements: rule `paths:` scoping "not applied", and "only two keys" re-applied per turn — fixed on master in `232013284` (`docs/PROMPT_ENGINEERING.md`, `docs/SKILLS.md` and `docs/SETTINGS.md` corrected; SETTINGS.md now names three re-applied keys, adding `maxOutputTokens`; a fourth stale claim, the `<system-reminder>` emitter list, is fixed too; each is pinned by a derived assertion in `DocFigureProseDriftTest`).
- **15d-15** `writeUserConfig()` replaced a symlinked `config.json` with a regular file, ran an unlocked read-merge-write and overwrote an unparsable config — fixed on master in `66f2f760a` (it writes through a symlinked config to its regular, policy-checked target, and writes nothing for a dangling or unsafe link; it refuses to persist over an unparsable or non-object config through a new `userConfigForMerge()`, where a missing or empty file counts as `{}`; and the read-merge-write runs under a 5 s `TimedFileLock` on a `.<name>.lock` sidecar, skipping the write on timeout).
- **15d-16** Huge numeric values for `maxToolSteps` and `maxOutputTokens` wrapped negative through `(int)` casts — fixed on master in `987caa2cf` (values at or above 2**63 resolve to null, the default, instead of wrapping negative; `maxToolSteps` refuses non-integral values such as 1.5, while 8.0 is accepted; `maxOutputTokens` keeps its documented truncation; `docs/SETTINGS.md` updated). Residual: there was no user notice for a nonsense value (R12); fixed since in `d1416fb86` (wave 9: `Bootstrap::reportNonsenseLimits()` raises one launch row per bad `maxToolSteps`/`maxOutputTokens` value on the TUI and on `-p`, the resolvers still answering null; absent, `null` and `""` count as unset; a differential test pins the token verdict to EngineBackend's resolver). No ceiling constant was added.
- **15d-22** A checkout path containing `[`, `*` or `?` silently dropped forced instructions and every repository memory note — fixed on master in `98ec1dd66` (the checkout root is glob-escaped in `InstructionFileLoader::loadForced()`, and every `MemoryStore` glob is replaced by one sorted `scandir()` helper).
- **15d-06** `/memory import claude` built the Claude Code project slug wrongly and imported nothing for any path containing `.` — fixed on master in `f26a4733b` (`claudeProjectSlug()` reproduces Claude Code's algorithm, turning every non-alphanumeric character into `-` and cutting a slug over 200 characters with a hash suffix, and falls back to the legacy `/`-only spelling; the importer lists with `scandir()`). Residual: `CLAUDE_CODE_PROJECT_DIR_NAME` and `CLAUDE_CONFIG_DIR` were not consulted; fixed in `e4f278ca5` (wave 8A, R11).
- **15d-07** Repo-shipped memory was presented as the user's own notes — fixed on master in `6a609f594` (`MemoryBlock` lists repo-store and home-store notes under separate provenance labels inside one `<project-memory>` fence; the cap, byte budget and omission count stay one newest-first walk; a home-only block is byte-identical to before; `docs/MEMORY.md` updated).
- **15d-11** MEMORY.md said `@~/…` imports resolve against home, while in practice every one was refused — fixed on master in `a4dc54c9d` (`ImportResolver` resolves `~` through `HomeDirectory::owned()`, so there is no more `/x.md` when `HOME` is unset and no world-writable home; with no owned home the reference is left as written; `docs/MEMORY.md` now says a `~/` import is blocked as `outside-repo-root` unless the home directory is inside the checkout).
- **15d-23** Repository memory notes could become unaddressable, and every write rewrote a git-tracked `MEMORY.md` — fixed on master in `18206b703` (wave 6: the id is the filename stem; the index is byte-stable with no timestamp) and `51efe5642` (wave 8A, N2: the repo store no longer writes `MEMORY.md`; `MemoryStore::forRepository()` builds a store whose `generateIndex()` writes nothing and whose `loadIndex()` derives the same bytes from the notes through the shared `renderIndex()`; every repo-store reader and writer goes through `ProjectMemoryWriter`, which builds it that way; a legacy generated index is removed on the next mutation of its scope, while a hand-written `MEMORY.md` is left alone). The home store is unchanged.
- **15d-25** MEMORY.md and PROMPT_ENGINEERING.md did not describe PromptFence's chat-template-token defang — fixed on master in `e4f278ca5` (both pages describe the `<|` / `<｜` defang and the attribute-bearing and unterminated tag match, with worked examples; the new `MemoryDocumentationDriftTest` replays each page's examples through the live `PromptFence`, requires every `CONTROL_TOKEN_PIPES` glyph to be named, and pins the new ENVIRONMENT.md "Claude Code variables" table against the importer's `*_ENV` constants).
- **15d-26** `CHANGELOG.md` still listed the four moved skills as built-ins — fixed on master in `9e69d6c9e` (a new subsection, "Skills — precedence, bounded reads, the moved monorepo skills (2026-10)", corrects the record; the historical entry is unchanged).
- **15d-27** `SkillLoader` read skill files with no size limit — fixed on master in `9e69d6c9e` (the new `Skills/SkillFileReader` handles every skill read — `Skill::fromFile`, the manifest head, the body and assets: it stats first and refuses files over 1 MiB, reads at most the ceiling plus one byte, and the manifest stage reads only the first 64 KiB and refuses frontmatter that does not close inside it; a refused skill is not listed and is recorded in `skipped()`; a 50 MB sparse `SKILL.md` loads with a peak memory rise under 8 MB).
- **15d-05** Home-store `project` notes were global, so they reached every repository's prompt — fixed on master in `0484a28c3` (`MemoryStore::forProject()` and `projectKeyFor()` keep the home store's `project` scope at `project/<slug>-<sha256[0:16] of the canonical root>/`, and list, search, get, the index, the skip map and the unreadable scan see only that directory; `Bootstrap::memoryStore($root)` builds the keyed store from the `ProjectRoot` answer, so Chat's `memoryStore->add()` fallback is keyed without a Chat edit; unkeyed legacy notes are moved, never overwriting, into the first keyed store that touches the scope, and a `.bound-legacy` record makes the next interactive launch show `MEMORY_LEGACY_BOUND_NOTICE_FORMAT` once). Residual (Chat edits): the `/memory add` reply did not say when a note fell back to the home store, and the `/memory import` sentinel was written under the cwd rather than `ProjectRoot::resolve()`; both fixed since in `aa7813a98` (wave 9: the sentinel and its containment root use `ProjectRoot::resolve(projectRoot())`, and a project note that falls back to the home store says so in its reply).
- **15d-13** A subdirectory launch reported "Is directory a git repo: No", dropped the git state and missed `.sugar-crush/*` — part (a) fixed in `119bc86d2` (a bounded `git rev-parse --show-toplevel`, the "repo root:" line and the full git section) and part (b) in `f2c1f0445` (wave 8B: the new `Support\ProjectRoot` resolves the root for `.sugar-crush/*` and `.mcp.json` lookups — the launch directory when it holds `.sugar-crush/`, `.mcp.json` or `.git`; otherwise, inside a work tree, the nearest directory up to the work-tree root holding `.sugar-crush/` or `.mcp.json`, else the work-tree root; outside a work tree, or for a work tree at or above `$HOME`, the launch directory — and it is wired into the settings layer and its trust, command-shell trust, workflows, agent presets, `hooks.yaml`, the `.mcp.json` decision, CommandLoader, SkillLoader, SkillDiscovery, RuleLoader and ProjectMemoryWriter; tools, hooks and spawned sessions still run in the launch directory, and `docs/SETTINGS.md` documents the rule). Not walked up, because they are not `.sugar-crush/*`: foreign `.claude` and `.opencode` skills and agents, InstructionFileLoader (it already walks) and `WorktreeConfig`. Behaviour change: trust entries must name the repository root.
- **15d-24** A trusted project could choose the title and summary model on the operator's credential — fixed on master in `7ec5a7a58` (`titleModel` and `summaryModel` left `PROJECT_TIER_KEYS`; the LayeredSettings docblocks retract the "cost is bounded by that choice" argument and say why; the SETTINGS.md tier column and the README refusal list name them).
- **15d-03** A repository's native skill silently replaced the user's own skill (and built-ins) of the same name — fixed on master in `9105feb48` (every shadowing reported in `skipped()`), `9e69d6c9e` (wave 8A: precedence built-in < project < user, decided by tier first; foreign-convention shadowing reported) and `32340e1b5` (wave 9: the launch notice's wording, `SKILL_SKIP_NOTICE_FORMAT`, now reads "N skill files were not loaded (unreadable, or shadowed by a same-named skill)", quoted in `docs/SKILLS.md` and pinned by a launch whose only skip is a shadowed skill).
