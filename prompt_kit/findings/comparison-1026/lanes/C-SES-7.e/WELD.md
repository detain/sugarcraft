# C-SES-7.e — WELD (batch-8 step 3)

Picks (master): `3b6c2ca12` ← 37e76cec7 (code+tests, applied clean); `61c984cec` ← 14bb7487f (protocol trio regen; dist hunks CONFLICTED → resolved by re-derivation, pick carries only the trio).

## Regen on merged tree (never hand-resolved)
- `php scripts/gen-protocol-schema.php --write`: "up to date" → the auto-merged schema.json == fresh derivation; `--check` rc=0.
- `npm run gen:protocol`: generated.ts re-write drift-empty (byte-identical to merged pick).
- Server-side drift guards: ProtocolSchemaDrift + ServerProtocolDocumentationDrift → 13T/362A green.
- `npm run build`: dist rebuilt on merged tree (includes C-WEB-4.b + this lane's generated.ts) — new hashed pair index-B9LBKiay.js / index-CepyELSQ.css + index.html + manifest ride this weld commit; superseded UIbrBU0h/D2fD8bEN pair removed.

## Gates (merged tree)
- phpunit `--filter 'SessionImportMethod|SessionArchiveMethods|InboundFrameCap'`: **16T/66A green**. Lead's DUR-file note misremembered: InboundFrameCapWireTest / ImportEphemeralHostCommandTest do not exist; the inbound-cap pins live inside SessionImportMethodTest (`testTheImportBoundIsTheVerifiedFrameCapLessHeadroom` :211, `testFragmentedOversizedContentCloses1009BeforeAnyHandlerSeesIt` :232 — CONFIRM leg present). Reviewer's 8T+7T vs actual: 9T+7T (verified against the test files themselves).
- vitest targeted serial: sidebarActions+sessionsRowActions+generated+dormantRoster+sidebar+sidebarShare → **35 passed (35)**.
- WELD-CURE no-op (recorded): lead's REPORT 'vitest 34T' claim — REPORT.md carries no such claim (its targeted number is 21T, full serial 618); no correction needed, file untouched.

## Bookkeeping
- durations.tsv: MINTED SessionArchiveMethodsTest 6.174/7 + SessionImportMethodTest 5.974/9, descending; asc-violations 4 (baseline parity).
- §4 mark: `✅ 14bb7487f`.
- suite-figure re-pin NOT owed here (§7/wave-close duty): this step adds +16T.
- REPORT.md/BRIEF.md for this lane are main-tree untracked (not on the branch); not staged — lead's evidence trail keeps them.
