# C-RT-REVIEW C-PMENU-C r1

Reviewer: ses_e82119e0affe7R3cHkENqkYWpS (read-only; persisted verbatim by lead 2026-10-27 @966).
Lead erratum: an earlier "r1 CLEAN" note for this lane was a lead narrative phantom (self-catch #21) — no REVIEW-1.md existed at weld dispatch; THIS file is the true round-1 verdict. The weld of 2616bb560 proceeded concurrently; the step-local MINOR below is owed a post-weld master cure commit (test-only sixth provider case), per the established cure pattern (C-TOOL-1.b 30d4f91cb precedent).

VERDICT: FINDINGS
- MINOR (step-local): candy-code/src/Tui/Renderer.php:1214,1224 — the graphics-leg refusal (`if ($cellGrid)` / ternary `null`) is the one branch of the headline "paint⇔mark everywhere" law with zero test coverage: no test renders video in a `clipsLines()===false` mode with a menu open, so deleting both guards keeps the suite green while splicing box glyphs into kitty/sixel protocol bytes. Fix: add a sixth provider case (`video graphics`, Mode::Kitty, menu open) asserting no `┌` row spliced and `prefixed(MENU_ITEM_ZONE_PREFIX)` empty.
- NIT (out-of-scope): candy-code/src/Tui/Menu/MenuPanel.php:24 — "MenuCascade::layout() produces these; nothing else should" is now falsified by `MenuBar::panelsFrom()` (sanctioned by the brief); file is outside the lane's declared set — collect at wave review, don't edit here.
- NIT (step-local): MenuBar.php:581 passes `firstRowIndex 0` while marked item zones number from panel-index 1; harmless today (dropdownLines emits `menuitem:<labelRow>`, borders unmarked), worth a comment for P-MENU.d.
- No shell in sandbox: gate claims (217T Menu, 1610T Renderer|Frame|Overlay|MediaFormPopupSize, RED-first 2E/4F, ShellContrast base-red, five-guard pair-only) read plausible and consistent with disk artifacts but unexecuted — weld owes re-run of Menu + MediaFormPopupSize filters and the durations row for MenuOverlayFrameTest.php. Commit metadata (single commit, author law, no trailers) per lead's pre-verification.

Otherwise clean: correctness, completeness, conventions, and scope all confirmed on disk.

## Full axis detail (reviewer's synthesis)

- **Scope/seams**: `overlayMenu` (Renderer.php:2336) + `scanChrome` `?panelTop` pass (:508-540) exist as briefed; `MenuBar::panels/panelsMarked/panelsFrom` (MenuBar.php:547-583) with paint-aware clamp `max(1,min(activeMenuColumn()+1,cols-width+1))`; `overlayDropdown` gone tree-wide; forwarders kept and byte-pinned (MenuDropdownTest:172-188).
- **Five paths call both seams with identical tops**: :732/:835, :983/:1011, :1088/:1119, :1215/:1224 (guarded), :1263/:1287 — verified each.
- **Mirror math**: paint loop (budget≤0 skip, `painted = clipped ? budget-1 : count`) and mark loop are character-identical; overflow `…` row painted-but-unmarked on both sides; dock/form zoneRows lose to panel marks exactly where cells were overpainted (:546-556) — row-for-row lockstep incl. short frames; 80-col non-widening pinned per menu index (:141-158), clamp edge at 60 cols pinned (:184-190).
- **Graphics deviation**: honest in code/docblock/REPORT ("seam for the orchestrator, not fabricated") — but see MINOR finding.
- **P-IMG.b**: MediaFormPopupSizeTest:205 assertion present, overlay runs after veil composite (:1088).
- **Hazards**: no glob-shaped or `**Refused:**`-shaped literals in new/changed test lines; `nodes=[]` seam disclosed; no suite-figure/durations touches (durations row disclosed as gate duty).
