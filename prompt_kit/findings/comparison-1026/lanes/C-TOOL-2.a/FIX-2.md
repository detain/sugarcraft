# C-TOOL-2.a — Round-2 Fix Report

Round-1 cures (MAJOR-1, MINOR-1, MINOR-2) verified by reviewer and NOT touched. This round fixes the two round-2 findings; the fix rides the single round-2 commit (git log names it).

## Per-finding status

- **MINOR — markdown branch forced `$complete=true` unconditionally: FIXED (cure option (a), house prefix shape).** `WebFetch.php` markdown branch now prepends `(The page exceeded the fetch byte limit; the Markdown covers only the bytes fetched.)\n` when the FETCH was wire-truncated (`!$complete`), and the note is out-of-budget — boundedBody gets `max(1, resultCap() - strlen($note))`, exactly the shape the fallback note and `statusResult` prefix use, so the result cap can never eat the announcement. The forced `true` for boundedBody itself stays: within this branch the raw body is by construction ≤ MAX_PARSE_BYTES (convert refuses above it), so the Markdown IS the full conversion of every byte fetched; what was missing is the disclosure that the fetch stopped early. Option (b) (gate on `strlen($body) > MAX_PARSE_BYTES`) was rejected as written — that predicate is dead in this branch — and passing the real `$complete` into boundedBody would misattribute the raw Content-Length to the converted text (its "server sent more than N bytes" note would name the Markdown length). The comment now states the exact config shape that breaks the defaults-premise (both `webFetchMaxBytes` and `toolSpillCaptureBytes` below the parse cap).
- **NIT — visibleText CDATA docblock oversell: FIXED (comment-only).** `HtmlToMarkdown::visibleText()` docblock no longer claims "text and CDATA survive": libxml's HTML lexer reads `<![CDATA[...]]>` as a bogus comment, so an HTML parse never yields a CDATA node; the `DOMCdataSection` leg is kept and named belt-only insurance (survives a subtree ever arriving from an XML parse). Code untouched.

## Pin + mutation proof

- New pin: `WebFetchMarkdownTest::testAWireTruncatedConvertedBodyDisclosesTheTruncation` — loopback fixture serves 260,000 B of `<p>` markup; tool wired `saveOverflow: false, maxWireBytes: 65536` (the shape the two user settings take together): read stops ~131 KiB with the stream open → `complete=false`, body ≤ 512 KiB parse cap → converts. Asserts `assertStringStartsWith(note + 'filler sentence here')` — a truncated body presented as complete fails it, and the note riding the *converted* (not raw) output is in the same prefix.
- Mutation: `$note = $complete ? ... : "..."` reverted to `$note = '';` (byte-for-byte the pre-cure state) → filter `HtmlToMarkdownTest|WebFetchMarkdownTest` = **41T/90A, Failures: 1 — exactly the new pin**. Restore cp + md5-verified (6dcaa16fabd2ff5660be5c5ee175c3fa).
- Non-vacuity the other way: the pre-existing byte-exact `testAnHtmlTwoHundredBodyComesBackAsMarkdown` pins that a COMPLETE fetch carries no note.

## Gates (cwd=candy-code, plain pipe, env -u LINES -u COLUMNS; PtyRun expect-miss case matched by no filter)

- `--filter 'HtmlToMarkdownTest|WebFetchMarkdownTest'` → 41T/90A (reviewer's 40T/88A + this round's +1T/+2A).
- `--filter 'WebFetch|HtmlToMarkdown'` → 198T/474A (reviewer's 197T/472A + 1T/2A).
- `--filter 'BuiltInToolTest|ToolSchemaConformanceTest|ToolSecurityTest'` → 253T/852A — byte-exact vs reviewer (+0T/+0A, no schema/description touch).
- Guard family (GlobDialect|DuplicatedTestHelperDrift|DocFigureProseDrift|StderrEmitterCensus|BootstrapLaunchFormatConstants|ReadPathCensus|SymbolCitationDrift|DuplicatedDocBlockLine) → 265T, Failures: 2 — the two rostered standing reds (BootstrapLaunchFormatConstants PAGE_QUOTES, ReadPathCensus RemoteSessionPush), identical set to the reviewer's; GlobDialect PathGlob figure not drifted by the new literals (test strings are space/paren-bearing, not glob-shaped).

## Delta for the welder

This round adds **+1T/+2A** on top of the lane-total +40T/+88A carry (→ lane total +41T/+90A vs master). No new test FILES (durations rows stay the two already owed for builder files). No trio/dist involvement; no census flips (zero new emitters/sprintfs/roster entries).
