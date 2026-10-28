# WAVE W12 — Round-1 Review (w12-rev)

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR wave findings. NITs and out-of-wave observations below.
Reviewed at master tip 5ed26cf92 (mega-welder mid C-EXT-4.a; W13/W14 arrivals excluded per brief).

## 1. Hash verification — 10/10 PASS
Every W12 §4 hash: `git cat-file -t commit` OK, `merge-base --is-ancestor <h> master` OK,
author AND committer email detain@interserver.net, zero Co-Authored/Co-developed trailers.

| step | hash | subject (abridged) |
|---|---|---|
| C-MCP-2.b | fad3d931e | MCP prompts/commands as launch-frozen command rows |
| C-SEC-5.d | a93d124b6 | trust dialog TUI surface |
| C-TOOL-7.e | 9070bd57e | malformed tool-call resample, count-bounded |
| C-TUI-1.a | d88f83e87 | composer vim text-objects + counts |
| C-TUI-7.a | 03280fdea (+impl 97113b210, weld c3dfec2a0) | startup summary — personal files + gather off view() |
| C-TUI-7.b | ea1f22bf7 | mac alt notice probe/stamp |
| C-WEB-3.f | bf42820d8 | web video submit (has the legitimate `git cherry -x` pick trailer) |
| C-WEB-7.a | 0e2a4e08e | shell tail trailing-edge flush |
| C-WEB-7.b | c69f190b1 | @ debounce, cross-tab history clear, palette action, re-announced questions |
| C-TOOL-5.b | 3ecf83d95 | formatAuto default formatter table |

The 'claude' grep hit in 9070bd57e is body prose ("mirroring Claude Code"), not a trailer.

## 2. Cross-step coherence on merged tree — PASS
- `lang/en.php`: zero duplicate keys (top-level + level-2 uniq -d empty). W12 additions disjoint:
  `tui.trust.*` (SEC-5.d), `settings.vimMode.help` re-word (TUI-1.a), `chat.startup.personal` (TUI-7.a),
  `cli.launch.mac_alt_notice` (TUI-7.b), `settings.formatAuto.*` (TOOL-5.b).
- `src/Cli/Bootstrap.php` (two W12 lanes): both hunks live at tip — TOOL-5.b trust-fold arm :6058,
  TUI-7.b `MacAltNotice::belongs` :10503/:10511. Union clean.
- `SessionHost.php` only MCP-2.b (+13 `customCommands()` read accessor — single-source map for
  command.list/exec vs submit(), no trust change). `Tui/Renderer.php` only SEC-5.d.
  `HandlesRunControl.php` only TUI-7.a (new `StartupSummaryGatheredMsg` branch returning [this, null] —
  provably off the failing settle path, see §5b). `Chat.php` body only TUI-7.a (init/applyCommandResult
  gather arms).
- Protocol trio at tip: `php scripts/gen-protocol-schema.php --check` rc0; `npm run gen:protocol -- --check` rc0;
  vitest `generated.spec.ts` 3/3; PHP guards `ProtocolSchemaDrift|ServerProtocolDocumentationDrift` OK 12T/332A.
- dist consistency: `dist/index.html` → `assets/index-aTWRuQiE.js` + `index-u7pv6rRs.css` both exist in dist/assets.
  WEB-7.b markers (`Clear prompt history`, `clear-history`) present in the tip bundle — the later welds
  63f2de5d9/5a45aa4c0 regenerated dist AFTER bf42820d8/c69f190b1 landed, so tip is coherent even though
  the two src-web commits deferred their dist rebuild (NIT, see §6). Full dist-truth stays §7 closeout.

## 3. Battery (plain pipe, env -u LINES -u COLUMNS, cwd candy-code, -c phpunit.xml)
- `DocFigureProseDrift|ReadmeRosterDrift|EnvRosterDrift|TrustKeyDrift|KeyBindingDrift|LangParity|GlobDialectDifferential`: 380T/47165A/1F —
  sole red = `thirty-seven` word-map miss from W13 C-AGT-5.a (5cd68af7e), NOT W12.
