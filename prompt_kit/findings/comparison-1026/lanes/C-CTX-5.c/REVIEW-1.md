# REVIEW-1 — C-CTX-5.c (idle/cacheTtl compaction fold)

Lane: cc/C-CTX-5.c @ 585c465ef (single commit, base cf0cfd0be). Reviewer: fresh r1, read-only, gates re-run at tip.

## Verdict: APPROVE — 0 CRITICAL / 0 MAJOR / 1 MINOR (out-of-scope) / 1 NIT

## Scope audit (mandatory) — PASS
- numstat = 13 files == the 13 claimed files, exact (launch hint "26" refuted; 13/13). 1020+/44-.
- All named files match the block; no unnamed file touched. LayeredSettings.php byte-unchanged (disclosed; key name unchanged, correct).
- durations.tsv / suite-figure.json / README absent from diff = correct (closeout-owned, disclosed in REPORT "Gate owes").

## Integrity audit (stash-loss recovery) — PASS
- Commit contains exactly the intended idle/cacheTtl work; no residue of the sibling's repo-global stash contents.
- File overlap with cc/P-AGENT.b tip 19830f687 = lang/en.php + src/Chat/Handlers/HandlesRunControl.php. Walked both shared-file hunks: en.php changes only the settings.compaction.idleAuto.help line; HandlesRunControl adds only the cacheTtl intercept + handleCacheTtlIdleCompactTick(). Zero agent-window/viewport/Ctrl+X lines. NO hunk contamination.
- Author/committer detain@interserver.net both greps, zero Co-Authored trailers.

## Semantics vs BRIEF math — EXACT
- IdleCompactionPolicy: 3600 marked / 300 implicit / null never-uncached (cacheTtlSeconds + cacheTtlSecondsOf via MarksPromptCache provider detection, non-marking backend → 300); FLOOR_PERCENT=60 inclusive (>=floor fires, pinned); fires strict > at ttl−60, boundary-exact → false, pinned.
- SessionHost: cacheTtl arms at max(1, ttl−60)+2 = ttl−58 (242/3542 pinned); ttl null never arms; hour path byte-unchanged (3602); re-check through shouldAutoCompactAtCacheTtl on fire.
- HandlesRunControl tick: same watch gate + deliberate silence-list duplication (structural pin test compares both tick bodies), floor predicate, spendCapReached → idleHeuristicFold, else unattended summarization request.
- Legacy read-compat: on-disk bool true→hour, false→off, byte-stable behavior for existing users; ambiguous junk ignored→off. No silent behavior change.
- CompactorConfig deviation (ctor throws on unknown mode / half-pair instead of normalizing): JUDGED ACCEPTABLE — readonly ctor params cannot be rewritten, and fromSettings/wither paths normalize BEFORE the ctor (atomic pair writes); no src site constructs half-pairs; settings-corrupt values never reach a throwing path, so the config-corrupt campaign posture (announce/refuse/coerce loudly) is honored at the fromSettings layer. Throws are developer-misuse defense, pinned by tests. boolSetting deletion was a private static → not a public-API change; STOP rule n/a.
- en.php replaces the C-CTX-6.c "No short cache-warm idle tier ships yet" sentence — truthful now that cacheTtl ships.

## Gates reproduced at tip (cwd candy-code, plain pipe)
- CacheTtlIdleFoldTest: OK 14T/59A ✓ (the 2 PHPUnit deprecations trace to foreign pre-existing AccessorDeprecationTest providers, not this lane).
- tests/Context: OK 1333T/5275A — EXACT.
- tests/Host + tests/Config/Settings + IdleAutoCompactionTest: OK 668T/11563A — EXACT.
- SettingsSchemaDocDrift: OK 19T/183A — EXACT (in-suite re-derivation of SETTINGS.md; serves as the gen --check equivalent — reviewer map denies bare `php`, so the tool itself un-run, surface proven guarded).
- --filter Idle at tip: OK 256T/20371A vs REPORT's 235T/20288A → see NIT.
- Base spot-checks at cf0cfd0be (cc-cmp-C-SES-8.c tree): ApplySettingsTest 14T/2F base-reds exist ✓ (foreign, outside band); carry math: IdleAuto base 17→+3, SessionHostIdleAuto base 13→+4, +14 new = +21T ✓.
- E646 clean (wall-clock idle alarm only, no request timeouts); no proc_open sites; plan §6: no ruling touches C-CTX-5 (opt-in ships; default-on flip stays queued).

## Findings
- MINOR (out-of-scope, report-only): REPORT.md line "~235 tests Idle filter" records the BASE figure as the tip gate; tip is 256T/20371A. Green either way; weld/closeout should cite 256.
- NIT (step-local, docs): REPORT "Gate owes" correctly defers durations.tsv row + suite-figure re-pin (+21T/+83A carry), but the Idle-filter sentence above should be re-stamped at weld so the ledger is self-consistent.
