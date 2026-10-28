# C-WEB-7.d — DONE

Verdict: shipped on branch `cc/C-WEB-7.d`, tip `04c99e248` (base `5ed26cf92`), ONE cohesive commit, never pushed.

AskUser's own choices now ride every ask wire surface typed: `SessionFeed::askSummary()`
(→ permission.pending + snapshot pendingAsks) and `TranscriptProjector::askData()`
(→ permission.requested + server-scope permission.asked) append `'choices' => $ask->choices()`
only when non-empty — every other ask is byte-identical. `Definitions::PENDING_ASK` gains
`choices: arrayOf(string(200), 6)` (AskUserTool consts, cited). `PermissionCard.vue` reads
`ask.choices` directly; the untyped `arguments.options` parse and the silent >8 free-text
fallback are gone (9 choices render 9 buttons). `BackgroundEvents.php` untouched (bg relays
never carry AskUser), per design.

Files (6, +202/−22):
- candy-code/src/Protocol/SessionFeed.php (+8/−1)
- candy-code/src/Host/TranscriptProjector.php (+7/−1)
- candy-code/src/Protocol/Schema/Definitions.php (+4)
- candy-code-web/src-web/components/PermissionCard.vue (−choices-compute swap, 21 lines)
- candy-code-web/src-web/components/__tests__/permissionCardExtras.spec.ts (typed fixtures; 'renders every typed choice — the old >8 free-text fallback is gone'; 'an ask without `choices` shows no choice buttons, even with `arguments.options`')
- NEW candy-code/tests/Protocol/AskUserChoicesWireTest.php — 3T: choices==options pinned + schema-fit on all four surfaces; hook:PreToolUse AskUser ask and gate Bash ask carry NO choices key.

Gates (plain pipe, env -u LINES -u COLUMNS):
- php -l ×4 clean; phpunit --filter AskUserChoicesWireTest → 3T/32A OK; adjacent set '…|PermissionAskDiffEventTest|PermissionFirstWinsTest|BgPermissionAskRelayTest|PermissionAskOriginEventTest' → 28T/229A OK; ProtocolSchemaDriftTest|ServerProtocolDocumentationDriftTest → 12T/332A OK.
- vitest --no-file-parallelism permissionCardExtras.spec.ts+protocol/__tests__/generated.spec.ts → 2 files, 12T pass; extras alone → 9T pass.
- Protocol regen-then-restore-3 obeyed: gen-protocol-schema --write + npm run gen:protocol regenerated locally (schema +18, generated.ts +4) for the fit-pins, then the trio (schema.json, SERVER.md, generated.ts) was `git checkout --`'d before commit. SERVER.md proved byte-unchanged by regen (Definitions rows don't reach its tables) — not edited, per design.
- Red-before (cp-harness, no stash): base src trio → phpunit 3T/1F (choices null); base .vue → vitest 2 failed/7 passed; restored mine cmp-clean, green.

Deviations/notes: (1) absence pins are green pre-fix by nature — only the presence pin discriminates; (2) C-SEC-2.b diff arms pre-existing at base, rode unchanged; (3) "PHPUnit Deprecations: 2" is pre-existing tree-wide (base-only PermissionAskDiffEventTest shows the same 2) — not from this lane; (4) dormantRoster.spec.ts does not exist at base (C-WEB-3.g unwelded) and this step adds no dormant web methods — nothing to roster; report it for whoever welds that spec; (5) dist/ untouched — weld rebuilds.
