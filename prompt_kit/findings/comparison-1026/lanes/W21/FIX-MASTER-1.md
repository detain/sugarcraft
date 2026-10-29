# W21 — wave loop fixer, round 1 (master, 2026-10-29)

Base: live tip d9a3dc110 (review ran efb97a1b5; master advanced — every row re-derived from file text at d9a3dc110 before editing, per brief).

## RED-PRE at d9a3dc110 (targeted filters, cwd=candy-code -c phpunit.xml, plain pipe)

- `--filter SettingsWriterCensusTest` → 5T/4971A **1F** :145 — derived census carries `Chat.php::persistFast->write`=1 + `Cli/Bootstrap.php::stampBundleRecommendations->write`=1, roster lacks both.
- `--filter PaletteSupersetDispatchTest` → 7T/246A **1F** :200 — survivors derive `fast` at index 1, SLASH_ONLY_SURVIVORS lacks it.
- `--filter ShellMenuTest` → 11T/398A **2F** :71 (placement vs registry) + :108 (sub:more exists because fast is unplaced).
- `--filter MenuBarLabelDerivationTest` → 11T/185A **1F** :92 — DERIVED_LABELS lacks `instructions` + `commit` rows. NOTE: the review prescribed carrying the `fast` row here; at the tip that row ALREADY LANDED (0da9b5071, C-PRV-3.b, git-log -S proven). The real tip-red of this arm is the instructions(2405)/commit(2850) rows, never carried (git-log -S: absent since creation) — the same family W15 cured for pr in 8dab423f0 ("current loop's fixer" per W15 WAVE-REVIEW-1:31 precedent). Rows re-derived via php -r over CommandRegistry::all() at d9a3dc110, exact order: instructions between memory(23) and skills(25); commit between security-review(32) and pr(34).

## Cure map (reviewer-prescribed 5 rows, re-derived at tip)

| # | Finding | File | Row(s) | Status |
|---|---------|------|--------|--------|
| 1 | MAJOR-1 | tests/Config/Settings/SettingsWriterCensusTest.php | `'Chat.php::persistFast->write' => 1` after persistEffort | applied commit 1 |
| 2 | MAJOR-1 | tests/Config/Settings/SettingsWriterCensusTest.php | `'Cli/Bootstrap.php::stampBundleRecommendations->write' => 1` after grantProjectTrust | applied commit 1 |
| 3 | MAJOR-2 | tests/Chat/PaletteSupersetDispatchTest.php | `'fast' => 'bare /fast reads the lane; on|off takes the argument — /effort sibling judgement'` after effort (verbatim from review; wording verified against Chat.php handleFast leg: bare reads, on/off args, refusal sentences) | commit 2 |
| 4 | MAJOR-2 | src/Tui/Menu/ShellMenu.php | PLACEMENT agent row: `'model', 'effort', 'fast', 'agents', 'btw',` (beside effort per R-MENU-1; no exact-order pins exist — placement test sorts, no-submenu test only needs placement) | commit 2 |
| 5 | MAJOR-2 | tests/Commands/MenuBarLabelDerivationTest.php | DERIVED_LABELS `'instructions' => 'List the instruction',` + `'commit' => 'Commit all changed',` (the tip-true content of the reviewed red; fast row already present) | commit 2 |

## GREEN-POST (to be filled before reply)

(see below)
