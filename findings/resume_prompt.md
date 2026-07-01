# Findings Resume Prompt

## Purpose
Resume the SugarCraft audit-fixing workflow from where it left off. This document is the single source of truth for starting or resuming work.

## Current Status
- **Plan file:** `findings/findings_resume_plan.md` (authoritative, up-to-date)
- **Phase:** Phase 0 complete for most items; Phase 1 in progress
- **Completed:** ~25+ libs have had Phase 0/1 fixes merged to `master`
- **Remaining:** ~430+ items across Phases 0-6, organized in groups of 3

## What Was Done
The first wave of audit work landed ~25+ commits to `master` covering Phase 0 critical security fixes and Phase 1 high-priority functional fixes for: candy-mosaic, sugar-table, sugar-toast, candy-shine, candy-fuzzy, candy-hermit, candy-focus, sugar-prompt, sugar-stash, sugar-post, candy-freeze, candy-testing, candy-log, candy-sprinkles, candy-buffer, candy-mouse, candy-input, candy-async, sugar-charts, sugar-veil, honey-bounce.

**The plan files themselves were NOT marked ✅ — the fixes landed but bookkeeping was not done.**

## What Remains
Everything in `findings/findings_resume_plan.md` — Phases 0 (remaining), 1 (remaining), 2, 3, 4, 5, 6.

## Plan File Location
```
findings/findings_resume_plan.md
```

## How to Resume

### Step 1: Read the plan file
Read `findings/findings_resume_plan.md` in full before starting. It contains:
- The SAFETY CONTRACT (mandatory per-agent rules)
- Concurrency model: **MAX 3 simultaneous agents**
- All phases 0–6 grouped into sets of 3 items each
- File paths, line numbers, and exact fixes for every item

### Step 2: Check current state
```bash
cd /home/sites/sugarcraft
git status && git branch -a && git log --oneline -5
```

### Step 3: Mark already-done items
Before spawning new agents, mark the plan file items that were already completed. From the completed commits, these plan files have items done:
- `findings/plan_candy-mosaic.md` — ✅ marked (resource leaks, SSRF hardening)
- `findings/plan_candy-shine.md` — ✅ marked (lazy-init blockStack/styleSheet)
- `findings/plan_sugar-stash.md` — ✅ marked (Phase 1 infrastructure)

All other plan files need their items marked based on the ~25 merged commits. Mark them now before starting new groups.

### Step 4: Start next group
Begin spawning agents in groups of 3, starting with the next incomplete group in Phase 0.

---

## Phase Breakdown (all groups)

### Phase 0: Critical Security Fixes
| Group | Items | Libs | Agent Count |
|-------|-------|------|-------------|
| 0-A | 0.1–0.3 | candy-files (trash path, path traversal), candy-serve (git ref shell injection) | 1 coder |
| 0-B | 0.4–0.5 | candy-serve (SSHServer path traversal, LFSHandler bearer, empty password, git init, array iteration, temp file leak) | 1 coder |
| 0-C | 0.6–0.9 | candy-pty (FD reuse race, encoding artifact), candy-vcr (undefined cassette, shared tileCache), candy-wish (async blocking, promise rejection loss) | 1 coder |
| 0-D | 0.10–0.12 | candy-shell (shell injection env-var), sugar-reel (SIGCONT), honey-flap (score wrong x) | 1 coder |
| 0-E | 0.13–0.15 | sugar-prompt (undefined $status), sugar-toast (unclamped maxWidth), candy-metrics (hard-coupled wish, missing flush(), re-emit all) | 1 coder |
| 0-F | 0.16–0.18 | candy-query (SQLite str_replace), candy-mouse (OSC bounds), candy-flip (fixed interval), candy-async (markCancelled visibility) | 1 coder |
| 0-G | 0.19–0.20 | candy-log (Phive directory, PSR-3 context array type) | 1 coder |

### Phase 1: High Priority Functional Fixes
| Group | Items | Libs |
|-------|-------|------|
| 1-A | 1.1–1.3 | candy-files (concurrency limiting, trash shutdown, syscall optimization) |
| 1-B | 1.4–1.7 | candy-hermit (Highlighter cache, ANSI parser cache, StatusBar/HelpBar returns copy) |
| 1-C | 1.8–1.9 | candy-kit (Section header rune formula, rule min-width) |
| 1-D | 1.10–1.14 | candy-kit (Theme type guard), candy-lister (RuntimeException→Throwable, dead enum case, UTF-8 grapheme, ANSI injection) |
| 1-E | 1.15–1.17 | candy-focus (next/previous DRY), candy-input (Shift+scroll tests, equals docblock) |
| 1-F | 1.18 | candy-palette (DetectionChain creation + refactor — large, give extra time) |
| 1-G | 1.19–1.23 | candy-palette (StandardColors cache), sugar-dash (EventDispatcher mutation, Chart render mutation, SystemModule mutation, array_search false) |
| 1-H | 1.24–1.27 | sugar-stash (removeDir DRY, interactive rebase), candy-vcr (FrameStream mutation, negative dt, double iteration) |
| 1-I | 1.28–1.30 | sugar-reel (rebuildAudio extraction, PNG buffer unbounded, DecoderInterface reopen) |
| 1-J | 1.31–1.33 | honey-bounce (CubicBezier validation, SpringConfig silent clamping) |

