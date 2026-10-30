VERDICT: PARTIAL — MAJOR-1 CURED+GREEN (commit below); MAJOR-2 STOP-REPORTED with census per brief branch (widening floods, roster cannot absorb)
## MAJOR-1 CURED (before-figures captured)
- Path erratum: reviewer cites tests/Cli/LayeredSettingsTest.php; live file is tests/Config/LayeredSettingsTest.php (only match, class identical).
- BEFORE: --filter LayeredSettingsTest -> 99T/234A/1F (assertSame literal 83 entries vs live userTierOnlyKeys() 85).
- Diff probe: literal misses promptCache.keepWarm (live idx 17, after compaction.idleAuto) + redactToolOutput (live idx 26, after permissions.autoTotalLimit); none extra. Guard needs BOTH for 0F -> adding both, attributing both.
- AFTER: 99T/405A OK (deprecations pre-existing pair).

## MAJOR-2 STOP-REPORT (census, not half-cured)
- Path erratum: reviewer cites tests/Cli/... ; live file is tests/Chat/ChatHostLangCatalogueTest.php (line 90 matches).
- BEFORE: class 3T/9A/1F — 20 stranded catalogue keys.
- Prescribed widening applied live (sources() += src/Commands/EffortDial.php + src/Renderer.php, W27 attribution comment): absorbs 7 EffortDial keys + chat.incognito.status (Renderer) = 20->12, but the class goes 1F->2F:
  1. NEW flood failure in testEveryCallPassesExactlyThePlaceholdersItsEntrySpells: Commands/EffortDial.php:110 passes [model, provider] but chat.effort.unsupported (lang/en.php:2282) spells {provider} only. Cure needs src-side or lang-side edit — outside "minimal test-side fixes" and outside this fixer's ownership (C-WEB-2.f account).
  2. 12 stranded remain, none asked in the two named files (grep-located askers): chat.theme.file_malformed/missing/notes/refused x4 -> Theme.php:251 (C-TUI-4.a 83353667a); chat.memory.recalled_notice.one/many x2 -> Diagnostics/RuntimeNoticeSink.php:504 (C-CTX-6.e/c 9c59bc8d1/16266186d); chat.startup.personal -> Support/StartupSummary.php; chat.attach.pdf_pages -> Attachments/FileMentions.php; chat.permission.not_persisted/persisted -> Permissions/SavedRules.php; chat.effort.launch_unshaped + chat.serviceTier.launch_unshaped -> Cli/LaunchContext.php (the 6-key pre-rostered foreign baseline + wave-moved legs).
- The guard has NO key-level allowance roster; only the sources() file list. Reaching 0F would mean widening by 6 more files (foreign-lane adjudication territory, review itself assigns theme/recalled legs to those lanes' accounts + §7 ledger) AND still could not absorb failure (1) test-side.
- Brief STOP-REPORT branch therefore invoked; widening REVERTED (working tree back to master blob); commit contains MAJOR-1 only.
- Lead options: (a) adjudicate full 8-file widening + EffortDial params cure as its own fixer; (b) §7 rider route: MAJOR-2 re-derive at closeout with C-TUI-4.a/C-CTX-6 legs; (c) keep the 2-file widening with src-side cure minted to C-WEB-2 follow-up.
