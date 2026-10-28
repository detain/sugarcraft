# C-WEB-7.d — WELD record (welder session 1027, master)

**PICK: CLEAN** — `04c99e248` → `7b730e485` (parent = lane-1 weld `07556c2e9`),
6 files +202/−22.

## Regen rider

`595dd738f` `weld: C-WEB-7.d regenerate` (committed BEFORE the weld commit, per brief):
- `candy-code/scripts/gen-protocol-schema.php --write` → `docs/protocol/candy-code.v1.schema.json`
  (typed AskUser choices blocks = intended surface move; drift arm red pre-rider → green after);
- `candy-code-web: npm run gen:protocol` → `src-web/protocol/generated.ts`;
- `npm run build` → `dist/` (hashed bundles index-pEJbljne.js / index-DY4iFKER.css,
  index.html + manifest.json, old bundles removed as renames).

## Merged-tree gates

| gate | expected | result |
|---|---|---|
| `AskUserChoicesWire` | ~3T/32A | **3T/32A OK** (5.6s, 2 deprecations standing) |
| `AskUserChoicesWire\|PermissionAskDiff\|PermissionFirstWins\|BgPermissionAskRelay\|PermissionAskOrigin` | 28T/229A | **28T/229A OK** |
| `ProtocolSchemaDrift\|ServerProtocolDocumentationDrift` pre-rider | 1F drift | **FAILS 1** at :39 (choices surface) — red pre-rider BY DESIGN |
| same post-rider | green | **OK 12T/332A** |
| vitest `permissionCardExtras.spec.ts` (serial; lane touched the spec) | — | **9/9 PASS** |

## Durations rider

`522bf2ccc` `scripts: durations for C-WEB-7.d (AskUserChoicesWireTest.php)` —
1 row `candy-code/tests/Protocol/AskUserChoicesWireTest.php 1.110 32` (class junit
recount, standalone run; 3T), canonical position between 1.113 (TextSelectionSugarMouseTest)
and 1.104 (MemoryImportCommandTest). Set equality: **1500 → 1501 rows** (live count + 1 ✓).

## Absorption proof

`git cherry master cc/C-WEB-7.d` → `-` for 04c99e248; regen rider is the
regenerated-against-master completion of the lane's intent (content union on master).

## Notes

- web dist law honored: `dist/` changed ONLY via the regenerate rider commit.
- §4 mark `#### ✅ 7b730e485 C-WEB-7.d — …`; lane evidence + run-state ride the weld commit.
- C-WEB-3.g unwelded → no dormantRoster.spec.ts action (per brief).
