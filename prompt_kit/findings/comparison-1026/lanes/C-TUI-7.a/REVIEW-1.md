# C-TUI-7.a — Round 1 Review (lane e003ffa15 atop b38baf0f8)

VERDICT: FINDINGS — 1 MAJOR (step-local), 1 weld-note (cherry conflict), minors. Everything the builder claimed is true on disk; gates re-run reproduce exactly.

## Verified claims
- Branch cc/C-TUI-7.a, ONE commit e003ffa15, tree clean; author=committer Joe Huss <detain@interserver.net> (both %ae/%ce greps), zero Co-Authored trailers, zero TODO/stub.
- Diff = 14 files +834/−85, exactly the brief's declared set. Forbidden files (suite-figure.json, durations.tsv, dist) untouched. One new src type (StartupSummaryGatheredMsg, final, one-type-per-file), one new test file.
- view() purity: render() paints only cheapFacts; structural keystone (12-method source-slice, 16 forbidden tokens) is red-at-base by construction (base render() contained self::gather() and branch/skill inline reads). Confirmed against base diff. No injection seam needed — GitRunner-spy wording concern met by structural+behavioral pair. Builder's pristine-swap base-red capture credible (lane's own pins mechanically fail at base).
- gatherCmd = Cmd::promise closure; E646-clean (zero timers); reads run off the paint path. store() re-keys from settled chat.
- Frozen hub law: HandlesRunControl arm addition IS rostered — RouteTraitParityTest gains 'StartupSummaryGatheredMsg' after StatusLineTickMsg, mirroring the StatusLineResultMsg precedent. ✓ §2.
- Personal-files privacy: loadPersonal yields 0–1 entries with FIXED basenames (AGENTS.md/CLAUDE.md under ~/.candy-code / ~/.claude); compose() shows only the Lang::t('chat.startup.personal') label with name — no home path, no contents; en.php row same commit. realpath-dedup repo-first correct (realpath?:path into $seen, personal skipped when seen). No untrusted-path leakage into the transcript.
- Chat::init() always-batch change disclosed with test flips (HostedRuntimeNoticeWakeTest discriminator retired honestly); applyCommandResult re-gathers after mutate in both ClearTranscript and SwitchSession arms.
- Class docblock truth-flipped VIEW-SIDE ONLY → VIEW-SIDE LIGHT (honest: still a static process-global memo, unchanged pattern).
- Gates re-run (cwd candy-code, vendor/bin/phpunit -c phpunit.xml, env -u LINES -u COLUMNS plain pipe):
  - StartupSummary filter: `Tests: 23, Assertions: 450` OK (matches claim).
  - StartupSummaryGatherTest|RouteTraitParity|AppModelTest: `Tests: 71, Assertions: 802` OK (6+4+61).
  - Five-guard: `Tests: 133` with exactly 2 failures = ReadmeSuiteFigureDrift staleness pair ONLY (pinned 25,537 vs live 26,390 — pre-existing foreign drift + lane's +8T; re-pin is weld duty).
  - ContainedPathInventory `Tests: 49` 2F: failing rows name AgentMemory.php/SkillLoader.php/ContextHostCommand.php/TranscriptProjector.php/PathJail.php — ZERO diff lines on all five and on the guard file itself (git diff b38baf0f8..e003ffa15) → base-inherited foreign, NOT attributed. Correctly disclosed by builder.

## MAJOR (step-local) — bag keyed by full deriveKey strands cheap-only forever after mode/model toggle
StartupSummary.php:112 looks up self::$gathered[$key] and :149–150 stores under the FULL deriveKey (session\0root\0mode\0provider\0model). But every expensive fact is mode/provider/model-independent (branch=root; docs/skills/MCP=root; memoryCount=chat store+root — read in full, StartupSummary.php memoryCount body). gatherCmd has exactly two producers (Chat.php:1928 init, :11862 Clear/Switch arms); PermissionModeToggledMsg (Shift+Tab cycle — the most common interaction) or /model switch changes the key → bag miss → render paints cheap-only and NO re-gather ever fires until /clear or session switch. Base showed the full rows on such frames (inline gather on memo miss). User-visible regression on a common path; the design's "cheap rows until those facts arrive" premise breaks — they never arrive.
Fix: derive the expensive-bag key from sessionId+projectRoot only (a private deriveGatherKey(); render() lookup :112 and store() :149–150 use it; the full-key memo $cache may stay as-is). Bag then survives mode/model flips; add one pin: render full rows after withPermissionMode change without re-gather.

## Weld-note — cherry-pick conflict vs 27757dd94 (master's C-PRV-5.c) CONFIRMED
Both sides touch RouteTraitParityTest array at @@ -80,6: lane adds TelemetryRetryTickMsg (dated cure comment) + StartupSummaryGatheredMsg; master already landed the same TelemetryRetryTickMsg cure plus ModelCatalogReadyMsg at :67. Overlapping hunks, non-identical comment lines → conflict certain. Resolution (union): take master's ModelCatalogReadyMsg + master's TelemetryRetryTickMsg line; add lane's StartupSummaryGatheredMsg entry after StatusLineTickMsg. Chat.php overlaps checked: master hunks at :116/:17245–17318, lane at use-block/init/applyCommandResult → regions disjoint, expect clean. HandlesSessionSurface: lane does not touch it.

## MINOR / NIT
- MINOR (step-local): StartupSummaryGatherTest.php is a new file → scripts/parallel-tests-durations.tsv row OWED at weld (correctly absent from lane).
- NIT: faintRows cheap-only frame is unmemoized (re-composed per render until bag lands) — cheap by construction, acceptable; note only.
- NIT: serve/web context pays gather cost at init (design-mandated trigger; Cmd::promise off paint path) — owner-glance at most.

## Laws
Conventions (strict_types, final, PSR-12, Lang::t, ::new-style, comments WHY) ✓. No proc_open/fork sites added. No blanket LLM timeout. Dormant code untouched. Traceability exact. Tests non-vacuous (structural pin + behavioral settle + re-gather walks).
