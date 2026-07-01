---
status: not-started
phase: 1
updated: 2026-06-30
---

# Implementation Plan: candy-mold

## Goal

Fix all actionable findings in `candy-mold` (a SugarCraft skeleton template) and add missing documentation/examples to make it a best-in-class bootstrap template.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Apply `strtolower()` to rune comparisons | Upstream Bubble Tea quit is rune-insensitive; skeleton should model correct behavior | `findings/candy-mold.md` Finding 1-2 |
| Cache Style as static property | Prevents creation of 3 intermediate objects per render frame at 60fps | `findings/candy-mold.md` Finding 4 |
| Fix `candy-template` → `candy-mold` in error message | Copy-paste artifact; package name is `sugarcraft/candy-mold` | `findings/candy-mold.md` Finding 5 |
| Create `CALIBER_LEARNINGS.md` | Every lib needs one per project standard; documents skeleton-specific patterns | `findings/candy-mold.md` Finding 10 |
| Add examples/ directory with async and step demos | Skeleton should demonstrate all key patterns | `findings/candy-mold.md` Findings 13-16, 18 |
| Update quit key comment to be precise | Current comment claims "plain" but `alt+q` / `ctrl+shift+c` don't quit | `findings/candy-mold.md` Finding 3 |

## Phase 1: Bug Fixes [PENDING]

- [ ] 1.1 Fix rune case sensitivity for quit keys — `src/Counter.php:51-53` ← CURRENT
- [ ] 1.2 Fix `candy-template` → `candy-mold` in error message — `bin/start:25`
- [ ] 1.3 Update quit comment to be precise — `src/Counter.php:49-50`

## Phase 2: Performance & Style Caching [PENDING]

- [ ] 2.1 Cache Style object to avoid per-frame reconstruction — `src/Counter.php:65-77`

## Phase 3: Missing Documentation & Examples [PENDING]

- [ ] 3.1 Create `CALIBER_LEARNINGS.md` for candy-mold
- [ ] 3.2 Add `examples/counter-with-step.php` showing configurable step
- [ ] 3.3 Add `examples/async-counter.php` demonstrating `Cmd::promise()`
- [ ] 3.4 Add commented subscription example in `Counter::subscriptions()`
- [ ] 3.5 Add commented init() startup command example
- [ ] 3.6 Add `CounterWithView` example showing `View` object

## Phase 4: Tests [PENDING]

- [ ] 4.1 Add uppercase Q quit test
- [ ] 4.2 Add uppercase Ctrl+C quit test
- [ ] 4.3 Add test for immutable Style caching behavior

## Phase 5: Validation [PENDING]

- [ ] 5.1 Run full test suite (`vendor/bin/phpunit`)
- [ ] 5.2 Verify bin/start runs without error
- [ ] 5.3 Validate composer.json integrity (`composer validate`)

## Appendix: Finding Index

| Finding | Severity | Location | Action |
|---------|----------|----------|--------|
| 1 | LOW | `src/Counter.php:51` | Fix uppercase Q with strtolower() |
| 2 | LOW | `src/Counter.php:53` | Fix uppercase Ctrl+C with strtolower() |
| 3 | LOW | `src/Counter.php:49-50` | Update comment to be precise |
| 4 | MEDIUM | `src/Counter.php:68-71` | Cache Style as static property |
| 5 | LOW | `bin/start:25` | Fix candy-template → candy-mold |
| 6 | INFO | `bin/start:17-27` | No-op (informational only) |
| 7-9, 11-12, 17 | N/A | - | No action needed |
| 10 | LOW | `candy-mold/` | Create CALIBER_LEARNINGS.md |
| 13 | INFO | `src/Counter.php:74-77` | Add commented subscription demo |
| 14 | INFO | `src/Counter.php:39-42` | Add commented init() demo |
| 15 | INFO | `candy-mold/` | Add examples/ directory |
| 16 | INFO | `src/Counter.php:65` | Add CounterWithView example |
| 18 | INFO | various | Add async-counter.php example |

## Notes

- **2026-06-30:** Plan created based on `findings/candy-mold.md` audit performed same date.
- **INFORMATIONAL findings** (6, 13-16, 18) are addressed in Phase 3 (documentation/examples).
- **N/A findings** (7-9, 11-12, 17) require no action.
- The `phpunit.xml` testsuite name is `"CandyTemplate"` — recommend updating to `"CandyMold"` as a minor consistency fix.
