# C-EXT-6.b — Hook log file + /hooks log (LATE) — lane report

Status: IN PROGRESS (commit 1 landed). Branch cc/C-EXT-6.b, worktree /home/sites/cc-cmp-C-EXT-6.b, base 288b81f11.

## Commits

- `2bf5d2f18b5b3b79bd10f68e2dcb2b465f515480` — `C-EXT-6.b: hook log file + /hooks log (LATE)` — 13 files, +2202/−14. cat-file'd same-shell (commit); author/committer %ae/%ce detain greps 1/1; zero Co-Authored trailer; tree clean at commit.

## Inherited-tree inventory (the 11 uncommitted files at resume, classified vs design block)

MODIFIED (8): candy-code/builtin-commands/1390-hooks.php (argumentHint `[log [n]]` + comment — covered); candy-code/lang/en.php (16 `cmd.hooks.*` keys incl log.*/hint/unknown — covered); src/Commands/HooksCommand.php (composeLog + LOG_COLUMNS floors + verdictLabel + logHeaders — covered); src/Hooks/HookRegistry.php (hookLog property, isBuiltInHook extracted, record per configured non-builtin run at the $spend-ledger unconditional point — covered); src/Host/Commands/HooksHostCommand.php (words routing: log→runLog with n-clamp/usage row, unknown word→cmd.hooks.unknown, tail+composeLog+unreadable suffix — covered); tests/Cli/StderrEmitterCensusTest.php (was PARTIAL — I fixed in-step: prose FIFTY/TWENTY-ONE, NUMBER_WORDS fifty, MESSAGE_SHAPES row `src/Hooks/HookLog.php=>3` at file-walk order after Subcommands, both channel-6 anchors widened to `[A-Z-]+` for hyphenated word-numbers — now covered, 95T/22901A OK); tests/Support/ReadPathCensusTest.php (`Hooks/HookLog.php|fopen` SELF_LOCATED row — covered, 19T/729A OK); tests/bootstrap.php (HookLog::pinDefaultLogFile sandbox pin after the AuditHook pin — NOT in the design file list; kept: it is the E351 AuditHook-family isolation idiom without which the suite writes real HOME; disclosed deviation).
UNTRACKED (3 of the 5 new at resume; my 2 more added below): src/Hooks/HookLog.php, src/Hooks/HookLogEntry.php (both covered — full files); tests/Hooks/HookLogTest.php (was PARTIAL with 7 failing tests — all root-caused and fixed, now 23T/97A OK).

## Design-vs-shape decisions (disclosed)

- PROMPT GLOSS suggested PrivateDir/AtomicJsonFile idioms; the DESIGN BLOCK prescribes the AuditHook pattern (bounded JSONL, flock append, one rotation generation, redactor). An append-only JSONL log cannot ride AtomicJsonFile's temp+rename writer. Design block wins; owned-home 0700/0600 discipline is preserved via directoryIsUsable guards + umask(0o077) around the append.
- Redaction precedes the byte cut (HookLogEntry::forRun: SecretRedactor → first line → mb_strcut 200). Pinned with a straddle arm (cut mid-MARKER — fragment absence is the discriminating pin) + a whole-marker arm.
- Writer fail-open: record() catches Throwable → one-shot RuntimeNoticeSink::warn (latched), verdict flow untouched.
- Symlink refusal arm bug fixed in src: the reason closure was `static fn` (no $this binding) — error inside noticeRefusalOnce after the latch spent → notice lost. Dropped `static`.

## Test fixes (all in my files; drift guard NOT edited — not in design set)

