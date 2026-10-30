# C-TOOL-2.a — Round-1 Fix Report

Base lane tip `201337275`; the fix rides the single round-1 commit (`git log` names it; a commit cannot contain its own SHA).

## Per-finding status

- **MAJOR-1 — DROP_TAGS textContent bypass: FIXED.** New `HtmlToMarkdown::visibleText()` (raw walk, skips DROP_TAGS subtrees recursively, text+CDATA only, comments excluded) swapped in at all three sites: renderPre (was :243), inline-code family (was :389), form-control label (was :417). Class docblock extended so the drop law now states the reads obey it. Pins: `testDroppedSubtreeTextNeverLeaksThroughWholeSubtreeReads` (script-inside-pre/code/button, payload absent + sibling text present) and `testDeepAndNonScriptDroppedSubtreesStayOutOfWholeSubtreeReads` (script two levels deep, style payload in pre).
- **MINOR-1 — nested-table ghost rows: FIXED.** `renderTable` iterates `tableRows()` = direct `tr` children plus one-level `thead`/`tbody`/`tfoot` descent (probed: libxml mints no tbody). Pin `testNestedTableRowsDoNotBecomeGhostOuterRows` (full byte pin; old read emitted 4 lines, cure emits 2) + `testExplicitSectionWrapperRowsStillRender`.
- **MINOR-2 — header charset: FIXED.** `convert()` gained `?string $charset`; a header token matching `^[A-Za-z0-9][A-Za-z0-9._+-]{0,63}$` is prepended as `<?xml encoding="…">` (probe: honors hint, no double-decode with in-doc meta, latin1 works, bogus token falls back like no-hint; validation means a hostile token is ignored, never interpolated). WebFetch `responseCharset()` reads the Content-Type parameter (quoted form unwrapped; rival charsets across headers guess null — matches rival-Content-Length posture) and threads it at the convert call. Pins: converter-level `testTransportCharsetHintDecodesUndeclaredUtf8` (incl. mojibake-baseline polarity + hostile-token leg) and end-to-end `testHeaderDeclaredCharsetReachesTheConverter` + `testQuotedCharsetParameterIsUnwrapped` (loopback fixtures).

## Gates (cwd=candy-code, plain pipe, env -u LINES -u COLUMNS)

- `--filter 'HtmlToMarkdownTest|WebFetchMarkdownTest'` → **OK 40T/88A** — reviewer's 33T/59A at lane tip + exactly the 7 new pins (+29A), 2 deprecations pre-existing.
- `--filter 'WebFetch|HtmlToMarkdown'` (superset incl. the other WebFetch suites) → **OK 197T/472A**.
- `--filter 'BuiltInToolTest|ToolSchemaConformanceTest|ToolSecurityTest'` → **OK 253T/852A** — reviewer's exact figures, green at fix.
- Guard family (Glob|DuplicatedTestHelper|DocFigure|StderrEmitterCensus|LaunchFormat|ReadPathCensus|SymbolCitation|DuplicatedDocBlock) → 264T, **2F = both documented standing reds** (ReadPathCensus RemoteSessionPush foreign; BootstrapLaunchFormatConstants PROJECT_TIER per REVIEW-1.md:58) — zero lane files in this diff touch Bootstrap.php or docs/, base-inherited by construction.
- No full suite. PtyRun test never in any filter.

## Mutation proofs (cp-backup → mutate → targeted run → cp-restore, md5 1915d794/26358ed7 verified)

- M1 renderPre re-textContent → 2F (pre + deep pins). M2 inline-code → 1F. M3 form-control → 1F. M4 getElementsByTagName('tr') → 1F (ghost pin). M5 charset unthreaded at WebFetch → 2F (declared + quoted). All discriminate; restores byte-clean.

## Welder notes

- No NEW test files (7 pins extended inside the two lane files already listed for durations rows).
- Suite figure grows +7T/+29A over the lane's +33T pin (40T/88A in the two files) — staleness pair remains a standing red until weld re-pin.
