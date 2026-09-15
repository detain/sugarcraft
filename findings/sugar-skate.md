# Audit: sugar-skate

**Library:** SugarCraft/sugar-skate — PHP port of charmbracelet/skate (personal key/value store, SQLite-backed, multi-database)  
**Date:** 2026-09-15 (first-ever audit; E719, round 82)  
**Auditor:** coder (lane q6)  
**Baseline:** master c489210e6 · suite green at baseline (206 tests / 427 assertions, PHP 8.3.6)

---

## Health Summary

sugar-skate is a **healthy lib**. Zero P0 findings: no `proc_open`/fork in `src/`, no external-CLI
invocation at all (so the AGENTS `escapeshellarg` law is vacuously satisfied), no `exit`/`die` in
`src/` (only in `bin/skate`, which is legitimate), no unbounded loops/pumps, every SQL statement is
prepared with bound parameters (no interpolation), `declare(strict_types=1)` in every file, every
public class is `final`, `Entry` is a true immutable readonly value object, the DB-name choke point
(`Store::dbPath`) validates against `[A-Za-z0-9_-]+` with traversal tests, and stored values are
terminal-sanitized on list (`Store::sanitizeForTty`) with an ESC-smuggling regression test. i18n
goes through the `Lang::t` facade extending `SugarCraft\Core\I18n\Lang`. The findings below are
P1/P2 polish and edge-case hardening — the profile of a lib that already went through a remediation
pass ("steps 12–15", commits `fcef4bdc8`, `97f8381b7`, `fe745d048`, `c8fcf8c56`), not a raw port.

Severity map used here: **P0** critical (none), **P1** high, **P2** medium, **P3** low/informational.

---

## 1. CORRECTNESS BUGS

### 1.1 [P1] YAML fallback emitter corrupts values containing newlines

**Location:** `src/Cli/ExportCommand.php:129-155` (`yamlString()`), trigger at `:151`

```php
\preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)
```

The needs-quoting control scan **deliberately excludes \x09 (TAB), \x0a (LF) and \x0d (CR)** and no
other quoting trigger fires for a plain embedded newline. A stored value `"line1\nline2"` is emitted
raw, producing a two-line YAML document where `line2` is no longer part of the value:

```
multi: line1
line2
```

**Verified repro** (PHP 8.3.6, master tree, 2026-09-15): export of a newline value, then re-import via
`YamlImporter::importFromString`, throws `RuntimeException: Syntax error` — the fallback parser rejects
its own emitter's output. The round-trip is lossy AND loud-broken. The symfony/yaml path handles this
(`DUMP_MULTI_LINE_LITERAL_BLOCK`, `:103`), but symfony/yaml is not a dependency of this lib, so the
broken fallback is the shipped default (the lib's own `YamlFallbackTest` header states it is the only
path exercised). No round-trip test covers `\n`, `\r` or tab in values.

**Recommendation:** add `\x0a`/`\x0d` (and decide explicitly about `\x09`) to the quoting trigger, keep
the existing `addcslashes($value, "\\\"\n")` escaping, and pin with a round-trip test per control byte.

---

### 1.2 [P1] README Quick Start documents a call shape that silently corrupts data

**Location:** `README.md` (Quick Start block)

```php
$skate->set('token', 'ghp_xxxx', 'passwords');
echo $skate->get('token', 'passwords');
```

`Store::set()`'s real signature is `set(string $key, string $value, bool $binary = false, ?int $ttlSeconds = null)`
(`src/Store.php:85`) and `get(string $key, string $fallback = '')` (`:114`). Under a non-strict-types
caller (the README snippet has no `declare(strict_types=1)`), `'passwords'` coerces to `true` →
the value is stored **base64-encoded with the binary flag set**, and `get('token','passwords')`
returns the base64 text (with `'passwords'` silently serving as the fallback). The documented
multi-database idiom is the `@dbname` key suffix (`src/Store.php:79-81`), which `examples/multidb.php`
and the tests use correctly — only the README is wrong. A user copying the Quick Start gets corrupted
secrets with no error.

