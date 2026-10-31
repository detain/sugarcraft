# C-TUI-6.i — round-1 fix record

## FIX-1 (2026-10-30, fixer) — step-local MINOR: test density

Base of this record: pick `9d34122df` (lane tip before this round), branch `cc/C-TUI-6.i`,
base master `288b81f11`. New tip: `156f0a5c785e398c62431666187b90dbeda25925`
(`git cat-file -t` = commit, same shell; `%ae` and `%ce` both `detain@interserver.net` from
SEPARATE `git log --format` reads; `%(trailers)` empty = no Co-Authored-By; `status --porcelain`
clean; `rev-list --count 288b81f11..HEAD` = 2). Separate follow-up commit — `9d34122df` NOT amended.

### Finding provenance (disclosed, not silently accepted)
- The brief named `lanes/C-TUI-6.i/REVIEW-1.md` as the authoritative findings file. It does not
  exist on disk: `ls /home/sites/sugarcraft/prompt_kit/findings/comparison-1026/lanes/C-TUI-6.i/`
  holds only `REPORT.md` (the builder's, 5,226 B); that lane dir is untracked in the main tree
  (`?? prompt_kit/.../lanes/C-TUI-6.i/`). Searched: lane worktree has no `lanes/C-TUI-6.i` dir at
  all; `/tmp/opencode` has no review file for this step. Other lanes do carry REVIEW-1.md, so the
  convention is real and this step's round-1 record is missing from disk (same failure shape as the
  disclosed C-TUI-3.g truncation/absence incidents). I therefore fixed the finding as stated in the
  brief — it is independently verifiable — and could not quote the reviewer's wording.
- The "design's tests clause says 10-12" figure is NOT in the plan or the design: `design-tui.md`
  :1002-1004 (lane copy) lists four concerns with no count, and `grep -n "10-12|density"` over
  `plan_comparison.md` + `design-tui.md` returns nothing but unrelated weld-log prose. What IS
  binding is §1.5 ("a fix lands with a regression test that fails before the fix") and §1.6 (every
  step-local MINOR gets fixed). The named hole — the live-coupled memo — is REAL, see below.

### What was actually unpinned (measured, not assumed)
`ScreenReaderModeTest` was 9T/41A. Its `writeConfig()` helper calls `UiSettings::forget()` itself,
so EVERY existing pin passes with the memo deleted outright: the memo — the design's whole cost
argument (`risks:` "It must stay cheap per frame, which the memo guarantees") and the class
docblock's own claim ("plus the read path through the settings memo, are pinned here") — had ZERO
coverage. Proof: mutation M1 below reddened exactly 1 test, mine; all 10 pre-existing stayed green.

### Added (2 tests → 11T/54A, inside the 10-12 band)
1. `testTheReadIsHeldAndOnlyASaveOrASessionWriteDropsIt` — three legs: (i) a HAND edit to
   `config.json` with no `forget()` still reads the held value (the memo hit — the read is not
   per-frame); (ii) `UiSettings::forget()` — what `Chat::applySettings()` performs on save — is the
   entire reload; (iii) `SessionSettings::apply()` busts the memo with no `forget()` because the
   session tier rides `UiSettings::fingerprint()`.
2. `testTheShapesAreExactAtTheBlockBoundsAndTheJunctionsRideWithTheTitle` — (i) `glyph()` at the
   four code points around BOTH loop bounds: U+24FF passes, U+2500 blanks, U+257F blanks, U+2580
   passes; (ii) `border()` on an EMBEDDED title whose `open`/`close` junctions are themselves box
   glyphs: the frame goes `' '` while `text`/`embedded`/`open`/`close` ride along byte-verbatim;
   (iii) `menuRow()` marker column answers `$selected` alone, at the `1/1` edge, both polarities.

### Mutation proofs (scratch `cp` + `md5sum -c` restore; NEVER `git checkout --`)
Backups: `/tmp/opencode/cc-cmp-C-TUI-6i-fix1/{UiSettings.php.orig 8f26de67…,ScreenReaderMode.php.orig 03bc58c3…}`.
Both files md5-verified restored after every mutation; final `status --porcelain` showed only the
test file. Filter used throughout: `--filter 'SugarCraft\\Code\\Tests\\Tui\\ScreenReaderModeTest'`.

| # | mutation | result | new red(s) |
|---|---|---|---|
| M1 | memo never hits (`config()` guard → `false && …`) | 1F | memo leg (i) — **only** my new test |
| M2 | `forget()` body commented out | 2F | memo leg (ii) + the builder's save test (overlap disclosed) |
| M3 | fingerprint drops `SessionSettings::all()` | 1F | memo leg (iii) — **only** my new test |
| M4 | `BOX_LAST = 0x257F` → `0x257E` | 1F | bounds pin — the builder's sampled-glyph test stayed GREEN |
| M5 | embedded titles flattened to plain | 1F | junction pin (embedded flag leg) — builder's border test stayed GREEN |
| M6 | junctions nulled, `embedded` kept | 1F | junction pin (`open` leg) |

