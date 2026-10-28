# C-EXT-3.c — REVIEW-1 (round 1, fresh reviewer, 2026-10-28)

VERDICT: CLEAN — 0 critical / 0 major / 1 minor (observation) / 2 nit. Lane ready to weld with its disclosed durations duty.

## 1. Phantom-first — PASS
- `git cat-file -t 7dbe548f6...` = commit; `git log --oneline master..cc/C-EXT-3.c` = exactly 1 ("C-EXT-3.c: Bundled skill-creator + /skills new scaffold").
- `%ae` and `%ce` grepped separately: both detain@interserver.net. Body trailer grep: 0 Co-Authored/Co-developed.
- Worktree porcelain: empty (clean).

## 2. Declared set — PASS (one disclosed deviation, verified honest)
- All 9 files in the diff (README, docs/COMMANDS.md, docs/SKILLS.md, lang/en.php, src/Host/Commands/SkillsHostCommand.php, src/Skills/BuiltIn/skill-creator/SKILL.md [NEW], src/Skills/SkillRegistry.php, tests/Host/Commands/SkillsNewTest.php [NEW], tests/Skills/BuiltInSkillsTest.php) are in the BRIEF files list.
- The SkillRegistry.php one-word docblock flip (eight→nine, SkillRegistry.php:44) was builder-disclosed as MANDATED in-step; verified: CompiledPatternCacheBoundTest.php:219 derives `$this->word(count($roster), 'built-ins') . ' shipped built-ins'` from the LIVE BuiltIn roster and asserts it against that constant's docblock — leaving 'eight' would have reddened the guard. Honest deviation, not scope creep.

## 3. Semantics — PASS
- 9th built-in skill-creator (SKILL.md:1-6 frontmatter): `allowed-tools: "Read, Grep"` — as-written per R-EXT-1 (§6.1 table), no Write pre-approval (promotion is the typed `/skills accept`, SkillHostCommand docblock "THE ONLY PROMOTION PATH" preserved). It is a wired prompt-context skill (user-invocable, model-invocable), not inert — consistent with its keys.
- SKILL.md factual claims re-verified against source: name slug ≤64 (ProposedSkills.php:55 MAX_NAME_LENGTH=64, sanitizeName :101-107), description ≤300 chars (:58), body ≤16384 bytes (:61), paths ≤8 globs ≤300 bytes (SkillPathNudge MAX_ENTRIES=8 :163, MAX_ENTRY_BYTES=300 :115), model/effort latest-declaration-wins (ActiveSkillEffects most-recent-first :52), requires bins/anyBins/env/os (SkillRegistry :247-280), `context: fork` preset default `coder` (SkillFork DEFAULT_AGENT :45), agent noticed only beside fork (Skill.php:40-42), `${CLAUDE_SKILL_DIR}`/`${SKILL_DIR}` substitution (SkillTool :81-82, :616), unknown-key did-you-mean diagnostics. Accept-only promotion story matches CommandsHost flow. All TRUE.
- `/skills new` arms traced (SkillsHostCommand.php :104-108 + newDraft :336-355): no name → `count>=2` door → usage failure, nothing written; invalid name (sanitises to nothing) → propose throws :129-131 before any mkdir; duplicate draft → file_exists throw :165-167 BEFORE the new dir is created; no owned home → requireRoot throw :315 first thing in propose (:127). Every arm writes nothing; run()'s catch (:127) turns throws into a system failure notice, never a reply. Scaffold lands only in `~/.candy-code/skills-proposed/` (ProposedSkills SUBDIR :46) — project tier never touched; symlinked-root and resolves-into-live-dir refusals (:326/:336) ride the store's own door.
- Tests pin the laws: SkillsNewTest 5T — draft parses back with typed name/description, live tier NOT created (:64-67), sanitisation before writing (`MySkill`→myskill, `../Hidden`→hidden with the raw spelling asserted ABSENT :75-78), dup refuses whole with original body intact (:86-89), refused scaffolds leave no skills-proposed dir at all (:99-108), default description (:112-120).