- matcher `'*'` was invalid PCRE (HookConfig wraps /…/i) → `.*`; AuditHook subscribes PostToolUse (builtin-exclusion test drives PostToolUse); RuntimeNoticeSink::reset() disarms — the 3 drain-asserting tests now arm explicitly (order-dependence cured); direct-ctor round-trip asserts WHOLE message (first-line law moved to its own forRun test); hookLogEntry helper routes through forRun (production shape); HOME-unset default-path test uses a non-existent HOME (HomeDirectory falls back to posix passwd).
- DuplicatedTestHelperDriftTest: my 3 collision rows cleared by renaming in MY files only — freshLog→reserveRacePath (genuinely different impl, no tempnam), testTheAnalyser*→testTheHookLogAnalyser*, WRITERS/RECORDS→RACE_*, FILE_APPEND_NAME/LOCK_EX_NAME→APPEND_FLAG_NAME/LOCK_FLAG_NAME, LEAK_PROBE_TOKEN→LEAK_CANARY_TOKEN.
- New: tests/Hooks/HookLogConcurrentAppendTest.php 6T/31A (real-fork LOCK_EX race + textual flags pin + 3 positive controls); tests/Host/Commands/HooksLogCommandTest.php 11T/33A (tail/limit-clamp/session-filter/usage-rows/unknown-word/bare-panel/unreadable-suffix/rotation rollback).

## Gates (targeted, cwd=candy-code -c phpunit.xml, plain pipe)

- HookLog|Concurrent|HooksLog filters: 40T/161A OK.
- hooks-adjacent `HookLogTest|HookLogConcurrentAppendTest|HooksLogCommandTest|HooksCommandTest|HooksHostCommandLiveChainTest|HookRegistryTest|AuditHook`: 209T/717A OK.
- StderrEmitterCensusTest 95T/22901A OK; ReadPathCensusTest 19T/729A OK; ReadmeRosterDriftTest 10T/78A OK.
- DuplicatedTestHelperDriftTest 14T/202A/2F — BOTH foreign not-to-cure rows (`titleBackend` Chat trio, `drift T0` Host/Tui pair; prompt lists "DuplicatedTestHelperDriftTest 2F"); zero rows name my files.

## Remaining (this session)

docs/HOOKS.md "The hook log" section; docs/COMMANDS.md regen via the command-docs generator; verify tests/Commands/HooksCommandTest needs any log-routing pin (suite green already); final guard battery re-run; follow-up commit(s).

## Foreign riders (disclosed, not cured)

MenuBarLabelDerivationTest base-red (doctor/recap rows landed by sibling lanes after my base); ReadmeSuiteFigureDrift staleness pair (forbidden file — §7 gate); durations.tsv rows owed for the 3 new *Test.php (listed for §7 gate: HookLogTest.php, HookLogConcurrentAppendTest.php, HooksLogCommandTest.php).

## CLOSE (same session, after commit 2)

- Commit 2: `14f3263052f8642a573634c6bc5dd03b7fb89974` — `C-EXT-6.b: hook log docs (HOOKS.md section, COMMANDS.md regen) + composeLog render pins` — 3 files, +100/−3. cat-file'd same-shell (commit); %ae/%ce detain greps 1/1; zero Co-Authored. Tree clean at tip; `git rev-list --count 288b81f11..HEAD` = 2.
- docs/HOOKS.md gained `## The hook log` (path + exclusion of built-ins, redact-before-cut order law, 1 MiB bound + one `.1` generation, owned-home guards + per-user temp fallback, fail-open + one notice, `/hooks log [n]` 1..200 default 20, unreadable-rows honesty). No `File.php:NNN`/`line N` anchors (arm-AI guard), no `| N bytes |` cells (arm :987).
- docs/COMMANDS.md regenerated via `php tools/gen-command-docs.php --write` (single /hooks `[log [n]]` hint row); `--check` rc=0.
- tests/Commands/HooksCommandTest.php modified per design: 4 pure composeLog pins (heading+none without rows, all-fields render, off-catalog verdict echoed verbatim, hostile field = one quoted physical row) + docblock truth-flip. (Caught my own fixture bug in-step: array-`+` union precedence silently dropped the overrides — assertions were red before the fix.)
- Final gates (targeted, plain pipe, PtyRun expect-miss never in range): consolidated `HookLogTest|HookLogConcurrentAppendTest|HooksLogCommandTest|HooksCommandTest|HooksHostCommandLiveChainTest|HookRegistryTest|AuditHook|StderrEmitterCensusTest|ReadPathCensusTest` = **327T/24365A OK 0F/0E**. Doc-guard battery (DocFigure|HookEnvDoc|ReadmeRoster|EnvRoster|TrustKey|ConfigWriteProducer|KeyBinding|ChildWallClock) = 422T/60600A OK. COMMANDS/lang family (SlashMenuTierBadge|RegistryTranslation|InertFrontmatter|CommandShellPosture) = 27T/984A OK. gen-command-docs --check rc=0.
- DuplicatedTestHelperDriftTest 14T/202A/2F — both rows foreign not-to-cure (`titleBackend` Chat trio; `drift T0` Host/Tui), zero rows name lane files. MenuBarLabelDerivationTest 1F — the foreign base-red `doctor` row (rider). SlashDispatchTest green.
- New *Test.php files for the §7 gate (durations rows owed there, NOT edited in-lane): tests/Hooks/HookLogTest.php (23T), tests/Hooks/HookLogConcurrentAppendTest.php (6T), tests/Host/Commands/HooksLogCommandTest.php (11T).
- Deviations disclosed: tests/bootstrap.php pin (E351 family-isolation idiom, not in design file list); StderrEmitterCensusTest + ReadPathCensusTest edits are the in-step guard-law consequence of new src files (census rows moved in the SAME commit as the code).
- Foreign riders NOT cured (per prompt): ReadmeSuiteFigureDrift staleness pair, DuplicatedTestHelperDrift 2F, MenuBarLabelDerivation doctor row, plus the standing list (ApplySettingsTest keepWarm, KeyHelpTest datasets, HelpTest TIER, BinCandyCodeDispatch --worktree, AttachPagingTest).

