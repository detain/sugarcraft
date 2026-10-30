VERDICT: FINDINGS — 1 MAJOR, 1 MINOR, 3 NIT

Lane: cc/C-TUI-2.f @ a34b878e4 (base dc9eea92d). 7 files, all named in the brief; forbidden
surfaces confirmed zero-touched (no dist/, protocol trio, generated.ts, PHP, suite-figure.json,
durations.tsv — `git diff master...HEAD --stat` verified).

## Gates reproduced (all serial vitest --no-file-parallelism, cwd candy-code-web)
- 3 target specs (diffSplit 15T + diffView 18T + highlight 10T): **43 passed (43)** ✔
- regression components+permissionCardDiff+diffSection+lineDiff: **47 passed (47)** ✔
- transitive agents+changesPanel+MediaCard+permissionCardExtras+transcriptToggles: **49 passed (49)** ✔
- reviewer extra: settings.spec (SettingsPreview — the 4th DiffView mount the builder's list
  omitted) + app.spec: **14 passed (14)** ✔
- `npx vue-tsc --noEmit`: **exit 0** ✔

## Deviation adjudications
1. **Total pairer w/ annotation-as-span — FAITHFUL.** splitDiffRows matches sugar-diff
   `SideBySideFormat::appendPairRun` rule-for-rule (del queue → add oldest-partner via
   `pending.shift()`, leftovers alone at run close, ctx flushes + self-pairs, hunk/file
   boundaries flush, `\`-meta does NOT cut the run). Every input DiffLine appears exactly once;
   ctx "duplication" is the canonical SideBySideRow(line,line) presentation, not data
   duplication. PHP's renderer keeps annotated hunks unified-styled full width; the web span
   row preserves the same information inside a split layout — nothing lost, nothing invented.
   Interleaved `+ + -` and trailing-orphan orderings walk identically to PHP. Verdict: keep.
2. **Eager highlight import — ACCEPTABLE.** DiffView is statically reachable from the eager
   graph (Transcript→TranscriptItem→ToolCard→DiffView, plus PermissionCard/ChangesPanel/
   SettingsPreview, all static imports, zero defineAsyncComponent tree-wide), so Rollup folds
   highlight.ts into the initial chunk regardless of MessageMarkdown's `import()` — the
   "lazy chunk" was already effectively eager once DiffView imports it. Cost is bounded
   (~4 KB source, dependency-free). The highlight.ts header docblock now states both facts
   honestly ("MessageMarkdown loads it on demand…; DiffView imports it eagerly"). Verdict: keep;
   the honesty is the point. (Note for weld docs: candy-code-web README:241 still says
   "highlight (lazy)" — see WELD DOC ROW below.)
3. **Pre-wrap split halves — ACCEPTABLE, but see MAJOR for the style-observation gap.**
   `minmax(0,1fr)` columns + `min-width:0` + `overflow-wrap:anywhere` contain wide lines (no
   horizontal blowout); halves stay in one grid row so pairing survives a wrap. The TUI's
   one-logical-row law exists because sugar-core repaints by absolute cursorTo — CSS grid has
   no such constraint, so the divergence is justified. Not observable in jsdom (see NIT-2).

## FINDING — MAJOR: token tint is emitted but never coloured (GAP-UI-04 inert on display)
- file: `candy-code-web/src-web/components/DiffView.vue:94-172` (the scoped `<style>` block).
- `cellHtml()` injects `<span class="hl-keyword">…` via `v-html` (unified + split, verified in
  DOM by the specs), but NO CSS rule anywhere in src-web colours `.hl-*` for DiffView mounts:
  the only palette is MessageMarkdown.vue's scoped `.markdown :deep(.hl-*)` rules, which match
  only inside MessageMarkdown's `.markdown` root — DiffView never mounts under one (ToolCard,
  PermissionCard, ChangesPanel, SettingsPreview all render it standalone; grep-confirmed zero
  `hl-` occurrences in ToolCard.vue, PermissionCard.vue, ChangesPanel.vue, SettingsPreview.vue,
  tokens.css; tokens.css is the ONLY global stylesheet main.ts imports). DiffView's own style
  block is `scoped` with no `:deep(.hl-*)` rules, and even if plain `.hl-*` rules were added
  they could not match v-html-injected nodes (no data-v attribute on them) — `:deep()` is
  mandatory. Result: syntax colouring ships as invisible markup; add/del row tinting (the
  pre-existing classes) still works, so the diff reads, but the headline of GAP-UI-04 is
  display-dead. The suite is green because jsdom applies no component CSS at all — the pins
  assert span *existence*, which styling cannot falsify.
- Cure (lane-local, ~10 lines + 1 pin): add to DiffView.vue's scoped style, mirroring
  MessageMarkdown's palette vars:
  `.diff :deep(.hl-keyword), .diff :deep(.hl-tag) { color: var(--accent); }`
  `.diff :deep(.hl-string) { color: var(--ok); }`
  `.diff :deep(.hl-number), .diff :deep(.hl-literal) { color: var(--warn); }`
  `.diff :deep(.hl-variable), .diff :deep(.hl-attr), .diff :deep(.hl-key) { color: var(--error); }`
  `.diff :deep(.hl-comment), .diff :deep(.hl-meta) { color: var(--muted); }`
  Plus a source-scan pin (jsdom cannot observe this): a spec that reads DiffView.vue's own
  source text and asserts it contains `:deep(.hl-keyword)` — the repo already uses
  source-scan pins where the runtime is unobservable.

## FINDING — MINOR (step-local): vacuous pin leg passes regardless of any change
- file: `candy-code-web/src-web/lib/__tests__/highlight.spec.ts` (last test, first expect).
- `expect(highlight('const a = 1', 'ts')).toBe(highlight('const a = 1', 'ts'))` compares a pure
  function with ITSELF — identical inputs on identical code, tautologically true under ANY
  mutation of highlight(). The "explicit-language callers are byte-unchanged" claim is currently
  carried only by the git diff (true: highlight()/supports()/highlightIn() bodies are untouched
  in this commit) and the case-leg below it. Cure: replace the first leg with a golden byte
  string, e.g. `expect(highlight('const a = 1', 'ts')).toBe('<span class="hl-keyword">const</span> a = <span class="hl-number">1</span>')`
  (derive from a probe run); keep the `'TS'`→contains-hl-keyword leg (it is load-bearing).

## NITs
- NIT-1: `LANGUAGE_BY_EXTENSION` has 32 keys, the twin spec exercises 31 inputs — the `cxx`
  extension is never asserted ('native/cxx.hxx' tests hxx, not cxx). One line to add.
- NIT-2 (observation, not fixable): the pre-wrap/wrap-preservation law (deviation 3) has no
  pin — jsdom reports no layout. If weld wants a guard, the same source-scan genre used for the
  MAJOR cure can assert `white-space: pre-wrap` inside `.split .row`.
- NIT-3: the 'shows the same texts unified and split' pin's `>= wide.text().length - 10`
  tolerance could silently swallow a ≤10-char row loss; the exact-structure pair/span pins
  cover the substance, so this leg is belt-only.

## VACUITY MANDATE — mutation analysis (static; shell denies cp/python/edit, see note)
The harness refused every file-mutation primitive for this read-only reviewer (cp, python3,
edit all permission-rejected), so mutations were reasoned line-by-line against the exact spec
inputs rather than executed:
- Flip `DIFF_SPLIT_MIN_PX = 1100→1101`: reddens diffSplit.spec `'auto', 1100 → 'split'` AND
  diffView.spec `'auto splits at 1100 px'` (mount at viewport 1100 expects .split). NOT vacuous.
- Neuter drop/keep (`pending.shift()`→`pop()`): reddens `'pairs the nth removal with the nth
  addition in order'` (r1/a1… becomes r3/a1). Both-sides pairing tests discriminating. NOT vacuous.
- Remove the `\ No newline` carve-out (else-branch flushes unconditionally): reddens
  `'carries the "\ No newline" annotation as a span without cutting a run'` (expects
  [[r1,a1]]; neutered yields [[r1,null],[null,a1]]). NOT vacuous.
- Remove the languageForPath twin (return null): reddens highlight.spec mapping/totality/
  supports-leg tests AND four diffView.spec tint pins (tints-.ts, split-halves-coloured,
  rename-header, plus 'leaves language-less path plain' stays green by design — correct
  polarity pair). NOT vacuous.
- Only genuinely vacuous assertion found: the highlight self-compare above (MINOR).

## Checklist confirmations
- resolveDiffStyle edges: 1099/1100 both sides pinned; explicit split wins at 200px; explicit
  unified wins at 1600px (unit + component level); junk→unified pinned twice. ✔
- diffStyle is localStorage-only: prefix `candy-code-web:` via shared storage.ts (not
  server-synced); key already exists server-side since C-TUI-2.c and the lane added NO schema/
  protocol/PHP change — settings.ts docblock explicitly owns the "viewer's pref, never a
  settings-schema key" law. Zero schema creep confirmed (diff touches no PHP/generated files). ✔
- languageForPath twin: 32/32 extension keys match PHP `RegexHighlighter::EXTENSIONS`; vocab
  spellings differ per file-local ALIASES but every value resolves in its own vocabulary
  (spec pins supports() true for the whole range); prefix-strip order diverges only on the
  path STRING (a/b/x), never on the language outcome (traced). Explicit-language callers
  byte-unchanged in code (bodies untouched). ✔
- diffPathForHeader: diff --git last-b/-token (rename twin of PHP loop), + timestamp split,
  /dev/null declines downstream, quoted paths degrade to null identically on both surfaces
  (traced through PHP diffPathLanguage). ✔
- pinia-less fallback: REAL path — `getActivePinia() == null` branch exercised by diffView.spec
  mount and by permissionCardDiff.spec (no pinia installed there). ✔
- resize: listener added onMounted, removed onBeforeUnmount; re-layout across threshold pinned
  both directions. ✔
- per-file body tinting: languageByLine walk is the exact diffRowLanguages law (diff --git
  replaces even when null; ---/+++ only-if-found). ✔
- conventions: strict_types/etc. are PHP laws, N/A; TS files follow the lib's house style;
  comments carry WHY; aria-label added; cycleDiffStyle junk→auto documented.
- E646 / proc_open / roster / dist / weld-only files: N/A or clean.

## WELD CARRIES
1. dist rebuild REQUIRED (src-web changed; lanes never commit dist — `npm run build` at weld,
   deterministic-content-hash law).
2. NO durations rows: new files are *.spec.ts vitest, not *Test.php — PHP-only duty.
3. WELD DOC ROW: candy-code-web README:241 "highlight (lazy)" → note the diff-eager half
   (and optionally add a diffStyle bullet beside the promptHistory localStorage lines).
4. Queued (out-of-lane by design file list): cycleDiffStyle has no production UI caller
   (cycleTheme lives in App.vue + CommandPalette) — a CommandPalette/theme-row twin is the
   natural next web step; not this lane's files.
5. Vue-tsc/vitest figure delta for the closeout ledger: +38 new vitest tests
   (diffSplit 15, diffView 18, highlight +5; 43 in the three files), 0 TS errors.

Reviewer: fresh round-1, static + targeted-gates only; no PHP run (none touched); tree
unmodified (all mutation attempts were permission-refused before touching disk; git status
clean — the worktree equals a34b878e4 exactly).
