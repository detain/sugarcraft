# C-TOOL-3.b — TUI paints the live shell tail — LANDED

Commit: 28609c078 on cc/C-TOOL-3.b (base master b985fbe51). Author/committer both
detain@interserver.net (verified %ae/%ce separately), zero Co-Authored trailers. Never pushed.

## What landed
- src/Chat.php (declared regions only): ToolProgress arms in applyBackendToolEvent
  (settled queue) and pumpLiveToolEvents (live pump) now write
  ToolTailSlots::set(toolCallId, tail, truncated, seq) beside the existing recordEvent;
  both stale comments ("paints no live tail this wave") rewritten to the wired truth.
  replaceToolRunningPlaceholder clears the tail slot beside PreviewSlots::clear, with the
  W2.4 leak-law comment extended to cover it.
- src/Renderer.php: renderPendingToolCall appends the new static tailLines(callId, theme,
  width) block after previewLines — newest TAIL_LINES=6 lines, each run through the frame's
  existing sanitizer chain self::untrusted() (= Sanitize::stripZoneSentinels +
  untrustedForDisplay: ANSI/C0 stripped, CR→LF breaks, bidi marked <U+XXXX>, sentinels
  stripped), dim-styled, clipWithEllipsis cell-clipped, one row per line, '  ' indent
  (preview-caption convention). Overflow announced above the shown lines via new lang key.
  Frames ending on a newline own no blank row. Empty slot → '' (byte-identical row —
  goldens unaffected: slot never armed in any golden flow; tests/Tui/{MediaForm,Menu}
  golden filter re-run green, zero regeneration needed).
- lang/en.php: 'tui.tool.tail_more' => '… {count} earlier lines' (after tui.tool.running,
  alphabetical block). Design specifies one key; singular reads "… 1 earlier lines" — per spec.
- src/Tui/ToolTailSlots.php (disclosed deviation, comment-only hunks): flipped the dep's
  own "NOT YET WIRED" class header + two "C-TOOL-3.b will wire" forward-refs to present
  tense, same commit. No guard pins those phrases (grepped; only hits were this file + the
  Chat pump comment, both rewritten in-step).

## Regression law
New tests red before fix / green after, proven by stash-swap of the four src/lang files:
pre-fix run 17 tests → 9 errors + 5 failures; post-fix 17/17 OK (64 assertions).

## Gates (all cwd=worktree candy-code, plain pipe, env -u LINES -u COLUMNS)
- --filter 'RunningToolTailTest|ToolTailFoldTest': 17T/64A OK (2 pre-existing Deprecations,
  baseline had same 2).
- --filter 'ToolProgress|MediaProgress|PreviewSlot|ToolTail|LangParity|GlobDialect|Placeholder|Pending|DocFigureProseDrift'
  post-commit: 372T/8269A 0F.
- Baseline pre-change --filter 'ToolProgress': 30T/172A OK — WEB-4 fold tests unregressed
  (recordEvent preserved; pins stay green).

## Pre-existing base reds (NOT mine; proven by re-running at pristine base with my files
hidden; left for their owning fleet)
- StderrEmitterCensusTest 3F: NonInteractive 14→15, ArgvParser 35→36 roster drift,
  BinCandyCodeAutoloadGuardTest 33-vs-34 fwrite count.
- TreeWideGuardRosterTest 2F: offenders Cli/JsonSchemaEnvelopeTest + Context/MemoryRecallBlockTest
  (foreign test files).

## HARNESS INCIDENT (disclosed)
During a stash push/pop verification pair a concurrent `git reset --hard HEAD` (reflog-
proven, message absent here) landed in this worktree mid-lane, wiping my popped tracked
changes and applying foreign edits (src/Cli/NonInteractive.php, tests/Cli/StreamJsonEmitterTest.php,
tests/Cli/EncodeDocumentEscapingTest.php — a different fleet's WIP). Recovered via
`git stash apply f21cbf11d` (dropped-stash object found with git fsck), re-verified gates,
committed immediately. Foreign files remain unstaged in the working tree; commit contains
exactly my six paths. Lesson: commit EARLY; stash pop in a shared object DB is race-prone.

## New test files (gate owes durations.tsv rows)
- candy-code/tests/Renderer/RunningToolTailTest.php (10T)
- candy-code/tests/Chat/ToolTailFoldTest.php (7T)
