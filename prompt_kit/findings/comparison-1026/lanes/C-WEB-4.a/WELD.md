# C-WEB-4.a — WELD (W15 bundle, lane 3)

- **Pick:** bf6331eb0 → 9e22d932fab8c5c4942defd5aa6e84b67e57a158 (one commit, 8 candy-code-web files: Composer.vue +150, HistorySearch.vue new, composerDropAndChips.spec.ts new 212, paste.ts/.spec, promptHistory.ts/.spec, README). Zero conflicts.
- **Reviews:** r1 FINDING cured (FIX-1.md); r2 CLEAN 0C/0M. REVIEW-1.md collected from /tmp/opencode/cc-cmp-lanes/C-WEB-4.a/ into this dir and committed with the weld.
- **Regen rider (dist):** commit 649f76f69 `weld: C-WEB-4.a regenerate` — npm run build (vite 8.3.4, 288 modules) after the pick; dist assets index-C0wZxEBm.js + index-Cd2YqZsf.css replace C6DjTF6G.js/DY4iFKER.css (css folded as rename by git), dist/index.html + dist/manifest.json updated. generated.ts showed no build drift → gen:protocol trio NOT owed, protocol-schema regen not run.
- **Merged gates:** `npx vitest run --no-file-parallelism` over the touched+adjacent composer set (composerDropAndChips, composerDictation, composerFilesImages, composerHistory, components, paste.spec, promptHistory.spec) → 7 files / 88T passed (lane's in-flight 66/66-era subset fully covered, superset green).
- **Durations:** none — vitest specs never get tsv rows.
- **Author law:** %ae/%ce separate greps = 1 each detain on pick and dist commit; zero trailers.
- **Queued NITs (closeout carry):** (1) HistorySearch Esc leg absent when seed query is empty (only the non-empty seed path pins dismissal); (2) line-cites inside the lane's prose drifted vs final landed line numbers (cosmetic).
