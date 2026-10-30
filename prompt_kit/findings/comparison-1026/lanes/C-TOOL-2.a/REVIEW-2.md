# REVIEW-2 — C-TOOL-2.a (WebFetch HTML→Markdown, round 2)

VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 1 MINOR (step-local) / 1 NIT
Lane: cc/C-TOOL-2.a @ 5d6aaedaccbe14b2c54dc670b71f8e5f7c506968 vs master e924d3525.
All three round-1 cures are REAL and non-vacuous. All gate figures reproduced byte-exact.

## Cure verification

### MAJOR-1 DROP_TAGS textContent bypass — CURED
- `visibleText()` (HtmlToMarkdown.php :412-424): DOMText|DOMCdataSection only, recurses, refuses
  subtrees rooted at any DROP_TAGS element at any depth; comments/PIs excluded.
- Called at ALL THREE sites confirmed on disk: :265 (renderPre fence), :464 (code/kbd/samp/tt
  inline family), :492 (form-control labels via collapseWhitespace).
- Independent probe (depth-3 nesting, three different drop tags per site):
  `pre>span>code>script`, `button>span>style`, `p>kbd>span>noscript` — payloads absent, sibling
  legit text present in all three. Passes.
- No legit-text regression: entity refs decode (`&amp;b&#233;` → `&béc` inside pre fence),
  pre whitespace (newline/tab run) preserved, comments still excluded, all 40T/88A lane pins
  (incl. every builder-era raw-fidelity and default-markdown pin) green. CDATA nuance recorded
  as NIT below, not a regression (identical behavior under old textContent).

### MINOR-1 nested tables — CURED
- `tableRows()` (:380-400): direct `tr` children + exactly one level of thead/tbody/tfoot.
- Probe: nested table inside `tbody>tr>td` — inner rows never surface as outer ghost rows
  (regex `^\|\s*inner\s*\|` m-mode finds nothing); explicit thead/tbody/tfoot sections all
  render. Builder byte pin on flattened-cell escaping green in gate.

### MINOR-2 header charset — CURED, hostile surface closed
- `convert(..., ?string $charset)`: MAX_PARSE_BYTES gate on the RAW body BEFORE anything (:111);
  BOM stripped before hint prepend (:121-123); hint only after
  `/^[A-Za-z0-9][A-Za-z0-9._+-]{0,63}$/` (:130-132). The character class excludes `< > " space % [ (`,
  so the token cannot close the attribute, open a tag/PI/DOCTYPE, or introduce a parameter entity.
  Probed hostile values `"><!ENTITY % x SYSTEM "http://e/x.dtd">`, `"><script>evil()</script>`,
  `a b`, `%;--`, 65-char token — ALL ignored, body still converts correctly. No injection, no
  XXE/PE vector (LIBXML_NONET still set; hint is inert with NOENT off).
- No double-decode: hint + in-document windows-1252 declaration + utf-8 meta probe produced no
  Ã-class re-decode mojibake.
- `WebFetch::responseCharset()`: quoted form unwrapped, case-folded, non-content-type decoy
  headers rejected (starts-with on `content-type:` of the lowercased line), rival charsets across
  headers → null (refuse to guess), agreeing duplicates fine, single claimer wins, malformed → null.
  All 7 reflection probes pass. e2e pins: declared charset reaches the converter (café), rival →
  no guess (notContains).
- Non-vacuity: unthreading responseCharset reddens the 2 e2e charset pins; reverting any single
  site to textContent reddens exactly that site's payload-absent assert.

## New finding (this round, whole-lane sweep)

### MINOR (step-local) — markdown branch forces `$complete=true` unconditionally
- WebFetch.php :406-420 (new code): `boundedBody($markdown, $headers, true, ...)`.
- The comment's premise ("a wire-truncated body is never convertible — it exceeds MAX_PARSE_BYTES")
  holds ONLY while `wireBound() > MAX_PARSE_BYTES`. Defaults: maxWireBytes 2 MiB, saveOverflow
  true → captureBound ≥ 4 MiB — premise sound. But `webFetchMaxBytes` AND `toolSpillCaptureBytes`
  are user settings (ToolLimits.php :380-383, SPILL_CAPTURE_BYTES_KEY) and withFetchLimits accepts
  any value ≥ 1; set both below ~512 KiB and a WIRE-truncated body (complete=false) can land
  ≤ MAX_PARSE_BYTES, convert successfully, and the truncation disclosure the raw path would have
  emitted is silently dropped — model sees partial-page Markdown presented as the whole page.
  No data fabrication; narrow double-non-default config; raw-fallback branch uses the true
  $complete correctly.
- Cure: emit a note like the existing fallback note when `!$complete` on the converted branch
  (e.g. "(the page exceeded the fetch byte limit; the Markdown covers only the bytes fetched.)"
  prepended before boundedBody, budgeted as the fallback does), or gate the forced `true` on
  `strlen($body) > HtmlToMarkdown::MAX_PARSE_BYTES` and otherwise pass $complete with the note.

### NIT — visibleText's DOMCdataSection branch is unreachable for HTML parses
- libxml's HTML lexer treats `<![CDATA[...]]>` as a bogus comment (probe confirmed: content lost
  exactly as the old textContent lost it), so the CDATA leg is dead insurance. Harmless; keep the
  leg, but the class docblock's "text/CDATA" wording slightly oversells what an HTML parse can
  yield. Comment-level fix only if taken.

## Gates (reviewer-run, targeted only, cwd=candy-code, plain pipe, env -u LINES -u COLUMNS)
- `--filter 'HtmlToMarkdownTest|WebFetchMarkdownTest'` → OK, 40T/88A (fixer claim exact; = builder
  33T/59A + fixer 7T/+29A).
- `--filter 'WebFetch|HtmlToMarkdown'` → OK, 197T/472A (fixer claim exact).
- `--filter 'BuiltInToolTest|ToolSchemaConformanceTest|ToolSecurityTest'` → OK, 253T/852A
  (fixer claim exact; identical at both ends → description pin flip cost +0T/+0A, no derived rows).
- Guard family `GlobDialect|DuplicatedTestHelperDrift|DocFigureProseDrift|StderrEmitterCensus|
  BootstrapLaunchFormatConstants|ReadPathCensus|SymbolCitationDrift|DuplicatedDocBlockLine` →
  265T, 2F — both STANDING reds (PROJECT_TIER/PAGE_QUOTES diff, RemoteSessionPush), zero
  lane-attributable; Glob figure not drifted by the new files.
- PtyRunToolTest expect-miss case excluded per convention (not matched by any filter above).

## Scope / docs / laws
- Diff touches exactly the named files (+ lane REPORT/FIX-1 riding the branch); no protocol trio
  (gen-protocol-schema.php references no tool/inputSchema surface), no dist/candy-code-web,
  composer.json change is `suggest.ext-dom` only. E646: no blanket timeout added. No new
  proc_open/fork sites. Docs/description strings truthful: "bytes counted after any conversion"
  and the fallback-note wording match the code; class docblock now matches the drop-aware reality.

## Weld carry
- NEW durations.tsv rows: `candy-code/tests/Tools/Web/HtmlToMarkdownTest.php` and
  `candy-code/tests/WebFetchMarkdownTest.php` (2 files; fixer added no new files).
- Lane-total figure delta vs master: **+40T / +88A** (builder +33T/+59A, fixer +7T/+29A —
  reconciled, matches measured 40T/88A; BuiltInTool trio contributed +0T/+0A).
- No protocol trio, no dist involvement.
