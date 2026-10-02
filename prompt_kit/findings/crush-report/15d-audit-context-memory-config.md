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

### 15d-06 — `/memory import claude` builds the Claude Code project slug wrongly and imports nothing for any path containing `.` (and probably `_` and spaces)
- **Severity:** Medium-Low · **Confidence:** Verified by repro (`r8.php`), plus on-disk evidence from the real `~/.claude/projects`
- **Where:** `src/Memory/ForeignMemoryImporter.php:302-307`
- **Code:**
  ```php
  return '-' . ltrim(str_replace('/', '-', $path), '-');
  ```
- **Failure scenario:** Claude Code replaces every non-alphanumeric character with `-`. On this machine `/home/sites/webhooks.interserver.net` is stored as `~/.claude/projects/-home-sites-webhooks-interserver-net/memory`, and `/home/sites/phlix/phlix-server/.claude/worktrees/…` as `…-phlix-server--claude-worktrees-…`. sugar-crush looks for `-home-sites-webhooks.interserver.net`, finds nothing, and reports "Nothing imported — no readable `claude` memory files were found". In `r8.php` the fixture's correctly slugged entry was ignored, and a decoy at the dotted slug was imported instead.
- **Fix:** `'-' . ltrim(preg_replace('/[^A-Za-z0-9]/', '-', $path), '-')` (check the leading-dash handling against Claude Code). Also try the old spelling for backward compatibility.
- **Test:** `ForeignMemoryImporterTest::testSlugMatchesClaudeCodeForDottedAndUnderscoredPaths`.

### 15d-07 — Repo-shipped memory is presented as the user's own notes
- **Severity:** Low · **Confidence:** Verified by reading
- **Where:** `src/Context/MemoryBlock.php:300-306`; `docs/MEMORY.md` ("git-visible, reviewable, like AGENTS.md")
- **Detail:** `<repo>/.sugar-crush/memory/project/*.md` comes from the clone, with no trust gate, yet the block header says "These are notes the user or a previous session wrote down". The escape is correct, but the provenance claim is wrong for a hostile or simply foreign checkout, and the model weighs "the user wrote this" differently from "the repository ships this".
- **Fix:** Render repo-store and home-store notes under separate headers ("shipped in this repository" vs "recorded by you"), or require `trustedProjectSettings` before reading the repo store.
- **Test:** Assert that the header wording differs by store.

---

## C. Instruction files and prompt-wide encoding/size

### 15d-09 — CLAUDE.md, AGENTS.md and forced instruction files have no size cap; a 3 MB file goes into every request whole
- **Severity:** Medium · **Confidence:** Verified by repro (`r9.php`: a 3,080,000-byte AGENTS.md gives a 3,085,385-byte system prompt with no notice)
- **Where:** `src/Context/InstructionFileLoader.php:264`, `:455`, `:543` and `ImportResolver.php:131`, all unbounded `file_get_contents`; `src/Runtime.php:3009-3040`. The docs loop never consults `$standingRemaining`, although rules on either side of it are held to `MAX_STANDING_RULE_BYTES` (65,536) and `RuleLoader::MAX_FILE_BYTES` (65,536).
- **Failure scenario:** A generated AGENTS.md, an `@import` of a large changelog, or `instructions: ["docs/*.md"]` costs megabytes of tokens per step. On a 1M window that silently consumes most of the budget, and on smaller windows every request returns 400. There is no warning, and `refusedPaths()` stays empty. Claude Code warns above about 40k characters.

  `CompactorConfig::$skillBudgetPerSkill` and `$skillBudgetCombined` exist, but `ContextCompactor::filterSkills()` and `compactSkills()` have no caller (`grep` shows only the self-call at `:467`), so enabled skill bodies are uncapped too.
- **Fix:** Add a per-document byte ceiling and a combined ceiling for the instruction-document slot. Over budget, emit a pointer in the style of `RulePathNudge::pointer()` ("AGENTS.md is 3.0 MB; read it with Read") and record a refusal. Do the same for enabled skill bodies, using the existing `skillBudget*` values.
- **Test:** `BaseSystemPromptTest::testAnOversizedInstructionDocumentIsDeferredNotInlined`.

