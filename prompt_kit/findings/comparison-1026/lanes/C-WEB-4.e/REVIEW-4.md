# C-WEB-4.e REVIEW-4 (new chain, full re-derivation) — VERDICT: CLEAN

Tip 47d19563f (54730fdcd → 5a89b0b20 → 47d19563f). Worktree clean at spawn and verified at exit.

## 1 Phantom law — PASS
Parents chain exactly; both commits A=%ae and C=%ce detain@interserver.net (separate greps); zero Co-Authored trailers. stat: c1 13 files +818/−28, c2 1 file +8/−1; union 54730fdcd..HEAD = 13 files +825/−28 (the −1 of c2 replaces a c1-added line; arithmetic consistent).

## 2 Security — PASS (re-derived on new code)
- Cap-before-buffer: Dispatcher validates params against the published schema BEFORE the method (Dispatcher.php call() :346-ish, "errors name paths, never values"); SchemaValidator enforces maxItems=2 (:140), maxLength=15,379,116 (:93), and array= is_array&&array_is_list (:173) at ingress. Third doc dies -32602 at schema (test pins code, discriminating: without maxItems the send would settle 'started'). 16 MiB frame (ServerConfig:57) bounds base64; 11 MiB decoded sum enforced at handler with named attachment_budget_exceeded; lone at-cap upload pins budget-naming (test 3). MAX_DOCUMENT_B64_CHARS arithmetic verified (11534336→15,379,116; +1 MiB text still < 16,777,216).
- Refusal vocab mirrors images_* exactly: documents_not_a_list (dormant 2nd layer, mirrors images_not_a_list), document_not_base64 (data-URL probe), document_not_decodable (PNG bytes), document_mime_mismatch (wire-latent behind enum, reflection-pinned — honest in comments), documents_while_busy (queue/steer refuse, interrupt admits + attaches to replacement turn), documents_with_command (/model, !ls, '  /compact'). Live proof of discrimination: an external transient mutation 'documents_with_commandX' flashed in the worktree mid-session and reddened exactly 2 arms (handler pin + docs bidirectional roster) before self-reverting — noted as environment, committed code correct.
- Name sink: basename() + …/'..'/'.' fallback + schema 256 cap; Vue renders via escaped interpolation.

## 3 Wire-key — server pinned, client parity gap (MINOR, out-of-scope family)
PHP side: MethodSchemas 'documents' key + generated schema.json + ProtocolSchemaDriftTest byte-arm + tests send the literal key — a server rename reddens. Client side: NO store-level unit pin that `sent('session.send').params` carries the key `documents` (composerDocuments.spec pins the Composer EMIT slot 3 only). Same shape as `images` since WEB-5b; a client rename would die loudly at the server (Schema::object sets additionalProperties=false, SchemaValidator:116) — never silent. Suggest 3-line session.spec leg at weld; not blocking.

## 4 Scope — brief-matched; "contextSources half" NOT-PRESENT
13 files = the brief's 10 + SessionView.vue (mechanical 4-arg forward rider), components.spec.ts (3 exact-tuple pin widenings), ServerProtocolDocumentationDriftTest.php (§1.8-mandated arm). grep: zero contextSources/sourcesWithDocuments touches in the diff — the picker halves are paste.ts imagesFromFiles/documentsFromFiles (Composer-scope). Nothing named contextSources in old chain either. No scope creep; no forbidden files (no suite-figure/durations/lang/README; dist/ correctly NOT committed — welder re-derives).

## 5 Gates — ACTUALS
- PHP (plain pipe, cwd=candy-code -c phpunit.xml): SessionSendDocumentsTest 7T/29A OK; filter SessionSendDocuments|SendImages|ServerProtocolDocumentationDrift|ProtocolSchemaDrift 26T/416A OK (builder's REPORT 27T/421A was the OLD chain's ProtocolSchemaDriftTest=8? current is 7T/303A — my set is the truth here). The 2 PHPUnit Deprecations are per-process constants from bootstrap (ParamsTest control run shows 2 too) — not lane.
- five-guard family: 134T/16,669A/3F = README/suite-figure staleness pair (welder re-pin +8T) + DocFigureProseDriftTest::testArchitecturePageCounters… (slash 59-vs-60). The latter is base-inherited: lane touches neither ARCHITECTURE.md nor CommandRegistry/Commands — identical inputs at base ⇒ foreign batch's staleness.
- vitest serial: targeted 5 files 64/64; FULL serial 79 files 618/618 passed, rc 0. sessionView.spec 2/2 inside; composerDocuments 11.
- vue-tsc --build --force: sorted error set = 20 lines, byte-identical to /tmp/opencode/vue-tsc-cweb4c-base-e14.txt — DELTA EMPTY; zero lane-file errors; no TS7053.
- Trio determinism: php/npx generators permission-refused for this lane; equivalent byte-proofs green: ProtocolSchemaDriftTest:39/:44 (schema.json + SERVER.md tables ≡ code), generated.spec.ts (generated.ts ≡ generate(committed schema.json)). Committed trio self-consistent ⇒ welder re-derive expected byte-identical.

## 6 Findings
- MINOR (out-of-scope family): client wire-key unit pin absent (§3) — mirror-of-images; suggest session.spec leg at weld.
- NIT (step-local): 5a89b0b20 message says "dist regenerated" — dist is intentionally not in the commit (wave law); wording rides from the dead-chain build.
- NIT: 7-file old-chain REPORT claims (27T/421A, 305/305) don't match the rebuilt tree (26T/416A, 618/618) — figures above are the new truth.

## Welder carries (batch-7)
Pick 5a89b0b20+47d19563f; durations.tsv row SessionSendDocumentsTest; suite-figure+README +8T re-pin; trio+dist re-derive once merged; foreign ARCHITECTURE slash-count staleness still red at tip (not this lane's); closeout re-arms five-guard trio expected.
