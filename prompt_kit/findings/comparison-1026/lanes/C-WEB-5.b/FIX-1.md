# FIX-1 — C-WEB-5.b (`serve url --qr`, W34 LATE, ruling R13)

## Round 1 (fixer, 2026-10-31)

Base state verified first-hand: lane tip `357ebe05e`, rev-list 288b81f11..HEAD = 1, porcelain clean.
Findings source read from disk: lanes/C-WEB-5.b/REVIEW-1.md (header + `VERDICT: FINDINGS — C=0 M=1 MINOR=0 NIT=1` as briefed).

### MAJOR-1 — CURED (tests-only? NO: data-order cure in lang/en.php; the guard IS the pin)
As the file words it: "`candy-code/lang/en.php:1119-1120` — the two new keys `cli.serve.qr.cleartext` and `cli.serve.qr.too_long` were inserted AFTER `cli.serve.status.not_running`, breaking the alphabetically-single-sorted CLI block. Red at lane tip: `CliLangCatalogueTest::testTheCliBlockIsOneSortedRunBetweenItsMarkers`. Fix: move those two lines to sort between `cli.serve.none` and `cli.serve.reason_see_log` (`cli.serve.url.qr_json` at :1151 IS correctly placed). One-line-move cure, no behavior impact."

Changed exactly that: `candy-code/lang/en.php` — the two lines moved from after `cli.serve.status.not_running` to between `cli.serve.none` and `cli.serve.reason_see_log`. git diff --stat: 1 file, 2 insertions, 2 deletions. Each key appears exactly once (grep -c = 1, 1); `php -l` clean.

Discrimination proof (RUN, not derived): the guard pin is the review's named test.
- RED-BEFORE at tip `357ebe05e`, my own run: `--filter 'CliLangCatalogueTest::testTheCliBlockIsOneSortedRunBetweenItsMarkers'` → `Tests: 1, Assertions: 4, Failures: 1` (diff shows qr.cleartext/too_long at sorted positions 468/469 vs actual after-467 placement), test at :196.
- GREEN-AFTER post-move, same filter, also re-run at the committed tip: `Tests: 1, Assertions: 5` OK.
- A revert re-dies the guard identically (same event as red-before), so no separate scratch mutation was needed; no production line was neutered because no production code changed.

Ripple: keys are name-looked-up, not position-read; Help screen text and cli.serve.usage untouched. Reviewer's Help.php premise verified first-hand at this tip: `grep -c "serve" src/Cli/Help.php` = 0, screen = one `cli.help.screen` catalogue entry (Help.php:59 via Lang::t) — nothing to move there; design's files.modify "Help.php" stays an over-specification, as the review ruled.

### NIT — DECLINED, NOT FREE (with evidence)
The review's NIT: "no run-level Serve::url pin drives a >213-byte URL end-to-end through the text branch", reviewer itself ruled the step "compliant" against the design's tests-pins line and noted it "for a later hardening pass". Cost probe at this tip: `Serve::url`'s text/QR branch (Serve.php ~:900-924) requires `self::locate()` success AND a real `self::controlRequest()` loginUrl answer from a live server — no hermetic seam exists in ServeArgsTest (whose run-level legs all fire BEFORE locate). A live-child `bin(['serve','url'])` harness lives only in tests/Server/ServeStatusStopTest.php, and minting a >213B URL there needs long-host server config manipulation — a hardening pass, not a free take. Left for the noted later pass; no new §7 test file minted.

### §1.8 guard ledger for this cure
- CliLangCatalogue sorted-run arm: GREEN at `a7399baa3` (the fix).
- Combined family `(StderrEmitterCensus|ReadPathCensus|CliLangCatalogue|Help|LangParity|DocFigureProseDrift)Test::` at the committed tip: 389 tests / 131,912 assertions / 6F + 1 risky — ALL failures whitelisted foreign: 1× HelpTest[CANDY_CODE_SERVICE_TIER], 5× KeyHelpTest datasets, risky = KeyHelpTest::testItStaysWithinTheTerminalWhileScrolledToTheEnd (60s abort; reviewer observed the identical risky on the MAIN tree → host/base-proven foreign). Count differs from reviewer's 326 because the un-anchored `Help` alternation also substring-matches KeyHelpTest (disclosed; the 6 classes of the reviewer's own filter are all in the run).
- ServeArgs+CliLangCatalogue+LangParity exact classes: 59/3844 OK.
- StderrEmitterCensus/ReadPathCensus: NOT-OWED and unchanged by this cure (no emitter, no I/O; pure lang reorder) — and they ran green inside the family filter above.
- ParsedArgs rosters, ACTION_FLAGS, docs/SERVER.md, DocFigure word-numbers: untouched by a key-order move; family filter confirms no new drift.
- ReadmeSuiteFigureDrift staleness pair: untouched (adds zero tests — live enum unchanged, pair stays as the briefed foreign shape).

### Commit
- `a7399baa3045f7e577487d011c97651be2a7e7df` ("C-WEB-5.b: review fixes (round 1)") — follow-up commit, pick `357ebe05e` NOT amended. cat-file'd as `commit` in the same shell that cited it; rev-list 288b81f11..HEAD = 2; porcelain clean after commit.
- Author law: `%ae` grep -c detain = 1, `%ce` grep -c detain = 1 (separate greps), body grep -c "Co-Authored" = 0.
- Files in commit (git commit -o, explicit path): candy-code/lang/en.php only.

### Forbidden set
No touch: suite-figure.json, durations.tsv, candy-code-web/dist, generated.ts, plan files, protocol trio, docs/SERVER.md (hand-edit), run-state.json, escalated, the junk file, AGENT_LAW_SNIPPET.md, LEAD_LAWS.md, .opencode/*. No composer/npm/refresh-deps run. No git against /home/sites/sugarcraft except this report file (sanctioned channel). New *Test.php for §7: NONE.
