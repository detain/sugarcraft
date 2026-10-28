# BRIEF — C-SES-3.d — Live read-only share links
- area: ses | kind: feature · effort M | effort:  | wave-deps: C-SES-3.b
- item: C-SES-3 (sources: CLAW-new#8)
- serialized: [lang:cmd.share.live] [docs:SERVER.md] [docs:COMMANDS.md]
- ruling: Ship anonymous LIVE read-only links, where anyone holding the token sees future messages until revoke or expiry?
- risks: Rendering per request costs a transcript load on the serve loop. Bound it with the existing `ShareSession` row cap and send `Cache-Control: no-store`.
- files.create: none
- files.modify: `candy-code/src/Share/ShareStore.php` (schema migration, publish($live)), `candy-code/src/Share/ShareRecord.php` (live), `candy-code/src/Server/Http/SharePageController.php` (__construct, __invoke), `candy-code/src/Cli/Serve.php` (controller construction near `:406`), `candy-code/src/Commands/ShareCommand.php` (serve(): `--live`), `candy-code/lang/en.php` (`cmd.share.live*`, `cmd.share.hint`), `candy-code/docs/SERVER.md` (share-page section), `candy-code/docs/COMMANDS.md` (regen)
- tests: modify:
- `candy-code/tests/Server/SharePageTest.php`: a live link shows a message appended after publish; revoke and expiry still 404; a snapshot link is unchanged;
- `candy-code/tests/Share/ShareStoreTest.php`: the `live` column migrates on an old table;
- `candy-code/tests/Commands/ShareCommandTest.php`: `--live` parsing.
- docs/guards: `CommandDocsDriftTest`. SERVER.md prose only; the method roster is unchanged.

## Approach


- **What it adds.** `/share serve --live [days]` publishes a link that re-renders the session's transcript at request time, so a reader sees existing and future messages. It keeps the same token, expiry and revoke semantics as snapshot links.
- **Storage.** `shares` gains `live INTEGER NOT NULL DEFAULT 0` through an idempotent ALTER in `ShareStore`. `ShareRecord` gains `live`.
- **Serving.**
- `SharePageController` takes an optional `Closure(string $sessionId): ?string` renderer. `Serve.php` builds it over `Bootstrap::sessionStore()`, `TranscriptStore` and `ShareSession` HTML.
- Live rows render through that closure. Snapshot rows keep the stored HTML.
- A live link to a deleted session is 404, which C-SES-3.a's revoke already guarantees.
- **What the user sees:** a "live" badge in `/share list`.
