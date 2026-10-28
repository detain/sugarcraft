# REVIEW-1 (fresh r1) — C-WEB-3.g dormant-method roster spec @ tip 0df1f0af0

Reviewer: fresh round-1 lane (prior reviewer session's RECORD on disk — REVIEW-1/FIX-1 at superseded tip a712efdcb — archived to /tmp/opencode/cc-cmp-lanes/C-WEB-3.g/{REVIEW-1-prev-a712efdcb.md,FIX-1-prev.md}; this file re-grounds against the final single commit and adjudicates the orchestrator's open questions).

## 1. Phantom-first / scope — PASS (disk-verified)
- `git log --oneline master..HEAD` → exactly ONE commit 0df1f0af0 "C-WEB-3.g: dormant-method roster spec"; blob cat-file OK; `git status --porcelain` empty before and after all probes (incl. post regen).
- `git show --stat` → single file NEW `candy-code-web/src-web/__tests__/dormantRoster.spec.ts` (+66). No dist/, no protocol trio, NO generated.ts edit — matches BRIEF Files-create exactly, Files-modify none. `shutdownDormant.spec.ts` byte-untouched (proven by the one-file stat).
- Author %ae/%ce detain@interserver.net (lead-verified; commit shown above), zero trailers.
- a712efdcb (superseded first incarnation) is NOT an ancestor of the tip — the branch is a clean re-cut; phantom excluded from review.

## 2. Design conformance (BRIEF + plan §1.8/:77, §2 dormant law) — PASS
- Derivation exactly as designed: `Object.keys(METHOD_SCOPES)` imported from `../protocol/generated` (RUNTIME import — no hard-coded name list; the only literals are the roster declarations themselves, which is the design) minus shipped-module call sites via the `shutdownDormant.spec.ts` idiom `\brequest(?:<[^(]*>)?\(\s*['"]M['"]|method:\s*['"]M['"]` — verified byte-faithful vs the precedent's regex (dot escape improved to GLOBAL `/\./g`, strictly more correct). MODULES = same `import.meta.glob('../**/*.{ts,vue}')` raw/eager scan minus `__tests__` minus `../protocol/generated.` (exclusion load-bearing — generated.ts names every method).
- Arm 1 set-equality is two-way fail-closed (new uncalled method OR gained caller both redden); arms 2/3 pin roster-keys-real and rostered-genuinely-uncalled. `MODULES.length > 40` anti-vacuity floor present.
- Plan :77 law quoted: "a protocol step that adds a method with no browser caller … adds it to the dormant set in dormantRoster.spec.ts with a reason; the step that adds the caller removes it." The spec IS that law made executable.

## 3. Deviation adjudication: roster {mcp.resources, server.shutdown} — JUDGED CORRECT (law-mandated)
- The BRIEF's pinned `{server.shutdown}` predates C-MCP-2.b landing; the BRIEF's own Risks section says "methods other areas add before this lands … must already have callers or join the set. Re-derive at landing."
- Disk truth: `mcp.resources` in METHOD_SCOPES at generated.ts:1524 ("read"); grep of all src-web shipped (non-test, non-generated) modules → ZERO occurrences (only the spec roster row + generated.ts carry it). Genuine dormancy. Pinning {server.shutdown} only would weld RED under this spec's own contract.
- Cites at the FINAL tip are all FACTUAL (each verified against plan_comparison.md):
  - "C-MCP-2.b shipped the mcp.resources method" → plan :1463 `#### ✅ fad3d931e C-MCP-2.b — … mcp.resources method`.
  - "the composer @mcp: resource picker (C-MCP-2.c) will be the first browser caller" → plan :2782 `#### C-MCP-2.c — Web composer @mcp: resource picker`, :2790 popup lists `mcp.resources` rows (W23, after this W15 lane).
  - "C-WEB-5.g wires the confirmed Admin caller later" (server.shutdown comment) → plan :3584 `#### C-WEB-5.g — Confirmed "Stop server" for Admin tokens`, whose Files-modify NAMES `dormantRoster.spec.ts` and whose Deps list C-WEB-3.g (W15) — the designed row-removal step. Reason string cites WEB-1; §6 R13 (flip-vs-dormant) stays unpresupposed — the lane pins TODAY's truth. 
- ORCHESTRATOR ANSWER to "reason cites C-WEB-7.d — factual?": that cite lived only in the superseded a712efdcb (and in the stale REPORT.md deviation paragraph); the prior r1 caught it as MINOR-1, FIX-1 amended in place → tip cites C-MCP-2.c. C-WEB-7.d (AskUser choices) has zero mcp.resources relation — correctly absent now. REPORT.md's provenance prose remains stale but is an evidence artifact superseded by FIX-1.md; committed code is correct.

## 4. Non-vacuity re-reasoning — claims hold
- M1 (plant `'probe.fake'` row): arm 1 red (roster ≠ derivation), arm 2 red (`scopes['probe.fake']` undefined vs toBeTypeOf('string')), arm 3 green (never called). 2F ✓.
- M2 (delete `server.shutdown` row): arm 1 red (derivation yields 2, roster 1), arms 2/3 iterate roster keys only → green. Exactly 1F ✓.
- Both mutations strike the same set-equality assertion from opposite directions → the pin is not one-sided.

## 5. Regen-rider safety (duty 1 second half) — PASS
- In-lane: `npm run gen:protocol` (node scripts/gen-protocol.mjs) → "wrote src-web/protocol/generated.ts" with `git status --porcelain` EMPTY afterwards — regeneration is byte-identical to the committed generated.ts on this tree (which already carries the WEB-7.d choices blocks). The spec therefore passes on the exact welded-tree content the trio rider will produce; the roster derive needs no hand-edit for this wave's regen. (Future no-caller method additions must add their own row per §1.8 law — that is the contract working, not a defect.)

## 6. Targeted test run (quote)
`env -u LINES -u COLUMNS npx vitest run src-web/__tests__/dormantRoster.spec.ts src-web/__tests__/shutdownDormant.spec.ts --no-file-parallelism`
→ **Test Files 2 passed (2) · Tests 6 passed (6)** (dormantRoster 3 + shutdownDormant 3; consistent with builder's trio run 3 files/9 incl. generated.spec).

## 7. Laws / conventions
- No secrets; no timers; no v-html. `../**/*.{ts,vue}` glob-shaped literal is the import.meta.glob API call itself and byte-matches the committed precedent — GlobDialect scans PHP test literals only, non-issue; no other wildcard-shaped strings.
- Naming/idiom match the __tests__ directory precedent set; header docblock explains WHY (fail-loud contract + relation to shutdownDormant behavioral pin).
- In-step guards: nothing on the PHP side moves (no doc rows, no census rosters — pure web spec), so the §1.8 doc/guard-law is vacuously satisfied.

## 8. Standing exclusions (report-only, not this lane)
Web typecheck standing 19-error set; ReadmeSuiteFigureDrift staleness pair; thirty-seven DocFigure; walker pair — untouched by a one-file diff; not filed.

## Findings
None. The sole prior-round MINOR-1 (mcp.resources provenance cites) is confirmed cured IN the reviewed tip 0df1f0af0 (blob shows C-MCP-2.b/C-MCP-2.c; grep-count of stale ids in the file: 0).

VERDICT: CLEAN (0C/0M/0m against tip 0df1f0af0; ship — welder: trio rider regen re-verifies byte-clean per §5.)
