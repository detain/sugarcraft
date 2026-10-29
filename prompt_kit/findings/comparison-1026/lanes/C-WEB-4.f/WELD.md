# WELD — C-WEB-4.f (batch-10, 2026-10-28)

- Pick: 8d39a7ca0 -> ddfb9b97b (re-hashed after the C-AGT-5.d reset rebase; content of the
  pick unchanged). r1 CLEAN; lead weld-cures ALL executed (below).
- LEAD WELD-CURES:
  (a) trio regen ON MERGED TREE: php scripts/gen-protocol-schema.php --write rewrote
      docs/protocol/candy-code.v1.schema.json (branch field); npm run gen:protocol rewrote
      src-web/protocol/generated.ts (:321 branch?: string | null); SERVER.md byte-clean —
      confirmed, ServerProtocolDocumentationDriftTest GREEN on merged tree (required).
  (b) dist rebuilt via npm run build; rider files ride THIS weld commit (dist/assets
      index-BDgshKWH.js + index-CY54g8ah.css in, index-B9LBKiay.js + index-CepyELSQ.css out,
      index.html + manifest.json updated).
  (c) vue-tsc --build --force: 20 errors, error SET byte-identical to the standing family
      (/tmp/opencode/vue-tsc-cweb4c-base-e14.txt); step-file TS2339 x12 gone. PROVEN.
  (d) WELD-DOC: lane REPORT.md vue-tsc sentence cured — measured 32 at the committed tip was
      the stale-generated.ts artifact; post-regen truth 20 == standing, stated in-place.
- Gates at final master: tests/Protocol dir 131T/1202A OK WITH regen (ProtocolSchemaDrift +
  ServerProtocolDocumentationDrift GREEN = required); SessionSummaryBranchTest 4T/8A inside
  the 61T re-verify filter OK; vitest serial branchBadge/statusBar/sidebar/generated 31T +
  dormantRoster 3T (lives at src-web/__tests__/ — the components-dir path in the brief was a
  label slip; ran the real file) all passed, --no-file-parallelism.
- DUR MINT: candy-code/tests/Protocol/SessionSummaryBranchTest.php 0.602/4.
- +4T figure note (closeout re-pin). §4: ✅ ddfb9b97b (weld commit carries trio+dist).
- Absorption: git cherry '-' + patch-id proof on the branch range tip (pre-rebase d699ebeb5
  lineage); worktree+branch pruned.