## 4. Non-vacuity — PASS
- Red-before claim (HEAD command file swapped → SkillsNew 5/5 F) re-reasoned arm-by-arm: reverting removes the `'new'` match arm → every draftedText assertFalse(isRefused) reds (3); dup test loses 'already waiting' (1); refused test's `'new <name> [description]'` usage needle absent from the old usage line (1). 5/5 red stands without executing.
- Mutation-reason: neuter sanitizeName → testTheNameIsSanitisedBeforeWriting reds on both `myskill`/`hidden` needles + the raw-spelling absence; neuter the dup file_exists check → propose overwrites → 'First seed note.'/'Original body.' asserts red; neuter the count>=2 door → '/skills new' bare form would attempt a draft → assertDirectoryDoesNotExist reds. Every pin load-bearing.

## 5. Gate reproduction (plain pipe, env -u LINES -u COLUMNS, cwd candy-code, PtyRunToolTest excluded) — PASS
- Builder's filter `SkillsNew|BuiltInSkills|SkillsHostCommand|SkillsEnableDisable`: **OK, 161T/795A, 2 deprecations (pre-existing)** — exact match to report.
- Guard set: **OK, 231T/46392A** (builder wrote 46381A; +11 assertion wobble from a tree-scan census, all green, not a finding).
- Census set `ReadPathCensus|FrontmatterKeyAudit|SkillFrontmatter|SlashDispatch|TreeWideGuardRoster`: **OK, 109T/4717A** — exact.
- Duty's filter spelling `SkillsNew|BuiltIn|...` broadened to 1403T/138940A in my run — still fully green; the duty numeral was written against the substring classes, builder's exact filter is the authority and reproduces.
- ReadmeSuiteFigureDrift staleness pair NOT run/attributed (foreign weld-era re-pin, per duty). `php tools/gen-command-docs.php --check` was permission-map-denied to my shell, but CommandDocsDriftTest is inside the green 231T guard set and re-derives the same COMMANDS.md table — generated row verified drift-free by the guard.

## 6. In-step guard law — PASS
- BuiltInSkillsTest flips all eight→nine in the same commit (provider row, count(8)→count(9) at :488/:622, method renames, docblocks) — a new BuiltIn dir without the flip would red the scan counts.
- COMMANDS.md: table row regenerated shape (escaped hint), narrative paragraph extended with the `new` verb — covered by the green CommandDocsDrift.
- SKILLS.md nine + row (:32), README Ships 9 (:2437) — nine-word flips present; CompiledPatternCacheBoundTest dynamic pin green.
- DocFigure/EnvRoster/ConfigWriteProducer/ReadmeRoster/LangParity/GlobDialect/DuplicatedTestHelperDrift all in the green 231T set; new literals in SkillsNewTest are neither glob-shaped (no wildcards) nor `**Refused:**`-shaped.

## 7. Project laws — PASS
- E646: zero timers/timeout knobs in the diff. Zero new proc_open (diff grep: 0). One type per file (SCAFFOLD_BODY is a class const, not a type).
- lang: en-only — correct today: candy-code/lang/ holds only en.php; the 11-locale strict-parity fleet lands LAST at C-TUI-6.h (§6.3 ruling honored).

## 8. Durations duty — CONFIRMED
- New candy-code phpunit file: exactly ONE — tests/Host/Commands/SkillsNewTest.php (5T). Welder owes ONE `scripts/parallel-tests-durations.tsv` row. No lib/vitest files in the diff.

## Findings (non-blocking)
- MINOR (observation, step-local, take-or-leave): no-home refusal arm (`/skills new` on an unowned/absent home) is traced code-correct (requireRoot throws before any write) but not pinned from the command surface in SkillsNewTest — ProposedSkillsTest already pins the door itself at the store level. No cure required for weld.
- NIT: builder REPORT quotes guard set "46381A" vs my tree's 46392A (+11 tree-scan wobble); both green.
- NIT: CompiledPatternCacheBoundTest docblock :134 prose still reads "the shipped built-ins" (wordless) — unaffected by the count flip; no action.