- `StderrEmitterCensusTest|TreeWideGuardRosterTest|ReadPathCensusTest`: 131T/25074A/2F — both =
  LspInstallerTest scandir walker added only by W13 C-TOOL-5.c (14d37bbf5), NOT W12.
- Targeted per-step PHP: 297T/13710A OK (MCP-2.b + TOOL-7.e + SEC-5.d + TUI-7.b + TOOL-5.b families),
  214T/7354A OK (TUI-7.a + TUI-1.a), 20T OK (WEB-3.f GenerateVideoSubmit + WEB-7.a), all green (2 pre-existing PHPUnit deprecations).
- vitest (serial): W12's 6 web spec files (topbarGenerate, generatePanel, composerFilesImages,
  composerHistory, paletteClearHistory, questions) = 6 files / 47 tests passed.
- LIMITATION: `php tools/gen-docs.php --check`, `php tools/gen-ansi-readme.php --check`,
  `tools/check-path-repos.php`, `tools/check-child-lifetimes.php`, and `php -r` forms are DENIED by this
  session's permission map — unrunnable here; the PHP-side guard families above cover the doc-drift genres
  they overlap. §7 closeout must run the four tools.

## 4. durations.tsv — PASS
All 13 new W12 PHP test files have exactly one row: CommandMethodsMcpPromptsTest, McpMethodsTest,
LaunchTrustDialogTest, ComposerVimTextObjectTest, ComposerVimCountTest, StartupSummaryGatherTest,
MacAltNoticeStampTest, GenerateVideoSubmitTest, BashToolProgressTest, ShellTailRingTest,
FormatAutoTableTest, FormatRunnerTest, PostEditFormatHookTest.

## 5. New-issue hunt — zero W12 regressions
(a) E646: C-TOOL-7.e resample is COUNT-bounded (MAX_MALFORMED_RESAMPLES=2, Runtime.php:281/:1930), no timers,
    notice rides RuntimeNoticeSink::warn with a census-pinned format; fad3d931e timer hits are test-side loop
    drivers only; FormatRunner::withTimeout is pre-existing machinery (carried, not introduced);
    c69f190b1 `mentionTimer` is a browser-side debounce (UI, not an LLM timeout). Zero new proc_open in W12;
    no fork/child roster obligations minted.
(b) OUT-OF-WAVE — 5 ChatTest::testASettledTurn.../suggestion pins are red at tip (tests/ChatTest.php:4219
    via :4268/:4285 and assertNull :4305/:4317/:4325): the settle batch gained a non-null member in the
    Oct-27 CTX band. Blame on every involved behavior line is ≤ 967479a37 (P-CTX.d, **W5**),
    020772dca (C-CTX-2.b), 14118b17d (C-CTX-2.e, **W10**) — all pre-positions of every W12 commit; W12's only
    near-miss (97113b210) adds a self-contained Msg branch returning null. NOT a W12 finding; flag to the
    W13 band owner — these 5 are absent from the standing-red list and likely belong with the EXT-4.a band.
(c) No containment/SSRF surface: MCP-2.b exposes an existing frozen map read-only; WEB-3.f reuses the
    established media submit channel; prompt-cache byte-stability untouched (TUI-7.a gather is paint-side only).

## 6. NITs (dispositions)
- N1: plan §4 tag for C-TUI-7.b says `cli.notice.mac_alt`; landed key is `cli.launch.mac_alt_notice`
  (wired Bootstrap.php:10503-10511, tests green). Tag/key-path prose mismatch only — owner fold at §7 ledger.
- N2: bf42820d8 and c69f190b1 shipped src-web changes without in-commit dist rebuild; healed one-step-later
  by weld regens 63f2de5d9/5a45aa4c0. Tip coherent — recorded, no action.

## 7. Weld hygiene
No W12 lane worktree or `cc/C-*` branch survives (all remaining cc-cmp worktrees are live W13/W14 lanes:
EXT-4.a, AGT-4.a, MCP-3.a, PRV-2.a, TOOL-4.a, WEB-7.c/d, TABS.e/g…). Generated protocol trio regenerated,
not hand-edited (all three --check guards green). Authors 10/10 as §1.
