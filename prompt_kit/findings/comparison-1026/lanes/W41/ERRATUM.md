# W41 close — ledger erratum evidence (same-path overwrite race)

Recorded 2026-11-01 by cc-cmp-welder (ledger-erratum duty, batch-1101W41erratum). This is a citation/bytes ledger defect, NOT a wave-status defect: the W41 close stands (see Concurrence below).

## 1. Forensics of the race

| Fact | Value |
|---|---|
| Close weld commit | `39296c576` (2026-11-01T03:57:48-05:00) — subject cites 'WAVE VERDICT: CLEAN …' from lanes/W41/WAVE-REVIEW-1.md at **10,556 B** |
| Follow-up fold commit | `ea0f5cd69` (run-state stamp; §3:177 flip rode 39296c576) |
| Committed blob at that path (39296c576) | **8,230 B**, final line `WAVE VERDICT: FINDINGS C=0 M=0 MINOR=0 NIT=1` |
| Lane dir mtime | 2026-11-01 03:56:51 |
| WAVE-REVIEW-1.md mtime | 2026-11-01 03:57:42 (8,230 B) |
| CLOSE.md mtime | 2026-11-01 03:57:43 (3,779 B) |
| Lane dir contents | ONLY CLOSE.md + WAVE-REVIEW-1.md — the 10,556 B CLEAN report and its REVIEW-2.md / REVIEW-2-ORIGINAL.md siblings are absent on disk |
| Erratum commit (§3:177 suffix) | `a3615a76f` |

Sequence: the W41 close welder (serialized sole master writer) statted the 10,556 B CLEAN r1 report and staged the lane evidence; between its stat and its `git add`, an independent concurrent r1 review — lead session ses_e69b69f5fffe83Bg6xv6vliXkK, dispatched against the same wave — wrote its own report to the SAME path (final flush 03:57:42), overwriting the CLEAN blob. The add then picked up the 8,230 B FINDINGS file, while the §3:177 row and the 39296c576 subject/ CLOSE.md continued to cite the CLEAN text the welder had read earlier. The lost CLEAN report's verdict line survives verbatim in CLOSE.md §1 (and, after this erratum, is cross-quoted in §3:177); the REVIEW-2.md / REVIEW-2-ORIGINAL.md siblings referenced in the row have no committed or on-disk copy — recorded as a §7 sweep item (bank or reconstruct from §3/CLOSE.md citations; both verdict texts are quoted verbatim there).

## 2. Verdict lines, verbatim

Committed 8,230 B concurrent r1 (git show 39296c576:prompt_kit/findings/comparison-1026/lanes/W41/WAVE-REVIEW-1.md | tail -1):

    WAVE VERDICT: FINDINGS C=0 M=0 MINOR=0 NIT=1

(its single NIT is record-only evidence bookkeeping: the C-TUI-6.h REVIEW-1.md 1,594 B mid-write-fragment provenance note — zero charged findings; closing line above it reads `VERDICT: W41 lands the final plan step with strict parity intact; zero blocking findings; one record-only evidence NIT.`)

Lost 10,556 B CLEAN r1, as preserved in CLOSE.md §1 (Authority read) and quoted in §3:177:

    WAVE VERDICT: CLEAN (C:0 / M:0 / MINOR:0 / NIT:0 charged to W41; 5 foreign/standing items listed under ## Not charged)

## 3. Concurrence conclusion

Both r1 reports, authored independently and concurrently, agree on substance: **C=0, M=0**, every gate green-or-accepted-foreign (Lang 635T/52058A/2Dep/1S EXACT; pairing 39T/192A; doc trio 140T/47834A; protocol pair 13T/362A; TWGR 17T/4013A; DTH 14T/204A; BinDispatch sole red = foreign --worktree :1958 leg; GlobDialect standing corpus rider; vitest 4 reds = accepted §7(1) idempotencyKey family), the same 5 foreign/standing riders carried to §7, and W41 close-eligibility. The FINDINGS-vs-CLEAN difference is only the record-only NIT classification, which charges nothing to W41. The close at 39296c576 + ea0f5cd69 (waves_closed full [1..42]) therefore stands; only the row's size/verdict-text citation needed the erratum now appended at plan_comparison.md §3 line 177 (commit a3615a76f).
