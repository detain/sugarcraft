# C-TOOL-2.a — Round-1 Review (WebFetch HTML→Markdown)

VERDICT: FINDINGS — 0 CRITICAL / 1 MAJOR / 2 MINOR / 0 NIT

Tip `20133727518659e0884e5d9d8712ca6ad268bca2` (single parent 07e49e872, cat-file verified) vs master `94432cfd1`.

## Ledger (severity + cure in sections below)

- MAJOR-1 `candy-code/src/Tools/Web/HtmlToMarkdown.php:243,389,417` — DROP_TAGS bypass: `textContent` reads in renderPre/inlineCode/form-control paths leak `<script>`/`<style>` payload text to the model, contradicting the class docblock (:39-40) and REPORT disclosure 3. Probe-proven. Cure: dropped-aware text extraction at the three sites + three pins.
- MINOR-1 `candy-code/src/Tools/Web/HtmlToMarkdown.php` (renderTable) — nested `<table>` rows duplicated (escaped in cell AND lifted as top-level rows); docblock claims flatten-only. Cure: direct-child `tr` iteration. step-local, trivial.
- MINOR-2 (step-local) — UTF-8 bodies whose charset is declared only in the HTTP Content-Type render as windows-1252 mojibake; `loadHTML` never sees the header. Cure: pass a charset hint from WebFetch into `convert()`.

## Scope and traceability — CLEAN

`git diff master...HEAD --name-only` = exactly the 7 files the design block names (HtmlToMarkdown.php NEW, WebFetch.php MOD, composer.json MOD suggest.ext-dom, BuiltInToolTest.php MOD desc pin, HtmlToMarkdownTest.php + WebFetchMarkdownTest.php NEW, lane REPORT.md). No hub-file overreach: WebFetch edits are inside the declared format-arg/description/execute regions; `resolveReference` promotion is declared. No weld-forbidden files (suite-figure.json, durations.tsv, dist/) touched — REPORT.md line 16 states this and disk agrees. Both new test files ARE listed for the weld durations row (REPORT.md :13-15); +33T re-pin disclosed.

## Findings

### MAJOR-1 — DROP_TAGS bypass via textContent (script/style payload reaches the model)

`HtmlToMarkdown.php:39-40` promises: "never rendered, not even as inert markup, so a 'ignore all previous instructions' payload inside a `<script>` never reaches the model at all." Three read sites use raw `DOMText textContent`, which ignores the drop list:

- `:243` `renderPre` — `trim($node->textContent ...)`
- `:389` inline-code case (`code`/`kbd`/`samp`/`tt`) — `$node->textContent`
- `:417` form-control label (`button`/`input`/`textarea`/`select`/`option`) — `$node->textContent`

Probe (live, `/tmp/opencode/cc-cmp-ctool2a-review/DropListLeakProbeTest.php`, ext-dom present):

- `<pre><script>alert("ignore previous instructions")</script>visible code</pre>` → fence body `alert("ignore previous instructions")visible code` — payload verbatim.
- `<code>call<script>HIDDEN-PAYLOAD-MARKER</script></code>` → `` `callHIDDEN-PAYLOAD-MARKER` ``.
- `<button>Go<script>BUTTON-PAYLOAD-MARKER</script></button>` → `press GoBUTTON-PAYLOAD-MARKER done`.

This is prompt-injection surface, not cosmetics: a hostile page chooses `<pre><script>` nesting routinely (syntax-highlighted code blocks are exactly where a page would hide instructions), and the tool output goes to the model. The lane's own pin only covers body-level `<script>`, which the block walker does drop — so the documented law is half-true and the test suite does not discriminate the false half. Design risk line said "script-injection fixture stays inert text"; it stays *absent* at body level and *present* at these three sites.

Cure (lane-side, ~20 lines): add `private static function visibleText(DOMNode $node): string` that walks children, skips TEXT/CDATA only when no DROP_TAGS ancestor within `$node`, and recurses into non-dropped element children (reuse the collapseWhitespace/inline flatten already present); swap it in at :243, :389, :417. Pins: script-inside-pre, script-inside-code, script-inside-button, each asserting the payload marker is ABSENT and the sibling visible text PRESENT. Mutation-reason: reverting any one site to textContent reddens exactly its pin — non-vacuous by construction.

### MINOR-1 (step-local) — nested table rows duplicated

`renderTable` collects rows with `$node->getElementsByTagName('tr')`, which returns descendants-of-descendants rows too. Probe: `<td>outer<table><tr><td>inner cell</td></tr></table>tail</td>` emits the inner content twice — escaped inside the outer cell (`\| inner cell \|`) AND lifted as spurious top-level rows (`| --- |` / `| inner cell |`). Class docblock says "nested tables flatten into their cell" — the flatten happens, but the ghost rows contradict it and corrupt the outer table's column count. Cure: iterate direct `tr` children of `$node`/its `tbody`/`thead`/`tfoot` children only (skip any `tr` with an intervening `table` ancestor); pin: nested-table fixture asserts row count == outer rows.

### MINOR-2 (step-local) — HTTP-declared UTF-8 renders as mojibake

