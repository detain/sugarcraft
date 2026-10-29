### C-AGT-5.d — Signed inbound webhooks + delivery dedupe
- item: C-AGT-5 (sources: p1-miss: inbound webhook signatures)
- kind: gap · effort M
- approach: Each `webhooks` row gains an optional `signature: {scheme: "github-sha256"|"hmac-sha256", header?}` (`ServerConfig::webhooksValue()` ~:700-770).
  - With a scheme, `HookWebhooks::handle()` verifies `sha256=<hex>` over the RAW body with the row's secret through `hash_equals`, in place of the `x-candy-code-hook` header, because GitHub cannot send custom headers.
  - A per-hook LRU of delivery ids (`X-GitHub-Delivery`, or a configured `deliveryHeader`) answers a replay `200 {ok:true, duplicate:true}` without spawning.
  - Failed signatures feed the same lockout limiter.
  - A GitHub recipe goes in `docs/SERVER.md`.
- files.create: none
- files.modify: `candy-code/src/Server/ServerConfig.php` (`webhooksValue`), `candy-code/src/Server/Http/HookWebhooks.php` (`handle`, dedupe ring), `candy-code/lang/en.php` (`settings.webhooks.help`), `candy-code/docs/SERVER.md` (hooks row + recipe)
- tests: create `candy-code/tests/Server/HookWebhooksSignatureTest.php` (a valid GitHub signature runs; a bad one → 401 and counts toward lockout; a replayed delivery id spawns nothing); modify `candy-code/tests/Server/ServerConfigWebhooksTest.php` (`signature` parse and refusals)
- docs/guards: `docs/SERVER.md`; `candy-code/tests/Config/ServerProtocolDocumentationDriftTest.php` stays green
- serialized: [hot:candy-code/src/Server/Http/HookWebhooks.php] [hot:candy-code/src/Server/ServerConfig.php] [lang: `settings.webhooks.*`] [docs:SERVER.md]
- deps: C-AGT-1.c, C-AGT-7.c
- phase: normal
- ruling: none
- risks: GitHub push payloads often exceed the 64 KiB global body cap (now a loud 413 from C-AGT-1.c). A per-route larger cap would be a separate server change.

