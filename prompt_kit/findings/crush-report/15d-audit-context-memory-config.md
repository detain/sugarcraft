# 15d — Audit: prompt/context assembly, memory, skills, configuration

Auditor scope: `src/Context/`, `src/Memory/`, `src/Skills/`, `src/Config/`, the config/trust half of `src/Cli/Bootstrap.php`, `src/Util/PathGlob.php`, `src/Compactor.php`, plus `docs/{SETTINGS,MEMORY,SKILLS,PROMPT_ENGINEERING}.md`.
Baseline: `99-synthesis.md` Part II. Nothing below repeats an item listed there; where a finding touches a known item, the new part is called out.

Tree state: master @ `05db616f3` (the working tree has unrelated uncommitted `candy-palette` edits). Vendor mode: `sugar-crush linked 20/20 symlinked` (`php scripts/refresh-deps.php --status`).
Repro scripts are in `/home/sites/crush-research-repos/_audit-scratch/15d/` (`rN.php`). Each takes about 1 s with `php rN.php` (`r14.php` is a timed race and takes as many seconds as its argument).

This report is final. What was read, what was only skimmed, and how each open lead was settled is listed under **Coverage** at the end.

---

## A. Skills (loader, registry, listing, nudges)

### 15d-03 — A repository's native skill silently replaces the user's own skill (and built-ins) of the same name; the "native wins" safeguard in SKILLS.md is defeated
- **Severity:** Medium · **Confidence:** Verified by repro (`r6.php` / `r6b.php`)
- **Where:** `src/Skills/SkillLoader.php:721-739` (`loadAllManifests()` merges builtin → user → **project**, so the last one wins); `src/Skills/SkillManager.php:92-99`; `docs/SKILLS.md:24-44`
- **Code:**
  ```php
  $manifests = array_merge($manifests, $this->loadManifestsFromDirectory($this->userSkillsDir(), self::homeDir()));
  $manifests = array_merge($manifests,
      $this->loadManifestsFromDirectory($this->projectSkillsDir($projectRoot), null, $projectRoot));
  ```
- **Failure scenario:** The user has `~/.sugar-crush/skills/deploy` and `~/.claude/skills/db-query`. A cloned repo ships `.sugar-crush/skills/deploy` and `.sugar-crush/skills/db-query`. After `loadAll()`, both names resolve to the repo files (`REPO-SHIPPED skill (…/proj6/.sugar-crush/skills/…)`). Without the project, the user's files load. A repo can also neuter built-ins such as `security-audit`. Nothing is logged: `skipped()` is empty.

  SKILLS.md says "cloning a repository that ships `.claude/skills/db-query` cannot re-point a `db-query` you already had" and that foreign project skills lose to user ones "because a project's foreign skill arrived with somebody's repository". The same reasoning applies to `.sugar-crush/skills`, which also arrives with the repository, yet that tier outranks the user. This is also the opposite of `LayeredSettings`' stated principle ("THE USER'S FILES OUTRANK THE PROJECT'S") and of Claude Code (personal > project).
