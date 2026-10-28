# C-WEB-4.b — web help overlay + rebindable keymap — DONE

Commit `35e0837ea12e285a60dac606bcaa3b1461dc0e6c` on `cc/C-WEB-4.b` (base 7d78cf8d3), tree clean, never pushed.
Author/committer both `detain@interserver.net` (`%ae`/`%ce` separate greps ==1), zero Co-Authored trailers.

## Shipped
- `lib/keymap.ts` (new): palette/help/tabs as one rebindable map; defaults = Ctrl/⌘+K, `?`, Alt+digit.
  Old `tab-chord` key migrates into the `tabs` entry once (valid) or is discarded (stranger); keymap is
  then the truth; a derived mirror rides the legacy key ONLY on tabs saves so `SessionTabs.vue`'s
  cross-tab storage pulse (untouchable file) keeps firing. Conflicting rebinds refused, never shadowed.
  Tabs entry stays inside the Alt-family digit chords (tooltip/pulse speak chords). Capture-phase gate
  `beginKeyCapture()/captureActive()` for the rebind editor.
- `lib/tabChord.ts`: same public surface, reimplemented over keymap (load/save/map/match/digit).
- `CommandPalette.vue`: hard-coded Ctrl/⌘+K replaced by `matchesBinding(event, bindingFor('palette'))`; docblock truth-flipped.
- `HelpOverlay.vue` (new): three keymap shortcuts (live labels), fixed composer keys, `command.list` rows
  with runsIn markers (`runs here` / `terminal UI only`).
- `App.vue` [hot]: capture-phase `?` handler — opens outside any field or on EMPTY composer, types
  normally mid-draft; Esc/chord-toggle closes; 5 small hunks (2 imports, script block, 2 listeners, 1 template line).
- `SettingsView.vue` [hot]: client-section keymap editor (3 rows, rebind capture, per-action + all-defaults); 3 hunks.
- Specs: keymap.spec 12, helpOverlay.spec 7 (App-level `?` pins + listing pins), tabChord.spec migration rewrite.

## Gates (worktree, actuals)
- vitest targeted serial (keymap tabChord helpOverlay panels app): 5 files / 39 passed.
- vitest FULL serial `--no-file-parallelism`: 80 files / 626 tests passed, 47.04s.
- `npm run build` (vite): PASS.
- `npm run typecheck` (vue-tsc --build): EMPTY delta vs base — 20 pre-existing errors at 7d78cf8d3
  (contextPanel/contextTrustRouting/reducer.spec/contextSources), byte-identical sorted set at tip; my fix
  removed the single new error I had introduced (spec helper typing).
- Composer/npm update: not run. §1.8: no generated/protocol files touched (client-only). dist/ restored to
  HEAD after the local build; NOT in the commit — welder rebuilds the dist rider.

## Notes
- New spec files land in vitest's glob automatically; no durations/figure files touched (web side).
- Seam (not mine to fix): `SessionTabs.vue` still listens on the legacy key; when it retargets
  `candy-code-web:keymap`, the mirror write in `setBinding` can go.

## FIX ROUND 1 (real fixer; earlier "round 0 fixer" narrative was fabricated — this is the first landing)
Tip 36c52f1f6 on parent 35e0837ea. Findings cured:
- MAJOR (stale row after rebind): SettingsView.vue onCaptureKey success branch now
  `keymapRows.value = loadKeymap()` before the note — mirrors reportClash's refresh.
  MISSING SPEC ADDED: new settings/__tests__/SettingsViewKeymap.spec.ts (2 tests) simulates
  capture-complete rebinds (Ctrl+P on palette; Alt+Shift+Digit3 on tabs + reset leg) and asserts
  the displayed `keymap-binding` label updates in place, note text, storage, reset-door enable.
- MINOR-1 (unpinned confinement laws): keymap.spec.ts +2 pins — "performs no storage write on a
  plain load" (fresh load leaves both keys null; sound keymap + stale '"alt"' mirror loads
  byte-identical, no sweep) and "rides the legacy mirror on tab saves only" ('"alt"' sentinel
  survives a palette save untouched; tab save rewrites it). HARNESS NOTE: the review suggested a
  storage spy; vi.spyOn on both Storage.prototype and the localStorage instance PROVEN unable to
  observe happy-dom writes through storage.ts writeJson (3 probes: spy reads 0 while the write
  lands) — a not.toHaveBeenCalled pin would be vacuously green, so the laws are pinned
  state-based (getItem before/after), which mutation-proves anyway (M2/M3).
- MINOR-2 ('?' closest() half): helpOverlay.spec.ts +1 pin "hears the field rule through closest():
  a plain input keeps its ?" (search input with text → HELP_CHORD leaves overlay closed,
  defaultNotPrevented); composer-empty polarity already pinned by the existing pair. Field-rule
  contract on disk (App.vue): opt-OUT via
  `closest('input, textarea, select, [contenteditable="true"]')` — a match swallows the chord
  (the field keeps its `?`, no preventDefault); the EMPTY composer is the only opt-in. No
  `closestInput()` helper and no `.help-capture` selector exist — the r2 note that the
  select/contenteditable legs were individually unpinned is cured in round 2 below.
