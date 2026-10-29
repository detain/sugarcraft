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

## GREEN-POST (at 77072a840 + cure commit 2)

- `--filter ShellMenuTest|MenuBarLabelDerivationTest|PaletteSupersetDispatchTest|SettingsWriterCensusTest` → **OK 34T / 5818A, 0F** (was 1F+1F+2F+1F).
- Collateral sweep `--filter 'Menu|Palette'` → **OK 496T / 19685A, 0F** (2 pre-existing Risky).

## Mid-cure re-derivations (file text at d9a3dc110 beat the review's letter; disclosed)

1. Row 4 (ShellMenu) is THREE placements, not one: with 'fast' alone placed, `placementNames` vs registry still differed by `commit` + `instructions` (in-registry-not-placed probe: exactly those two). Both placed by registry adjacency — commit before pr in Agent▸Automate (2850 sits before 2860, write-automation sibling group), instructions between memory and skills in Agent▸Knowledge (exact 2405 registry position, design-menu.md's "Memory & rules" submenu). Same family attribution the review itself uses for the label/palette carries ("current loop's fixer", W15 8dab423f0 precedent).
2. Row 5 (MenuBarLabel) landed as instructions+commit rows, NOT the 'fast' row (already carried by 0da9b5071). Rows re-derived live, order = CommandRegistry::all().
3. Both enumerated reds (ShellMenuTest :71/:108, MenuBarLabel :92) were proven to predate every W21 batch weld (probe at aa38e06b1^ derived instructions+commit already, rosters never carried them) — riding waves W16..W20 uncured; the live loop cures, exactly the W15 r1→8dab423f0 shape. MAJOR-1's persistFast row is the genuinely W21-routed one; stampBundleRecommendations is W21's own C-EXT-4.d.

Commits: 1 = 77072a840 (census rows + this map). 2 = f8f9e6a18 (palette survivor + ShellMenu placements + label roster). Both %ae=%ce=detain@interserver.net, zero Co-Authored trailers. (Map self-update: this line.)
