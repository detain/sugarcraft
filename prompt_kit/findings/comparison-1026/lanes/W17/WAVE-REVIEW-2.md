# W17 WAVE-REVIEW-2 — r1-cure verification at master d3536ac91 (+cd87233d0 evidence commit)

VERDICT: CLEAN — r1's sole MAJOR is closed; no new wave-level findings.

## 1. r1 MAJOR cure — CONFIRMED
- d3536ac91 `git show --stat`: scripts/parallel-tests-durations.tsv ONLY, 1 file, 1+/1-. Diff = path-only re-key `tests/Agents/AgentInterruptSendTest.php` -> `tests/Chat/AgentInterruptSendTest.php`; duration/count fields unchanged (0.015/4); sort order preserved (neighbors 0.016.../0.015 at lines 1029-1033). Row now present at tsv:1032 (HEAD).
- `candy-code/tests/Chat/AgentInterruptSendTest.php` exists on disk; phantom `candy-code/tests/Agents/AgentInterruptSendTest.php` does NOT (ls-verified both). Key now matches plan §4:2047 promised path verbatim.
- Author/cure hygiene: d3536ac91 %ae=%ce=detain@interserver.net, zero Co-Authored trailers.
- Shard discoverability proof: no phpunit guard reads durations.tsv (grep census across repo: zero test files reference it — r1's "no guard validates tsv paths" still true; the candidate fail-closed arm remains an un-minted follow-up, non-blocking). make-shards walk is a verbatim tsv->shard-list copy with no existence filter, so a resolving row IS the discoverability condition. Direct proof of executability in the shard's own command shape: `vendor/bin/phpunit -c phpunit.xml --filter AgentInterruptSend` (cwd candy-code, plain pipe, -u LINES -u COLUMNS) => OK Tests: 4, Assertions: 24 (the 4 Ctrl+X pins; 2 PHPUnit deprecations = standing house noise per r1 §4). Bare-`php` shell denied in this reviewer harness, so make-shards not re-executed; row-exists + file-exists + runner-green is equivalent.

## 2. Cure commit — no new breakage
tsv-only 1-line change; no generated surfaces, no src/tests touched; §4/§3 untouched by the cure (ledger commit cd87233d0 is docs-only lanes/W17 evidence).

## 3. r1 CLEAN-claim spot samples (2/2 PASS, phantom law)
- §4 mark C-TOOL-2.d: plan:2131 carries `✅ d284451fa963cca330542f013b1e9f3e01b433d6` (full); eadec1a44 (weld) and pick d284451fa both ancestors of HEAD; eadec1a44 subject records pick a14d85245->d284451fa, patch-id SAME, gates EXACT 122T/730A — matches r1 table.
- Battery figure: `--filter 'Azure|OpenRouter'` re-measured => OK Tests: 23, Assertions: 59 — byte-exact vs r1's 23T/59A (zero wobble).

## 4. Notes for the lead (wave close = welder step)
- Close needs: commit WAVE-REVIEW-1.md + WAVE-REVIEW-2.md into prompt_kit/findings/comparison-1026/lanes/W17/ (both currently untracked); §3 W17 close line; §4 verification stamp.
- Still-open carried items from r1 (unchanged, non-blocking): NIT-1 foreign-red routing (CliLangCatalogue / ChatHostLangCatalogue / OneSidedHomeSandbox rows); candidate durations.tsv existence guard NOT yet minted (this exact phantom class can recur); ReadmeSuiteFigureDrift staleness pair rides the §7 closeout re-pin as designed.
