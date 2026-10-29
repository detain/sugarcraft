# C-EXT-5.b — REVIEW-1 (round 1, fresh reviewer)

VERDICT: FINDINGS

Lane: cc/C-EXT-5.b @ 9d0750cd8 (base e306bf4fc), 9 files +535/−13. Author/committer detain verified separately, zero Co-Authored. No suite-figure.json / durations.tsv / dist / generated-file touches. Master moved since base; the only overlapping file (lang/en.php) is a disjoint section — the pick rebases conceptually clean, and the lane contradicts no landed palette semantics (blanket mid-turn arm untouched, second-level arms untouched, action-row segment byte-order preserved via PaletteCatalog invariants).

## Gate results (reviewer-run, cwd=candy-code, plain pipe, -u LINES -u COLUMNS)
- php -l: all 8 touched PHP files clean.
- `--filter Palette`: **229T / 4175A, 0F, RC=0** (2 pre-existing deprecations) — builder claim reproduced exactly.
- `--filter '(DocFigureProseDrift|GlobDialect|ReadmeSuiteFigureDrift|ChildWallClockBudget|DuplicatedTestHelper|StderrEmitterCensus)'`: **230T / 39074A, only reds = the inherited staleness pair** (25,537 vs live 27,864) — builder claim reproduced.
- `--filter '(SlashDispatch|MenuCommandDraftPreservation|KeyBindingDrift|SettingsCommand|MediaFormKeys|DeckHostedSessionRoutes|InFlightInputQueue)'`: **318T, 0F, RC=0** — reproduced.
- Base-red claim verified live in the main tree at master 6742a2ad3: PaletteSupersetDispatchTest fails 1 (roster arm :183, `/instructions` untabulated). The lane cures it in-step in its assigned file; welder takes the roster addition on the pick.
- Regression discrimination (read, not guessed): every new pin routes through arms that throw / return the fail-closed no-op at base (runPaletteAction prefill arm, paletteItemLabels catalog arm) — no base-passing shape exists; builder's 6/6-red pristine-base mutation run is credible.
- WIDE_UNCLICKABLE_ROWS census arm RE-DERIVES from the painted frame (assertSame against wideUnclickableLabels), so the literals are fail-closed tripwires, not a vacuous list. No glob-shaped/`**Refused:**`-shaped literals (GlobDialect green).

## Findings

1. MINOR (step-local, fix now) — `candy-code/src/Chat.php:10882-10888`. `runPaletteAction()`'s own header docblock now lies for the labels this lane taught it to accept: "leaves the draft alone" and "Mid-turn it is refused exactly as Enter on that palette row is … anything else gets refuseInFlightAction()'s notice". A prefill label writes the draft, and mid-turn it is allowed on purpose. Fix: extend the docblock with one clause, e.g. after "leaves the draft alone." add "— PREFILL rows are the exception this method's C-EXT-5.b half introduced: they fill a blank draft (or keep a non-blank one with a notice) and, being draft writes only, they stand mid-turn even though Enter's mid-turn arm refuses them (see seam in the lane REPORT)." The claim of exact Enter-parity is now false; say what differs and where.

2. MINOR (out-of-scope, weld/owner ruling) — design bullet "Prefill is allowed mid-turn" is NOT production-reachable mid-turn: the user's route (Ctrl+P → Enter/click) goes through `runSelectedPaletteActionWhileInFlight()` (Chat.php:17823), which blanket-refuses (P-TABS.f/W3.10 landed law: "the rule's worth is that it is ONE rule"), and the only production caller of the public arm (App.php:4811) passes action labels, never prefill labels. The lane implemented the bullet inside exactly the two functions the design named, disclosed the asymmetry as seam (a), and pinned Enter/click consistency — a sound reading of a three-way doc tension the design never adjudicated. Escalate: a follow-up minting the prefill arm in `runSelectedPaletteActionWhileInFlight` (prefills write no turn state, so the arm's own measured justification excludes them) needs an owner ruling, not a lane widening outside its serialized region.

3. NIT — `candy-code/docs/COMMANDS.md:441` opens the paragraph with "Since C-EXT-5.b", teaching a campaign ID to users. The tree already carries ISS-037/P-TABS.e precedents, so this is consistent — but "Since this release" or dropping the clause reads better in a user doc.

## Weld-carry (already disclosed in the lane report; recorded here for the ledger)
- `scripts/parallel-tests-durations.tsv` row owed: `tests/Chat/PalettePrefillRowsTest.php` (6 tests) — new test FILE.
- suite-figure.json + README headline re-pin (inherited staleness pair 25,537→live; the lane adds ~+9 methods: 6 new + 1 superset arm + roster-driven rows).
- Seam (b) (Renderer.php wide-label click-zone loss, latent pre-lane, pinned honestly meanwhile) and seam (c) (no palette scroll) stay owner-visible.

BRIEF conformance: every declared file moved as specified; the three undeclared test flips (PaletteFullQueryMatchTest / PaletteClickTest / RendererTest) are forced consequences of the grown list and carry their §1.8 in-step disclosure. No stubs, no fabricated paths. StderrEmitterCensus unaffected (zero new emitters/sprintfs; Lang::t notice path). i18n: candy-code ships en only; key added under the declared `palette.*` prefix.

Fix #1 (one docblock paragraph, in the already-declared method region) and the lane is weldable.
