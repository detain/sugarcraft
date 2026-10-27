### C-AGT-1.c — Webhooks refuse over-size bodies with 413
- item: C-AGT-1 (sources: AUT-6-P1)
- kind: fix · MAJOR · effort S
- approach: Root cause: `RequestBodyBufferMiddleware(MAX_API_BODY_BYTES)` (`src/Server/Server.php:241`) does not refuse an over-limit body; it swaps in an empty body and calls `$next`. `HookWebhooks::handle()` then splices `PromptFence::escape('')` (`src/Server/Http/HookWebhooks.php:130-133`) and spawns a billed turn (:136). Fix: before the splice, answer `413 payload_too_large` when `Content-Length` > `ServerConfig::MAX_API_BODY_BYTES`, or when a body was declared (`Content-Length` > 0 or `Transfer-Encoding: chunked`) and the buffered body is `''`. `$run` is never invoked on that path. Correct the :127-129 docblock and document the 413 in `docs/SERVER.md`.
- files.create: none
- files.modify: `candy-code/src/Server/Http/HookWebhooks.php` (`handle`, docblock), `candy-code/docs/SERVER.md` (the `POST /api/hooks/{name}` row, ~:299)
- tests: modify `candy-code/tests/Server/HookWebhooksTest.php` (`Content-Length: 70000` with an empty body → 413, `$run` never called); create `candy-code/tests/Server/HookWebhooksBodyCapStackTest.php` (posts 70 KiB through the real middleware stack → 413)
- docs/guards: `docs/SERVER.md` row; `ServerProtocolDocumentationDriftTest` stays green (the endpoint table wording only)
- serialized: [hot:candy-code/src/Server/Http/HookWebhooks.php] [docs:SERVER.md]
- deps: none
- phase: normal
- ruling: none
- risks: real GitHub push payloads over 64 KiB now get a loud 413 instead of a silent empty run. A per-hook body cap is out of scope (noted for C-AGT-5.d).

---

## C-AGT-2 — /review & CI action fixes

