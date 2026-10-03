# 15d — Audit: prompt/context assembly, memory, skills, configuration

Auditor scope: `src/Context/`, `src/Memory/`, `src/Skills/`, `src/Config/`, the config/trust half of `src/Cli/Bootstrap.php`, `src/Util/PathGlob.php`, `src/Compactor.php`, plus `docs/{SETTINGS,MEMORY,SKILLS,PROMPT_ENGINEERING}.md`.
Baseline: `99-synthesis.md` Part II. Nothing below repeats an item listed there.
Repro scripts are in `/home/sites/crush-research-repos/_audit-scratch/15d/`.

**No finding from this audit remains open.** Fixed findings have been removed from this appendix.

---

## Coverage

### Read end to end
- **Context:** `PromptFence.php`, `MemoryBlock.php`, `InstructionFileLoader.php`, `ImportResolver.php`, `RuleLoader.php`, `Rule.php`, `RulesState.php`, `RulePathNudge.php`, `Triggers/*`, `EnvironmentBlock.php`, `RepoMapBlock.php`, `ProjectMemoryWriter.php`, `CompactorConfig.php`, `Sections/MaximsSection.php`, `ContextWindow.php`, `IdleCompactionPolicy.php`, `Stability.php`, `PromptSection.php`
- **Memory:** `src/Memory/{MemoryStore,ForeignMemoryImporter,MemoryEntry}.php`
- **Skills:** `src/Skills/*` and the frontmatter of all 12 built-in `SKILL.md` files
- **Config:** `src/Config/LayeredSettings.php`, `src/Config/StatusLineCommand.php`
- **Support and util:** `src/Support/Frontmatter.php`, `src/Util/PathGlob.php`, `src/Util/Exporter.php`, `src/Util/TokenTracker.php`, `src/Compactor.php`, `src/CompactedGroup.php`, `src/Registry/{Tool,ToolSignature}.php`
- **`src/Cli/Bootstrap.php`:** the config paths, user-config read/merge/write, permission layers, policy file, nudge and tool wiring, tool-set filtering, instruction loader and title/summary backend ranges
- **Docs:** `docs/SETTINGS.md`, `docs/ENVIRONMENT.md` (env tables compared against `src/`), `docs/SKILLS.md`, most of `docs/MEMORY.md` and `docs/PROMPT_ENGINEERING.md`
- **Root `.sugar-crush/`:** `config.json`, `config.dev.json`, `agents/{coder,reviewer,security-auditor}.md`

### Skimmed, or deliberately left to other reports
- **`src/Context/ContextCompactor.php`:** the rest is the compaction-quality surface already covered by known items #15-#19.
- **`src/Chat.php` and `Bootstrap::chat()/app()`:** wiring ranges only.

### Leads dropped
- **`filterToolSet()` non-string entries:** `toolSetUnder()` checks `is_string($pattern)` before matching, for both `allowedTools` and `disabledTools`.
- **`forcedInstructions()`:** non-list values and non-string or blank entries are filtered, the key is user-tier only, and every match is containment-checked.
- **`LayeredSettings::merge()` null-vs-absent:** only layer 4 (`config.json`) passes through unfiltered, and a JSON `null` there can come only from the user's own hand edit. The permission keys are guarded by `withoutEmptyPermissionOverrides()`.
- **`boundHeadAssistantForSummary()` `## Skill: ` exemption:** documented by the authors as an accepted residual (`ContextCompactor.php:686-700`).

### Other checks with no finding
- **Root `.sugar-crush/`:** `config.dev.json` is read only from the **package** root, never from a project, so a cloned repo cannot point the provider at its own endpoint (ENVIRONMENT.md's "a project `.sugar-crush/config.dev.json`" means the package; wording only). The monorepo root's `"trustedProjectMcp"` key is inert there, because trust keys are read only from the user's layer-4 file. The agent presets' `model`/`permissionMode`/`isolation` keys are known item #23.
- **`KeywordTrigger`:** keywords with a non-word edge (`.env`, `C++`, `--force`, `@deprecated`) can never fire, and a prompt containing one invalid UTF-8 byte fires no keyword. Both are documented in the class docblock, and the matcher has no consumer today. When it is wired, `Rule::new()` should refuse such keywords loudly (known #37).
- **`RulePathNudge`:** budget, escape and clip logic are correct; deferral pointers are bounded at 1,024 B and escaped.
- **`Exporter::toJson()`:** returns `json_encode()`'s `false` through a `: string` return type on invalid UTF-8 (a TypeError), but its only caller is `ShareSession` (`/share`, a stub per known #35).
