# C-AGT-5.b review — REVIEW-2 (round-2 cure-reviewer, 2026-10-28)

VERDICT: CLEAN (0 CRITICAL / 0 MAJOR / 0 MINOR / 1 NIT recorded accept-as-is; r1's two MINORs resolved)

## Tip and delta
- `git rev-parse cc/C-AGT-5.b` = 5f551912e (parent 8fd05587a = declared base; `master..HEAD` exactly 1; porcelain 0; `%ae` and `%ce` on separate greps both detain@interserver.net; `%(trailers:only)` empty).
- `git diff a3a1f573c 5f551912e --numstat`: exactly three files — BackgroundPanel.vue +109/-2, backgroundSchedules.spec.ts +106 (new), MenuBarLabelDerivationTest.php ±1. Nothing else moved; the entire PHP core is byte-identical to what r1 reviewed, so r1's verdicts (delete-guard non-vacuity across all three §6.3 ruling polarities + unbound edge + tool e2e legs; add-through-factory, READ_VERBS stays ['list']; fireDue records all six emits; legacy row byte-preserved) stand unchanged.
- MINOR-1 (r1 provenance): weld picks 5f551912e. MINOR-2 (r1 web half): resolved — see adjudication below.

## Web-half adjudication
- Declared? YES. BRIEF.md (lanes/C-AGT-5.b/BRIEF.md, modify list + tests create) and design-agt.md C-AGT-5.b both name BackgroundPanel.vue + backgroundSchedules.spec.ts with [hot:] claim; the round-1 gap was under-delivery against a declared surface, not undeclared scope. §2 collision is the scheduler's concern; no in-flight claimant conflict observed at this base.
- Spawn premise corrected on evidence: src-web has NO schedules state channel (repo grep: only reconnect prose and an unrelated `scheduler?` DTO field in generated.ts). The binding design prescribes "runs command.exec /schedules and renders the listing" — the implementation matches the design, and "No protocol change" is honored: generated.ts untouched; command.exec pre-exists with result `Admission | {rows[{role,content,uiOnly}],effects}` and the component guards the Admission branch via `'rows' in result`.
- Server-supplied outcome only: the panel renders listing lines verbatim (`{{ line.text }}`); the ' — last: fired' suffix is server-composed (verified against host.schedules.row_outcome in en.php, and the row shape against SchedulesCommand::listing + lang template). No client-side fabrication anywhere.
- XSS: zero v-html in the component (grep rc=1). Interpolation only.
- Read-only posture: buttons gate on the pre-existing `canWrite` (connection.scopes write) computed; the mutating verbs ride command.exec which the protocol itself scopes to write (generated.ts METHOD_SCOPES). Null session short-circuits with zero requests.
- Spec pins non-vacuous (mutation-reasoned): request shape+count (a fabricated channel or missing mount fetch breaks sent()); 4 rendered lines with buttons forbidden on the two note lines (loose parse breaks); [on]/[off] label polarity (enabled-derivation break breaks 'parked'); verb→args 'disable nightly' then argless reload ordering (drop-reload breaks request #3); delete verb string; both gates polarities. At base the section nodes do not exist → the whole file is red-before.

## Vitest (serial, plain)
- `npx vitest run --no-file-parallelism …backgroundSchedules.spec.ts` → 5/5 passed (602ms).
- With neighbor panels.spec.ts (mounts BackgroundPanel) → 2 files, 16/16 passed — builder's "vitest 16/16 incl neighbors" reproduced EXACT.
- vue-tsc: reviewer shell permission denied for `npx vue-tsc` → recorded as builder-claim (REPORT: rc0); the weld/closeout typecheck gate re-runs it.

## MenuBarLabelDerivationTest flip — legitimate in-step companion
The lane's cmd.schedules.hint adds `add`, so the derived label moves 'List, enable, disable' → 'List, add, enable'; §1.8 obliges the roster row to move in the same commit. Verified live at tip (filter of 4 classes): 42T/311A with the schedules row as passing context; the single remaining failure is the foreign `'pr' => 'Push the current'` row — absent from the test roster at base AND tip, with no lane file touching the pr command, so pre-existing-identical, correctly disclosed in REPORT with the toucher SHAs. Not an out-of-scope fix, not hidden.

## PHP gate re-verification (cwd candy-code worktree, plain pipe, -u LINES -u COLUMNS; no PtyRun in filters)
- `--filter 'Schedule|Schedules'`: Tests: 171, Assertions: 756, Failures: 1 — sole red DreamPassTest.php:288 (foreign Cmd-shape; lane's Chat.php is a 4-line docblock truth-flip only, verified in-diff). Matches r1 exactly.
- `--filter ScheduleCrossRootDeleteTest`: OK — 8T/25A, the REPORT's figure exact.
- Red-before structural: base 8fd05587a has zero deleteRefusal/lastOutcome occurrences (grep) → new pins cannot pass pre-lane, consistent with builder's 31T/7E/9F base-src overlay run.
- lang/ ships en.php only — en-only lawful (§6.3-h locale wave lands last).

## REPORT.md cross-check
Commit SHA/base/deviation ledger all consistent with the tree. Deviations accepted: #2 line-based listing (design's TranscriptTable rebuild breaks non-owned pins; the web client consumes the same listing, so no drift), #3 recording inside fireDue (r1 accepted; docblock-only BackgroundEvents), #7 the in-step flip (above), #1 the 10:34 checkout/clean sweep incident — honestly disclosed, rebuild verified. NEW phpunit file duty: exactly one — candy-code/tests/Background/ScheduleCrossRootDeleteTest.php (1 durations.tsv row at weld). LoopSchedulesCommandTest EXISTS_AT_BASE (modify; row present). backgroundSchedules.spec.ts is vitest — never a tsv row. No weld-only files in the diff (no dist/, suite-figure.json, durations.tsv).

## Findings
- NIT (step-local, accept-as-is): parseScheduleLine matches English-only tokens ([on]/(file)/U+2014). Today lawful (en-only lang); a non-English host listing would render rows verbatim without buttons — graceful and the header comment declares "the panel never re-derives meaning the command did not print". Flag for the C-TUI-6.h locale wave if server-side strings ever localize. No change required now.
- r1 NITs re-judged ACCEPT-AS-IS: null-hostRoot unbound arm (unreachable in real launches, fail-closed toward refusal); stale name-keyed outcome after same-name re-create (memory-only, self-heals next pump, legacy line byte-preserved).
- Foreign NOT-findings: DreamPassTest:288; MenuBarLabelDerivation 'pr' row + PaletteSuperset rows (disclosed, untouched by law); CommandDocsDrift exec-child flake (r1 oddity; tip carries regen).

Lane is weld-ready on 5f551912e.