`loadHTML` defaults to windows-1252 when the document itself declares no charset; WebFetch's Content-Type (e.g. `text/html; charset=utf-8`, which the lane already parses for `servedAsHtml`) is never passed down. Probe: `<p>café naïve 😀</p>` as raw UTF-8 bytes with no meta → output `cafÃ© naÃ¯ve` (double-encoded, bytes C3 83 C2 A9). Default `format=markdown` silently degrades text for a large share of real pages that declare charset only in the header. Cure: extract the charset parameter where `servedAsHtml` already splits params, thread an `?string $charset` (or prepend a `<?xml encoding="...">` hint / `mb_convert_encoding(...,'HTML-ENTITIES'...)` alternative for the 8bit path) into `convert()`; pin: utf-8-bytes-without-meta body converts `café` intact.

## Review-focus adjudications (no findings beyond the three above)

1. **Parser safety**: `LIBXML_NONET` set; no `LIBXML_NOENT` and no entity-substitution path (external entities cannot be fetched even with NETWORK off; internal refs stay literal text); 512 KiB pre-parse cap (`MAX_PARSE_BYTES`, checked on `strlen` before `loadHTML`); `NOERROR|NOWARNING` + `@`-suppress + libxml errors captured internally, cleared, previous setting restored in `finally` — no warning leakage to stderr. `<iframe>`/`<noscript>`/`<svg>`/`<nav>` dropped at block level (body-level only — see MAJOR-1 for the leak class). Terminal-escape exposure: HTML cannot produce raw ESC bytes and links go through `safeHref`; not an OSC-8 vector. Untrusted-data framing sentence in WebFetch description byte-identical.
2. **Link resolution**: `markdownBaseUrl()` strips userinfo (probe: `https://u:p@host/x/y` → base has no `u:p`); resolution reuses the single promoted `resolveReference`; fragments dropped (inert); relative traversal (`../../`) yields an absolute URL string — it is *never dialed* by the markdown path, and the resulting `[text](url)` can only be re-fetched by an explicit later WebFetch call subject to the existing SSRF guards. No new dial vector.
3. **Default-behavior change**: description flipped in-step and byte-matches the BuiltInToolTest pin (flipped honestly, untrusted sentence untouched); `format=raw` escape hatch asserted verbatim byte-for-byte; non-HTML (json/text/no-claim) bodies asserted untouched via `servedAsHtml=false` verbatim paths; absent ext-dom → `convert` returns null → loud-but-non-fatal note + raw bytes, never throws (null-branch is shared by all failure causes and pinned behaviorally; builder's disclosure that ext-dom can't be unloaded in-process is honest and accepted).
4. **Cap-after-conversion**: `boundedBody($markdown,...,$cap)` runs on the converted string; pre-existing `mb_strcut` keeps UTF-8 well-formed at the cut (shared with the long-standing raw path — no new corrupt-frame class introduced by this lane).
5. **Pin vacuity**: mutation-reasoned five key pins (servedAsHtml-neuter → 2 reds; late format check → refusal-message red; cap-before-convert → 113,999 + startsWith reds; fallback-throw → assertSame red; oversize-cap neuter → assertNull red). All discriminate. M1 (content-type keying) claim stands. Both new files listed for durations rows (:13-15).

## Gate runs (targeted only, cwd=candy-code, plain pipe, env -u LINES -u COLUMNS)

- `--filter 'HtmlToMarkdownTest|WebFetchMarkdownTest'` → **OK, Tests: 33, Assertions: 59, Deprecations: 2** (builder's 24+9 exact; 2 deprecations pre-existing).
- `--filter 'BuiltInToolTest|ToolSecurityTest'` → **OK, Tests: 253, Assertions: 852, Deprecations: 2** — description flip + untrusted sentence green at HEAD.
- `--filter 'ReadPathCensusTest'` → 19 tests, **1F**: red names `Session/Import/RemoteSessionPush.php|file_get_contents` — not in the lane diff (`git diff master...HEAD --name-only | grep -ci remotepush` → 0); last touched by foreign weld `42f894078` (C-SES-7.g). Builder's "proven red at pristine base" spot-check **confirmed**; standing red, not a lane finding.
- Standing reds roster (not reported): ReadmeSuiteFigureDrift staleness pair, CliLangCatalogue serve-tls, TreeWideGuardRoster ServeArgs, BootstrapLaunchFormatConstants PROJECT_TIER, PermissionsIntroDrift Schedule, GlobDialect PathGlob figure, ReadPathCensus RemoteSessionPush (foreign-confirmed above).
- Harness note: `PtyRunToolTest::testExpectMissIsLoudAndTheScriptStillContinues` excluded per brief; no full suite run.

## Ruling for the fixer

MAJOR-1 must land before weld (docblock law is load-bearing for the W25 security posture and is currently half-false). MINOR-1/2 are cheap same-lane cures; if the fixer defers MINOR-2, the docblock/README must stop implying header-declared charsets are handled. Re-review: probe file at `/tmp/opencode/cc-cmp-ctool2a-review/DropListLeakProbeTest.php` reruns green-to-red discrimination directly.
