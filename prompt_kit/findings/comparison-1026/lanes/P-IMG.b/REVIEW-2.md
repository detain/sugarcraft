# P-IMG.b REVIEW-2 (round 2, fix commit b9dd35a93)

[Persisted verbatim from reviewer reply — sandbox denied shell/php/git; static review only. Gate figures unverified here → weld re-runs them.]

All claims verified against disk (shell/php/git denied in this sandbox — static review only; lead persists REVIEW-2.md).

VERDICT: CLEAN

- [OK] Fix scope: sweep + docblock live in `candy-code/src/Tui/Renderer.php:1112-1134` (`BAND_ZONE_TAG_PATTERN`, `padBand()` replaces before `clipWidth`/cell-cut at :1134); round-2 touches only Renderer.php + the two brief-declared test files — cumulative feature set (naturalSize/naturalWidth/composeBand/padBand) confined to the 5 brief files, no hub drift.
- [OK] Regex byte-identical to chat `ZONE_MARKER_PATTERN` (`candy-code/src/Renderer.php:810`); grammar sound vs `Mark::wrap` (open+content+`/`-close tags, `Mark.php:150-154`, `ID_PATTERN` = same charclass). Anchored to sentinel bytes `\xEE\x80\x80`/`\xEE\x80\x81` — cannot eat U+E002+ ImageOverlay markers, cannot span ANSI (ESC not in charclass).
- [OK] Pin non-vacuous: `MediaFormPopupSizeTest.php:223-246` reflection-invokes padBand with a live `Mark::zone`; removing the sweep leaves U+E000 in the line → red at :238 (Width is zone-aware so :237 survives — matches builder's "fails at :238" mutation claim); content-survive and id-absence legs at :242-243 are real discriminators.
- [OK] Docblock honest: no longer overclaims; states scanRoot premise + "split column and any future band producer may still carry tags". REPORT.md Round-2 records the empirical-probe disclosure (band sentinel-free at today's code; strip defensive) honestly.
- [OK] NIT fixed: `MediaFormWiringTest.php:129-131` — single blank before SavePreset comment.
- [NIT] Fixer claim (4) wording misnames the pin file ("the wiring test file"); disk shows the pin is in MediaFormPopupSizeTest (still in-brief). No code impact.
- Unverifiable here: gate figures (1,668T/71,661A; five-guard pair-only, live 25,749) and 2-commit/author-law — re-run at weld per plan.