### Phase 2: DRY Refactoring and Architecture
| Group | Items | Libs |
|-------|-------|------|
| 2-A | 2.1–2.3 | candy-core (Clamp migration incomplete), repeated_logic (Validation API mismatch), sugar-bits (sanitizeCell extraction) |
| 2-B | 2.4–2.6 | candy-mosaic (DRY helpers), sugar-dash (Chart wither boilerplate), candy-hermit (computeWidth redundant) |
| 2-C | 2.7–2.9 | candy-hermit (withItems filter, applyFilter array_filter), sugar-bits (sortedRows single-pass) |
| 2-D | 2.10–2.12 | sugar-bits (AnimatedProgress spring cache), candy-buffer (DiffOptimiser array_merge, DiffEncoder state reset) |
| 2-E | 2.13–2.15 | candy-buffer (Hyperlink equals), candy-shine (string concat loops, Theme::fromJson issues) |
| 2-F | 2.16–2.18 | candy-freeze (LayoutCalculator extraction, match expression), candy-testing (O(n) array_shift) |
| 2-G | 2.19–2.20 | candy-testing (escapeVhsRune addcslashes, KeyType coverage) |

### Phase 3: Missing Features and API Completeness
| Group | Items | Libs |
|-------|-------|------|
| 3-A | 3.1–3.3 | candy-fuzzy (matchAllGenerator, FuzzyMatcherFactory, MatchResultSorter) |
| 3-B | 3.4–3.6 | candy-focus (enabledCount, disabledCount, IteratorAggregate, JsonSerializable) |
| 3-C | 3.7–3.9 | candy-kit (Theme getters, Banner cache), sugar-table (column accessor, visible column pre-compute) |
| 3-D | 3.10–3.12 | sugar-toast (timer control API, action buttons), candy-lister (navigation methods, setLineOffset fluent, sort O(n) cursor) |
| 3-E | 3.13–3.18 | candy-lister (navigation, fluent, sort), candy-testing (TemporaryDirectoryTrait), candy-input (EscapeDecoderOptions, ReactInputDriver) |
| 3-F | 3.19–3.23 | candy-input (SignalResizeDriver), candy-palette (AsyncProbe), honey-bounce (reducedMotionOverride, Vector methods, EasingFunction interface) |
| 3-G | 3.24–3.30 | candy-serve (Async GitDaemon — deferred ⏭️), sugar-reel (Seeking callback — deferred ⏭️), candy-log (StreamHandler type, HookRegistry return, JsonFormatter coercion, RemoveHandler void, Styles return) |

### Phase 4: Documentation and CALIBER_LEARNINGS
| Group | Items | Libs |
|-------|-------|------|
| 4-A | 4.1–4.3 | CALIBER_LEARNINGS (new-factory-required, mutable trait, AsyncOps ADR) |
| 4-B | 4.4–4.6 | sugar-veil (scanner state docs, RenderSession frame counter), sugar-stash (sync-only docs) |
| 4-C | 4.7–4.9 | candy-mouse (async gap, spatial index, memoization) |
| 4-D | 4.10–4.12 | candy-palette (async gap), sugar-bits (transitional architecture, ReactPHP/async note) |

### Phase 5: Test Coverage Additions
| Group | Items | Libs |
|-------|-------|------|
| 5-A | 5.1–5.3 | sugar-table (frozen/hidden conflict, invalid key tests), candy-flip (per-frame delay test) |
| 5-B | 5.4–5.6 | candy-flip (DISPOSAL_PREVIOUS), candy-input (FocusEvent tests), candy-async (markCancelled test) |
| 5-C | 5.7–5.9 | honey-bounce (CubicBezier invalid control points), sugar-glow (viewport key binding tests), candy-mosaic (ChafaRenderer pipe cleanup) |
| 5-D | 5.10 | candy-mosaic (ChafaRenderer::reset test) |

