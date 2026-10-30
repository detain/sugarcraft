VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR / 0 NIT. Weld-ready.
Reviewer: fresh round-1, read-only. Worktree /home/sites/cc-cmp-C-WEB-2.f @ 5ea346d6b (2 commits on cut base 5f8717874). NOTE: written in the MAIN TREE — the lane worktree's write map refused this path; the welder copies this file onto the branch.

## Scope confirmed
git diff master...HEAD = exactly 8 files, 743+/66−:
- NEW candy-code/src/Commands/EffortDial.php
- NEW candy-code/src/Host/Commands/EffortHostCommand.php
- NEW candy-code/tests/Commands/EffortDialTest.php (13 methods)
- NEW candy-code/tests/Host/Commands/EffortHostCommandTest.php (10 methods)
- MOD candy-code/src/Chat.php (handleEffortCommand → 4-line delegation; hunk confined to that method — W27 serialization-region claim honored)
- MOD candy-code/builtin-commands/0350-effort.php (+->withHostCommand(...))
- MOD candy-code/tests/Config/Settings/SettingsWriterCensusTest.php (roster row + header docblock)
- MOD candy-code/docs/SETTINGS.md (hand prose AFTER the `<!-- settings:end -->` marker — outside the generated region, verified by marker position)
Authors: both commits %ae/%ce = detain@interserver.net, zero Co-Authored trailers.

## 1. Verbatim-lift audit — PASS
Dial decision logic vs base blob `git show 5f8717874:candy-code/src/Chat.php` (region saved /tmp/opencode/C-WEB-2.f-base-Chat.php): identical branch ORDER (engine → provider FallbackProvider::primary unwrap → 'this backend' fallback → EffortGate::verdict BEFORE branches; args==[] → status/status_no_backend; count>1 usage BEFORE token parse; strtolower(trim); auto|clear BEFORE Effort::tryFrom; usage on null level; !$shapes refusal with ShapesReasoningEffort two-key pick; set path), identical Lang keys, identical row text, persist-before-withReasoningEffort-overlay ORDER preserved, auto/clear with null engine → delete lands + no backend change (terminal/host parity). Terminal Chat wraps: [$row,$next]=EffortDial::decide(...); [$chat,$cmd]=effortRow($row); next===null?$chat:$chat->withBackend($next). Sole drift: refusal branch passes BOTH {provider,model} to both keys; `chat.effort.unsupported` carries no {model} placeholder but T::interpolate is strtr (sugar-core/src/I18n/T.php) → extra key ignored → byte-identical output. Corroborated: pre-existing EffortCommandTest NOT in diff and drives the REAL Chat::update(KeyMsg Enter) route (reflection withInputBuf, then update) — 13T/83A green UNMODIFIED = the byte-shape proof (not a stubbed copy).

## 2. Terminal↔served parity — PASS
Same decisions and wording; host surface: CommandText::words (file byte-unchanged), readOnly refusal ONLY when $words!==[] via existing host.command.read_only, delegates to the same EffortDial::decide, applies through the EXISTING CommandEffect::setModel($engine->provider()->name(), $engine->model(), $next) — no new effect kind, no duplicated apply logic (FastHostCommand shape). runsIn is DERIVED: src/Protocol/Methods/CommandMethods.php:125 reads hostCommand!==null; zero protocol src touched by the lane.

