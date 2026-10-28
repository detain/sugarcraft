# REVIEW-1 — C-TOOL-3.b (28609c078 on cc/C-TOOL-3.b, base b985fbe51)

VERDICT: CLEAN (0 critical / 0 major; 1 minor + 2 nit, all step-local cosmetic/docblock).

## Verified
1. REGION DISCIPLINE — Chat.php has exactly 3 hunks; new-file ranges 3742-3757 (inside
   applyBackendToolEvent 3657-3781), 4274-4288 (inside pumpLiveToolEvents 4048-4324),
   4394-4404 (inside replaceToolRunningPlaceholder 4393-4425). Nothing near the status-report
   region; base..master weld diff there (C-SES-9 docblock cure, :12280-12288) does not intersect
   any lane hunk → pick collision impossible. Renderer/lang/ToolTailSlots edits all inside claims.
2. SANITIZE/CLIP — "frame-sanitized" concretely = self::untrusted() =
   Sanitize::stripZoneSentinels(Sanitize::untrustedForDisplay()): Ansi::strip kills CSI/OSC-8/
   DCS/APC + 8-bit C1, C0+DEL sweep, CR→LF break mapping, invisible bidi marked <U+XXXX>.
   Cells via clipWithEllipsis (Width::string/truncate). Tail rows carry no mouse zones — zone
   widths untouched. Empty slot → '' (byte-identical row).
3. CLEAR-ON-SETTLE — per-id ToolTailSlots::clear beside PreviewSlots::clear. Mutation-reason
   (drop only the clear line at :4401): ToolTailFoldTest::testTheSettleChainClearsTheSlotWhenThe
   CallFinishes AND testTheFinishedHalfClearsOnlyItsOwnCallSlot both red. Stale bleed impossible
   across calls (id-keyed peek + seq guard + MAX_SLOTS=8).
4. LANG PARITY — candy-code/lang ships en.php only; LangParityTest derives locales from the
   directory, and the src↔en key-exists leg covers the new key. Gate green honestly.
5. CAPTION MATH — earlier = count-TAIL_LINES(6), slice keeps newest 6, caption above window;
   test covers 9→"… 3 earlier lines", row-1/3 absent, row-4/9 present, ordering, within-budget
   silence, trailing-newline no-gap, width-0 refusal.
6. GOLDENS — diff touches zero golden files; MediaFormRenderTest|MediaFormFieldsTest|
   MenuCascadeTest filter: 48T/840A OK, no regeneration.
7. REGRESSION — live cp-swap re-verify was REFUSED by the reviewer permission map (no writes
   to src/); tree confirmed clean and unmodified afterward. Static reconstruction of the
   builder's pre-fix split matches EXACTLY: base lacks tailLines → 9 ReflectionMethod errors +
   e2e-frame assert fail (Renderer file) + 4 fold fails = 9E+5F = claimed 14 non-pass of 17.
8. LAWS — diff = exactly the 6 claimed files (+472/−21); author+committer detain@interserver.net
   (%ae|%ce verified), zero trailers; no suite-figure.json/durations.tsv/dist; new test files +
   durations duty listed in REPORT; ToolTailSlots flip is comment-only and disclosed; strict_types,
   final, one type/file, Lang::t for the caption.

## Gates re-run (cwd=candy-code, plain pipe, -u LINES -u COLUMNS)
- RunningToolTailTest|ToolTailFoldTest: 17T/64A OK (2 pre-existing Deprecations) — matches claim.
- ToolProgress|MediaProgress|PreviewSlot|Placeholder|Pending: 238T/3838A OK.
- LangParity|GlobDialect|DocFigureProseDrift: 112T/7161A OK.

## Findings (all optional, step-local)
- MINOR (doc-truth + wrap): src/Tui/ToolTailSlots.php class docblock, reflowed LEAK LAW line —
  ~128 chars and breaks the file's own wrap style, and it preserves "and on session resets" while
  clearAll() has no production caller (only tests; the effective bound is settle-clear +
  MAX_SLOTS cap — PreviewSlots mirrors the same fiction, so fleet-consistent, but the lane rewrote
  this exact sentence). Fix: rewrap and say "…settles (replaceToolRunningPlaceholder); the
  MAX_SLOTS cap bounds anything else."
- NIT: src/Renderer.php:5777 — double blank line between `private const TAIL_LINES = 6;` and the
  following docblock.
- NIT (accepted-as-disclosed): '… 1 earlier lines' singular; REPORT flags it, spec asked one key —
  fine to weld as-is; a plural-aware second key can ride a later TUI lane.

## Residue check
git status clean; C-TOOL-7.d file family (NonInteractive.php, StreamJsonEmitterTest,
EncodeDocumentEscapingTest) neither dirty nor in the commit — stash-incident resolution confirmed.
Base-inherited reds not exercised per brief. Safe to weld; the two minors need no re-review round.
