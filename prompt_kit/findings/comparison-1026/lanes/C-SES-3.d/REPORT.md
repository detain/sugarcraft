# C-SES-3.d — Live read-only share links — LANDED

Commit `f899d0551` on `cc/C-SES-3.d` (base master `cc14518d5`), 12 files, +473/−48.
Author/committer `%ae`/`%ce` both detain (separate greps = 1/1), zero trailers, tree clean.
Ruling §6.3 (2026-10-28) honored: anonymous LIVE read-only links shipped as designed.

## What shipped
- `ShareStore`: `live INTEGER NOT NULL DEFAULT 0` column — fresh DBs via CREATE_TABLE, old
  DBs via idempotent PRAGMA-gated `ALTER_LIVE` in new `ensureSchema()` (runs wherever the bare
  CREATE ran: atPath/publish/revoke; read-only-DB migration failure stays fail-safe, comment-body catch).
  `publish(..., bool $live = false)`; all three readers (lookup/liveForSession/liveAll) carry `live`.
- `ShareRecord`: `public readonly bool $live = false` (last param, named-arg BC); docblock split
  snapshot-law vs live-law truthfully.
- `SharePageController`: optional `?Closure $liveRenderer` ctor param; live row + renderer ⇒
  per-request re-render, `null` ⇒ the SAME shared 404 (no new death shape); no renderer ⇒ stored
  snapshot fallback; snapshot rows never consult the renderer. Cache-Control: no-store unchanged
  for every page, now load-bearing for live ones. Docblock rewritten (old "never from the live
  transcript" claim was falsified for live rows).
- `Serve.php` (declared :406 region only): wires the renderer over the already-built
  `Bootstrap::sessionStore(prune: false)` — `loadTranscript` null-check (session gone → 404),
  `TranscriptStore::reviveRow` (public static, same revive path Chat::loadTranscript uses),
  `(new ShareSession(...,FORMAT_HTML))->serialize()` — the SAME serializer, row filter and
  SecretRedactor a snapshot passed (serializer reuse; round-1 erratum: the design's
  "row cap" risk-line was a false premise — ShareSession's filter is a visibility
  filter, not a size bound, no row-count cap exists, and the live path re-serializes
  the full filtered transcript per request). `catch (\Throwable) return null`
  = statement body (not bare) so SwallowingCatchSrcCensus unaffected.
- `ShareCommand`: `--live` flag stripped (strtolower match, one occurrence) before the existing
  shape checks — second flag/`--lively` fall into existing bad-days refusal, extra word into
  too-many; publish gains `live:`; reply `cmd.share.live-served` (snapshot `cmd.share.served`
  byte-identical); list rows badge ` live` only on live rows.
- `lang/en.php`: hint/usage gain `[--live]`; new `cmd.share.live-badge`, `cmd.share.live-served`
  (alphabetical, en-only lib — LangParityTest provider globs lang/*.php = en).
- Docs: COMMANDS.md prose paragraph rewritten + `tools/gen-command-docs.php --write` regen
  in-step (table row :349), `--check` rc0. **SERVER.md untouched — disclosed deviation:** its only
  share surface is the session.share RPC row INSIDE the `protocol:methods:begin` generated region
  (gen-protocol-schema — lanes never commit it), and that RPC still publishes snapshots only
  (SessionMethods.php:303, 4-arg publish, unchanged). No non-generated share prose exists there;
  COMMANDS.md is the prose surface for the flag.

## Regression-first
Pre-src run (regression-before.txt): 6 errors + 6 failures — unknown named param `$live`,
`->live` on null, `serve --live` exit 1, old usage/hint needles. Post-src: all green.

## Gates (plain-pipe, cwd candy-code, env -u LINES -u COLUMNS)
- `--filter 'SharePage|ShareStore|ShareCommand|CommandDocs|CommandRegistry|LangParity'`:
  **OK — Tests: 114, Assertions: 3468, 0F** (2 PHPUnit Deprecations pre-existing at base).
- Broad `--filter 'hare'` (365 share-family tests incl session.share RPC + router/access-log):
  **OK — 365, 4731A, 0F**.
- `php -l` clean on all 6 touched src/lang files; gen-command-docs --check rc0.
- KNOWN pre-existing base-red disclosed, NOT touched: SwallowingCatchSrcCensus accuses
  `src/Tools/BuiltIn/SkillTool.php::execute` (bare-empty catch) — file and test both unmodified
  by this lane (git diff empty); landed by another program before the census.

## Mutation proofs (cp /tmp harness, md5-verified restores)
- Neuter controller live branch → 3 SharePage reds (live-render, gone-session, revoke-frozen).
- Remove ALTER exec → 1 ShareStore red (old-table migration).
- Neuter list badge → testListBadges red (first perl pattern was a no-op false-green — redone
  with verified diff, then red; restore md5-identical all three).

## Files
src/Share/ShareStore.php, src/Share/ShareRecord.php, src/Server/Http/SharePageController.php,
src/Cli/Serve.php, src/Commands/ShareCommand.php, lang/en.php, docs/COMMANDS.md;
tests/Server/SharePageTest.php, tests/Share/ShareStoreTest.php, tests/Commands/ShareCommandTest.php,
tests/Commands/CommandRegistryTest.php, tests/Commands/CommandDocsDriftTest.php (needles in-step).
No new test FILES → no durations.tsv rows. suite-figure.json/dists/protocol files untouched. Never pushed/stashed.
