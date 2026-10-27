# C-AGT-1.c — Webhooks refuse over-size bodies with 413 — DONE

Commit: 7c1bc504f on cc/C-AGT-1.c (base ff10efc43). Author+committer detain@interserver.net verified separately; no trailers. Tree clean, not pushed.

## What landed
- `src/Server/Http/HookWebhooks.php` `handle()`: before the PromptFence splice, `413 payload_too_large` when `Content-Length` > `ServerConfig::MAX_API_BODY_BYTES`, or a body was declared (`Content-Length` > 0 or `Transfer-Encoding: chunked`) and the buffered body is `''` (the shape `RequestBodyBufferMiddleware` hands downstream after its silent swap). `$run` never invoked on that path. Docblock :127-129 truth-corrected (middleware discards, does not refuse).
- `docs/SERVER.md` :299 endpoint row: documents the 413 (hand-maintained table, OUTSIDE the `<!-- protocol:… -->` generated regions — no generator run needed; ServerProtocolDocumentationDriftTest green in-lane).

## Tests (all via cwd candy-code, plain pipe, -c phpunit.xml)
- Pre-fix red (src reverted, tests kept): 4 failures — incl. the stack test showing 70 KiB answering `HTTP/1.1 200 {"ok":true}` = the billed empty run. Regression-before-fix proven.
- Post-fix: `--filter 'HookWebhooks'` 20T green; `HookWebhooks|ServerProtocolDocumentationDriftTest` 25T OK (2 PHPUnit deprecations are environmental — base-untouched files show the same pair).
- Guards: five-guard family + ServerProtocolDocumentationDrift + TreeWideGuardRoster + StderrEmitterCensus + SuiteSkipRoster + FixtureLifetimeCensus + SymbolCitationDrift + DuplicatedDocBlockLine → only reds are the EXPECTED ReadmeSuiteFigureDrift staleness pair (live 25,544 vs pinned 25,537, +7T = 4 unit + 3 stack) — weld/gate re-pin duty per §1.5.

## Mutations (3, each exactly-1-red, md5-verified restore)
- M1 drop over-cap leg → declaration-leg unit test red. M2 drop declared-empty leg → chunked unit test red. M3 `>` → `>=` on the cap → exactly-the-cap stack test red.

## New test files (gate must add to durations TSV)
- `candy-code/tests/Server/HookWebhooksBodyCapStackTest.php` (3T: 70 KiB→413+no delivery; exactly-cap 65,536→200 with verbatim splice; small body→200).
- Modified `HookWebhooksTest.php`: +4 unit cases via a new `deliver()` helper (crafted post-middleware request shape: over-cap+empty, over-cap+bytes, chunked-empty, bodyless-declaration-positive-control).

## Deviations / notes
- None from the design block. Brief risk honored: real >64 KiB GitHub pushes now get a loud 413; per-hook body cap left for C-AGT-5.d.
- Leg fixtures are deliberately independent: 70 KiB through the real stack trips BOTH legs (documented in test comments), so leg-2 removal is caught only by the crafted chunked unit case and leg-1 only by the with-bytes unit case — the guard is not silently one-legged.
- E646: no timers added (guard is header+buffer inspection; test-side pump timers only). GlobDialect: no glob-shaped literals minted (figure untouched).