### 15d-25 — MEMORY.md and PROMPT_ENGINEERING.md do not describe PromptFence's chat-template-token defang
- **Severity:** Low (docs) · **Confidence:** Verified-by-reading (found while fixing 15d-10)
- **Where:** `docs/MEMORY.md:199` (the fence "rewrites the `<` of a recognised open/close tag to `&lt;`, touches nothing else"); `docs/PROMPT_ENGINEERING.md:103` (§"Fence and provenance rules").
- **Detail:** since 15d-10's fix (`161d60881`), `PromptFence::escape()` also rewrites the `<` of chat-template control-token openers, `<|` and `<｜` (fullwidth U+FF5C) with an optional `/`, so `<|im_start|>` and `<｜User｜>` reach the prompt as `&lt;|im_start|>` and `&lt;｜User｜>`. It also matches roster tags that carry attributes. MEMORY.md's "touches nothing else" is now false, and PROMPT_ENGINEERING.md's fence rules list only the roster tags. A user who sees `&lt;|` in a memory note's prompt rendering has no documentation explaining why.
- **Fix:** add the control-token defang (and the attribute-bearing tag match) to both passages.
- **Test:** a doc-drift assertion that both pages name the `<|` / `<｜` defang, alongside `PromptFence::CONTROL_TOKEN_PIPES`.

### 15d-11 — MEMORY.md says `@~/…` imports resolve against home; in practice every one is refused
- **Severity:** Low · **Confidence:** Verified by reading
- **Where:** `src/Context/ImportResolver.php:104-105` resolves `~/` via `getenv('HOME')`, but every caller passes the containment gate `InstructionFileLoader.php:848` (`ContainedPath::within($realPath, $boundary)` against the repo root). `docs/MEMORY.md:231`: "`~/...` resolves against the home directory".
- **Detail:** `@~/my-conventions.md` in a project CLAUDE.md always renders `<import-blocked reason="outside-repo-root">`. The documented feature cannot be reached unless the repo *is* `$HOME`. Separately, `getenv('HOME')` is used instead of `HomeDirectory::owned()`, unlike every other home read.
- **Fix:** Either document that `~/` imports are always blocked, or allow imports under `HomeDirectory::owned()` for user-tier files only.
- **Test:** A doc-drift test that renders `@~/x.md` and asserts the documented outcome.

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

### 15d-15 — `writeUserConfig()` replaces a symlinked `config.json` with a regular file; later edits to the real file (permission rules) silently stop applying
- **Severity:** Medium-Low · **Confidence:** Verified by repro (`cfg7/`)
- **Where:** `src/Cli/Bootstrap.php:3608-3680` (`tempnam` in `dirname(userConfigPath())`, then `rename($temp, userConfigPath())`)
- **Failure scenario:** A dotfiles setup has `~/.sugar-crush/config.json -> ~/dotfiles/sugar-crush/config.json`. The first `/theme` or Ctrl+P provider switch replaces the link with a 0600 regular file. The dotfiles copy is now stale. The user later tightens `permissionMode` or `permissionRules` in the dotfiles repo (or syncs from another machine), and the live policy no longer changes. There is no warning. `requirePrivatePolicyFile()` follows symlinks, so symlinked configs are otherwise fully supported.

  Related, Low: the read-merge-write has no lock, so two concurrent sessions persisting different keys lose one update. And a `config.json` that became invalid mid-session (an editor save) is read as `[]` by `rawUserConfig()` and overwritten with only the patch.
- **Fix:**
  - Resolve the target with `realpath()` (when it is a link whose target is home-owned and not world-writable) and write the temp file beside the target.
  - Refuse to persist when `json_decode` of the existing file fails, rather than treating it as `{}`.
  - Wrap the read-merge-write in `TimedFileLock`.
- **Test:** `BootstrapConfigPathOverrideTest::testWriteUserConfigPreservesASymlinkedConfig` and `::testWriteUserConfigRefusesToOverwriteAnUnparsableConfig`.