VERDICT: C-EXT-6.b COMPLETE at tip 14f326305, 2 picks, tree clean, all in-step guards green, ready for weld review.

## FINAL — CLOSED (appended at tip 14f326305)

All "Remaining" items above landed in commit 2 (14f326305: HOOKS.md +31-line "The hook log" section, COMMANDS.md regen, HooksCommandTest +70 composeLog render pins). Tree clean; no further edits needed.

Battery re-run AT TIP (targeted, cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS plain pipe):
- Own 4 classes (HookLogTest|HookLogConcurrentAppendTest|HooksLogCommandTest|HooksCommandTest): 52T/203A OK (0F/0E).
- DocFigureProseDriftTest + LangParityTest: 99T/7598A OK — the new HOOKS.md prose trips no numeral arm; the 16 new cmd.hooks.* keys pass parity.
- `php tools/gen-command-docs.php --check`: "command docs: up to date" rc=0.
- ReadPathCensusTest 19T/729A OK; StderrEmitterCensusTest 95T/22901A OK.
- Rider (disclosed, not cured): every filter reports a constant "PHPUnit Deprecations: 2" incl. classes untouched by this lane — suite/bootstrap-level, pre-existing, PHPUnit notices (not Warnings; failOnWarning clean).

DuplicatedTestHelperDriftTest at tip: 14T/202A/2F — BOTH reds are the foreign not-to-cure rows (titleBackend Chat trio, drift T0 Host/Tui pair); zero rows name my files. MenuBarLabelDerivationTest base-red = foreign sibling riders (doctor/recap rows landed after my base). suite-figure/README staleness pair = §7 gate duty.

§7 NEW test files (durations.tsv rows are gate duty; NEVER staged here):
- candy-code/tests/Hooks/HookLogTest.php
- candy-code/tests/Hooks/HookLogConcurrentAppendTest.php
- candy-code/tests/Host/Commands/HooksLogCommandTest.php

Design-block coverage: files.create 2/2, files.modify 7/7 (incl gen'd COMMANDS.md), tests 3 new + 1 modified, guards (gen-command-docs, ReadPathCensus; +consequential StderrEmitterCensus and tests/bootstrap.php pin, both disclosed above), serialized claims honored (own builtin-commands file, HOOKS.md, lang cmd.hooks.log. prefix, HookRegistry hot-file region = executeHooks/scan only). Containment: owned-home ~/.candy-code/hooks-log.jsonl (0700-created dirs, umask-0o077 append → 0600 leaf), refuse-not-repair dir policing, symlink-leaf refusal, 1 MiB rotate one generation; redaction SecretRedactor→first-line→200-byte cut pinned (straddle arm). Fail-open verified end-to-end by smoke probe. STATUS: DONE.
