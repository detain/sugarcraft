# C-TUI-2.c — WELD (batch-5 lane 2, 2026-10-28)

- Pick: 2b8bf855c -> 18822cc20 (cherry-pick -x onto 988e41ac3). CONFLICT as REVIEW-1 predicted: docs/SETTINGS.md (generated). Resolved by REGENERATING, not by hand: took lane side, php tools/gen-settings-doc.php --write rewrote the merged table (keep-both proven: diffStyle rows at LayeredSettings :613/:910 + C-TOOL-5.a rows intact), --check rc0; regenerated SETTINGS.md amended into the pick (zero remaining diff vs generator output = content proof for the '+' side). LayeredSettings.php auto-merged clean.
- Author %ae/%ce detain both greps; zero Co-Authored trailers. 11 files +912/-60.
- Gates merged tree (plain pipe cwd=candy-code):
  - PaneWidthInvariantTest + RendererTest: OK 320 / 29,250 — EXACT.
  - Settings-guard sextet: 105T EXACT (39,926A vs r1 39,761A = generated-doc row wobble post-regen; 0F).
  - Combined battery: reviewer's exact filter string not reconstructible from REVIEW-1 wording; union runs of the named families all green — DiffSplitRender|DiffGutter|config-guards|DocFigure leg 171T, wider superset 230T, 0F everywhere. (r1's 180T/6,104A predates batch-3+ era moves.)
  - DiffSplitRenderTest file-run: OK 12 / 1,576.
- Durations: NEW row candy-code/tests/Tui/DiffSplitRenderTest.php 0.28 / 12 (merged junit; brief's ~0.3s); tsv 1558->1559 desc-order verified.
- Regen riders: gen-settings-doc --write (SETTINGS.md only; README byte-identical). gen-command/tool/schema not owed (diff-tree proves no src/Protocol or web files).
- suite-figure carry: +16T (closeout §7).
- §4 mark cites 18822cc20 (re-stamped from lane tip 2b8bf855c).
