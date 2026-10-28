# WELD — C-MCP-2.a (welder, 2026-10-28)

**PICKED.** Master base `581ac6ede`; lane commit `8b98aeec9` cherry-picked clean
(zero conflicts — auto-merge in `NonInteractive.php`/`SessionHost.php` moved context
only; `git diff` of the two patches differs solely in header/index lines and one
`@@` offset shift (-1992→-1994) from an earlier welded hunk in SessionHost.php; no
`+` line came from a conflict resolution) → master pick `51812d155`. Stable
patch-id `ab1536388ca341eb83414ad7e0b01b39e0453740` on BOTH commits. Author `%ae`
and `%ce` grepped separately: both `detain@interserver.net`; message byte-identical
to the lane; zero trailers. Stat identical: 8 files, +641/−37.

**Review loop:** REVIEW-1 FINDINGS (1 MAJOR — drain() guard timer 20.0 vs sibling
15.0, DuplicatedTestHelperDrift — fixed in r2, one-line diff `b41c22b95..8b98aeec9`)
+ REVIEW-2 CLEAN (0C/0M/0 step-local MINOR). REVIEW-2.md copied from
`/tmp/opencode/cc-cmp-lanes/C-MCP-2.a/` (reviewer perms) into this lanes dir per
run-state weld_note.

**Regeneration:** NOT needed — not a protocol lane; docs/MCP.md section is outside
generated regions, schema/SERVER.md/generated.ts/dist untouched (reviewer-verified).
Ran as drift check on the merged tree anyway: `gen-protocol-schema.php --check` rc=0,
`gen-command-docs --write`/`gen-tool-docs --write`/`gen-settings-doc --write` all
"up to date", zero generated-file drift.

**Durations:** new test file `candy-code/tests/Host/SessionHostMcpSurfaceTest.php`
row added in the preceding `scripts:` commit `026004012`: `1.287  5` (junit
per-testcase sum, single-file 5T/18A run; 0.432+0.264+0.224+0.157+0.210). Canonical
duration-desc position: between 1.289 ClaudeCodeStreamJsonTest and 1.286
UsageWiringTest (no tie). Set-equality 1469+1=1470 both directions.
`suite-figure.json` untouched (staleness pair is standing base-inherited red).

**Merged-tree gates** (cwd candy-code, plain pipe, env -u LINES -u COLUMNS,
PtyRunToolTest never in filters):
- `--filter 'SessionHostMcpSurface|NonInteractiveMentions|TurnControllerMcp|ContextMentions|McpPromptCommands'` → OK 42T/211A (reviewer union was 41T/152A incl. McpPromptProvider; +ContextMentions rows per lead's filter — within expected ~40T+ band, zero failures).
- `--filter 'DuplicatedTestHelperDrift'` → OK 14T/194A — EXACT vs REVIEW-2.
- `--filter 'DocFigureProseDrift'` → OK 91T/3965A — EXACT vs REVIEW-2.
- Standing base-inherited red families (StderrEmitterCensus 3F, TreeWideGuardRoster 2F, ReadmeSuiteFigureDrift staleness pair) not in these filters; not ours.
- 2 PHPUnit deprecations in every run are tree-wide ambient noise (REVIEW-2 noted).

**Queued NIT (closeout, per run-state weld_notes + REVIEW-2):** refusal-literal
shared constant — the @mcp refusal string duplicated at `NonInteractive.php:462`
vs `TurnController.php:1098` should be extracted to one constant. NOT touched at
weld (lane-complete change-set; fix-wave/closeout duty).

**Marks:** §4 heading `#### ✅ 51812d155 C-MCP-2.a — …`; run-state: C-MCP-2.a
inflight→welded, 106→107, inflight 9. Lead's working-tree run-state changes
(tick6c SEC-5.b review stamp etc.) carried intact in this commit.

**Carry watched:** C-SEC-3.b deferral note references C-MCP-2.a row identity for
C-EXT-4.c — no weld action.

Never pushed.