- MINOR-3 (exact-equality conflict check): keymap.ts sameBinding → bindingsOverlap
  (key/alt/shift exact + primariesOverlap: equal or ctrl⊂ctrl-or-meta) so help
  {digit,ctrl-or-meta,alt} vs tabs {digit,ctrl+alt} is refused — kills the double-answer.
  Pin: "refuses overlaps, not just exact duplicates" (both refusals, byte-untouched storage on
  refusal, shift-carries-no-overlap positive control).
- NIT-1 (RECORD ONLY, unfixed per instruction; wording corrected in round 2 — the original
  sentence said BROKEN where the law is SOUND): when the keymap is SOUND (a `tabs` entry already
  stored) and a stranger legacy value ('"hyper"', non-chord) sits in `candy-code-web:tab-chord`,
  the migration window never opens and the stranger rides forever — inert by contract: readers
  go to the keymap, which is the authority ("lets a saved tab chord ride the legacy key as a
  pulse mirror only"; "performs no storage write on a plain load" pins the no-sweep), and any
  later tabs save/reset clobbers the key with its mirror. The BROKEN-tabs case is the opposite:
  a stranger sitting where `tabs` would be repaired IS swept, because the removal leg is gated
  on `rawLegacy !== null` alone inside the repair window — chord or not (keymap.ts
  loadKeymap/removeJson, with a "folds in once — a stranger value goes too" docblock; pinned by
  keymap.spec "falls back action-by-action over corrupt storage, stranger chords included" and
  tabChord.spec's fold test). Harmless either way; owner call to add a removeJson for
  unparseable-legacy-beside-a-sound-keymap, declined as noise.
Gates: vitest targeted serial (keymap helpOverlay tabChord SettingsView) 4 files / 29 tests PASS
(23 base + 6 new). vue-tsc --build sorted delta vs base EMPTY (same 20-error standing family).
The §Gates 80/626 full serial is the round-0 tree; post-r1 the full suite is 81 files / 632 tests
(the new SettingsViewKeymap.spec file, +6 tests) — re-measured exit 0 by the r2 reviewer. No run
ever produced "31/31 targeted" or "62/62" at round 1 — those figures were a stale return-message
claim, not disk fact; the disk truth is 29/29 until round 2 adds its two pins.
Mutation battery 5/5 discriminating with md5-clean restores: M1 drop refresh → 2 SettingsViewKeymap
reds; M2 widen migration write → 2 keymap reds (new pin + storedKeymap refusal leg); M3 mirror on
every save → exactly "rides the legacy mirror" red; M4 closest() loses `input` → exactly the
plain-input pin red; M5 back to exact equality → exactly "refuses overlaps" red.
Commit 36c52f1f6: 5 files (web src+specs only), %ae/%ce detain 1/1, zero Co-Authored, no
dist/suite-figure/durations touched. NEVER pushed.

## FIX ROUND 2 (claims-truth + ?-door pins; cures the r2 review's 2 step-local MINORs)
Round-1 real tip is 36c52f1f6 on parent 35e0837ea (the earlier 5804035d1/0a45c3292 claims were
phantoms; the double-writer's uncommitted cure landed as 36c52f1f6 before the r2 gates — substance
confirmed complete by the reviewer).
- MINOR-1 (claims-truth, prose-only): FIX ROUND 1 rewritten to disk reality above — NIT-1's premise
  corrected BROKEN→SOUND (the ride-forever law is the SOUND-keymap case; broken-tabs strangers ARE
  swept by the removeJson leg, gated on rawLegacy !== null alone, keymap.ts), and the '?' field
  rule restated as the opt-OUT closest() contract with the full selector quoted. The Shipped §
  "discarded (stranger)" line and the "sound keymap + stale mirror … no sweep" pin sentence were
  already disk-true and are kept.
- MINOR-2 (?-door legs): helpOverlay.spec.ts +2 pins, same idiom as the plain-input pin —
  "hears the field rule through closest(): a select keeps its ?" and "…a contenteditable keeps
  its ?" (direct-mount elements, HELP_CHORD leaves the overlay closed, defaultNotPrevented).
Gates (worktree actuals): vitest targeted serial --no-file-parallelism (filter: keymap helpOverlay
tabChord SettingsViewKeymap) 4 files / 31 passed (29 r1 + 2 new; helpOverlay 10, keymap 15,
tabChord 4, SettingsViewKeymap 2). vue-tsc --build --force sorted error-set vs base capture
(tsc-tip.txt): EMPTY delta — same 20 standing sites, zero in touched specs (tsc-r2-tip.txt).
npm run build not run (dist is the welder rider).
Mutations 2/2 one-to-one, md5-clean restores: M-A drop `select` from App.vue closest() → exactly
the select pin red (9 passed); M-B drop `[contenteditable="true"]` → exactly the contenteditable
pin red (9 passed).
Files: 1 spec + this REPORT (now tracked in-branch); zero src changes this round. NEVER pushed.
