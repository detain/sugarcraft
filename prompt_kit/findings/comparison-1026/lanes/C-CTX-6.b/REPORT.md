# C-CTX-6.b — LANDED c82b89249 (cc/C-CTX-6.b, base 4b771634d, not pushed)

Verdict: page-ceiling refusal + session-wide document base64 budget shipped; regression proven red pre-fix, green post.

## What landed
- FileMentions: `DOCUMENT_MAX_PAGES=100`; refuse PDF estimated >100 pages; estimate = count of `/Type /Page` objects in plain bytes (positive-count law; compressed counts pass unknown); raw-sprintf notice with its family.
- EngineBackend: `DOCUMENT_BASE64_BUDGET=30MiB`; reverse-walk budget pre-pass in toTypedMessages withholds older over-budget document blocks per row (newest rides); placeholder line in the row text; one `Lang::t('chat.attach.document_withheld')` notice per turn, joined after any capability notice; seeds the `$documents` memo so capability probe stays exactly-1.
- en.php: `chat.attach.document_withheld` (sorted insert). MEDIA.md: new "## Attachment limits" section.

## Tests / gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS)
- NEW test file: `candy-code/tests/Backend/DocumentAggregateBudgetTest.php` (9T) — weld owes durations.tsv row.
- FileMentionsTest +3 tests. Pre-fix (git-show HEAD src): 26T/64A/8E RED; tip (FileMentions|DocumentAggregateBudget): OK 26T/91A. [NIT fixed per REVIEW-1: earlier 25T/58A/8E and "35T/134A" figures were not reproducible; the closest trio (FileMentions|DocumentAggregateBudget|EngineBackendAttachmentsTest) = 36T/140A basis, and the family FileMentions|DocumentAggregateBudget|EngineBackend = OK 314T/1894A.]
- Guards green: DuplicatedTestHelperDrift, DuplicatedDocBlockLine, LangParity, LangLaunchLocale, GlobFigureDrift, DocFigureProseDrift (91T), ChatHostLangCatalogue (my key passes).
- Suite delta for re-pin: +12T/+36A. ReadmeSuiteFigureDrift staleness pair RED at base too (pin 25,537 vs live 26,504 incl. others' +955) — merge/closeout re-pin, forbidden file untouched.

## Review-fix (round 1, MINOR + NIT) — amended into 723418d56
- MINOR (step-local) per-turn-ness pins added to DocumentAggregateBudgetTest: substr_count(notice,'Documents withheld')===1 leg on testTwoDocumentsOverTheBudgetWithholdTheOlderOne, NEW testTheWithholdNoticeIsComposedOncePerTurnAcrossRows (three over-half rows: two withheld rows compose ONE notice sentence), NEW testTwoWithheldDocumentsOnOneRowShareOneSentence (three docs one row: two withheld names ride one sentence, one emission). +2T/+14A: file 11T/44A; fresh pair FileMentions|DocumentAggregateBudget = OK 28T/105A; trio family FileMentions|DocumentAggregateBudget|EngineBackend = OK 316T/1908A.
- Mutation re-proof: per-row emission refactor of the once-per-turn aggregation (EngineBackend.php) → exactly testTheWithholdNoticeIsComposedOncePerTurnAcrossRows red (1F); cp-restore md5-verified, tree clean.
- NIT: REPORT.md gate figures corrected to reviewer-truth above (base 26T/64A/8E; trio basis 36T/140A).
- Suite delta for re-pin revised: +14T/+50A.

## Deviations disclosed
- `chat.attach.pdf_pages` NOT minted: FileMentions notices are a raw-English sprintf family and ChatHostLangCatalogue's source list excludes src/Attachments — an unused key would go red. Page refusal uses the file's own sprintf.
- Budget pre-pass lives in toTypedMessages (declared region was typedUserMessage): "older withheld" requires the cross-row newest-first view and the once-per-turn notice; call-site/const companions are in-file, sole EngineBackend lane (9 sibling worktrees verified zero hits).
- ChatHostLangCatalogue 1 failure `chat.effort.launch_unshaped` — proven pre-existing at base and live in main tree; not touched.
