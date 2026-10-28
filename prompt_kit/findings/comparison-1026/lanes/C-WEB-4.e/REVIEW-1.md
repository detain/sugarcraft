# C-WEB-4.e REVIEW-1 — verdict: CLEAN

Lane tip b0ea554b4 (parent 24d746501, detain A/C, 0 trailers, 18 files +859/−69).

## Gates re-derived at tip (all green)
- phpunit (plain pipe, cwd=candy-code -c phpunit.xml): SessionSendDocumentsTest 7T/29A OK; ServerProtocolDocumentationDriftTest 6T/59A OK (master 5→tip 6, +1 arm); ProtocolSchemaDriftTest 7T/303A OK. Builder's "27T/421A" filter set not fully reconstructible; every touched suite individually green. Suite delta = +8T (7 new file + 1 derived drift arm) — matches the welder note.
- The 2 PHPUnit Deprecations in the wide filter window live in tests/Server/SendImagesTest.php (untouched by lane) — pre-existing, out-of-scope.
- vitest --no-file-parallelism: components+views 169/169 (superset of claimed 107); sessionView.spec.ts 2/2; generated.spec.ts 3/3; components.spec 3 emit-pin flips are legit full-tuple pin moves (4th slot []), not goalpost shifts.
- vue-tsc --noEmit rc=0.
- Trio/dist determinism: npm run build at tip → git status --porcelain -- dist/ EMPTY (byte-identical); generated.ts ≡ fresh generate(schema.json) pinned green; schema.json/SERVER.md ≡ code re-derivation pinned green by ProtocolSchemaDriftTest:41/44. (Direct `php scripts/gen-protocol-schema.php --check` was permission-refused for this reviewer; the guard tests are the equivalent proof.)

## Security core — passes
1. Sniff refusal non-vacuous: wire-level exact-kind asserts via real Dispatcher fixture — data-URL string → document_not_base64; PNG bytes → document_not_decodable; off-enum mime → −32602 at schema. Removing the sniff leg changes the outcome → reds.
2. Size-before-buffer ordering: SchemaValidator (Dispatcher.php:346) enforces data maxLength=15,379,116 and maxItems=2 at ingress BEFORE decodeDocuments runs; the 16 MiB frame (ServerConfig:57) bounds the whole payload, so base64_decode never amplifies (decoded ≤ 3/4 of received). Handler sum is the named-error leg, not the DoS boundary — docblock says exactly that.
3. MAX_DOCUMENTS: per-send at ingress (maxItems), mirror of MAX_IMAGES; sparse-key lists still die at the handler's array_is_list leg (documents_not_a_list genuinely reachable).
4. mime_mismatch: honestly labeled. Test comments state the enum makes it wire-latent, reflection-pins decodeDocuments directly, and keeps it for a second sniff kind — accepted per task law (would be a finding only if claimed reachable).
5. name: basename() + 256 cap; sinks (Anthropic `title`, textOnly(), persistence/snapshot) are the PRE-EXISTING image-attachment sink surface (anthropicDocument:129 predates the lane; client-wire names since WEB-5b) — mirror parity, no new sink class. Vue renders via text interpolation (escaped).
6. Mirror parity vs decodeImages: refusal set, per-leg ordering (list→base64→decodable→mismatch), budget-before-busy, busy queue/steer refuse + interrupt admits, command-leg checks — all mirrored; session.ts mirrors the two named refusals client-side; C-TOOL-2.d vocabulary consistent (arrays plural, legs/ToolResult singular).

## Deviations — judged
- SessionView.vue 6-line rider: mechanically forced by the 4-arg emit (declared choke is Composer); benign, listed for welder.
- @path route keeps DOCUMENT_MAX_BYTES=20 MiB vs 11 MiB wire budget: deliberate (const docblock + SERVER.md:600-614 tell users). Asymmetry disclosed, OK.
- %PDF- is magic-only (8-byte forge passes as a "PDF"): matches images' magic-only law and the BRIEF's wording; provider/reload robustness is CTX-2 territory.
- typelessPasteNotice stays images-only: NOT a gap — the image reader announces typeless entries over the same clipboard; BRIEF requires no widening.
- en.php untouched: BRIEF requires no lang keys; refusals are wire vocabulary (English constants), identical to the images leg.

## §1.8/§1.9
- Moved guards: ServerProtocolDocumentationDriftTest +1 dynamic arm (figures derived from TurnMethods/FileMentions consts; 8 kinds scanned docs↔handler over a fixed roster). Mandated by the schema/doc change; in-step.
- Fixtures clean: no glob-shaped or denial-shaped literals in the new PHP/TS test files; no forbidden files (suite-figure/durations/lang/README) in the diff.

## Welder carries (explicit)
1. durations.tsv row for candy-code/tests/Protocol/SessionSendDocumentsTest.php + suite-figure/README +8T re-pin.
2. C-WEB-4.d (416fee321, branch cc/C-WEB-4.d) touches StatusBar.vue/reducer.ts/README/dist — ZERO source-file overlap with this lane, but dist/ + manifest collide by construction: pick both, then re-derive trio+dist once on the merged tree (regen flow verified deterministic at this tip).
3. Lane base predates batch-3 master (e14c3cc7c): after rebase re-run sessionView.spec (2/2 at tip) + the 13T drift pair.
4. No Co-Authored trailers; author law already satisfied at tip.
