VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR (confirmed cures + §7 carries recorded, non-blocking). W29 = C-SES-8.b · C-TUI-3.d · C-TUI-4.a, re-reviewed merged against master c013d930d (tip verified same-shell).

## Cure verification (r1 FINDINGS → now closed, landed-blob proof)
- MAJOR-1: `--filter UsageRecordsTest` on master → **Tests: 27, Assertions: 145, OK** (r1 was 141A/1F). Pin at UsageRecordsTest.php:633 now `count => 3` "compact, turn, warm" — concordant with SessionHost blob: :887 'compact', :1130 'warm', :2667 'turn' (the C-CTX-5.d carry).
- MINOR-1: `git show master:candy-code/src/Chat.php` :7965-7972 — comment truth-flipped ("Theme::byName() has no failure path since C-TUI-4.a … The catch stays as dormant defense in depth — dormant code is kept, never deleted — not a live route"). Catches stand per dormant-code law. Cure commit c013d930d: %ae/%ce both detain (separate fields, verified), zero trailers, +0T (count bump inside existing method + comment + FIX-m1.md report).
- 2 PHPUnit Deprecations ride EVERY filter run unchanged (27-run, 67-run, 50-run, 114-run, 301-run, 4-run) — pre-existing global shape, not wave-borne.

## Ancestry (re-derived, same-shell)
14 wave SHAs (13 commits: picks 9c784d441/e9852993a/4e896dd88, c9cee706c evid; e6e701c82/77199e554/f11a0b019, fc608096f evid; 83353667a/f911db824/e385af7c2/30fde46c0, 51bdf5d13 evid) + cure c013d930d — cat-file-exist + `merge-base --is-ancestor … master` = YES 14/14. Authors: 14/14 `detain@interserver.net|detain@interserver.net`. Trailers: zero Co-Authored/Signed-off tree-wide for the set; 7 picks carry `(cherry picked from …)` provenance per the disclosed 1030U/V/-x weld precedent recorded in the §4 marks — accepted convention, not a finding.

## Claims vs landing (3/3 marks anchored + re-traced on master blobs)
- C-SES-8.b (mark :3446 ✅ 4e896dd88): migration cols :752-753 + PRAGMA-gated ALTERs :766-770; `appendUsageRecord(..., ?string $source = null)` :2535; GROUP BY source aggregation :2751; live label set complete — Chat.php :2904 'goal' / :3887 'superseded' / :19825 'compact' (mark cited :19823, benign drift) / :21161 accountUsage → :21163 account(sink,$model,$source) / :21209 store write; trait HandlesTurnCompletion :191 turn, :430/:450/:462/:550 aux, :473/:493 compact, :572 title; UsageCommand.php 36 source|advice hits (the `/usage` leg live). Evidence erratum re-proved: c9cee706c blob REPORT.md :11 reads "description/usage prose" — 'title' word gone, rider applied.
- C-TUI-3.d (mark :3458 ✅ f11a0b019): HandlesKeys.php :411-414 negative-stamp idle two-beat → `Cmd::send(new OpenMessagePickerMsg())`, :417 idle arm; App.php :3318 `OpenMessagePickerMsg => dialMessagePicker()`; registry :1107 chat.message-picker + :1516-1519 msgpicker.* four actions + :200 context label. §6 matrix row (line 4191 area) "Idle Esc Esc opens it, as in Claude Code" — landed behavior matches the ruling; in-flight Esc Esc stays chat.cancel (:425-449 positive-stamp path intact).
- C-TUI-4.a (mark :3471 ✅ 30fde46c0): Theme.php `names()` body = `array_values(array_unique([...NAMES, ...ThemeFile::names()]))` (dynamic union confirmed on blob); byName :191 → customFileTheme :206-211 → ThemeFile::resolve; ThemeFile.php lives on HomeDirectory::owned() only, SUBDIRECTORY '.candy-code/themes' :39; ThemeNameValidator class exists on master + wired InterfaceSettings.php:64 `withValidators(ThemeNameValidator::new())`; OptionsProvider untouched — intent satisfied via the now-dynamic names() (r1 NIT stands, design-cite drift only).

## Merged-tree gates (cwd=candy-code -c phpunit.xml, plain pipe, exact-class filters)
- SES-8.b family `UsageCommandTest|UsageSubcommandTest|UsageModelThreadingTest|SpendLedgerTest|LangParityTest` → **67T/3603A OK**.
- TUI-3.d+4.a `MessagePickerWiringTest|EscapeSoftCancelTest|ThemeTest|ThemeFileTest|PaletteThemePickerTest` → **50T/265A OK** (byte-matches r1).
- Guards: `StderrEmitterCensusTest|ReadPathCensusTest` → **114T/23481A OK**; `KeyBindingDriftTest|KeyBindingRegistryTest|ReadmeRosterDriftTest|CommandDocsDriftTest|DuplicatedTestHelperDriftTest` → **301T/3632A OK** (168/168 registry cure shape holds merged).
- Generators: gen-command-docs --check rc=0 · gen-settings-doc --check rc=0 · gen-tool-docs --check rc=0 · scripts/gen-protocol-schema --check rc=0.
- Web leg: 0 touches to candy-code-web/dist/generated.ts/protocol schema across all 14 wave commits (name-only union grep) — vitest N/A, no regen owed.
- Laws: src+lang range scan 547c9b1e0~1..c013d930d added-lines grep proc_open|pcntl_fork|stream_select|sleep(|usleep = **0** — fork/child discipline untouched, E646 clean (no timers minted).
- DUR rows on master tsv: line 138 UsageCommandTest 2.745/21, 155 MessagePickerWiringTest 2.402/11, 767 EscapeSoftCancelTest 0.093/15, 1176 ThemeFileTest 0.034/9 — wave-minted files all rostered.

## Carries (§7 duties — recorded, NOT wave-blocking per standing instruction)
- ReadmeSuiteFigureDriftTest at tip: **4T/26A/2F** = the suite-figure/README staleness pair (W29 moved +14T/+17T/+14T with zero suite-figure.json touches in any wave commit — closeout re-pin; cure adds +0T).
- ThemeFileTest DUR figure 9.903 (minted 51bdf5d13) → 0.034 (current row) via later-era re-derivation, case count 9 preserved — figure-trust note for closeout.
- `/tmp/opencode/weld1030L/base-check` prunable detached worktree = weld 1030L (other wave), not W29 hygiene.

## Hygiene
`git branch --list 'cc/*'`: C-SES-8.g, C-TOOL-6.a, C-TUI-5.a, C-TUI-5.e, C-WEB-2.a — no W29 lane branch survives; worktree list likewise (main + those five + the 1030L scratch). candy-code tree clean (`git status --porcelain -- candy-code` empty). No weld-only file (suite-figure/durations/dist) inside any lane pick; DUR/mark rows rode welder commits.