## 3. §1.8 census — PASS
SettingsWriterCensusTest derives callSites mechanically via token walk (callSites('write','->') over src, key '<relpath>::<method>' => count, ksort + bidirectional assertSame) — the row 'Host/Commands/EffortHostCommand.php::persist->write' => 2 is a derived count, not hand-typed (persist() has exactly 2 ->write( token sites). Row removal reddens via the array diff (line-reasoned; read-only review, no mutation run). Header docblock enumeration moved in-step. TrustKey / ConfigWriteProducer / EnvRoster / ReadmeRoster families: §1.8 guard batch green → nothing else owed a move.

## 4. No scope widening — PASS
Zero lang keys added (chat.effort.* reused). Zero getenv/$_ENV in diff +lines → Help.php/lang-en help-screen roster + EnvRosterDrift genuinely N/A. No new settings key (reasoningEffort pre-existing; SettingsSchemaDocDriftTest green). Zero candy-code-web files (grep 0) → vitest N/A.

## 5. The numeral — PASS
docs/ARCHITECTURE.md not in diff; DocFigureProseDriftTest.php:715-718 live-counts CommandRegistry::slashCommands() and is green at the lane tree → 64 stays correct (lane adds NO slash command). welder: /copy sibling owns the 64→65 bump in ITS commit. Demand LANDED-BLOB proof (`git show <sha>:<path>`) for that line post-weld — dirty-tree gates have lied twice this run.

## 6. Disclosed deviations — JUDGED ACCEPTABLE
(a) CommandText::words keeps quotes → quoted 'high' yields a usage row on the SERVED surface only; pre-existing sibling-wide shape (CommandText.php unchanged at base, FastHostCommand identical); terminal path uses the parser's unquoted tokens → terminal behaviour untouched. Inherited asymmetry, correctly disclosed, not lane-fixable in-step.
(b) Extra {model} param on the refusal key: strtr-ignored, byte-identical — verified above and pinned by the unmodified terminal refusal test.

## 7. Untrusted input — PASS
Only Effort enum values (tryFrom-gated) or null (delete-on-auto) reach the persistence door; refusal/read/usage branches never open it (dial pins $seen===[] ×5 + host content-compare pin). Unknown model/effort cannot poison settings.

## Gates reproduced (plain pipe, cwd candy-code in lane worktree, env -u LINES -u COLUMNS)
- php -l on all 7 touched PHP files: OK
- combined core filter: 50T / 5729A OK (2 deprecations = foreign, below)
- protocol+host conformance: 99T / 848A OK
- doc guards (DocFigure|Glob|LangParity|ReadmeRoster|SettingsSchemaDoc): 141T / 8057A OK
- §1.8 batch (ConfigWriteProducer|TrustKey|ReadmeSettingsTier|EnvRoster|LayeredSettings): 169T / 41319A OK
- PtyRunToolTest expect-miss case excluded per instruction.
Gate noise spot-check: the 2 deprecations are tests/Config/AccessorDeprecationTest.php private-static providers (eraPairs :57, runtimeEraPairs :124) — file untouched by the lane (diff grep 0) → truly foreign.
Extra guard batch (TreeWideGuardRoster|DuplicatedTestHelperDrift|OneSidedHomeSandbox|SuiteSkipRoster): 64T/4183A, 4 FAILURES — ALL FOREIGN, none names a lane file: DuplicatedTestHelperDrift HEAVY_CHARS (standing, f4e747cf0); OneSidedHomeSandbox row Tools/SendMessageCrossSessionTest.php (landed via master 126a557e6, C-AGT-4.e — NEW standing red, welder roster cure); TreeWideGuardRoster x2 Cli/NonInteractiveSessionPersistenceTest.php (master 417ab470a, C-SES-7.c) + standing Cli/ServeArgsTest.php scandir pair.

## Could-not-run (permission map denies `php tools/*.php`; 3 forms tried) — line/blob-reasoned instead
gen-protocol-schema --check / gen-command-docs --check / gen-settings-doc --check not executable here. Diagnosis of the trio item CONFIRMED structurally: foreign 815ba17a0 (C-TUI-2.g, ancestor of this cut base) edited src/Protocol/Schema/MethodSchemas.php (+14) and FilesMethods.php WITHOUT regenerating docs/protocol/*.json, docs/SERVER.md, or candy-code-web generated.ts (name-grep 0) → the schema check is stale-at-base; the lane diff touches ZERO protocol src → zero lane-attributable schema drift. PAIR regen (`gen-protocol-schema.php --write` + `npm run gen:protocol` + dist rebuild, ONE commit) is owed for the FOREIGN checkpoint drift, NOT this lane's content. Equivalent doc-generator checks ARE green via their guard tests: SettingsSchemaDocDriftTest (141T batch) and CommandDocsDriftTest + CommandsTableTakesColumnDriftTest (99T batch). vue-tsc not re-run: zero web files in diff → builder's counts (7 app-scope / 37 cold --build, all foreign, baseline grew past ~21 since C-TUI-2.g) are structurally non-lane; zero lane-attributable TS errors accepted on that basis.

## Weld carries
1. durations.tsv rows for the 2 NEW *Test.php files: tests/Commands/EffortDialTest.php (13T), tests/Host/Commands/EffortHostCommandTest.php (10T).
2. Suite figure: +23T (13+10, both files all-new); re-pin README+suite-figure.json at closeout per standing cadence (staleness pair = standing red until then).
3. PAIR protocol regen: owed for FOREIGN 815ba17a0 checkpoint drift (25+/3− regen incl ZERO effort/runsIn entries) — NOT for this lane; builder verified-then-restored without committing, correct handling.
4. Foreign roster cures riding nearby welds: OneSidedHomeSandbox SendMessageCrossSessionTest row; TreeWideGuardRoster NonInteractiveSessionPersistenceTest row; ServeArgsTest standing pair.
5. ARCHITECTURE 64-numeral: landed-blob proof after weld; /copy lane owns 65.