**Recommendation:** change the two lines to `$skate->set('token@passwords', 'ghp_xxxx');` /
`echo $skate->get('token@passwords');`.

---

### 1.3 [P2] `globToLike()` does not escape the backslash (the LIKE ESCAPE character) itself

**Location:** `src/Database.php:259-281`, consumed by `count()` `:220-226`, `deleteMany()` `:244-247`, `buildGlobQuery()` `:387-399`

`%` and `_` are backslash-escaped, but a literal `\` in the pattern falls through unescaped while the
queries declare `ESCAPE '\'`. A pattern that contains a backslash therefore eats the following
character's meaning, and a trailing `\` dangles.

**Verified repro:** key `a\b` stored; `count('a\b')` returns **0** (the LIKE `a\b` matches literal `ab`,
not `a\b`); `count('dangling\')` returns 0 rather than erroring or matching `dangling\`. Silent
wrong-count / wrong-match / wrong-delete-set for any key containing a backslash.

**Recommendation:** in the `else` branch, also emit `'\\' . $c` when `$c === '\\'`.

---

### 1.4 [P2] Reserved key `_ttl` collides with user data and crashes export

**Location:** `src/Cli/ExportCommand.php:50-71`

`_ttl` is metadata in the export format but is not reserved in the store. `set('_ttl', 'mine')` plus
any TTL-bearing entry makes `$entries['_ttl'][$key] = $ttl` index into a string:
**verified** — `TypeError: Cannot access offset of type string on string` out of `exportToString('json')`
(caught by `run()` as `Export failed:`/exit 1, but the store can never export again while the key exists).
Without a TTL entry the collision instead silently shadows the user's `_ttl` value on re-import.
The YAML emitter fallback has the same confusion (`skate_ttl_*` rows are appended per `:120-124`).

**Recommendation:** either reject `_ttl` as a key at `Store::set`/import time (loud, parse-don't-validate)
or namespace metadata (`{"_skate":{"ttl":…}}`); a rename would need import-side back-compat reading the old shape.

---

### 1.5 [P2] Binary entries are not exportable — `skate export json` fails whole-document

**Location:** `src/Cli/ExportCommand.php:66` (`$entries[$key] = $entry->rawValue();`) + `:86-94`

The export drops the `binary` flag and emits `rawValue()` (decoded bytes). **Verified:** one
`set('bin', "\x00\xff\xfe", true)` entry makes `json_encode` fail — `Failed to encode JSON:
Malformed UTF-8 characters` — so the entire export errors out; a UTF-8-clean "binary" value instead
round-trips as *non-binary* text (flag lost). YAML export has the same defect with worse manners
(raw control bytes partially quoted).

**Recommendation:** export binary entries with an explicit marker (e.g. `{"_skate_binary": {key…}}`
side-map or a `"b64:…"` prefix) and teach both importers the inverse, or document loudly that
export is text-only and skip-with-count binary entries.

---

### 1.6 [P2] `deleteDatabase()` leaks WAL sidecars when another holder keeps the connection open

**Location:** `src/Store.php:321-329`

`deleteDatabase` drops its cache slot and unlinks `<db>.db` but never calls `Database::close()` and
never removes `<db>.db-wal` / `<db>.db-shm`. If a live iterator holds the `Database` (e.g. an
in-flight `Store::list()` generator — `src/Database.php:166-200` binds the statement to the same
connection), refcounting delays the close, the main file is unlinked underneath an open WAL-mode
connection, and after the eventual close the sidecars remain on disk.

**Verified repro:** start a `list()` iteration on db `wipe`, call `deleteDatabase('wipe')`, finish
GC → data dir still contains `wipe.db-shm` and `wipe.db-wal`. A later `set('x@wipe', …)` reopens a
fresh DB next to stale sidecars.

**Recommendation:** pull the entry out of the cache into a local, `close()` it explicitly before
`unlink()`, then also unlink `-wal`/`-shm` if present.

---

### 1.7 [P2] Importers reach into `Store`'s private via Reflection instead of the public `Store::transaction()`

**Location:** `src/Import/JsonImporter.php:124-130` and `src/Import/YamlImporter.php:123-129` (byte-similar copies)

```php
$reflection = new \ReflectionClass($this->store);
$method = $reflection->getMethod('database');
$method->setAccessible(true);
$db = $method->invoke($this->store, $targetDb);
return $db->transaction($import);
```

`Store::transaction(string $dbName, callable $fn)` is public (`src/Store.php:358-361`) and does exactly
this. The reflection block (1) defeats the private-API contract, (2) will silently rot if `Store::database`
is ever renamed, (3) forces both importers to carry the whole db-routing pre-scan inline instead of
sharing it.

**Recommendation:** replace with `$this->store->transaction($targetDb, $import)`; additionally factor the
duplicated atomic/dbs-collection block out of both importers (it is ~35 near-identical lines).

---

### 1.8 [P2] `$delimiter` on the list API is dead — documented behavior unimplemented

**Location:** `src/Store.php:262,265-274`, `src/Database.php:163,166-200`

Both docblocks promise "Delimiter between key and value when mode='all'", but `Database::list()` never
reads `$delimiter` — with `mode='all'` it yields `Entry` objects and the delimiter is a no-op. The CLI
happens to build `key{delim}value` itself (`bin/skate:83`), masking the dead parameter. A library caller
following the signature gets silently ignored options.

**Recommendation:** either drop the parameter (BC note) or document that delimiting is a renderer-side
concern and return `'all|keys|values'` strings accordingly.

---

### 1.9 [P2] YAML importer trusts the parser result type (asymmetry with the guarded JSON path)

**Location:** `src/Import/YamlImporter.php:150-159`

`JsonImporter` was hardened to reject a non-array top level via candy-core `Json::decodeArray`
(commit `fe745d048`, pinned by `testJsonImporterRejectsNonArrayTopLevel`). The YAML path never got
the equivalent guard: `Yaml::parse('42')` (or an empty file → `null`) returns a scalar, and the
downstream `foreach ($data as …)` emits `Warning: foreach() argument must be of type array|object`
then reports a **successful 0-entry import**. Dormant while symfony/yaml is absent (fallback parser
already throws `RuntimeException: Syntax error` on such input), live the moment a consuming app
installs symfony/yaml — `class_exists()` at `:153` then silently switches behavior per deployment.

**Recommendation:** `if (!is_array($parsed)) throw new RuntimeException('Expected YAML top level to be a mapping');` after `parseYaml()`, with a symfony-present test gate or a stubbed class_exists harness.

---

### 1.10 [P2] `_ttl` map values are not validated — hostile file crashes import mid-transaction

**Location:** `src/Import/JsonImporter.php:74-91`, `src/Import/YamlImporter.php:71-90`

**Verified:** `{"_ttl":{"k":"soon"},"k":"v"}` → `TypeError: Store::set(): Argument #4 ($ttlSeconds)
must be of type ?int, string given` at JsonImporter.php:91. The transaction rolls back, but a
file-shaped input should fail with a pointing message (parse-don't-validate), not an internal
TypeError. (YAML side is accidentally safe: `(int)` cast at `:74` after an `is_numeric` gate.)

---

### 1.11 [P3] `suggestSimilar` / threshold trivia

**Location:** `src/Store.php:215-234`

`(int)(strlen($key)/2)` makes single-char keys un-suggestable (threshold 0, distance 1 fails) and the
linear scan is O(keys) with a 255-byte-per-side `levenshtein` cap — a >255-char key scans every
candidate through the capped comparison. **Measured:** PHP 8.3 `levenshtein(str_repeat('q',300),…)`
returns a real distance (no `-1`), so current behavior is merely imprecise for very long keys, not
broken. Informational.

---

## 2. CLI ROBUSTNESS

### 2.1 [P2] `bin/skate` set/get/delete/list paths have no exception guard — fatal trace on bad input

**Location:** `bin/skate:43-104` (contrast `:106-130` where import/export do `run()`-wrap Throwable)

`import`/`export` funnel through `run()` and print a clean one-line error; the other commands call
`Store` directly, and `parseKey`/`dbPath` **throw by design** (`src/Store.php:387-391, 413-417`).
**Verified:** `skate set a@b@c v` →

```
PHP Fatal error:  Uncaught InvalidArgumentException: Entry key 'a@b' cannot contain @ …
Stack trace: … (exit 255)
```

The lib's own `CliSmokeTest::assertNoFatal()` doctrine (W15 lesson) is not applied to these paths.
**Recommendation:** wrap the switch bodies (or the whole dispatch after Store construction) in
try/catch printing `Lang::t`-ed error + exit 1, and extend CliSmokeTest with the `a@b@c` and
`key@bad.name` cases.

---

### 2.2 [P3] `Store` construction side effects; `--help` still creates `~/.config/skate/default.db`

**Location:** `bin/skate:40` (Store built before the `help` arm), `src/Store.php:65-69`

**Recommendation:** build Store lazily per command (help/list-dbs-only paths still need it for list-dbs;
help does not).

### 2.3 [P3] Unwritable data dir → raw `PHP Warning: mkdir()` then a misleading SQLite error

**Location:** `src/Store.php:65-67`

**Verified:** `$ro/nested` under a 0500 parent prints `Warning: mkdir(): Permission denied in …/Store.php on line 66`
then throws `Unable to open database file` — two errors, wrong emphasis, no path named.
**Recommendation:** suppress + check: `!is_dir && !@mkdir(...) && !is_dir` → throw `RuntimeException(Lang::t('store.cannot_create_dir', ['path'=>…]))` (needs new key).

### 2.4 [P3] `--ttl=` non-numeric silently becomes "no expiry"

**Location:** `src/Cli/ArgParser.php:33-34` — `(int) substr(…)` maps `--ttl=soon` to 0, which
`Store::set` treats as "never expires". Fail-fast would reject. Also bare `--ttl` (no `=`) is dropped
by the unknown-flag arm.

### 2.5 [P3] Unknown flags are ignored across `set`/`list`/`export`

**Location:** `src/Cli/ArgParser.php:35-36,74-75,135-136` — `skate export --foo json` proceeds silently.
House law "pass ALL external-CLI flags every invocation" is not the issue here (no external CLI), but
argparse best-practice is to reject unknown flags for a user-facing tool.

---

## 3. I18N

### 3.1 [P2] `database.query_failed` key missing from `lang/en.php` → raw key surfaces as user error

**Location:** `src/Database.php:214, 230` throw `Lang::t('database.query_failed')`; `lang/en.php` defines
only `database.entry_unreadable`, `store.cannot_read` and the `cli.*` set. Per the lookup contract
(exact → base → `en` → raw) the user sees `skate.database.query_failed` — **verified**.
**Recommendation:** add the key to `en.php` (and it is then translatable).

### 3.2 [P3] Import/Export command strings bypass `Lang::t` while two orphan keys sit unused

**Location:** `src/Cli/ImportCommand.php:38,46,56,66,69`, `src/Cli/ExportCommand.php:34`; `lang/en.php:23-24`
defines `cli.import_success` / `cli.export_success` which nothing references (and bin's actual success
line at `ImportCommand.php:66` duplicates the text untranslated). Also `JsonImporter`/`YamlImporter`
hard-throw English (`"Cannot read JSON file: …"`, `'Atomic import is not supported …'`) while
`Store::setFile` uses `Lang::t` — inconsistent within one lib.

---

## 4. CONVENTION & HYGIENE

### 4.1 [P3] Implicit nullable parameter — PHP 8.4 deprecation on CI

**Location:** `src/Store.php:267` — `string $dbName = null`. CI runs `['8.3','8.4']`
(`scripts/affected-libs.php:55`); on 8.4 this raises `Deprecated: Implicitly marking parameter $dbName
as nullable is deprecated`. Fix: `?string $dbName = null` (signature-compatible).

### 4.2 [P3] Docblock upstream-cite convention under-used

House convention is `Mirrors charmbracelet/<repo>.<Method>` on ported surfaces; sugar-skate cites
`charmbracelet/skate` only in the `Store.php` header `@see` and `ArgParser.php:10`. `Database`, `Entry`,
importers, and export carry no per-method Mirrors lines. Cosmetic — the research doc
(`docs/research/libraries/sugar-skate-research.md`) documents upstream semantics well.

### 4.3 [P3] Dead code / lint nits

- `src/Store.php:42` — `public const DEFAULT_SUBDIR = '.config/skate'` referenced nowhere.
- `src/Store.php:22`, `src/Database.php:7` — `use SugarCraft\Skate\Lang;` self-namespace imports (no-ops).
- `src/Database.php:52-59` — `query()` result not checked for `false`; PRAGMA loop result never `finalize()`d.
- `src/Database.php:78,96,136,150,189,227,249,298` — `execute()` results unchecked for `false` (defensible since failure needs a closed DB, which Errors first, but the pattern diverges from `count()` which does check).
- `src/Database.php:191-199` — generator's `$stmt->close()` skipped on early `break` (no try/finally).
- `src/Database.php:339-358` — a failing `COMMIT` runs `ROLLBACK` inside the `catch`, which can throw and mask the original error (wrap the rollback best-effort).
- `src/Cli/ImportCommand.php:31-35` — the matched `$importer` is only a format validator; a second importer is re-instantiated per branch (`:50-52, 59-63`) — use the variable.

### 4.4 [P3] README metadata drift

Badge says `php-≥8.1` (composer requires `^8.3`); packagist badge links `sugarcore/sugar-skate`
(wrong vendor — repo package is `sugarcraft/sugar-skate`); README "Features" advertises export of
"entries in JSON or YAML" without the text-only / newline caveats from §1.1/§1.5.

---

## 5. TEST-SUITE NOTES

No stream-write (`ftruncate;rewind`) anti-patterns — the lib has no stream-capture tests (CLI E2E
uses `proc_open` pipes, correctly closed and `proc_close`-reaped, though `CliSmokeTest::runSkate` has
no wall-clock bound — a hang wedges the suite; house precedent: bounded-child traits from candy-*/sugar-crush).
Coverage per public method: **good** — Store/Database/Entry/ArgParser/Importers/Commands are all
exercised, including traversal guards and sanitizeForTty. Gaps to close (beyond the bugs above,
which each lack a failing-first test): (a) round-trip with `\n`/tab values; (b) backslash-in-glob;
(c) `_ttl` user-key collision; (d) binary export; (e) deleteDatabase sidecar cleanup; (f) `bin/skate`
bad-key fatal paths (CliSmokeTest extension); (g) YAML symfony-present parse-shape. Two weak tests:
`StoreTest.php:457-483` (`if (... !== false)` guards make the ordering leg vacuous) and
`ImportExportTest.php:240-268` (`testJsonExportWithTtl` never inspects the export output — asserts
store state instead; also the dead `$allKeys = $this->store->list();` line that leaks an unconsumed
generator).

---

## 6. Positive Patterns (keep as reference examples)

- `Store::sanitizeForTty` + `bin/skate list` wiring + regression pin = the model for TTY injection defense.
- Single-choke-point `dbPath()` name validation with a full traversal fixture table in `StoreTest`.
- `ON CONFLICT DO UPDATE` upsert preserving `created` (`src/Database.php:121-136`).
- Guarded `Json::decodeArray` boundary parse (`fe745d048`) — extend the same posture to YAML (§1.9).
- `Database::transaction` re-entrancy guard (`:341-343`) and idempotent `close()` (`:366-373`).
