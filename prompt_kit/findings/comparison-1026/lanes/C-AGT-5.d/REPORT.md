# C-AGT-5.d — signed inbound webhooks + delivery-id dedupe — DONE

Commit: 44fae6291 on cc/C-AGT-5.d (base 302e99ac2), author/committer detain 1+1, zero Co-Authored, tree clean after commit.

## Shipped (6 files)
- ServerConfig: `INBOUND_SIGNATURE_SCHEMES` (github-sha256 → x-hub-signature-256/x-github-delivery; hmac-sha256 → x-signature/no dedupe), `webhooksValue` parses optional `signature:{scheme,header?,deliveryHeader?}` (header names RFC7230-token-validated, lower-cased; unknown/malformed are startup refusals), row gains `signature` key in all docblocks.
- HookWebhooks: signed rows verify `sha256=` HMAC-SHA256 over the RAW body keyed by the row secret, `\hash_equals($expected, getHeaderLine($signature['header']))`, IN PLACE OF the custom-header secret (legacy compare byte-kept for unsigned rows); failed signatures feed the same per-hook lockout; a seen delivery id answers 200 `{"ok":true,"duplicate":true}` without spawning, charged to neither limiter; per-hook LRU ring of 1024 ids (freshest-at-tail, re-set refreshes position); ids recorded only after $run returns, so a 503 delivery stays replayable.
- en.php `settings.webhooks.help` soft-append; docs/SERVER.md hooks-row Auth/Answer cells + `#### Signed inbound deliveries: the GitHub recipe` subsection; ServerConfigWebhooksTest extended; tests/Server/HookWebhooksSignatureTest.php NEW (10 tests, direct-drive).

## Gates (measured, plain pipe, cwd=candy-code)
- `--filter 'HookWebhooks|ServerConfigWebhooks|ServerProtocolDocumentationDrift'`: Tests: 69, Assertions: 2474, Failures: 0, Deprecations: 2 (pre-existing — untouched siblings alone emit the same 2).
- `--filter 'Server|Lang|DocFigure|GlobDialect|ProtocolSchema|Readme|TreeWideGuardRoster|StderrEmitterCensus'`: Tests: 1828, Assertions: 45754, Failures: 11, Skipped: 1 — ALL 11 reproduce identically with my 6 files reverted to pristine base (ChatHostLangCatalogue, CliLangCatalogue, ReadmeJsonErrorContractDrift ×4, ReadmeSuiteFigureDrift ×2, AttachPaging ×2, WorkspaceHost ×1 = foreign standing reds, base weld message flagged the first family). Zero mine.
- Mutations M1–M5 all cp+md5-clean, one-flip-discriminating: M1 hash_equals→`===` reddens only the source-pin; M2 dedupe-gate poison reddens the 4 duplicate tests; M3 record-on-503 reddens only failed-admission-replayable; M4 early-413-removal reddens only over-cap; M5 strike-on-duplicate reddens only replays-charge.

## Deviations
- Signed path answers over-cap 413 BEFORE the compare (middleware-swapped empty body must not false-strike a large sender); unsigned rows keep the legacy order exactly.
- Missing-scheme refusal reads `signature scheme "null" is not one of:` (get_debug_type idiom, not empty string).

## Seam notes (gate/welder)
- durations.tsv needs a row for HookWebhooksSignatureTest.php (lane-forbidden file — GATE mints).
- Suite live-enum +18 tests (10 new + 1 + 7 provider cases) — README/suite-figure re-pin owed by welder (staleness pair already red at base for foreign reasons; do not attribute to this lane).
- docs/SERVER.md "Webhook delivery covers inbound only" paragraph left untouched — its outbound sentence is C-AGT-5.f's domain.

## FIX ROUND 1 (review r1 → fixer, 2026-10-28)
- MINOR-1 (vacuity) FIXED: HookWebhooksSignatureTest `testABadSignatureIsRefusedAndStrikesTheLockout` — the `$missing` request now pairs a VALID `x-candy-code-hook` header secret with a missing signature (401 still demanded; message claims "header secret or not"), and the `HeaderSecretIsNotConsulted` test comment cross-references where the law bites. Discrimination PROVEN: mutation `!hash_equals(HMAC) && !hash_equals(header-secret)` on HookWebhooks.php:154 → exactly 1 red, precisely the new leg (Tests: 63, Assertions: 2402, Failures: 1); cp+md5 restore → green.
- MINOR-2 (doc truth) FIXED, no persistence added: HookWebhooks class docblock (dedupe parenthetical) + docs/SERVER.md recipe step 4 both now state the ring is process memory only — a restart re-admits an already-seen delivery (GitHub retries a timed-out delivery past one). Table row at SERVER.md:299 left alone (process-scoped claim, not over-promised; finding named step 4 + docblock).
- Guards: ServerProtocolDocumentationDrift green after the SERVER.md edit; DuplicatedDocBlockLine + SymbolCitationDrift green after the docblock edit (13T/17557A). Combined gate `--filter 'HookWebhooks|ServerConfigWebhooks|ServerProtocolDocumentationDrift'`: Tests: 69, Assertions: 2474, Failures: 0, Deprecations: 2 (pre-existing; figure byte-identical to the original lane gate — the new pin replaces a request payload and one assert message, no new assertions).
- No new test methods or files → the welder's durations/suite-figure duties from the original report stand unchanged.

## FIX ROUND 2 (merge collision, 2026-10-28)
- Batch-10 weld STOP-with-record: picking this range reddens DuplicatedTestHelperDriftTest const polity — `BODY` in the lane's tests/Server/HookWebhooksSignatureTest.php:34 vs pre-existing tests/Automation/WebhookSignerTest.php:23 (guard never run on the MERGED pair by lane gate or r2).
- Fix (tests-only, rename side = ours; foreign file untouched): `BODY` → `WEBHOOK_BODY` (1 decl + all 37 `self::BODY` occurrences; no reflection/name-pinned consumers — grep-verified). No guard widening, no ACCEPTED_CONST_DUPLICATION row needed.
- Guard pair: pre-fix RED exactly `drift BODY: Automation/WebhookSignerTest.php vs Server/HookWebhooksSignatureTest.php` (14T/196A, 1F); post-fix GREEN (14T/197A — +1A tree-scan recount wobble, 2 pre-existing deprecations).
- Re-derivations: HookWebhooksSignatureTest 10T/2150A; combined gate `HookWebhooks|ServerConfigWebhooks|ServerProtocolDocumentationDrift` 69T/2474A — byte-identical to FIX ROUND 1 figures (rename behavior-neutral). php -l clean.
