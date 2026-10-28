# C-SES-8.f — Review round 2 (independent)

(Fallback location per lane brief: canonical prompt_kit/findings/comparison-1026/
lanes/C-SES-8.f/REVIEW-2.md was write-refused from this reviewer's permission
map; welder/closeout should copy this file there.)

VERDICT: CLEAN (r2 step-local: 0 findings)

Scope verified: master..HEAD = 25c91d6eb (build) + f9f079205 (fix), tip
f9f079205d55ff9e973adc339e32f41152adb7b6 — both hashes real, author/committer
Joe Huss <detain@interserver.net> on both, zero trailers. Fix diff touches
exactly the 2 claimed files (+10/-3): JsonlTranscriptWriter.php docblock and
JsonlTranscriptWriterTest.php rewind test. No new files (durations.tsv row not
owed); README.md diff = 0 lines (DOC-ROW-only correctly).

1. Docblock truth CONFIRMED against code (read scanHighWater :182-200 and
   save :138-141 independently): markers set head = max(0,'to') — the "re-set
   the mark exactly as scanHighWater() does" sentence is true; save opens the
   journal 'ab' and never truncates, rewind branch appends only the marker —
   "the journal keeps every row ever appended, including rewound branches" is
   true. The false replay-as-export sentence is gone (it was false: after the
   pinned rewind the file is m1,m2,m3,marker,m2 — not an Exporter::toJsonl
   replay).
2. +2A pin CONFIRMED real (self::assert count 41->43 in-file; no new methods).
   Mutation reason: the old docblock contained the literal "replays as the
   export", so assertStringNotContainsString goes red on lie-restoration and
   the second leg fails too — but both legs sit in the one rewind test, so
   restoring the old prose = exactly 1 red, as claimed. The corrected claims
   are also behaviorally pinned in the same test pre-existing legs (prefix
   byte-identity + lines[4] msg-2 re-set).
3. Gates reproduced (cwd candy-code, env -u LINES -u COLUMNS, plain pipe):
   JsonlTranscriptWriter|Share family 362T/4811A green; (Writer|ShareSession|
   Exporter|ExportCommand|ShareCommand) 115T green; tests/Share+ExportCommand+
   ReadPathCensus 52T; SymbolCitationDrift 9T/17233A ({@see self::scanHighWater}
   resolves); CommandDocsDrift|ReadmeRosterDrift|EnvRosterDrift 53T/38846A.
   Note: could not reproduce the exact "161T/1641A" filter string (lane REPORT
   absent from prompt_kit lanes dir — see CLOSEOUT); every family filter run is
   green and the structural +0T/+2A delta matches the claim.
4. r1 PASS items carried: jsonl export threading (Exporter::toJsonl,
   ExportCommand format, ShareSession untouched semantics), 0600/0700 + umask,
   symlink refusal, marker-vs-row asymmetry guard, best-effort save(),
   ReadPathCensus rows for new file reads — all byte-unchanged by the fix.

CLOSEOUT-ONLY (inherited from r1, unchanged):
- README.md:621 Sessions row still says `/export [md|html|json|text]` — jsonl
  DOC-ROW owed by closeout welder (COMMANDS.md:357 and lang hints already carry
  jsonl; no guard currently reddens README).
- C-SES-8.d adjacent carries (from r1): journal enablement/wiring lives in
  8.g launch mirror; 8.d consumer rows unchanged by this lane.
- Evidence-archive gap: prompt_kit/findings/comparison-1026/lanes/C-SES-8.f/
  held no REPORT.md/REVIEW-1.md on disk in this worktree (r2 found /tmp scratch
  copies only: /tmp/opencode/cc-ses8f-fix1). Welder: collect the lane REPORT.md
  with FIX ROUND 1 section into the evidence dir before the §4 mark.

Welder note: §4 mark cites FINAL tip f9f079205; full-lane pick =
25c91d6eb..f9f079205 (two commits, cherry together).