- **Nothing pins the current order as intended, and the codebase disagrees with itself.** The foreign-tier rule ("a clone may ADD a skill, never re-point one you rely on") is pinned by `SkillManagerTest::testAClonedRepositoryCannotRePointTheUsersOwnImportedSkill` (`tests/Skills/SkillManagerTest.php:366`), but no test covers native project vs native user. The only nearby test, `testLoadAllProjectSkillOverridesRegistrySafely` (`:163`), asserts only that a project skill registers. The dormant `SkillDiscovery::discoverAll()` (`src/Skills/SkillDiscovery.php:132-154`, no caller in `src/`) merges in the opposite order (project, then user, then lib, so the user wins). The order the authors once wrote down is the safe one; the live loader does the reverse.
- **Fix:** Use the order builtin < project < user for native skills, or namespace project skills (`project:deploy`) whenever the name collides. Log every shadowing to `skipped()` or `projectTierRefusals` so `/doctor` can report it.
- **Test:** `SkillManagerTest::testAProjectSkillCannotShadowAUserSkillOfTheSameName`, plus a doc-drift assertion that SKILLS.md's precedence table matches the merge order.
- **Partly fixed on master in `9105feb48`** (part (a), reporting). `SkillLoader::mergeTier()` is now the one later-wins merge both walks use, and each replaced same-key skill is recorded in `skipped()` with a reason naming the winner and both tier badges; `SkillManager::loadAll()` records foreign skills replaced by a native manifest, and Claude skills replaced by opencode ones, the same way. A byte-identical copy is not reported. **Remaining:**
  - (b) the precedence order itself is unchanged (project still beats user); that is a deferred decision (wave plan §3 #6).
  - Shadowing inside one foreign convention (`~/.claude/skills` vs the repository's `.claude/skills`, resolved in `ForeignSkillDiscovery`) is still silent.
  - `Bootstrap::SKILL_SKIP_NOTICE_FORMAT` (`src/Cli/Bootstrap.php:304-305`) still reads "%d skill file%s could not be read and %s skipped", although the count now includes skills that were read fine and lost to a same-named one. The launch notice needs wording that covers both.

### 15d-26 — `CHANGELOG.md` still lists the four moved skills as built-ins
- **Severity:** Low (docs) · **Confidence:** Verified-by-reading (found while fixing 15d-21)
- **Where:** `sugar-crush/CHANGELOG.md:389-395` ("Built-in skill relocation — moved 8 skill directories (… `explore-codebase`, `mcp-authoring`, `worktree-workflow`, `matchups-sync`) into `src/Skills/BuiltIn/<name>/SKILL.md` … extended it to cover all 12").
- **Detail:** 15d-21's fix (`cadba57fd`) moved those four out of `src/Skills/BuiltIn/` into the monorepo's project tier (`.sugar-crush/skills/`), and `docs/SKILLS.md` and `README.md` now say eight built-ins. The changelog has no later entry recording the move, so a reader of the release history still believes all twelve ship with sugar-crush.
- **Fix:** add a changelog entry for the move (four monorepo-only skills to the project tier, the destructive `git clean` step removed), leaving the historical entry as it is.
- **Test:** none; a doc check could assert the changelog mentions the move.

### 15d-27 — `SkillLoader` reads skill files with no size limit
- **Severity:** Low · **Confidence:** Verified-by-reading (found while fixing 15d-09 and C3 in wave 5)
- **Where:** `src/Skills/SkillLoader.php:682` (`loadSkillManifest()`), `:977` (`loadSkillBody()`) and `:1042` (the asset read), each an unbounded `file_get_contents()`.
- **Detail:** since `dd8be915d` (15d-09, C3), enabled skill bodies are held to `CompactorConfig`'s per-skill and combined budgets before they enter the prompt, and instruction documents are stat-checked and read with a bound. The skill budgets cap only what is spliced, not what is read: a repository's multi-megabyte `SKILL.md` is still read whole into memory at launch (the manifest stage reads the whole file to get the frontmatter) and again for its body, then UTF-8-scrubbed and measured before the budget replaces it with a pointer line.
- **Fix:** read through a bounded reader as `InstructionFileLoader::readBounded()` does: stat first, read the frontmatter with a small cap for the manifest stage, and refuse or defer a body over a ceiling with a skip reason in `skipped()`.
- **Test:** a project skill whose `SKILL.md` is 50 MB loads its manifest and is deferred, with peak memory well under the file size.

---

## B. Memory store

### 15d-05 — `project` scope in the home store is global, so "project" notes leak into every other project's system prompt
- **Severity:** Medium · **Confidence:** Verified by repro (`r10.php`)
- **Where:** `src/Context/MemoryBlock.php:213-229` (reads `$store->list(MemoryScope::Project)` from the home store with no project key); `src/Cli/Bootstrap.php:7863-7868` (`memoryStore()` = `~/.sugar-crush/memory`, one directory for all projects); `src/Chat.php:12534-12537` (falls back to the home store)
- **Code:**
  ```php
  foreach ([...($projectStore?->list(MemoryScope::Project) ?? []), ...$store->list(MemoryScope::Project)] as $entry) {
  // Chat: ProjectMemoryWriter::createForRoot($root)?->write($content) ?? $this->memoryStore->add($content, $scope)
  ```
- **Failure scenario:** `/memory add --scope project "projA: deploy with kubectl apply -f prod/…"` runs while the repo cannot host `.sugar-crush/memory` (read-only checkout, `.sugar-crush` symlinked out, or root `''`). It also applies to any note written before E25. The note lands in `~/.sugar-crush/memory/project/`, and from then on it is rendered into the `<project-memory>` block of **every** repository, under a header that says "Notes recorded for this project". `r10.php` shows a note written "in projA" rendered for an unrelated `projB`. The `/memory add` reply says only "scope: project", not that the note went to the global store.

  Known item #14 covers which scopes are injected. It does not cover the fact that the injected scope is not project-keyed.
- **Fix:** Key home-store project entries by canonical root, either with a `<hash(realpath root)>` sub-directory or a `root:` frontmatter field that `capture()` filters on. Say so in the `/memory add` reply when the fallback happens, and migrate unkeyed legacy entries into a quarantine scope.
- **Test:** `MemoryPromptWiringTest::testAHomeStoreProjectNoteFromAnotherRootDoesNotReachThisPrompt`.

---

## C. Instruction files and prompt-wide encoding/size

### 15d-25 — MEMORY.md and PROMPT_ENGINEERING.md do not describe PromptFence's chat-template-token defang
- **Severity:** Low (docs) · **Confidence:** Verified-by-reading (found while fixing 15d-10)
- **Where:** `docs/MEMORY.md:199` (the fence "rewrites the `<` of a recognised open/close tag to `&lt;`, touches nothing else"); `docs/PROMPT_ENGINEERING.md:103` (§"Fence and provenance rules").
- **Detail:** since 15d-10's fix (`161d60881`), `PromptFence::escape()` also rewrites the `<` of chat-template control-token openers, `<|` and `<｜` (fullwidth U+FF5C) with an optional `/`, so `<|im_start|>` and `<｜User｜>` reach the prompt as `&lt;|im_start|>` and `&lt;｜User｜>`. It also matches roster tags that carry attributes. MEMORY.md's "touches nothing else" is now false, and PROMPT_ENGINEERING.md's fence rules list only the roster tags. A user who sees `&lt;|` in a memory note's prompt rendering has no documentation explaining why.
- **Fix:** add the control-token defang (and the attribute-bearing tag match) to both passages.
- **Test:** a doc-drift assertion that both pages name the `<|` / `<｜` defang, alongside `PromptFence::CONTROL_TOKEN_PIPES`.

---

## D. Environment block (git)

### 15d-13 — Launching from a repository subdirectory reports "Is directory a git repo: No" and drops all git state
- **Severity:** Medium-Low · **Confidence:** Verified by repro (`gitrepo/sub`)
- **Where:** `src/Context/EnvironmentBlock.php:929-932`; root = `getcwd()` (`Bootstrap.php:2374`)
- **Code:**
  ```php
  return file_exists($this->cwd . '/.git');
  ```
- **Failure scenario:** Running `cd repo/src && sugarcrush` renders `Is directory a git repo: No` with no branch, status or diff, although `git rev-parse --show-toplevel` succeeds. `InstructionFileLoader::ancestorRoot()` explicitly supports subdirectory launch, so the two layers contradict each other in the same prompt. Project settings, skills, rules and memory are also looked up at the subdirectory (`<cwd>/.sugar-crush`), so the trusted repo's `.sugar-crush/settings.json` is silently ignored there. (That last part is Verified by reading.)
- **Fix:** Resolve `git rev-parse --show-toplevel` once (with a timeout), use it for `isGitRepo()`, and report "Working directory: …/src (repo root: …)". Decide and document whether `.sugar-crush/*` lookups walk up to the repo root.
- **Test:** `EnvironmentBlockTest::testASubdirectoryOfARepoIsReportedAsInsideTheRepo`.
- **Partly fixed on master in `119bc86d2`** (part (a), git state). When `<cwd>/.git` is absent, the block runs a bounded `git rev-parse --show-toplevel` (memoised per block and copied by the wither) and renders `Working directory: <cwd> (repo root: <root>)` with the full git section; the root is escaped and capped, and a timeout renders `unavailable (…)`. A launch at the repo root sends the same bytes and makes the same number of calls as before. **Remaining:** (b) `.sugar-crush/*` lookups (project settings, skills, rules, memory) still resolve at the subdirectory, not the repo root; whether they walk up is a deferred decision (wave plan §3 #14).

---

## E. Configuration and trust

Both findings here (15d-15, 15d-16) were fixed in wave 6; see **Fixed since audit**.

---

## F. Lower-priority observations (Verified by reading; nondeterminism and caps)

Its only entry, 15d-17, was fixed in wave 3; see **Fixed since audit**.

---

## G. Findings from the resumed pass

### 15d-23 — Repository memory notes can become unaddressable: the frontmatter `id` is displayed, the filename is what `/memory edit|delete` looks up; every write also rewrites a git-tracked `MEMORY.md` with a timestamp
- **Severity:** Low · **Confidence:** Verified by repro (`r13.php` + `mem13/`)
- **Where:** `src/Memory/MemoryStore.php:188-273` (`get/update/delete` glob `*/<id>.md` and require `^[0-9a-f]{32}$`); `:506-535` (`parseEntry()` takes `id` from the frontmatter); `src/Chat.php:12894-12906` (the listing prints `$entry->id()`); `MemoryStore::generateIndex()` `:308-340`
- **Failure scenario:** A note file whose name and frontmatter `id` disagree is listed under an id that no command can reach. Ordinary hand edits produce this. The repo store is documented as "git-visible, reviewable, like AGENTS.md", so teams will edit it.
  - **Duplicated note:** `cp <id>.md deploy-variant.md` and edit the body. `/memory list project` shows two notes with the **same** id. `/memory delete <id>` removes the original. A second delete says "not found", yet the variant still lists, and is injected, under that id. Nothing can edit or delete it.
  - **Readable id:** A hand-written note with `id: deploy-notes` lists as `deploy-notes`, but `/memory delete deploy-notes` answers "not found" (`get()` returns null for a non-hex id; `MemoryStore::delete()` called directly throws "Invalid memory entry id format").

  Separately, every `add/update/delete` calls `generateIndex()`, which rewrites `<repo>/.sugar-crush/memory/project/MEMORY.md` with a fresh `Loaded at: <timestamp>` line (`r13.php` lists it beside the notes). In the git-visible repo store, every note change therefore produces a diff in a second file whose only change is the timestamp, and two branches that each add a note conflict on it.
- **Fix:** Treat the filename stem as the id. `parseEntry()` should take `id` from `basename($file, '.md')` and warn when the frontmatter disagrees. Accept any `[A-Za-z0-9._-]{1,64}` stem in `get/update/delete`. Drop the timestamp from the index, and do not write the index in the repo store at all (it is derivable).
- **Test:** `MemoryStoreTest::testACopiedNoteFileIsListedAndDeletableUnderItsOwnFilename`, `::testAHandAuthoredReadableIdIsDeletable`, and `::testGenerateIndexIsByteStableWhenNotesAreUnchanged`.
- **Partly fixed on master in `18206b703`.** A note's id is its filename stem: the frontmatter `id:` is never read, stems are validated as safe, and a file with a bad stem is reported through `skipped()`. The index carries no timestamp, is byte-stable, and is not rewritten when nothing changed. **Remaining:** the repo store still writes the git-visible `MEMORY.md` index (the fix asked for it not to be written there at all), so two branches that each add a note can still conflict on it.

### 15d-24 — A trusted project can choose the model for every title, prompt suggestion and compaction on the operator's credential; an unpriced choice bills as $0 against the spend cap
- **Severity:** Low (needs `trustedProjectSettings`) · **Confidence:** Verified by reading (key reachability); the $0 accounting is the documented unpriced-model behaviour
- **Where:** `src/Config/LayeredSettings.php:584-592` (`titleModel`, `summaryModel` in `PROJECT_TIER_KEYS`); `src/Cli/Bootstrap.php:7773-7796` (`toollessBackend()` reads `readUserConfig()[$modelConfigKey]`, which is project-merged); `src/Providers/OpenAIProvider.php:56-62`, `:521-535` (price table; unknown model → `null` → $0 lower bound)
- **Failure scenario:** A trusted repository's `.sugar-crush/settings.json` sets `{"titleModel": "o1-pro", "summaryModel": "o1-pro"}` (or any current model missing from the 7-entry price table). Every turn's prompt suggestion (one call on the title backend per turn, `docs/ENVIRONMENT.md:47`), every session title and every `/compact` summary now runs on that model with the operator's key. Each is billed $0.00 as an "under-counted" lower bound, so `SUGARCRUSH_MAX_COST` never trips on them. `LayeredSettings` (`:324-325`) justifies the project tier for these keys with "cost is bounded by that choice [of provider]", but within one OpenAI provider the spread is more than 100×. The same file (`:226`) refuses `modelPrices` to projects precisely because a project "could zero a rate and silently blind the spend cap"; an unpriced model choice reaches the same result.
- **Fix:** Make `titleModel`/`summaryModel` user-tier only (remove them from `PROJECT_TIER_KEYS` and update the SETTINGS.md table, which `TrustKeyDocumentationDriftTest` pins). Alternatively, accept a project value only when the provider has a price for it, and never above the main model's rate.
- **Test:** `LayeredSettingsTest::testAProjectCannotChooseTheTitleOrSummaryModel` (or the priced-and-not-dearer variant).

---

## Summary table (by severity)

| ID | Sev | Conf | Title | Location |
|---|---|---|---|---|
| 15d-03 | Medium | Repro | Project `.sugar-crush/skills` shadows the user's own skills and built-ins silently; contradicts SKILLS.md. Partly fixed (`9105feb48`: every shadowing reported in `skipped()`); remaining: precedence order (deferred), foreign-convention shadowing still silent, skip-notice wording | `SkillLoader.php:721-739` |
| 15d-05 | Medium | Repro | Home-store `project` notes are global → injected into every repo's prompt | `MemoryBlock.php:213-229`, `Chat.php:12534` |
| 15d-13 | Med-Low | Repro | Subdirectory launch: "not a git repo", git state dropped; `.sugar-crush/*` not found. Partly fixed (`119bc86d2`: `rev-parse --show-toplevel`, "repo root:" line, git section); remaining: (b) `.sugar-crush/*` walk-up (deferred decision) | `EnvironmentBlock.php:929-932` |
| 15d-23 | Low | Repro | Memory id shown from frontmatter, looked up by filename → unaddressable notes; repo `MEMORY.md` rewritten with a timestamp on every change. Partly fixed (`18206b703`: id is the filename stem, index byte-stable with no timestamp and skipped when unchanged); remaining: the repo store still writes the git-visible `MEMORY.md` index, so two branches that each add a note can conflict on it | `MemoryStore.php:188-340`, `Chat.php:12894` |
| 15d-24 | Low | Reading | Trusted project picks `titleModel`/`summaryModel` on the operator's key; unpriced → $0 → spend cap blind | `LayeredSettings.php:584-592`, `Bootstrap.php:7773-7796` |
| 15d-27 | Low | Reading | `SkillLoader` reads `SKILL.md` and asset files uncapped (the 15d-09 budgets cap only what enters the prompt) | `SkillLoader.php:682, 977, 1042` |
| 15d-25 | Low (docs) | Reading | MEMORY.md ("touches nothing else") and PROMPT_ENGINEERING.md don't describe the `<\|` / `<｜` control-token defang | `MEMORY.md:199`, `PROMPT_ENGINEERING.md:103` |
| 15d-26 | Low (docs) | Reading | `CHANGELOG.md` still lists the four moved skills as built-ins | `CHANGELOG.md:389-395` |

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
- **15d-02** Repo skill descriptions reached the system prompt unfenced, uncapped, multi-line and in harness voice — fixed on master in `a2d3dfcf3` (each line escaped, one-line, capped; enabled bodies escaped) and `da2930fbd` (the listing is fenced as `<available-skills>` under `Runtime::SKILL_LISTING_AUTHORITY_PREAMBLE`, the ninth `PromptFence` tag, and each line carries a provenance badge `[built-in]`/`[user]`/`[project]` (+ `foreign: claude|opencode`) from the new `Skills\SkillOrigin`; untiered skills default to project), with the memory follow-up `0171ed120` (unreadable memory notes shown to the user: `MemoryStore::unreadable()`, `Memory\UnreadableNotes`, `Bootstrap::reportMemorySkips()` launch notice, and an "Unreadable notes" section in `/memory list`/`/memory search`). `SkillPathNudge` lines are deliberately not badged. Residual: the `-p` path (`src/Cli/NonInteractive.php`) does not call `reportMemorySkips()`, so non-interactive runs raise no unreadable-memory launch notice (one line beside its `reportSkillSkips()` call would add it).
- **15d-10** PromptFence let attribute-bearing roster tags through and did nothing about chat-template control tokens — fixed on master in `161d60881` (the roster pattern is a lookahead on the `<` alone, so attribute lists, split lists and unterminated openers are matched; the same rewrite defangs `<|` and `<｜` openers, listed in `PromptFence::CONTROL_TOKEN_PIPES`). The special-token half was never confirmed live (the tokenizer probe stays unrun). Residual: the docs do not describe the defang (15d-25).
- **15d-21** The built-in skills shipped SugarCraft-monorepo procedures to every project — fixed on master in `cadba57fd` + `d70017a2f` (the four monorepo skills moved to `<repo root>/.sugar-crush/skills/`, the project tier, so they load only in this monorepo; `worktree-workflow`'s dirty-tree step now stops and reports instead of discarding; `docs/SKILLS.md` and `README.md` list eight built-ins; `BuiltInSkillsTest` scans both trees for discard-work commands, walking directories instead of globbing). Residual: `CHANGELOG.md` still lists them as built-ins (15d-26).
- **15d-18** `skillKeyFor()` broke on a trailing slash or a foreign path spelling — fixed on master in `0f971f954` (the base is `rtrim`med, a leftover leading `/` is trimmed, and a path not spelled under the base is keyed through both realpaths, then by the skill's own name).
- **15d-17** Count caps were applied in readdir order, and the repo-map walk was unbounded for non-PHP files — fixed on master in `7e2e08492` (`RuleLoader` and `RepoMapBlock` walk each directory with `scandir()` + `sort()` and cap after sorting; a visit budget counts every entry, `MAX_WALK_ENTRIES = 4096` for rules and 100,000 for the repo map, and a cut is recorded).
- **15d-14** The git subprocesses in prompt assembly had no time bound — fixed on master in `6e2df9a26` (`runCaptured()` gains an optional 4th `?float $timeoutSeconds`, default null so Bash and Grep are unchanged; on expiry the group gets SIGTERM then SIGKILL, the result carries `timedOut` and exit 124; the env block bounds each git read at 2 s, renders `unavailable (git timed out after 2s)`, and one timeout skips the rest of the git section; the branch read is bounded through `runCaptured()` too, keeping the `shell_exec` fallback).
- **15d-12** Env-block git calls honoured the user's git config and took `index.lock` — fixed on master in `da6674686` (every call runs as `git --no-pager --no-optional-locks -c color.ui=false -c core.quotepath=false`; `log` adds `--no-color --no-show-signature`; diffs use the plumbing `diff-index --cached` and `diff-files` with `--no-ext-diff --no-textconv --no-color`, so the index is never written; an unborn HEAD diffs against the empty tree; `GIT_OPTIONAL_LOCKS=0` is set through a new optional 5th `runCaptured()` parameter, `array $envOverrides`; `PromptStabilityTest`'s colour control is rewritten so its fixture must yield zero escapes). Residual: a stat-dirty binary file still counts in the shortstat, and with no stat refresh a heavily stat-dirty tree renders slower (about 0.5 s per 3,000 files) until the user's own git refreshes the index.
- **15d-20** A `paths:`-scoped rule added or edited mid-session was never delivered: the splice skipped it and the boot-time nudge never learned of it — fixed on master in `d1157349b` (`RulePathNudge::fromLoader()` re-walks the rules on each consult; the announced ledger holds a digest of each rule's body, so a rule added mid-session is delivered, an edited rule is re-announced with its current body, and a rule whose `paths:` is removed is no longer nudged; Bootstrap changes only the construction line).
- **15d-09** CLAUDE.md, AGENTS.md and forced instruction files had no size cap (a 3 MB file went into every request whole), and enabled skill bodies were uncapped — fixed on master in `dd8be915d` (with 15a C3: instruction documents are priced in framed, escaped bytes against 64 KiB per document and 128 KiB combined; a document or `@import` that does not fit becomes a pointer line and is recorded in `InstructionFileLoader::refusedPaths()`; every document read is stat-checked and bounded at 60 KiB; enabled skill bodies are held to `CompactorConfig`'s per-skill and combined budgets via `TokenEstimate`; a prompt under budget is byte-identical; `ContextCompactor::filterSkills()` is left dormant). Residual: there is no user-visible notice for a deferred instruction file yet, because nothing drains `refusedPaths()`; skill deferrals appear only in the prompt and are recorded nowhere; skill budgets use the `CompactorConfig` defaults, because App carries no compactor config; `SkillLoader` still reads skill files uncapped (15d-27).
- **15d-19** Stale, unpinned doc statements: rule `paths:` scoping "not applied", and "only two keys" re-applied per turn — fixed on master in `232013284` (`docs/PROMPT_ENGINEERING.md`, `docs/SKILLS.md` and `docs/SETTINGS.md` corrected; SETTINGS.md now names three re-applied keys, adding `maxOutputTokens`; a fourth stale claim, the `<system-reminder>` emitter list, is fixed too; each is pinned by a derived assertion in `DocFigureProseDriftTest`).
- **15d-15** `writeUserConfig()` replaced a symlinked `config.json` with a regular file, ran an unlocked read-merge-write and overwrote an unparsable config — fixed on master in `66f2f760a` (it writes through a symlinked config to its regular, policy-checked target, and writes nothing for a dangling or unsafe link; it refuses to persist over an unparsable or non-object config through a new `userConfigForMerge()`, where a missing or empty file counts as `{}`; and the read-merge-write runs under a 5 s `TimedFileLock` on a `.<name>.lock` sidecar, skipping the write on timeout).
- **15d-16** Huge numeric values for `maxToolSteps` and `maxOutputTokens` wrapped negative through `(int)` casts — fixed on master in `987caa2cf` (values at or above 2**63 resolve to null, the default, instead of wrapping negative; `maxToolSteps` refuses non-integral values such as 1.5, while 8.0 is accepted; `maxOutputTokens` keeps its documented truncation; `docs/SETTINGS.md` updated). Residual: there is no user notice for a nonsense value (neither key raises one for any bad value, and the per-turn `EngineBackend` read cannot show one); no ceiling constant was added.
- **15d-22** A checkout path containing `[`, `*` or `?` silently dropped forced instructions and every repository memory note — fixed on master in `98ec1dd66` (the checkout root is glob-escaped in `InstructionFileLoader::loadForced()`, and every `MemoryStore` glob is replaced by one sorted `scandir()` helper).
- **15d-06** `/memory import claude` built the Claude Code project slug wrongly and imported nothing for any path containing `.` — fixed on master in `f26a4733b` (`claudeProjectSlug()` reproduces Claude Code's algorithm, turning every non-alphanumeric character into `-` and cutting a slug over 200 characters with a hash suffix, and falls back to the legacy `/`-only spelling; the importer lists with `scandir()`). Residual: `CLAUDE_CODE_PROJECT_DIR_NAME` and `CLAUDE_CONFIG_DIR` are not consulted.
- **15d-07** Repo-shipped memory was presented as the user's own notes — fixed on master in `6a609f594` (`MemoryBlock` lists repo-store and home-store notes under separate provenance labels inside one `<project-memory>` fence; the cap, byte budget and omission count stay one newest-first walk; a home-only block is byte-identical to before; `docs/MEMORY.md` updated).
- **15d-11** MEMORY.md said `@~/…` imports resolve against home, while in practice every one was refused — fixed on master in `a4dc54c9d` (`ImportResolver` resolves `~` through `HomeDirectory::owned()`, so there is no more `/x.md` when `HOME` is unset and no world-writable home; with no owned home the reference is left as written; `docs/MEMORY.md` now says a `~/` import is blocked as `outside-repo-root` unless the home directory is inside the checkout).
