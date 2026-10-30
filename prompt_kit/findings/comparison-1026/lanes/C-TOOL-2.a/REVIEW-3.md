# REVIEW-3 — C-TOOL-2.a (WebFetch HTML→Markdown, round 3)

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR / 1 NIT (comment numeral, non-blocking)
Lane: cc/C-TOOL-2.a @ 1553df038efaf31590b328e685671cc78dd78df8. The round-2 loop CAN EXIT at 0C/0M — weld-ready.
(Reviewer write map refused the worktree; this file lives in the main tree only — WELDER MUST COPY IT ONTO THE BRANCH.)

## 1. MINOR cure (truncation disclosure) — CONFIRMED correct

(a) **Cap contract holds.** WebFetch.php:430-435: payload = note + boundedBody(markdown, true, max(1, resultCap()-strlen(note))). boundedBody's two exits both respect the cap it is given (early return at $total<=$cap :553-555; marker path pre-cuts to budget-reserve and the trait never exceeds it, docblock :524-530). note(88 B) + (cap-88) = cap exactly. Same shape as the pre-existing statusResult prefix (:489-493) and the lane's fallback note (:450-454); degenerate caps below ~88 B overflow ALL THREE identically (including long-standing statusResult) — house-family property, not a lane defect. No action.

(b) **Premise true.** $complete is minted at the read loop only: :827 `strlen($body) <= $wireBound || feof($stream)` — false means bytes remained on the stream when the wire bound stopped the read; nothing downstream reassigns it. In the $markdown!==null branch convert() already refused bodies > MAX_PARSE_BYTES (:111-112, gate before any parse — r2-verified), so the fetched body is ≤512 KiB and the conversion consumed every fetched byte. The forced `true` into boundedBody is honest about the CONVERSION; the new note carries the FETCH half. Option (b) truly dead: that branch is unreachable for strlen($body) > MAX_PARSE_BYTES.

(c) **No misnaming, no double-disclosure.** Note text names neither Content-Length nor a byte count; "the Markdown covers only the bytes fetched" is exactly true. boundedBody's raw-wire note (:557-559 "server sent more than N bytes") is unreachable on this path (forced true) so it cannot misattribute a raw figure to converted text — fixer's stated reason for rejecting real-$complete verified in code. Branches (markdown-success / fallback / status / raw) are exclusive returns — at most one disclosure set per result. The truncation marker that may co-occur names the converted total, which is what the description promises ("bytes counted after any conversion" :232) — compatible, not contradictory.

(d) **Opposite polarity pinned.** testAnHtmlTwoHundredBodyComesBackAsMarkdown byte-exact assertSame("# Hi\n\nSee [next](...).\n") — a note on a complete fetch fails it.

## 2. NIT cure (CDATA docblock) — CONFIRMED

HtmlToMarkdown.php:402-414 now states what happens ("libxml's HTML lexer reads <![CDATA[...]]> as a bogus comment, so an HTML parse never yields a CDATA node") and names the DOMCdataSection leg belt-only insurance for a hypothetical XML-sourced subtree. Leg kept, zero cost, comment honest — resolved as prescribed, code untouched (git show 1553df038: docblock hunk only).

## 3. Vacuity — reasoning method (read-only: no mutation executed in the lane)

Note literal is tree-unique: exactly src/Tools/BuiltIn/WebFetch.php:426 and tests/Tools/WebFetchMarkdownTest.php:257 (grep). With $note='' reverted, only assertStringStartsWith(note+...) can fail: the byte-exact complete-fetch pin is unaffected ($note was already '' on complete=true), the fallback pin asserts a different literal (:446 untouched). Tip measures 41T/90A/0F, so the mutated tree's single failure is necessarily the new pin — fixer's claimed 41T/90A + Failures:1 = exactly-the-pin reproduces by deduction. Non-vacuity the other way holds via (d).

## 4. NIT (step-local) — fixture size comment says 260,000 B; strlen('<p>filler sentence here</p>')=27 × 10000 = 270,000 B (echo|wc -c measured). Behavior (read stop ~131 KiB, converts) is identical; cure: "260,000" → "270,000" in the WebFetchMarkdownTest.php:236 docblock and FIX-2.md. No guard pins the numeral; weld-carriable as a one-word fix or accept.

## 5. Gates (all reviewer-run: cwd=candy-code, plain pipe, env -u LINES -u COLUMNS)

- `--filter 'HtmlToMarkdownTest|WebFetchMarkdownTest'` → Tests: 41, Assertions: 90, Deprecations: 2 (pre-existing) — fixer claim EXACT.
- `--filter 'WebFetch|HtmlToMarkdown'` → Tests: 198, Assertions: 474 — EXACT.
- `--filter 'BuiltInToolTest|ToolSchemaConformanceTest|ToolSecurityTest'` → Tests: 253, Assertions: 852 — EXACT (+0T/+0A vs r2; description pin unchanged this round, untrusted sentence byte-identical in the diff).
- Guard family (GlobDialect|DuplicatedTestHelperDrift|DocFigureProseDrift|StderrEmitterCensus|BootstrapLaunchFormatConstants|ReadPathCensus|SymbolCitationDrift|DuplicatedDocBlockLine) → Tests: 265, Failures: 2 — both STANDING: BootstrapLaunchFormatConstantsTest PAGE_QUOTES reader-roster (named in the isolated 18T/1F run) + ReadPathCensus RemoteSessionPush (foreign 42f894078, rostered). Excluding both, the other 228T gate OK.
- New pin's +2A decomposition verified: method's own startsWith + helper's assertIsResource(:359).

## 6. Final sweep

Zero getenv/env-var in the whole-branch diff → NO Help.php + lang/en.php roster flip owed (sibling-lane trap does not apply). Diff = exactly 9 files (5 lane code/test + composer suggest.ext-dom + BuiltInToolTest pin + 3 evidence .md); description discloses the markdown default truthfully ("An HTML 2xx body is converted to Markdown by default... pass format \"raw\""); no protocol trio, no dist, no suite-figure/durations touch; E646 clean (no blanket request timeout; 30 s per-read pre-existing); no new proc_open/fork site; no new test FILES this round.

## Weld carries

- Durations rows (builder's 2 files): `candy-code/tests/Tools/Web/HtmlToMarkdownTest.php` + `candy-code/tests/Tools/WebFetchMarkdownTest.php` — NOTE: the spawn brief's §4 citation missed the `Tools/` segment on the second path; disk truth is tests/Tools/WebFetchMarkdownTest.php.
- Lane-total delta vs master: **+41T / +90A** — independently reconciled: the two new files ARE 41T/90A at tip (measured), BuiltInToolTest edit contributes +0T/+0A; arithmetic builder +33/+59, fix-1 +7/+29, fix-2 +1/+2.
- NO protocol trio regeneration, NO dist carry.
- Optional same-commit word fix: 260,000→270,000 comment numeral (§4 NIT).
- WELDER: this REVIEW-3.md exists only in the main tree (reviewer write map refused the worktree) — copy it onto the branch.