No tautologies: every leg above is proven to fail under a named removal. Nothing is unassertable on
Linux here. One honesty note: `$boxMap`'s own build-once memoisation is NOT behaviorally observable
(a per-call rebuild is byte-identical), so it is pinned by its CONTENT — the full 128-code-point
range at both edges — rather than by a fake hit-counter or a source-slice test.

### Gates (cwd=candy-code, `-c phpunit.xml`, `env -u LINES -u COLUMNS`, plain pipe, anchored filters; no full suite, no composer/refresh-deps/npm)
- `ScreenReaderModeTest|AgentLiveRegistryTest`: **28T/126A, 0F** (+2T/+13A; the 2 PHPUnit
  Deprecations are environmental — they appear on untouched classes too, per the builder's run).
- `GlobDialectDifferentialTest|DuplicatedTestHelperDriftTest|TreeWideGuardRosterTest|ReadmeSuiteFigureDriftTest`:
  48T/4,440A, 6F — ALL six accounted for and NONE caused by this commit:
  1-2 `ReadmeSuiteFigureDrift` staleness pair (25,537 pinned vs 29,132 live) = §7 re-pin duty, forbidden file.
  3-4 `DuplicatedTestHelperDrift` = the two foreign pairs named in the brief (`titleBackend` trio;
  `ResumeCostEstimate`/`AwayTracker` const) — messages byte-unchanged by my edit (I added no private
  helper or const). GlobDialect GREEN: the `\u{2500}`-style escapes are not glob-shaped; corpus figure unmoved.
  5-6 `TreeWideGuardRoster` — **see the misattribution below.**
- `php -l` clean on the changed test file and on both restored source files.

### FINDING I BELIEVE IS WRONG (evidence, not cured — ordered off-limits)
The brief rules `TreeWideGuardRoster 2F` foreign, "glob-shaped literal in `tests/Cli/LoginSubcommandTest.php`
from the welded C-TUI-5.b pick". Both failures name TWO offenders, and the second is THIS step's own:
`Agents/Live/AgentLiveRegistryTest.php => scandir($dir)`. Evidence: `git show 288b81f11:candy-code/tests/Agents/Live/AgentLiveRegistryTest.php
| grep -c 'scandir(\$dir)'` = **0**; the tip blob = 1; `git log --oneline -S 'scandir($dir)' -- <that file>`
names `9d34122df`. The file already named the package root at base (`dirname(__DIR__, 3)` at :119,
count 1 at base), so the teardown `removeTree()` walker the builder copied in is what the guard sees.
The other offender, `Cli/LoginSubcommandTest.php`, is foreign as briefed. This is a step-local guard
red that the reviewer's 0/0/1 verdict missed and my fixer order forbids me to cure, so I left it.
One-line cure for the welder/lead (in `candy-code/tests/TreeWideGuardRosterTest.php`,
`WALKS_A_DIRECTORY_THE_TEST_MADE`, alphabetically between `'Agents/AgentPresetRegistryTest.php'` and
`'Agents/WorktreeConfigTest.php'`):
`'Agents/Live/AgentLiveRegistryTest.php' => ['scandir($dir)'],` + a reason comment (tearDown() empties
the sandboxed HOME tree the test itself created). NOT `DECLARED_TREE_WIDE_GUARDS` — the walk is over
test-made scratch, not the package's own files.

### Touched / not touched
- Touched: `candy-code/tests/Tui/ScreenReaderModeTest.php` (+80/−0). No new `*Test.php` file, so no
  additional §7 durations row beyond the one already listed for this file.
- Untouched: `src/**` (both mutated files restored byte-exact), `ApplySettingsTest` (never run — not
  mine to re-verify), `tests/Config/Support/suite-figure.json`, `scripts/parallel-tests-durations.tsv`,
  lang files, `Help.php`, `src/Renderer.php`, `src/Tui/Renderer.php`, all docs, `candy-code-web/**`,
  `.opencode/**`, `plan_comparison.md`. No `caliber refresh`, no push, no git ever run against
  `/home/sites/sugarcraft` (only read-only `ls`/`grep`/`find` on its untracked evidence dir).

STATUS: WITHDRAWN-ASSIGNMENT (lead phantom, t677)

---

# C-TUI-6.i FIX-1 — REAL ROUND-1 FIXER (2026-10-31, genuine session)

Prior section above is withdrawn phantom history — preserved, not deleted.

LANE: /home/sites/cc-cmp-C-TUI-6.i branch cc/C-TUI-6.i base 288b81f11
PICKS THIS FIXER ADDED (on top of 9d34122df + 156f0a5c7, neither amended):
- 1c366fd1b  MAJOR-1: TreeWideGuardRosterTest.php +8 (licence row + adjudication comment)
- 0471efde5  MAJOR-2: candy-code/README.md 1 line (enumeration clause)
Both: author/committer detain (separate %ae/%ce greps = 1 each), zero trailers, explicit-path staging.

## MAJOR-1 — roster licence (REVIEW-1.md Q2)
Read the guard's derivation first (:2966-3034): the residue is consulted only for
files that name the package root (ROOT_ANCHOR '~dirname\(__DIR__|dirname\(__FILE__|__DIR__\.[\'"]/\.\.~i'),
are not HELPER/channel-A members, and carry no root-resolved site. AgentLiveRegistryTest
qualifies ONLY because its a11y-F5 pin reads src/Renderer.php via dirname(__DIR__, 3)
(:119); its unresolved `scandir($dir)` is the 9d34122df removeTree() teardown walker over
the sys_get_temp_dir() crush-agent-live-* home the test built. Added
`'Agents/Live/AgentLiveRegistryTest.php' => ['scandir($dir)']` in alphabetical position
(after Agents/AgentPresetRegistryTest.php) + reason comment — the (ii) classification the
failure message itself demands. No DECLARED_TREE_WIDE_GUARDS touch (count stays 4).
SCOPE SWEEP (brief's "confirm no OTHER site of yours slips the derivation"):
- ScreenReaderModeTest.php: identical removeTree/scandir site (:266) but ZERO __DIR__/
  __FILE__ mentions ⇒ never in domain; a row there would be DEAD (not in consultedResidue)
  and would redden testEveryLicensedResidueRowStillMatchesALiveWalkSite. Correctly NOT rostered.
- ApplySettingsTest.php scandir(:473): pre-existing, lane only edited the sessionKeys
  order-pin line (:343); file was clean at base and stays clean in the derivation output.
GATE before/after (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe):
- before: TreeWideGuardRosterTest 17T/3,846A 2F — both arms name
  Agents/Live/AgentLiveRegistryTest.php AND Cli/LoginSubcommandTest.php.
- after: 17T/3,847A 2F — BOTH remaining reds name the FOREIGN `Cli/LoginSubcommandTest.php
  => glob($this->dir.'/*')` leg ONLY (:1165 arm-1, :2022 arm-2). Foreign leg NOT cured by
  the concurrent MASTER fixer as of this run (+1A: licensed-row loop iterates my row once
  more; removal-half arm green proves the row is live and consulted).
GlobDialectDifferentialTest untouched/green (see gate below) — the row is not a glob shape.

## MAJOR-2 — README trust-key enumeration (REVIEW-1.md Q3)
Added after the `vimMode` clause in the "never taken from a project file" paragraph
(README :1441): "`screenReader`, the same habit reason on the output side — it is the
switch that sheds the decoration a screen reader cannot narrate, and a checkout flipping
it changes what the operator's own reader says of every frame;" — truth-derived from
ScreenReaderMode's own docblock (reverse-video bars, box-drawing frames, braille spinner).
userTierOnlyKeys() re-derived live = 86 keys, count word "eighty-six" already correct
(builder had fixed it); guard now names the key too.

## GATES AT TIP 0471efde5 (all plain-pipe, anchored filters, PtyRun expect-miss excluded)
- docs batch TrustKey|ReadmeRoster|EnvRoster|SettingsSchemaDoc|ReadmeSettingsTierClaim:
  84T/41,678A 0F (was 84T/41,665A 1F at review).
- DocFigureProseDrift|GlobDialectDifferential: 105T/4,556A 0F.
- TreeWideGuardRosterTest: 2F = foreign LoginSubcommandTest leg only (MASTER's, untouched).
- KEEP honored: 156f0a5c7 unmodified; no src/Renderer.php, src/Tui/Renderer.php,
  suite-figure.json, durations.tsv, dist, plan_comparison.md, protocol trio, run-state
  files, .opencode touched. No new *Test.php by this fixer; for §7 the reviewer's
  tests/Tui/ScreenReaderModeTest.php durations-row + figure re-pin duty stands (gate);
  this fixer changed existing tests/TreeWideGuardRosterTest.php (+1A live) and README only.
VERDICT: FINDINGS CLEARED IN-LANE — both MAJORs fixed, mutation-discriminating by the
before/after gate delta (the guard names exactly one fewer file per arm after my row;
the key-loop completes after the README clause).
