# Lane C-TOOL-2.a — WebFetch HTML→Markdown (W25)

STATUS: COMPLETE. Branch cc/C-TOOL-2.a off master 07e49e872. One commit.

## Files
- NEW candy-code/src/Tools/Web/HtmlToMarkdown.php — ext-dom DOMDocument converter (LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD, no entity loading, no network). Drops script/style/nav/svg/iframe/noscript (+ head metadata); keeps h1-h6, links (safe schemes only, relative refs resolved against the final URL via WebFetch::resolveReference), ordered/unordered nested lists, inline+block code with backtick-fence escaping, blockquotes, pipe tables, dl, img with alt, entities decoded. MAX_PARSE_BYTES 512 KiB pre-parse cap. Returns null for empty/nothing-renderable/oversized/parse-failure/NO ext-dom.
- MOD candy-code/src/Tools/BuiltIn/WebFetch.php — `format: "markdown"|"raw"` arg (default markdown, validated BEFORE dialing); HTML detection on the claimed Content-Type (text/html, application/xhtml+xml; disagreement/absence = no claim = raw); conversion on the 2xx success path only; cap applies AFTER conversion; fallback = one-line leading note + raw bytes (out-of-budget prefix shape of statusResult). resolveReference promoted private→public (single RFC 3986 impl, disclosed below). description() rewritten (untrusted-data sentence byte-identical).
- MOD candy-code/composer.json — suggest `ext-dom`.
- MOD candy-code/tests/Tools/BuiltInToolTest.php — testWebFetchToolHasCorrectNameAndDescription description pin flipped in-step.
- NEW candy-code/tests/Tools/Web/HtmlToMarkdownTest.php — 24 tests.
- NEW candy-code/tests/Tools/WebFetchMarkdownTest.php — 9 loopback-fixture tests.

## New test files for the weld (durations.tsv rows owed)
- candy-code/tests/Tools/Web/HtmlToMarkdownTest.php
- candy-code/tests/Tools/WebFetchMarkdownTest.php
Suite-figure re-pin owed: +33 tests (24+9) over base live enum. suite-figure.json and durations.tsv UNTOUCHED by this lane per law.

## Tests run (all cwd=candy-code, -c phpunit.xml, plain pipe)
- WebFetchMarkdownTest alone: OK (9 tests, 26 assertions)
- filter 'WebFetch|HtmlToMarkdown|html': OK 197T/476A (2 pre-existing PHPUnit deprecations, identical count at base — 163T/410A there)
- ToolSecurityTest + BuiltInToolTest + both new files: OK (286 tests, 911 assertions)
- Guards GlobDialect|DuplicatedTestHelper|ReadPathCensus|ToolDocDrift|ToolCatalog|ToolDescriptionSentenceFloor|ReadmeSuiteFigure|StderrEmitterCensus|DescriptorSinkArgumentCensus: 202T — failures = staleness pair ONLY (pinned 25,537 vs live 28,505, pre-existing batch-era drift, +33 from this lane rides it) + ReadPathCensus RemoteSessionPush row (PROVEN red at pristine base via stash — foreign weld 42f894078 minted the sink without the roster row; not this lane's file).
- Doc family DocFigure|ReadmeRosterDrift|EnvRosterDrift|TrustKey|ConfigWriteProducer|KeyBindingDrift: OK 376T/46770A.
- tools/check-path-repos.php --no-lib-path-repos: clean.
- Mutation M1 (servedAsHtml keying neutered → always-convert): exactly 2 reds (json-verbatim, no-claim-verbatim); restore md5-verified bf22008d…

## ext-dom-absent fallback proof
`HtmlToMarkdown::convert()` branch 2 is `!class_exists(DOMDocument::class) → null`; null is the SOLE failure signal and the WebFetch fallback path consumes it identically for every cause. ext-dom cannot be unloaded in-process on this host (compiled-in sibling of libxml; no dlclose seam), so the null→note+raw path is pinned behaviorally by testUnconvertibleHtmlFallsBackToRawBytesWithOneLeadingNote (empty-render → null → note+verbatim bytes, exact assertSame), and the two other null branches (oversized, empty) are pinned directly in the converter suite. The composer suggest line states the degraded behavior truthfully.

## Disclosures / design adjudications
1. Conversion applies ONLY on the 2xx success body path; non-2xx bodies keep the announced-status raw verbatim shape (design named the body path only).
2. Unknown `format` value → isError refusal naming the allowed values, BEFORE any dial (no silent default).
3. Script/style payload is DROPPED WHOLE (design's drop list), so the injection fixture text never appears in output even as inert label (pinned: payload absent).
4. `WebFetch::resolveReference` promoted to public for reuse — one RFC 3986 §5.2.2 implementation, no second copy. Consequence it inherits: fragments are dropped, so same-page `#anchor` links name the document URL (converter comment + pin).
5. Link base has userinfo stripped (markdownBaseUrl) so credentials in the fetched URL never propagate into every emitted link.
6. Success-path boundedBody receives complete=true: the wire figures described the bytes the conversion consumed; the cap counts the converted text (pinned: marker total 113,999 for a 144,000-byte raw body).
7. svg dropped whole (no text-keep nuance); form controls render label text only; no Markdown escaping of prose asterisks/underscores (docblock rationale: escaping is the one class of fidelity loss worse than literal marks in fetched prose).
8. Tool model-facing strings are plain literals here (existing WebFetch refusals do not use Lang::t; house shape preserved).
9. Description pins honored: untrusted-data sentence byte-identical; 'at most 65,536 bytes' substring kept (ToolSecurityTest:603 survives).