### Phase 6: Remaining Items by Plan File
See `findings/findings_resume_plan.md` Section "Phase 6" for the full table. These are low-priority items from all remaining plan files — work through after Phase 5 or bundle them into groups of 3.

---

## Safety Contract (paste verbatim into every agent dispatch)

```
SAFETY CONTRACT — VIOLATION = STOP AND REPORT
1. BRANCHES: You may ONLY commit/push to `master`. Do NOT create branches,
   do NOT push to non-master branches, do NOT open PRs. If you need to
   branch, STOP and explain why.
2. STAGING: Do NOT run `git add .` or `git commit` or `git push` until you
   have verified your changes compile and tests pass for that lib. Stage
   and push ONLY when ready — not before.
3. CHAINED COMMANDS: When you are ready to commit, do NOT run separate
   git commands with delays between them. Use a SINGLE chained command
   that stages, commits, and pushes atomically with `&&`:
   `git add -A && git commit -m "message" && git push origin master`.
   No intermediate `git status` or other commands between staging and push.
4. WORKING TREE: Do NOT leave the working tree dirty. If your changes need
   more work before committing, complete the work first, THEN stage+commit
   in one chain. Never `git add .` on partial work.
5. NO OTHER BRANCHES: If you see any branch in `git branch -a` output that
   is not `master` or `remotes/origin/master`, do NOT interact with it.
   Report its existence and continue with master only.
```

---

## Agent Dispatch Template

```
You are executing audit fix items for the SugarCraft monorepo.

Your task: Implement items [LIST ITEM NUMBERS] from `findings/findings_resume_plan.md`.

SAFETY CONTRACT — VIOLATION = STOP AND REPORT
1. BRANCHES: You may ONLY commit/push to `master`. Do NOT create branches,
   do NOT push to non-master branches, do NOT open PRs. If you need to
   branch, STOP and explain why.
2. STAGING: Do NOT run `git add .` or `git commit` or `git push` until you
   have verified your changes compile and tests pass for that lib. Stage
   and push ONLY when ready — not before.
3. CHAINED COMMANDS: When you are ready to commit, do NOT run separate
   git commands with delays between them. Use a SINGLE chained command
   that stages, commits, and pushes atomically with `&&`:
   `git add -A && git commit -m "message" && git push origin master`.
   No intermediate `git status` or other commands between staging and push.
4. WORKING TREE: Do NOT leave the working tree dirty. If your changes need
   more work before committing, complete the work first, THEN stage+commit
   in one chain. Never `git add .` on partial work.
5. NO OTHER BRANCHES: If you see any branch in `git branch -a` output that
   is not `master` or `remotes/origin/master`, do NOT interact with it.
   Report its existence and continue with master only.

Context:
- Working directory: /home/sites/sugarcraft
- Plan file: findings/findings_resume_plan.md
- Tests: cd <lib> && composer install --quiet && vendor/bin/phpunit

For each item assigned to you:
1. Read the relevant plan file: findings/plan_<lib>.md
2. Read the relevant source files to understand context
3. Implement the fix
4. Run tests to verify: cd <lib> && composer install --quiet && vendor/bin/phpunit
5. Once tests pass, stage+commit+push in ONE chained command:
   git add -A && git commit -m "<lib>: <short description>" && git push origin master
6. Report DONE with commit hash and test output

Items for this dispatch:
- [ITEM NUMBERS AND DESCRIPTIONS FROM PLAN FILE]
```

---

## Verification After Each Group

After each group of 3 agents completes:
```bash
cd /home/sites/sugarcraft
git status && git log --oneline -5
git branch -a
# Run tests for all libs modified in this group:
cd <lib1> && composer install --quiet && vendor/bin/phpunit 2>&1 | tail -3 && cd ..
cd <lib2> && composer install --quiet && vendor/bin/phpunit 2>&1 | tail -3 && cd ..
cd <lib3> && composer install --quiet && vendor/bin/phpunit 2>&1 | tail -3 && cd ..
```

## Marking Plan Files

After each group completes, mark the items in the individual plan files:
```bash
# Example for candy-files items 1.1 and 1.2:
# Edit findings/plan_candy-files.md, find "1.1" and "1.2" items and add:
# ✅ COMPLETE — <commit hash>
# or for items that cannot be fixed:
# ⏭️ WONTFIX — <reason>
```

## Key Rules
- **MAX 3 agents simultaneously** — never more
- **master only** — no branches, no PRs
- **Chained commit/push** — `git add -A && git commit -m "msg" && git push origin master`
- **Verify before commit** — tests must pass before staging
- **Mark plan files** — update `findings/plan_*.md` after each group

---

*Last updated: 2026-06-30*
