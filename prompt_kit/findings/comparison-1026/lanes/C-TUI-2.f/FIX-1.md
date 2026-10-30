# C-TUI-2.f — FIX-1 (round 1)

DONE. Both step-local findings cured; gate set green at the same baseline figures
(44 targets = builder's 43 + the new pin, 47/49/14 regressions, vue-tsc 0).
Branch cc/C-TUI-2.f, base a34b878e4; the commit carrying this file has subject
`C-TUI-2.f: review fixes (round 1)` (its SHA is reported to the orchestrator,
cannot self-reference here). Verdict source: lanes/C-TUI-2.f/REVIEW-1.md
(main-tree copy; read fully).

## MAJOR — tint display-dead: CURED
- `candy-code-web/src-web/components/DiffView.vue`: added the token palette to the
  scoped `<style>` block, right after the `.diff` root rule — seven groups:
  `.diff :deep(.hl-comment)` (muted + italic), `.hl-string` (ok),
  `.hl-number`+`.hl-literal` (warn), `.hl-keyword`+`.hl-tag` (accent),
  `.hl-variable`+`.hl-attr`+`.hl-key` (error), `.hl-meta` (muted),
  `.hl-add`/`.hl-del` (diff-add/diff-del backgrounds). Groups, order and
  variables mirror MessageMarkdown.vue's `.markdown :deep(.hl-*)` palette
  token-for-token; `:deep()` is mandatory because v-html nodes carry no data-v.
  A WHY comment names the v-html opacity and the drift pin.
- Note: `.hl-add`/`.hl-del` are unreachable from DiffView today
  (languageForPath never yields the diff language), mirrored anyway so the two
  palettes are byte-mirrored and the pin exception-free — disclosed here.
- **Duplication status (as mandated, stated):** the palette rules are
  structurally duplicated, the values are shared. Vue scoped styles cannot
  share `:deep()` selectors across components; both blocks reference the same
  tokens.css variables, so colours cannot drift as long as the selector groups
  do — and the new pin fails if they do. Options rejected:
  (a) hoisting `.hl-*` into the single global sheet tokens.css — breaks the
  token-only purpose of that file (contrast.spec.ts reads it as a token map),
  strips MessageMarkdown's `.markdown` scoping, and would tint third-party
  v-html surfaces fleet-wide;
  (b) an indirection layer of `--hl-*` custom props in tokens.css — still needs
  per-component `:deep(.hl-x){color:var(--hl-x)}` rules, i.e. same duplication
  plus an abstraction with one consumer pair. The reviewer-suggested genre
  (source-scan pin) is the actual anti-drift law, so duplication + pin wins.
- **Source-scan pin:** `diffView.spec.ts` › `ships a scoped tint rule for every
  token class it emits` — reads DiffView.vue and highlight.ts from disk (house
  genre: styles/__tests__/contrast.spec.ts, protocol generated.spec.ts), derives
  the emitted token-kind set from highlight.ts's `kind: '...'` rules (floor 12,
  so a refactor that strands the derivation fails loud), and asserts DiffView's
  own text contains `:deep(.hl-<kind>)` for every kind — all twelve classes the
  highlighter can emit, not just keyword.
- **Mutation proof (non-vacuity):** neutered the palette in place
  (`s/:deep(\.hl-/(\.hl-/g`) → exactly 1 failed | 18 passed in diffView.spec,
  naming `DiffView.vue palette rule for .hl-add`; the other 18 mount specs
  stayed green — live confirmation of the reviewer's "jsdom cannot falsify
  styling" point. Restored from /tmp copy, md5-verified
  (`DiffView.vue: OK`), spec re-ran 19/19 green.

## MINOR — vacuous self-compare: CURED
- `highlight.spec.ts` › `explicit-language callers are byte-unchanged by the
  twin`: first leg replaced with the probe-derived golden
  `expect(highlight('const a = 1','ts')).toBe('<span class="hl-keyword">const</span> a = <span class="hl-number">1</span>')`
  (bytes captured by a deliberate-failure probe run, throwaway spec removed
  same command). The load-bearing `'TS'`→contains-hl-keyword leg kept.
  A constant-compare is vacuous-by-construction; no mutation needed.

## NITs — disposition
- NIT-1 (cxx extension never asserted in the 32-key mapping leg): NOT wording,
  a coverage add — queued for closeout, not taken here (fixer scope: wording
  NITs only per dispatch; none of the three are wording).
- NIT-2 (pre-wrap law unobservable in jsdom; source-scan candidate):
  observation — could ride closeout using the same genre as the MAJOR pin.
- NIT-3 (`- 10` tolerance leg is belt-only): observation — leave; substance is
  covered by the exact structure pins.

## Ruled-accepted — untouched (per lead, not re-litigated)
Total pairer w/ annotation-as-span; eager highlight import;
`minmax(0,1fr)` + `overflow-wrap:anywhere` pre-wrap.

## Gates (all serial `--no-file-parallelism`, cwd candy-code-web, after fixes)
- targets diffSplit+diffView+highlight: **44 passed** (baseline 43 + 1 new pin)
- regression components+permissionCardDiff+diffSection+lineDiff: **47 passed** ✔
- transitive agents+changesPanel+MediaCard+permissionCardExtras+transcriptToggles:
  **49 passed** ✔
- settings.spec + app.spec: **14 passed** ✔
- `vue-tsc --noEmit`: **exit 0** ✔ (first attempt via backgrounded `npx` hit a
  temp-download misfire — re-run serially with the project-local vue-tsc@^3.3.12
  bin, exit 0; no npm ci/install ever run)

## Carries recorded (NOT done here)
1. **dist rebuild = WELD duty** — src-web changed again; never committed by lanes.
2. NO durations rows (vitest specs, phpunit-only file).
3. **DOC-ROW for closeout:** `candy-code-web/README.md:241` still says
   highlight is "lazy" — needs the diff-eager half (and optionally a diffStyle
   bullet beside the promptHistory localStorage lines).
4. `cycleDiffStyle` still has no production UI caller (CommandPalette/theme-row
   twin queued; out of this lane's file set).
5. Closeout ledger figures: new vitest test on tip = 44 in the three lane files
   (diffSplit 15, diffView 19, highlight 10).
6. NIT-1..3 dispositions above ride closeout if the lead wants them.
