# C-EXT-5.c — REVIEW-1 (round 1, fresh attempt-3 reviewer)

Lane cc/C-EXT-5.c, base 86f80fef8, tip d69c9a430 (1 commit, clean tree; %ae/%ce both detain@interserver.net, zero trailers).
Reviewed against BRIEF.md / design-ext.md §C-EXT-5.c, plan §1/§2/§6.

VERDICT: FINDINGS — 0C / 0M / 1 step-local MINOR (+ NITs, weld carries).

## Contract checks (all pass)
- Validator `command:<name>` branch: COMMAND_NAME_PATTERN byte-identical to CommandSpec::NAME_PATTERN (KeyBindingsValidator.php:50 vs CommandSpec.php:64); equality pinned by reflection (KeyBindingCommandBindingTest). Null-unbind and chord rules preserved.
- Registry: commandFor()/commandBindings() added; `$commandMemo` cleared in resetDerived() and covered automatically by KeysReloadTest's reflection *Memo census (verified :110-124). Hot-path empty-map short-circuit pinned without memo touch. First-winner `??=` pinned. parseChord/pressId share one alphabet — chord normalisation correct.
- App::handleKey(): consult sits at the very top, above honourKeybindingAliases() and every modal gate; lands on Chat::runCommand('/'.$name) — the REAL typed dispatch (withInputBuf→submit), menu-bar tail idiom (withChat/autoEnablePanes/rekeyDeck/scopeForegroundCmd). Traced Chat.php:10854: in-flight refusal + draft preservation + unknown notice all ride the typed path; permission gates are enforced inside command handlers exactly as for typing — NO bypass. Ordering pinned by testTheCommandClaimsTheChordBeforeTheAliasFunnel (reddens if moved below funnel).
- Renderer.php: addition inside renderKeyHelp (:7238 — declared in files.modify). No 'Command Flags'/sibling-region text anywhere in tree; nothing foreign disturbed. Block renders zero rows when no bindings (base-fingerprint identity proven by builder, accepted).
- SETTINGS.md :307 row byte-identical to InterfaceSettings withReadBy prose; new command:<name> section; lang/en.php keys.command.heading/keys.command.runs (candy-code ships en-only — no parity guard to move).
- Reload pin drives real Bootstrap::reloadKeybindings() with memo-staleness discriminator.
- Scope: all 11 files exactly inside the brief's declared set; no stubs/TODOs.

## Gates (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe, lane tip)
- --filter 'KeyBindingCommandBinding|CommandKeybindingDispatch|KeysReload|KeybindingCollisionNotice|KeyBindingDrift': Tests: 262, Assertions: 1995, Failures: 0 (2 pre-baseline PHPUnit deprecations). Builder claim EXACT.
- --filter 'DocFigure|ReadmeRosterDrift|TreeWideGuardRoster|GlobDialect': Tests: 133, Assertions: 8251, 0F.
- --filter 'KeyHelp': 64T/96540A/5F — same 5 named tests as disclosed foreign fingerprint. NOT attributed.
- --filter 'Settings': 906T/16388A/7F — matches disclosed foreign fingerprint. NOT attributed.
- php -l: all 10 PHP files clean.
- gen-settings-doc --check could NOT be re-run (reviewer permission map denies `php <script>`); static substitute: generated row is byte-identical to its readBy source and builder reported rc0.
- NEW MASTER cross-check (master==44ff31314): lane diff touches no Chat/Palette file; only shared upstream file is lang/en.php (palette/skills hunks, disjoint prefixes, keys.command.* lands in keys region); `git merge-tree --write-tree master d69c9a430` → clean tree 5bb1e8c14, zero conflicts; --filter 'Palette' on master: 229T/4175A 0F (5.b weld figure exact).

## Findings
1. MINOR (step-local): display leg unpinned — no test asserts renderKeyHelp output contains keys.command.heading / the spelled chord / the 'Runs /name' row (grep of all 4 lane test files: zero references); deleting the Renderer block keeps all 262 lane tests green. Fix: add one KeyHelpTest method (or reflection call of renderKeyHelp with overrides registered) asserting the heading + chord-as-spelled + description row, plus the no-bindings case adds nothing.
2. NIT: KeyBindingRegistry.php:388 `$commandMemo` docblock lacks the `@var array<string,string>|null` line its five sibling memo properties carry; wording describes the derivation (which lives in commandMap) — align to sibling style.
3. NIT: contract's cited 'single source of truth' overstatement (KeyBindingsValidator.php:625) is absent at this tip — current 'MIRRORS … pinned equal by test' prose is truthful. Considered cured/never-landed; no action.

## Weld carries
- durations.tsv rows: tests/App/CommandKeybindingDispatchTest.php, tests/Commands/KeyBindingCommandBindingTest.php.
- suite-figure/README re-pin (staleness pair: pinned 25,537 vs live ~28.1k; lane adds 20 methods).
- Re-run lane filter on the picked tree per §2 weld step 3 (merge-tree already clean).
- sibling-locale carry N/A (candy-code lang is en-only).
- SETTINGS.md :307 rides the row source; run gen-settings-doc --check at weld anyway.