### 15d-16 — Huge numeric values for `maxToolSteps` and `maxOutputTokens` wrap negative through `(int)` casts
- **Severity:** Low · **Confidence:** Verified by repro (PHP 8.3.6: `(int) 9.3e18 === -9146744073709551616`)
- **Where:** `src/Cli/Bootstrap.php:2945-2958`; `src/Backend/EngineBackend.php:1251-1269`
- **Detail:** `$raw >= 1 ? (int) $raw : null` accepts `9.3e18` and returns a negative int. `maxOutputTokens` then sends a negative `max_tokens` (provider 400 on every request), and `maxToolSteps` becomes negative. These are user-tier only, so the risk is an odd but plausible "no limit" value such as `1e19`.
- **Fix:** Clamp to a documented ceiling (for example `min($raw, 1_000_000)`) before the cast, and refuse non-integral floats with a notice.
- **Test:** A data provider covering `1e19`, `9.3e18` and `1.5`.

---

## F. Lower-priority observations (Verified by reading; nondeterminism and caps)

Its only entry, 15d-17, was fixed in wave 3; see **Fixed since audit**.

---

## G. Findings from the resumed pass

### 15d-19 — Three doc statements are stale and unpinned: rule `paths:` scoping "not applied" (it is), and "only two keys" re-applied per turn (three are)
- **Severity:** Low · **Confidence:** Verified by reading
- **Where and what:**
  - `docs/PROMPT_ENGINEERING.md:229-233` ("Triggers are built but not applied … a path-scoped rule renders into every session") and `:250` ("Triggers. Built per rule, consulted by nobody at assembly").
  - `docs/SKILLS.md:155-160` ("`rules paths:` scoping is not applied at the splice … Path-conditional splicing is a deferred step (P6.S5b)").

    P6.S5b landed. `Runtime.php:2911-2924` says "the paths half is gated", the splice skips `RulePathNudge::isPathScoped()` rules, and `Bootstrap.php:6863` wires `RulePathNudge` into Read/Edit/Write/Glob/Grep. Only `keywords:` and `description:` remain unapplied: `KeywordTrigger` and `IntentTrigger` have no consumer in `src/`, and the `Rule::$models` field is parsed and never read (covered by known #37).
  - `docs/SETTINGS.md:600-603` ("only two keys actually change behaviour mid-session … `parallelToolCalls` and `parallelToolDeadlineSeconds`"). `EngineBackend::runTurn()` (`src/Backend/EngineBackend.php:782-789`) also re-reads `maxOutputTokens` from the per-turn config, so a mid-session edit to it takes effect on the next turn.
- **Why it matters:** These pages call themselves drift-guarded, but none of the three sentences is pinned (`grep -rn "only two keys\|not applied at the splice\|Triggers are built" tests/` returns nothing). A reader of SKILLS.md would expect a `paths:` rule to appear in every prompt and would not look for it in tool output.
- **Fix:** Correct the three passages. Add each "N keys re-applied per turn" figure to `DocFigureProseDriftTest`, derived from the keys `runTurn()` reads.
- **Test:** The drift assertion above.

### 15d-20 — A `paths:`-scoped rule added or edited mid-session is never delivered: the splice skips it and the boot-time nudge never learns of it
- **Severity:** Medium-Low · **Confidence:** Verified by repro (`r16.php` + `proj16/`)
- **Where:** `src/Cli/Bootstrap.php:6863` (`RulePathNudge::new((new RuleLoader($root))->load(), $rulesState)`, walked **once at boot**); `src/Runtime.php:2950-2970` (the splice re-walks `RuleLoader::load()` on **every build** and skips every `RulePathNudge::isPathScoped()` rule)
- **Code:**
  ```php
  // Bootstrap (boot):  $ruleNudge = RulePathNudge::new((new RuleLoader($root))->load(), $rulesState);
  // Runtime (every build):
  if (RulePathNudge::isPathScoped($rule)) { continue; }   // "deliver them at tool time … instead"
  ```
- **Failure scenario:** The user says "add a project rule: every PHP file under src/ uses strict_types". The agent writes `.sugar-crush/rules/php.md` with `paths: ["src/**/*.php"]` (the agent can write that directory, known item #9). The next build's splice loads the rule, sees a `PathTrigger`, and skips it as "delivered at tool time". The nudge was built from the boot-time walk and has no candidate for it, so `forPath('src/A/B.php')` returns `null` (`r16.php`). A fresh nudge over the same rules returns the reminder. The rule is in neither channel until restart, which is exactly the "silently-vanishing defect" the Runtime comment at `:2918-2921` says the shared predicate prevents.

  The same split causes two more failures:
  - **Stale body:** an existing path-scoped rule whose body is edited mid-session keeps announcing the boot-time text.
  - **Double presentation:** a rule whose `paths:` is removed mid-session is spliced into the prompt and also still nudged if it had not yet been announced.

  `/rules` toggles are honoured live (via `RulesState`), so only the rule *content* is frozen.
- **Fix:** Have `RulePathNudge` take a loader callable (or a cheap mtime/size fingerprint of the rule directories) and rebuild its candidate list when the fingerprint changes. Alternatively, have `Runtime` hand the per-build rule list to the nudge (`$ruleNudge->withRules($rules)`), so both channels always see the same walk. Keep the `announced` ledger keyed by path across rebuilds.
- **Test:** `RulePathNudgeTest::testARuleAddedAfterBootIsAnnouncedOnItsFirstMatchingTouch` and `::testAnEditedRuleAnnouncesItsCurrentBody`.

### 15d-22 — A checkout path containing `[`, `*` or `?` silently drops forced instructions and every repository memory note
- **Severity:** Low-Medium · **Confidence:** Verified by repro (`r18.php` + `br[1]/`)
- **Where:** `src/Context/InstructionFileLoader.php:508-509` (`glob($this->repoRoot . '/' . $pattern)`); `src/Memory/MemoryStore.php:118`, `:163`, `:198`, `:231`, `:259`, `:284` (`glob($this->memoryPath . …)` / `glob($dir . '/*.md')`)
- **Failure scenario:** The repository lives at `…/br[1]/` (bracketed names are common for client folders, for example `~/work/[acme]/site`, and for copies such as `proj[1]`). PHP `glob()` treats `[1]` as a character class, so:
  - **Forced instructions:** `instructions: ["docs/*.md"]` loads 0 files, and `refusedPaths()` stays empty.
  - **Repo memory:** `ProjectMemoryWriter::createForRoot()->write('repo note')` succeeds and the file is on disk, but `list('project')` returns 0 entries. `/memory add --scope project` reports success, then the note never reaches `<project-memory>`, `/memory list` never shows it, and `/memory delete <id>` / `edit` say "not found" because `get()` globs too.

  A class such as `[acme]` can also match a *different* sibling directory (`…/a/site`). Containment then refuses the forced-instruction matches, but the memory store would read and write under the wrong tree. No warning is emitted on any of these paths. The same applies to the home store when `$HOME` contains a metacharacter (rare).
- **Fix:** Never pass a filesystem path through `glob()` unescaped. Escape the fixed prefix (`addcslashes($prefix, '\\*?[')`) and glob only the user-supplied pattern part, or replace the fixed-directory listings in `MemoryStore` with `scandir()`/`FilesystemIterator` plus a suffix check. For `get()/update()/delete()`, build the path directly as `scopeDirectory($s) . '/' . $id . '.md'` for each scope instead of globbing.
- **Test:** `MemoryStoreTest::testARootContainingGlobMetacharactersListsItsNotes` and `InstructionFileLoaderTest::testForcedInstructionsLoadUnderABracketedRoot` (temp dir named `x[1]`).

### 15d-23 — Repository memory notes can become unaddressable: the frontmatter `id` is displayed, the filename is what `/memory edit|delete` looks up; every write also rewrites a git-tracked `MEMORY.md` with a timestamp
- **Severity:** Low · **Confidence:** Verified by repro (`r13.php` + `mem13/`)
- **Where:** `src/Memory/MemoryStore.php:188-273` (`get/update/delete` glob `*/<id>.md` and require `^[0-9a-f]{32}$`); `:506-535` (`parseEntry()` takes `id` from the frontmatter); `src/Chat.php:12894-12906` (the listing prints `$entry->id()`); `MemoryStore::generateIndex()` `:308-340`
- **Failure scenario:** A note file whose name and frontmatter `id` disagree is listed under an id that no command can reach. Ordinary hand edits produce this. The repo store is documented as "git-visible, reviewable, like AGENTS.md", so teams will edit it.
  - **Duplicated note:** `cp <id>.md deploy-variant.md` and edit the body. `/memory list project` shows two notes with the **same** id. `/memory delete <id>` removes the original. A second delete says "not found", yet the variant still lists, and is injected, under that id. Nothing can edit or delete it.
  - **Readable id:** A hand-written note with `id: deploy-notes` lists as `deploy-notes`, but `/memory delete deploy-notes` answers "not found" (`get()` returns null for a non-hex id; `MemoryStore::delete()` called directly throws "Invalid memory entry id format").

  Separately, every `add/update/delete` calls `generateIndex()`, which rewrites `<repo>/.sugar-crush/memory/project/MEMORY.md` with a fresh `Loaded at: <timestamp>` line (`r13.php` lists it beside the notes). In the git-visible repo store, every note change therefore produces a diff in a second file whose only change is the timestamp, and two branches that each add a note conflict on it.
- **Fix:** Treat the filename stem as the id. `parseEntry()` should take `id` from `basename($file, '.md')` and warn when the frontmatter disagrees. Accept any `[A-Za-z0-9._-]{1,64}` stem in `get/update/delete`. Drop the timestamp from the index, and do not write the index in the repo store at all (it is derivable).
- **Test:** `MemoryStoreTest::testACopiedNoteFileIsListedAndDeletableUnderItsOwnFilename`, `::testAHandAuthoredReadableIdIsDeletable`, and `::testGenerateIndexIsByteStableWhenNotesAreUnchanged`.

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
| 15d-09 | Medium | Repro | No size cap on CLAUDE.md/AGENTS.md/forced/imports (3 MB inlined); skill budgets inert | `Runtime.php:3009-3040` |
| 15d-20 | Med-Low | Repro | A `paths:` rule added or edited mid-session is never delivered (splice skips it; nudge built once at boot); stale bodies, double presentation | `Bootstrap.php:6863`, `Runtime.php:2950-2970` |
| 15d-06 | Med-Low | Repro | Claude memory import slug ignores `.` (and probably `_`/space) → imports nothing | `ForeignMemoryImporter.php:302-307` |
| 15d-13 | Med-Low | Repro | Subdirectory launch: "not a git repo", git state dropped; `.sugar-crush/*` not found. Partly fixed (`119bc86d2`: `rev-parse --show-toplevel`, "repo root:" line, git section); remaining: (b) `.sugar-crush/*` walk-up (deferred decision) | `EnvironmentBlock.php:929-932` |
| 15d-15 | Med-Low | Repro | `writeUserConfig()` breaks symlinked config (stale policy); unlocked read-merge-write; overwrites an unparsable file | `Bootstrap.php:3608-3680` |
| 15d-22 | Low-Med | Repro | Glob metacharacters in the checkout path drop forced instructions and every repo memory note, silently | `InstructionFileLoader.php:509`, `MemoryStore.php:118-284` |
| 15d-23 | Low | Repro | Memory id shown from frontmatter, looked up by filename → unaddressable notes; repo `MEMORY.md` rewritten with a timestamp on every change | `MemoryStore.php:188-340`, `Chat.php:12894` |
| 15d-24 | Low | Reading | Trusted project picks `titleModel`/`summaryModel` on the operator's key; unpriced → $0 → spend cap blind | `LayeredSettings.php:584-592`, `Bootstrap.php:7773-7796` |
| 15d-19 | Low | Reading | Stale, unpinned docs: rule `paths:` "not applied" (it is); "only two keys" re-applied per turn (`maxOutputTokens` too) | `PROMPT_ENGINEERING.md:229-250`, `SKILLS.md:155-160`, `SETTINGS.md:600-603` |
| 15d-07 | Low | Reading | Repo-shipped memory framed as "notes the user wrote" | `MemoryBlock.php:300-306` |
| 15d-11 | Low | Reading | Doc says `@~/` imports resolve; containment always blocks them | `ImportResolver.php:104`, `MEMORY.md:231` |
| 15d-16 | Low | Repro | `(int)` of a huge float wraps negative for `maxToolSteps`/`maxOutputTokens` | `Bootstrap.php:2945`, `EngineBackend.php:1251` |
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
