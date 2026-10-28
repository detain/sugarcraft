# C-AGT-6.a review r1 — VERDICT: CLEAN (0C/0M/0 step-local MINOR)

Lane 96f14da66 (parent c4c13f101), 11 files +713/−8. detain %ae/%ce=1, Co-Authored grep=0, no quote-named file in tree, worktree clean.

## Gates re-derived at tip (cwd candy-code, plain pipe, -c phpunit.xml)
- 71T/1529A (BuiltInCommands|CommandDocsDrift|ReadmeRosterDrift|ForeignCommandDiscovery|PaletteSupersetDispatch|InFlightInputQueue) OK — 2 pre-existing PHPUnit deprecations, not failures.
- 146T/2147A (tests/Host/Commands + AutoCommitterTest) OK.
- 22T/313A (CommitHostCommandTest 7T + AutoCommitterTest 15T) OK.
- gen-command-docs --check: raw `php tools/...` refused by this reviewer's bash map; the identical check runs as CommandDocsDriftTest ("byte-equal … as a test", green inside 71T) plus the names-every-row-once IN ORDER table pin green.

## Core checks
1. commitAll reuses the SAME hardened commit(): explicit-path staging `git add -A -- <paths>` from the changedPaths walk, user core.hooksPath honored, trailer, authorArgs, 120s subprocess timeout, reset-unstage on failure, session-scoped record kind `command`. No weakened copy, no new spawn site (all GitRunner), `--` guards pathspec-as-option. No secret/large-file guard existed in commit() to begin with — turn mode has none either, so nothing bypassed.
2. Undo honesty: served row hedges "/undo can take it back."; loud ABSENT_IN_PARENT refusal for add-file commits pinned (AutoCommitterTest); edit-only undo green. AutoCommittedMsg::notice()'s unconditional "/undo reverts it." is pre-existing turn-mode wording the TUI /commit row inherits — disclosed, judgment (ii) ruled ACCEPTABLE (one DTO keeps title-usage billing on both landers, no out-of-scope Chat-arm/Msg mint); closeout carry stands.
3. porcelain -z walk: rename/copy source consumed from next field and staged with destination (pinned); -z is NUL-delimited unquoted → spaces/newlines safe, core.quotepath moot. NIT (not filed): conflicted tree leaks stage-mode fields as bogus paths → `git add` refuses loudly (fail-noisy, degenerate; unpinned).
4. Draft path: draftDiff rides diff() capture (2×MAX_DIFF_BYTES), request() re-cuts at 24KiB with cut notice; subjectFrom Sanitize::untrusted; fallback deterministic; no blanket LLM timeout (E646 clean); clean-tree answers before any spawn (pinned both layers).
5. Read-only: shared `host.command.read_only` key (identical to /pr/compact/init/model); command absent from Chat::READ_ONLY_COMMANDS (default refusal — the design's "no roster edit" holds); mid-turn refusal by the generic turn gate.

## §1.8/§1.9 + per-command law
- PaletteSuperset 'commit' row order-correct: census is order-sensitive assertSame vs CommandRegistry::all(); 2850 sits before pr's 2860 (green).
- R7 pin plants `.claude/commands/commit.md` in a per-test sys_get_temp_dir repo + fake HOME (setUp verified) — real cwd untouched; built-in-answers assertion live.
- en.php: own EOF block, cmd.commit.*/host.commit.* only; ChatHostLangCatalogueTest's host.* scan passes for them.
- 2850-commit.php per-command-disjoint (own lang keys, shared category key as siblings); no paletteAction with judgement recorded in spec + census row — matches brief, no §6 ruling touched.
- Chat.php diff = new handleCommitCommand only (claim [chat:new handleCommitCommand] honored).

## Out-of-scope (weld/loop domain)
- ChatHostLangCatalogueTest::testEveryCatalogueKeyIsAskedForByChatOrHost is RED at tip — the 5 flagged keys (chat.startup.personal, chat.attach.pdf_pages, chat.effort.launch_unshaped, chat.permission.*) all pre-date this lane (grep at parent c4c13f101: present; launch_unshaped introduced by C-PRV-1.b a992413b0). Base-red, wave-loop/closeout glance owed.

## Welder carries (per return contract)
- durations.tsv row: candy-code/tests/Host/Commands/CommitHostCommandTest.php (7T); suite delta +10 methods (7 new file + 3 in AutoCommitterTest).
- Closeout note: AutoCommittedMsg::notice() over-promise (lane's finding iii).
