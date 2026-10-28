# W15 WAVE-REVIEW-2 (cure round) — master tip c6b1d0317d9fad74ab4b40bd775ab8d76dc7c6e7

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR / 2 NIT (r1's MAJOR + NIT both cured)

## 1. Cure commit 8dab423f048adae6deccf487a1d5ae2ef308f98b — PASS

- File set is exactly the three claimed: `candy-code/src/Tui/Menu/ShellMenu.php`,
  `candy-code/tests/Chat/PaletteSupersetDispatchTest.php`,
  `candy-code/tests/Commands/MenuBarLabelDerivationTest.php` (9+/1−).
- `git log -1 --format='%ae'` → `detain@interserver.net` (count 1), `'%ce'` → same (separate greps), `%B` has zero
  Co-Authored-By / Signed-off-by trailers.
- Three flips are the r1-prescribed set: automate group `…'shells','schedules','pr','workflow'`;
  `DERIVED_LABELS` gains `'pr' => 'Push the current'` after `security-review`; `SLASH_ONLY_SURVIVORS` gains
  `'pr'` with a dated C-AGT-6.d (w15) comment. Both roster entries sit in registry order (2840-review <
  2845-security-review < **2860-pr** < 2900-bg).

### b. Survivor-row judgement vs the spec — TRUE (one attribution nit, §5)
Read `candy-code/builtin-commands/2860-pr.php` and `Chat.php:12136 handlePrCommand()` (→
`PrCommand::prompt()` → `submit()`): bare `/pr` **does** dispatch a real turn. The spec's own words are "the calls run
in the turn through the Bash tool, where the session's permission mode gates them — **the write is honest about being a
write**", and "A command that STARTS A TURN … it belongs to no read-only roster". The row's `honestly a write` echoes
that verbatim claim; `no PaletteAction arm was minted` is mechanically true — the census derives survivors from
`$spec->paletteAction === null` (`PaletteSupersetDispatchTest.php:167`), and the roster test passing proves pr has no
action, so it never reaches `CommandRegistry::paletteEntries()`/the Ctrl+P list. Label truth:
`cmd.pr.description` = "Push the current branch and open a pull request for it with gh" (`lang/en.php:1442`) →
derived first clause "Push the current", matching the new `DERIVED_LABELS` row (gate green, not hand-forced).
Idiom vs `/shells` (`'bare /shells is the whole list surface; …'`) and `/schedules` (`'… no picker target exists'`):
same shape — bare-form verdict + no-target reason. `Typed dispatch only` is house shorthand used identically for the
review pair (line 73–75) whose specs are also ShellMenu-placed, and holds for the host/web surface too
(`PrHostCommand` is the typed route; zero `'pr'` references in `candy-code-web/src-web`). No contradiction of spec or
behavior → no finding beyond NIT-1.

### c. Gates (plain pipe, `env -u LINES -u COLUMNS`, cwd `candy-code`, PtyRunToolTest never in a filter)
- `--filter 'Palette|ShellMenu|MenuBarLabel'` → **OK 244 tests / 4212 assertions, 0F** (brief's expected 244T exactly).
- r1 MAJOR trio `--filter 'ShellMenuTest|MenuBarLabelDerivationTest'` → **OK 22T / 562A, 0F** (was 3F at 045751c44 —
  discrimination is r1's red-before record; this round is green-after).
- `tests/Chat/PaletteSupersetDispatchTest.php` alone → OK 6T/197A. r1-instructed `--filter MouseModalGuardTest` → OK
  22T/267A.

### d. What actually governs the automate-group position — the design tree, not registry order
`ShellMenuTest::testEveryRegistryRowIsPlacedExactlyOnceAndNoPlacementIsABluff` **sorts both sides** (lines 61/70) — it
is a set+uniqueness law, NOT order-sensitive, so no test pins where in the submenu `pr` sits. Order is governed by the
R-MENU-1 approved tree in `prompt_kit/findings/comparison-1026/design-menu.md:24-26`
(`Automate ▸ /goal · /grind · /loop /bg · /schedules · /workflow`), which is already non-numeric:
`workflow`=**2300** (lowest spec number) sits last, `goal`/`grind`=3400/3410 (highest) sit first. So "2860 sorts before
2905 ⇒ pr must precede shells" does not apply. Precedent 9120011fc placed `shells` **between** `bg` and `schedules`
(spec-adjacent to its sibling pair) rather than appending; the cure's `pr` after `schedules`, before the `workflow`
tail, follows the same keep-`workflow`-last reading. Verdict: placement lawful, order discretionary, nothing to fix.

## 2. Cure commit c6b1d0317d9fad74ab4b40bd775ab8d76dc7c6e7 — PASS
Single file `plan_comparison.md`, 1+/1−, exactly line 1833: `✅ e2bc4292d` → `✅
e2bc4292dafc00444a13fc9687e4622507981fd3`. `git cat-file -t` → commit; `git merge-base --is-ancestor` → true (subject
`C-TUI-2.b: sugar-diff side-by-side writer`); no other plan line moved. Matches every other W15 §4 mark's full-hash
style. r1's NIT cured.

## 3. Regression sweep of the r1-CLEAN areas the cure could touch
- `--filter 'CommandDocsDrift|ReadmeRosterDrift|KeyBindingDrift|DocFigureProseDrift'` → **OK 331T / 5945A, 0F**
  (standing roster unchanged).
- `--filter 'SlashDispatch'` → OK 21T/441A, 0F.
- Generators: this reviewer's permission map denies bare `php`, so `gen-*-docs --check` / `gen-protocol-schema --check`
  were NOT re-runnable (rejected, not failing). Substituted statically: the cure edits no generator input — no spec
  file, no `lang/` value, no README/`docs/` line, no protocol surface — so nothing re-derives; r1 recorded all four
  generators rc0 at the pre-cure tip.

## 4. Weld hygiene / laws
No `cc-cmp-*`/`lane/W15*` worktree or branch survives (`git worktree list` + `git branch -a` greps empty for
w15|cc-cmp|cc/); `git status` clean on all four touched paths; no new test file ⇒ no `durations.tsv` row owed; no
weld-only file was committed; both commits are the welder's own (sole-master-writer law honored). E646/dormant/fork
laws: the cure adds only comments and roster rows — zero emitters, zero timers, zero spawns, so no census could move
(confirmed by the green guard sweep).

## 5. NITs (non-blocking)
- **NIT-1 `candy-code/tests/Chat/PaletteSupersetDispatchTest.php:78-84`** — the comment says the spec file "records its
  own judgement". True for the write-gating, but the roster test's own message asks for "a per-row judgement recorded
  in the spec file" for the *palette* decision, and `2860-pr.php` carries no palette sentence; the strongest precedent
  does (`0350-effort.php:12` "Deliberately no palette action", `1340-status.php:11` "No paletteAction: …"). Cure
  (either, one line): append to `2860-pr.php`'s docblock "Deliberately no `paletteAction`: the bare form IS a gated
  write, and a picker row would trade that friction for nothing — the ShellMenu row is placement-law mandated"; or
  re-word the comment to attribute the palette call to this census rather than the spec.
- **NIT-2 `plan_comparison.md:1888`** — C-AGT-6.d's §4 mark still names only `b6369af78695…`, under which the tree
  shipped 3 reds. Precedent on this band records carries on the row (cf. 045751c44's row text). Cure: a dated note
  "+ roster flips 8dab423f0 (W15 wave-loop)".

## 6. Ambient (rostered, not findings)
2 PHPUnit deprecations in every filter (r1 §4's AccessorDeprecation ambient pair) and the stderr line
"⚠ Permission mode is bypass-permissions … ~/.candy-code/config.json", which also prints under `SlashDispatch` —
this box's real config read by fixtures, pre-existing, not cure-introduced. No NEW red beyond the r1 roster.
