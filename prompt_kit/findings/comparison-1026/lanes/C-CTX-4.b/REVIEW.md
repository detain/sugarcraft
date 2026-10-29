# C-CTX-4.b — REVIEW — r1 — verdict: APPROVE (0 CRITICAL / 0 MAJOR / 0 MINOR / 2 NIT)

Branch cc/C-CTX-4.b, base 0c1ad6c64, tip 532bb044a (cat-file-verified, tree clean at review).

## Scope / traceability — PASS
- Diff touches exactly the 4 declared files (MEMORY.md, InstructionFileLoader.php, ContextSources.php, PersonalInstructionFileTest.php). No undeclared file, no out-of-region edit.
- ContextSources.php = docblock-only 6-line addition above priceInstructionFiles; co-tenant C-MCP-4.a regions (answer()/mcp rows) byte-untouched (single hunk @@ -99,6 +99,12 @@). Functional claim verified: the pre-existing personal foreach already prices EVERY loadPersonal() entry as a personal:true row in loader order → persona rows reach panel/wire/instructions by the same door.
- Forbidden files zero-diff: suite-figure.json / durations.tsv / candy-code-web/dist / protocol trio.

## Completeness — PASS
- No stubs/TODOs. loadPersonal() head+personas loop, one shared ContainedPath::within site (:643), fallback keeps its own gate (:677) + array_unshift head-slot; tail extracted to private readPersonalEntry() with NO gate inside (verified: no within() call between :643's method and the tail — call-site list 507/643/677/1018/1140/1350/1514/1557/2150).
- Runtime::planInstructionDocuments loops every loadPersonal() entry under the shared budget with per-document <user-rules> fence — no single-entry assumption. Memo guard intact; refusal-message format byte-carried from base (INT-M2 consumers unaffected).

## Laws — PASS
- Census: comment-stripped within() sites = 9 at BOTH base and tip (machine-counted) → ContainedPathInventoryTest row 9 (:208), MEMORY.md 'nine' + 7 named `X()` spans, and DocFigure containment arm (live token census + method-span coverage walk, which I read — not vacuous) all agree and are green.
- Class docblock EIGHT→NINE prose is a truth-fix vs the machine-pinned 9 (base prose was stale; unpinned).
- Author law: both commits %ae AND %ce = detain@interserver.net (separate greps), 0 Co-Authored trailers. §1.3 says one commit/step; the 2-commit const+truth / impl+docs+tests split follows established program precedent (WELD-R lanes shipped multi-commit chains APPROVE'd) and each commit is independently guard-green.
- E646: no timers. Fork discipline: no proc_open sites. Dormant wired: PERSONA_FILENAMES consumed; no deletions. One type per file; strict_types; no new user strings (refusal shape reused, Lang untouched); Lang::t law not implicated.
- Test-literal hazards: no glob-shaped, no Refused-shaped literals; new helpers soulPath()/userPath() drift-green.
- In-step guard law: MEMORY.md doc rows + guards moved in the SAME tip commit; MemoryDocumentationDriftTest green.

## Gates (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)
- PersonalInstructionFileTest: OK 24T/78A (builder claim matched; +7T/+? methods 17→24 as claimed; 2 PHPUnit deprecations = foreign AccessorDeprecationTest non-public data provider, pre-existing).
- Filter PersonalInstructionFile|ContextSources|MemoryDocumentationDrift|DocFigureProseDrift|GlobDialect|DuplicatedTestHelper|DuplicatedDocBlockLine: OK 170T/4955A, 0F (builder's 173T/1F matched a Symbol-prefixed class too).
- SymbolCitationDrift: 1F = testEveryCitedTestSymbolExists, Chat.php:13712 AgentPresetFieldCarryTest cite — lane diff -- Chat.php EMPTY → identical at base → inherited foreign (pick 093ea6e13 C-AGT-4.a), correctly untouched.
- ContainedPathInventoryTest: 49T/2F, both foreign census-drift rows (PathJail 5→6 etc.); InstructionFileLoader row not implicated. Inherited, disclosed in brief.

## Regressions — PRESENT and behavior-pinned
- Order (head,soul,user paths+bodies), absent-costs-nothing (exact single-entry array incl. imports=>[]), per-file fence+preamble inline ordering vs repo, persona symlink escape refused+recorded+others survive+secret absent from prompt, stand-in head-slot with persona riding, oversized persona → own null-body pointer entry + pointer line in plan, ContextSources end-to-end personal rows in loader order. Each asserts derived behavior/order/count (not copied constants) → structurally discriminating: neutering the persona foreach removes soul/user paths+bodies from the asserted arrays; dropping unshift inverts the asserted [claude, soul] order; adding a tail gate makes the live census 10 vs the word-pinned nine in two guards.

## Nits (non-blocking)
- NIT-1 (handoff text): the suggested-mutation table claims the absent-files pin reddens under M1; statically it stays green (that fixture writes only the head). Harmless prediction slip, not code.
- NIT-2 (process, not lane): reviewer could not EXECUTE mutations live — the wave-reviewer permission map denies cp/python/edit on this worktree (mission law: do NOT edit any file in the worktree). Mutation discrimination argued statically as above; builder likewise left M-runs to review, so weld-time battery is the next backstop.

## Conclusion
Root cause of the gap (loadPersonal read exactly one personal file) is removed: the two OpenClaw persona names are loaded, fenced, contained, budgeted, priced, documented, and pinned — with the shared-gate census law preserved at exactly 9. APPROVE; weld on the range.
