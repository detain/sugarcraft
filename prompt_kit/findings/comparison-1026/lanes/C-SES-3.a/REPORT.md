# C-SES-3.a — DONE

Commit `6c5d1048615b042faf3533f6c7ce73a35f556864` on `cc/C-SES-3.a` (worktree `/home/sites/cc-cmp-C-SES-3.a`, base `ff10efc43`). Author AND committer `detain@interserver.net` (verified via separate `%ae` / `%ce` greps); zero Co-Authored-By trailers; never pushed. Tree clean after commit.

## What shipped (6 files, +460/−6)

**`candy-code/src/Share/ShareStore.php`**
- `public static revokeForSessionsSql(int $count): string` — the single home of
  `UPDATE shares SET revoked_at = ? WHERE revoked_at IS NULL AND session_id IN (?,?,…)`.
- `public liveAll(): list<array{record, sessionName, orphan}>` — every live share across
  sessions, LEFT JOIN on sessions for the name, orphans flagged; probes the sessions table
  (`hasTable`) so a shares-only database still answers with every row orphaned; never throws
  (PDOException → `[]`); newest-first ordering mirrors `liveForSession`.

**`candy-code/src/Session/SessionStore.php`**
- `deleteSessionTrees()` — inside the existing immediate transaction, after the per-chunk
  session DELETEs, a chunked `ShareStore::revokeForSessionsSql()` execute guarded by
  `hasTable('shares')`. Every deletion entry point (Chat, Subcommands, SessionMethods,
  pruneSessions) funnels through this one site, so delete and prune both revoke.

**`candy-code/src/Server/Server.php`**
- Access-log middleware now logs `self::redactShareToken($path)`: `/s/<token>` →
  `/s/<first 4 hex>…`, keyed on `ShareStore::URL_PREFIX`; non-share paths and bare `/s/`
  pass through. Class docblock chain item 2 and the middleware comment updated in the same
  commit (§1.8 doc-moves-with-guard law).

**Ruling honored:** revoke, not delete — the row survives with `revoked_at` set, stays
listable/auditable via `revoke()` semantics, and `sweepInertRows()` reaps it later.

## Tests

- NEW `candy-code/tests/Session/SessionDeleteRevokesSharesTest.php` — 5T/13A: deleteSession
  revokes (`lookup()` null, row survives revoked — the revoke-not-delete pin); pruneSessions
  revokes; subagent-tree children revoked while detached branch-children keep their live
  page; deletion tolerates a database without the shares table (green pre-fix by design —
  over-correction guard); structural source-slice pin: the revoke rides
  `ShareStore::revokeForSessionsSql(` and SessionStore never spells `UPDATE shares`.
- `candy-code/tests/Share/ShareStoreTest.php` — 16T/111A, +2 liveAll tests: shares-only DB
  spans sessions newest-first, revoked/expired absent; real SessionStore DB names live
  sessions and flags the orphan, and deleteSession drops the page from liveAll. Helper
  `ageSessions` byte-identical to SessionIndexAndRetentionTest's (licensed DuplicatedTestHelperDrift
  copy).
- `candy-code/tests/Server/SharePageTest.php` — 10T/37A, +1 live-server test: GET `/s/<token>`
  answers 200 and the captured log line is exactly `GET /s/<first4>… 200` with no full token
  on any logged line. Uniquely-named helpers (`pumpLoop`, `drainSocket`).

**Fails-before-fix proven** (src reverted to base via cp-backup + `git checkout`, md5-verified
restore): SessionDeleteRevokesSharesTest 5T/**4F**; ShareStoreTest liveAll 2T/**2E**
(undefined method); SharePageTest redaction **1F**.

**Green gates** (targeted only, plain pipe, cwd `candy-code`, `vendor/bin/phpunit -c phpunit.xml`):
- `--filter 'SessionDeleteRevokesSharesTest|ShareStoreTest|SharePageTest'` → 31T/161A OK
- per-file: 5T/13A OK · 16T/111A OK · 10T/37A OK
- directory run `tests/Session tests/Share tests/Server` → 689T/4610A OK
- guards `DuplicatedTestHelperDrift|DuplicatedDocBlockLine|GlobDialect|SymbolCitationDrift|TreeWideGuardRoster` → 57T/19727A OK
- `--filter 'Share|Session|Serve'` `tests/Protocol tests/Commands` → 94T/857A OK
- (2 ambient PHPUnit deprecations in filter-wide runs are pre-existing:
  `tests/Config/AccessorDeprecationTest.php:168/:202` non-public providers, fire at class-load
  for any whole-suite run including untouched tests; foreign fleet file, untouched.)

## Deviations / notes

- None from the design block. §6 rulings applied as written.
- New test file for the gate's durations TSV: `candy-code/tests/Session/SessionDeleteRevokesSharesTest.php`
  (ShareStoreTest/SharePageTest are pre-existing rows). No suite-figure.json/durations.tsv edits (forbidden).
- No doc pages or lang keys owed by this step; no guard-roster flips needed beyond the
  same-commit Server.php docblock move.
