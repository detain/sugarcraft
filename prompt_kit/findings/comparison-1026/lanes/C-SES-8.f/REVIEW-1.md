# C-SES-8.f REVIEW-1 (fresh r1, tip 25c91d6eb)

VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 3 MINOR. Security core GENUINE.

Scope: 9-file numstat == brief list + §1.8-owed ReadPathCensus rows only. Author detain|detain, 0 trailers. No suite-figure/durations/dist/protocol files in diff. CommandDocs drift guard green (COMMANDS.md regen = 1 line, /share row stays 4-format; ShareCommand parseFormat hardcoded — SUPPORTED_FORMATS has zero external consumers, so /share jsonl refusal is real, pinned no-write).
Security/durability verified on disk: rewind law = role-absence + type=rewind + is_int(to); role present in Message::toWire(:1078) and all four Messages\*::toArray, nested type only; json_encode escapes \n so content cannot forge a line; placeholder row carries role. umask 0077 + mkdir 0700 + fopen 'ab' 0600 + fresh-chmod, symlink doors — mirrors SubAgentTranscriptLog (:221-250) idiom honestly. enabled() default OFF, zero prod callers; pinDefaultRoot is the house seam (.g); pathFor pure (test-pinned). ReadPathCensus 2 rows = exactly the file's 2 sinks, classifications honest. Mutation M1 reasoning confirmed (marker-honour removal → lines[4] rewind assert red, no other pin moves).
Gates: gate2 EXACT 649T/17861A OK; 2 deprecations tree-wide pre-existing (reproduce under unrelated filter). Durations row for the new test correctly absent+flagged.

- MINOR (step-local) candy-code/src/Share/JsonlTranscriptWriter.php:17-19: "Skipping marker lines, the remaining lines ARE toJsonl() output — the file replays as the export" is FALSE after a rewind (journal keeps pre-rewind branch rows; own test proves [m1,m2,m3,R1,m2'] ≠ export [m1,m2']). Fix: rewrite to "markers re-set the mark exactly as scanHighWater() does; the journal keeps every row ever appended, including rewound branches."
- MINOR (step-local) REPORT truth: gate1 claims 151T/1591A; tip re-measures 161T/1639A (+10T/+48A == the lane's own 9+1 new tests + 1 derived getcwd-census dataset row for the new src file). Fix: re-run and stamp actual figures in REPORT.md.
- MINOR (out-of-scope → weld/closeout) candy-code/README.md:621 still lists `/export [md|html|json|text]`; jsonl missing. No guard owns the row (ReadmeRosterDrift green). Lane correctly did not edit the shared README but the REPORT owes the DOC-ROW — collect it at weld.

Fix the two step-local MINORs → weld-ready.
