# REVIEW-2 — C-SEC-8.b round 2 (narrow scope: r1 MINOR-1 fix verification)

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR (r1's step-local MINOR-1 cured) / 0 new NIT

Lane shape: branch cc/C-SEC-8.b, exactly 3 commits atop 9fb981c5c (git log --oneline master..HEAD: 688547446, e94d13c59, a8ea134ae; all git cat-file -t = commit; r1 verified the first two end-to-end, this round verifies a8ea134ae plus the full-range hygiene). Worktree /home/sites/cc-cmp-C-SEC-8.b porcelain-clean at review start AND end.

## 1. Docblock truth vs shipped behavior — VERIFIED (landed-blob proofs at a8ea134ae)

- New PermissionSettings.php text: "/export adds no redaction of its own: it prints exactly what was stored, so anything saved before turning this on stays raw and a turn saved after it exports redacted; the export promise is about the LOCAL copy, and redaction never rewrites persisted history."
- Redaction choke CONFIRMED pre-emit: Runtime.php (git show a8ea134ae blob) — redactResult() call sites are EXACTLY :3760 (settle, before ToolFinished::fromResult at :3766) and :4545 (failure, before :4547). grep "ToolFinished::fromResult" over candy-code/src: only those two call sites + one TranscriptProjector:155 docblock mention. Persisted bytes therefore carry the redaction — post-toggle export prints redacted. TRUE.
- "/export adds no redaction of its own" CONFIRMED: ExportCommand.php:185 calls $session->serializeRaw(); the class docblock records serializeRaw = the same rows/formats minus the redactor, with ShareSession::serialize() (SEC-4 redactor) reserved for share artifacts. The lane diff touches neither ExportCommand.php nor ShareSession.php (git diff --stat master...HEAD: 8 files, none of them export-path).
- "never rewrites persisted history" consistent: redactResult exists only at the two settle/failure chokes — no rewrite path added.
- Diff vs the en.php help (landed blob :3850): help ends "...it prints exactly what was stored, so anything saved before turning this on stays raw." Docblock shares that head VERBATIM and ADDS the truthful post-toggle ON clause. The help string itself is true against the shipped code (not a wrong string being parroted). The r1 MINOR-1 falsehood ("stays raw on either setting") is gone from the tree: grep "stays raw on either" candy-code/ = 0 hits; no guard/test pins the new phrase either (grep over tests/ = 0 hits).

## 2. Comment-only proof — VERIFIED mechanically

git diff --ignore-all-space e94d13c59..a8ea134ae: 1 file (PermissionSettings.php), +6/-3; every +/- line filtered through grep -vE '^[+-][[:space:]]*//' = 0 surviving non-comment lines. Independent of the fixer's claim.

## 3. Test gates — re-run green, figures exact

cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe, at tip a8ea134ae:
- --filter DocFigureProseDriftTest: Tests: 92, Assertions: 4169, OK (+2 house PHPUnit deprecations) — fixer claim 92T/4169A EXACT.
- --filter LangParity|SettingsSchemaDocDrift: Tests: 26, Assertions: 3485, OK (+2 deprecations) — same 26T/3485A r1 measured, zero prose coupling broken by the rewrite.

## 4. Hygiene — VERIFIED

- a8ea134ae: %ae=detain@interserver.net, %ce=detain@interserver.net (separate format greps), zero Co-Authored-By trailers.
- git show --stat each of the 3 commits: file sets are exactly the declared lane files (README/SETTINGS.md/en.php/LayeredSettings/PermissionSettings | SETTINGS.md/en.php/EngineBackend/Runtime/ToolOutputRedactionTest | PermissionSettings). suite-figure.json, scripts/parallel-tests-durations.tsv, candy-code-web/dist appear in NONE.
- r1 carried items (NIT-1 listener annotation, NIT-2 withReaderSymbol funnel, NIT-3 guard figure) — unchanged code, dispositions stand; NIT-2 accepted-not-taken per lead.

## CARRIES for weld (updated range)

- Pick range 9fb981c5c..a8ea134ae — EXCL-start, EXACTLY 3 commits: 688547446, e94d13c59, a8ea134ae. Cherry-pick only, never tree-copy.
- DUR MINT: candy-code/tests/Runtime/ToolOutputRedactionTest.php — 24 cases, JUNIT-TESTCASE-SUM.
- §7: +24T.
- Content-resolve: 688547446's LayeredSettings.php + SETTINGS.md generated-region lines vs concurrent master moves (hand edits stay outside BEGIN/END generated markers; docs/MCP.md collision law).
- Evidence collection pre-prune: REPORT.md + REVIEW-1.md + FIX-1.md + REVIEW-2.md (main tree prompt_kit/findings/comparison-1026/lanes/C-SEC-8.b/) onto the branch.
- Foreign standing reds (run-state standing_reds_add) remain green-equivalent; not chased, none observed in the targeted gates above.

