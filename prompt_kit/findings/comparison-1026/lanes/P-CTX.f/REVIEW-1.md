# P-CTX.f review (cc/P-CTX.f @ 827e37702, base b24e81154) — static (php denied in lane)

Reviewer: ses_e820d5452ffew77FNhbWtlP3UZ (read-only; persisted verbatim by lead 2026-10-27 @974).

- **Tip verified:** `git log b24e81154..HEAD` = single commit `827e37702`; subject matches brief claim.
- **§1.8 restore law honored:** diff touches exactly `src/Host/ContextSources.php` (+36), `src/Protocol/Schema/Definitions.php` (+23), `tests/Protocol/ContextSourcesTest.php` (+117/−1); `git diff --stat` on `docs/protocol/`, `docs/SERVER.md`, `candy-code-web` is empty; worktree porcelain clean.
- **serialize():** `categories` rows map `ContextCategories::from($b)->rows` 1:1 (key=case value, label=`case->label()`, nullable tokens, `share`=(int) round % of window, null iff tokens null or window≤0; `sub` = the five `ContextCategories::sub()` fields verbatim — label/tokens/bytes/personal/note, all always present); `buffer` = `ContextCategories::bufferTokens($b)` (static; 0 off / null no-config, matches enum doc); `mcpServers` = `$b->mcpServers` pass-through ({name,count,tokens} per ContextBreakdown:81). Nothing re-measured; Definitions declares only.
- **Definitions.php:** three members added as properties; required list byte-identical (diff context) — old clients omitting them still validate. Back-compat ✓.
- **answer() untouched:** hunks only at :11 (import) + :221/:237/:270 (serialize) — C-MCP-4 seam clean.
- **Tests non-vacuous:** flipped ksorted key-list pin reddens any dropped key; real-session pin asserts enum order/vocabulary, share↔tokens null coupling, honest nulls (system-prompt/buffer/mcpServers), summing law off the wire; deterministic pin asserts share=3 (30/1000), doc sub bytes+personal exact, mcp sub label sequence incl. not-sent note row, buffer 0 under MODE_OFF, mcpServers shape equality + count. Mutation "drop categories" → ≥3 reds.
- **Author law:** `%ae=%ce=detain@interserver.net`, zero trailers.
- **§1.9 / E646:** no denial-vocabulary literals; the `mcp__<server>__*` token is a space-bearing sentence string → outside GlobDialect `isGlobShaped` harvest (builder's green GlobDialect gate consistent); no timers.

**Weld re-run duty** (reviewer could not execute php): cwd `candy-code`, plain pipe, `env -u LINES -u COLUMNS`:
1) `vendor/bin/phpunit -c phpunit.xml --filter 'ContextSources|ProtocolSchemaDrift|ServerProtocolDocumentationDrift'` — expect 22T/443A (2 pre-existing deprecations);
2) five-guard filter — expect ONLY the ReadmeSuiteFigureDrift staleness pair (~25,537 pinned vs live +2);
3) `php scripts/gen-protocol-schema.php --write` → `cd candy-code-web && npm run gen:protocol && npm run build`; commit trio + dist only if build changed (SERVER.md expected byte-unchanged by generator per lane REPORT).

---

VERDICT: CLEAN
- [NIT out-of-scope] Definitions.php:311 — schema describes `share` as "null exactly when `tokens` is", but serialize also nulls it when window≤0 (unreachable in production; ContextBreakdown::percentage answers 0 there). Wording-only; weld may fold a sentence fix into the trio-regen commit.
