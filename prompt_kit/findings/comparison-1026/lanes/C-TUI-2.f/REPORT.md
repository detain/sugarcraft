# C-TUI-2.f — Web split + highlighted DiffView — LANDED

## Status: COMPLETE
Tip: `a34b878e4` (branch `cc/C-TUI-2.f`, off master `dc9eea92d`).
Cat-file verified; author+committer `%ae`/`%ce` greps = 1 each; no Co-Authored-By trailer.
Tree clean. Never pushed. No dist, protocol trio, schema, PHP or durations touched.

## What landed
- **src-web/lib/diff.ts**: new `splitDiffRows()` — the TS mirror of sugar-diff's
  canonical `SideBySideFormat` rule (C-TUI-2.b): within one changed region removals
  queue and additions take the oldest partner line-by-line, leftovers stand alone,
  context pairs with itself, headers (file/hunk/meta) are boundaries that flush the
  pending run, and the `\ No newline` annotation does NOT cut a run. Discriminated
  union `{type:'span'}|{type:'pair'}` keeps vue-tsc narrowing sound.
  Also `DIFF_SPLIT_MIN_PX = 1100`, `resolveDiffStyle(style,width)` (unrecognised
  stored value → unified), and `diffPathForHeader()` (new-side path of a
  `diff --git a/… b/…`/`+++` line, timestamp column dropped, `a/`/`b/` stripped).
  Reuses `parseUnifiedDiff` as the sole parser — no second pairer forked.
- **src-web/lib/highlight.ts**: added `languageForPath(path)` — the twin of
  `RegexHighlighter::languageForPath()` (C-TUI-2.a), same final-extension map,
  values guaranteed `supports()`-true (pinned). `highlight`/`supports`/`highlightIn`
  untouched: explicit-language callers byte-unchanged (pinned in spec).
- **src-web/stores/settings.ts**: added client `diffStyle` pref (`'auto'|'unified'|'split'`,
  localStorage key `candy-code-web:diffStyle` via the existing readJson/writeJson +
  watch-persist pattern, `cycleDiffStyle()` beside `cycleTheme()`). CLIENT pref only —
  NO settings-schema/registry row was minted or needed (no PHP/protocol change).
- **src-web/components/DiffView.vue**: split mode (per-half numbers/colours + `│`
  divider; header/hunk/meta rows ride full width; context pairs with itself; halves
  wrap rather than clip — row box grows, pairing stays visible) driven by the
  `diffStyle` pref over live `window.innerWidth` + resize. Body rows tinted by the
  language of the file they belong to (language walks the diff's own headers,
  mirroring candy-code `diffRowLanguages`), via eagerly-imported `highlight`
  (v-html is safe here: highlight escapes before wrapping — same contract
  MessageMarkdown uses). Falls back to a plain localStorage read when mounted
  without an active pinia (permissionCardDiff.spec.ts never installs one).

## Tests (vitest, `--no-file-parallelism`, run from candy-code-web)
- CREATED `src-web/lib/__tests__/diffSplit.spec.ts` (15T) and
  `src-web/components/__tests__/diffView.spec.ts` (18T — includes the hostile-markup
  escape pin, the pinia-less mount, the resize flip, the persistence/cycle/junk pins,
  the highlight-by-extension and rename-header language-tracking pins).
- MODIFIED `src-web/lib/__tests__/highlight.spec.ts` (10T now — languageForPath twin).
- Target run: `npx vitest run --no-file-parallelism src-web/lib/__tests__/diffSplit.spec.ts
  src-web/lib/__tests__/highlight.spec.ts src-web/components/__tests__/diffView.spec.ts`
  → **3 files / 43 tests passed** (1.64s).
- Regression set: `components.spec.ts permissionCardDiff.spec.ts diffSection.spec.ts
  lineDiff.spec.ts` → **4 files / 47 passed**; transitive DiffView mounts
  `agents.spec.ts changesPanel.spec.ts MediaCard.spec.ts permissionCardExtras.spec.ts
  transcriptToggles.spec.ts` → **5 files / 49 passed**.
- `npx vue-tsc --noEmit -p tsconfig.json` → exit 0.

## Disclosed deviations
1. The PHP pairer (and candy-code's own split renderer) DROPS the `\ No newline`
   row / keeps an annotated hunk unified; the web layout has no per-hunk unified
   fallback, so the pairer is TOTAL here: the annotation rides as a span row and
   the rest of the run still pairs. This is the web twin of the canonical rule
   with the one data-retention difference documented in the diff.ts doc comment.
2. DiffView imports `highlight.ts` statically (MessageMarkdown keeps its lazy
   `import()`), because diff-body tint must be synchronous for the gutter/wrap
   layout. The header docblock was truth-flipped accordingly; the module remains
   small and dependency-free.
3. Split halves use `pre-wrap` (TUI hard-truncates); no information loss, pairing
   stays visible. Unified layout's emitted DOM for known-kind rows is unchanged.

## Not touched (verified empty in own diff)
`candy-code/**` (PHP), the protocol trio, `src-web/protocol/generated.ts`,
`dist/`, `suite-figure.json`, durations TSV.
