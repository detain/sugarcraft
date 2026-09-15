---
status: not-started
phase: 1
updated: 2026-09-15
---

# Implementation Plan: sugar-skate Audit Findings (E719)

## Goal

Remediate every finding in `findings/sugar-skate.md` (first-ever audit of this lib, round 82): fix the
two empirically-verified P1 defects (YAML newline round-trip corruption; README's data-corrupting
Quick Start), the PHP 8.4 deprecation, and the P2 correctness/robustness edges (backslash glob, `_ttl`
collision, binary export, CLI fatal paths, WAL sidecar leak, unvalidated TTL types, missing i18n key,
reflection API-bypass), then close the listed test gaps with failing-first tests. No P0 findings
exist — the lib is healthy; scope is polish, not rescue.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| All §1 bug claims are **reproduced** on PHP 8.3.6 at master c489210e6 (not inferred) | verify-script evidence at `/home/sites/crush-r61-artifacts/q6/` (V1-V11); baseline suite green 206T/427A | `findings/sugar-skate.md` §1-§2 |
| symfony/yaml is **not** a dependency, so the fallback emitter/parser is the shipped default | `composer.json` requires only php/candy-core/candy-fuzzy/ext-sqlite3; `YamlFallbackTest` header says so | `sugar-skate/composer.json:26-31` |
| Fix emitter+parser pair in lockstep — never fix only one side (round-trip contract) | fallback parser rejects fallback emitter's own newline output today | `ExportCommand.php:151`, `YamlImporter.php:191-212` |
| `_ttl` reservation: reject at `Store::set`/import boundary (fail-fast) rather than rename the format | renaming needs an import back-compat reader; reject is one guard + two messages | `findings/sugar-skate.md:1.4` |
| Binary export: side-map marker `"_skate_binary": [keys]` in JSON, `skate_binary_<key>: b64` in YAML — same philosophy as existing `_ttl`/`skate_ttl_` | keeps plain values plain; importers already strip metadata maps | `JsonImporter.php:74-79`, `ExportCommand.php:107-124` |
| `Store::transaction($dbName, $fn)` is the sanctioned route — the importers' reflection is redundant | public method already does `database($dbName)->transaction($fn)` | `Store.php:358-361` |
| DROP the dead `$delimiter` list-API param (BC note in CHANGELOG) rather than implement it — `mode='all'` yields `Entry` objects, delimiting is a renderer job | `bin/skate:83` already renders its own delimiter; no consumer exists | `findings/sugar-skate.md:1.8` |
| CLI fatal fix = one try/catch around the dispatch switch in `bin/skate`, reuse the `ImportCommand::run` message shape | import/export already prove the pattern; set/get/delete/list lack it | `bin/skate:106-130` |
| i18n: every new/renamed user-facing string goes through `Lang::t` + `lang/en.php` (add `database.query_failed` + new keys); `cli.import_success`/`cli.export_success` get WIRED, not deleted | house rule: i18n via Lang::t; orphans prove the wiring was the intent | `lang/en.php:23-24`, `Database.php:214,230` |
| `?string $dbName = null` is signature-compatible | implicit-nullable deprecation only fires on 8.4; CI matrix includes 8.4 | `scripts/affected-libs.php:55` |
| YamlImporter symfony-path guard must be tested WITHOUT installing symfony (stub autoload or class_exists seam note) | lib's composer.json deliberately omits symfony | `YamlImporter.php:153` |
| No per-lib `repositories[]` anywhere in this plan (path-repo policy) | AGENTS.md composer rule | `AGENTS.md` |

---

## Phase 1: P1 correctness [PENDING]

### 1.1 YAML fallback emitter: quote values containing newlines/CR [PENDING] ← CURRENT

**What is expected:** `ExportCommand::yamlString()` treats `\x0a` and `\x0d` as needs-quoting triggers
(extend the control-class regex at `:151`; decide+document tab: quoting `\t` inside double quotes is
already safe for the fallback parser — recommend also triggering on tab for symmetry), keeping the
existing `addcslashes($value, "\\\"\n")` body. Fallback parser must round-trip the quoted form
(it already strips surrounding quotes and the emitter escapes `"`/`\`).

**Why the change should be done:** verified round-trip corruption — export of a `"line1\nline2"` value
re-imports as `RuntimeException: Syntax error` (V1). Silent until it crashes; the advertised
import/export feature is broken for any multi-line value on the default install.

**Severity:** P1 (HIGH)

**Conditions for success:**

- Failing-first tests in `YamlFallbackTest`: round-trip for `\n`, `\r\n`, and tab-bearing values; plus a mixed table.
- No regression in the 9 existing `testYamlRoundTrip*` cases.

**Related code locations:**

- `src/Cli/ExportCommand.php:129-156` (`yamlString`)
- `src/Import/YamlImporter.php:166-216` (`minimalYamlParse` — quote-stripping already at `:196-199`)

**Investigation notes:**
```
V1 repro output (2026-09-15):
  EXPORTED: multi: line1\nline2\n
  RE-IMPORT THREW: RuntimeException: Syntax error
The exclusion set of the preg at :151 is exactly \x09,\x0a,\x0d — the three whitespace
controls YAML quoting exists for.
```

### 1.2 README Quick Start: correct the multi-database example [PENDING]

**What is expected:** replace `set('token', 'ghp_xxxx', 'passwords')` / `get('token', 'passwords')`
with the `@passwords` key-suffix idiom; scan the rest of README (and `docs/_data/sugar-skate.body.html`
if it repeats it — regenerate via `tools/gen-docs.php`, never hand-edit `docs/lib/`) for the same shape.

**Why:** the documented call coerces a string into `bool $binary` for any non-strict caller and stores
base64 garbage; the API has no (key, value, dbName) overload.

**Severity:** P1 (HIGH) — docs that corrupt data.

**Conditions for success:** a rendered `php -l`-style excerpt test isn't required, but the snippet must
match `examples/multidb.php` usage; grep finds no other 3-arg `set(` doc occurrence.

**Related code locations:** `README.md` Quick Start; `src/Store.php:79-85`; `examples/multidb.php:26-28`.

### 1.3 PHP 8.4 implicit-nullable fix [PENDING]

**What is expected:** `src/Store.php:267` → `?string $dbName = null`.

**Why:** CI 8.4 leg deprecation-noises every `Store::list()` call.

**Severity:** P3 (compat hygiene), zero-risk edit — `findings/sugar-skate.md`
§4.1 classifies it under CONVENTION & HYGIENE, and the deprecation is noise on
the 8.4 CI leg, not a correctness defect. Sequenced into Phase 1 purely because
it is a one-line zero-risk edit that rides the first remediation PR.

**Conditions for success:** sugar-skate green on both 8.3 and 8.4 CI legs.

---

## Phase 2: P2 correctness & robustness [PENDING]

### 2.1 `globToLike` escape the escape character [PENDING]

- Pass `'\\\\' . $c` (emit `\\` for a literal backslash) in the character loop; failing-first tests:
  `count('a\\b') === 1` with key `a\b` stored; trailing-backslash pattern matches nothing but does not
  error; `%`/`_` cases pin unchanged behavior. `src/Database.php:259-281`. Severity P2.

### 2.2 Reserve `_ttl` (and `skate_ttl_*`/`_skate_binary`/`skate_binary_*`) at the store boundary [PENDING]

- Guard in `Store::set` (and both importers' key loops, which now route through it) —
  `\RuntimeException(Lang::t('store.reserved_key'))` naming the key; add the lang key.
  Failing-first: V9 becomes an import-time error, export succeeds after the stored collision goes away.
  `src/Store.php:85-90`, `src/Cli/ExportCommand.php:50-71`. Severity P2.

### 2.3 Binary export/import round-trip [PENDING]

- JSON: emit `"_skate_binary": ["key", …]` listing binary keys (values stay base64 as stored);
  importer decodes the side-map and sets binary=true. YAML: `skate_binary_<key>` rows (mirroring
  `skate_ttl_` handling at `YamlImporter.php:68-78`). Update README Features + `docs/_data` body text
  and regenerate the docs page. Failing-first: V7 exports and round-trips. Severity P2.

### 2.4 `bin/skate` dispatch guard [PENDING]

- One `try { switch … } catch (Throwable $e) { fwrite(STDERR, Lang::t('cli.error', ['message'=>$e->getMessage()])."\n"); exit(1); }`
  around the switch (help arm already safe). Extend `CliSmokeTest`: `set a@b@c v`, `get k@bad.name`
  assert exit 1 + no `Stack trace`/`Fatal error` needles (the file already defines `assertNoFatal`).
  `bin/skate:43-142`. Severity P2.

### 2.5 `deleteDatabase` explicit close + sidecar unlink [PENDING]

- Pop cache slot to a local, `->close()`, `@unlink($path)`, `@unlink("$path-wal")`, `@unlink("$path-shm")`,
  return real bool; failing-first test holds a live `list()` generator over the doomed db then asserts
  zero `wipe.db*` residue (V10). `src/Store.php:321-329`. Severity P2.

### 2.6 Importers: public API + shared helper + input validation [PENDING]

- Replace reflection blocks (`JsonImporter.php:124-130`, `YamlImporter.php:123-129`) with
  `$this->store->transaction($targetDb, $import)`; factor the db-collection/multi-db-guard into a
  private shared helper or small trait (kills the duplicated 35-line block).
- Validate parsed `_ttl` values: `is_int($v) || (is_numeric($v) && floor…)` else `RuntimeException`
  naming the offending key (V3 becomes clean).
- YAML: guard `parseYaml()` result — `is_array` or throw (V-parity with Json's `decodeArray`);
  correct `parseYaml` docblock (it does NOT handle nested maps/lists — drop that claim and the dead
  `$currentKey`/`$inBlock`/`$blockIndent` variables, or implement nested-skip honestly).
  Severity P2.

### 2.7 i18n completion [PENDING]

- `lang/en.php`: add `database.query_failed`, `store.reserved_key`, `store.cannot_create_dir`,
  `cli.error`; wire `cli.import_success`/`cli.export_success` into `ImportCommand.php:66` / export path;
  route the importers' two hard-throw sentences and ExportCommand's catch message through `Lang::t`.
  Verify no raw-key fallback (`Lang::t('database.query_failed')` must stop printing the dotted key — V5).
  Severity P2.

---

## Phase 3: P3 polish (batch into the P2 PR or a follow-up) [PENDING]

### 3.1 Drop dead `$delimiter` param from `Store::list`/`Database::list` (+ docblocks, bin unaffected) — `Store.php:265-274`, `Database.php:166-200`.
### 3.2 `Store` ctor: checked `@mkdir` with `Lang::t('store.cannot_create_dir')`; consider lazy Store in `bin/skate` so `--help` creates nothing — `Store.php:65-67`, `bin/skate:40`.
### 3.3 `ArgParser::set`: reject non-numeric `--ttl=` and valueless `--ttl` (fail-fast, message to STDERR); consider rejecting unknown flags CLI-wide — `ArgParser.php:32-40,65-79,134-140`.
### 3.4 Remove `DEFAULT_SUBDIR` (or use it in `defaultDataDir()` — better: use it), self-namespace `use` lines, un-consumed `$allKeys` line `ImportExportTest.php:260`, dead `$importer` reuse `ImportCommand.php:31-52` — multiple.
### 3.5 `Database`: `try/finally` around generator `stmt->close()` (`:191-199`); best-effort ROLLBACK inside `transaction()` catch (`:351-353`); `finalize()` the PRAGMA result; doc the deliberate unchecked `execute()===false` posture or add guards — `src/Database.php`.
### 3.6 README metadata: PHP badge 8.1→8.3, packagist badge `sugarcore/`→`sugarcraft/`, `docs/_data` body regen after 1.2/2.3 — `README.md:8-9`.
### 3.7 Strengthen vacuous test legs: unconditional ordering assertions in `testFuzzyFilterReturnsScoreDescendingOrder` (`StoreTest.php:478-482`); make `testJsonExportWithTtl` actually capture-and-assert the export string (needs a stdout seam or `ob_start()` on the echo path — prefer refactoring `ExportCommand::run` to accept a writable stream, which also kills PHPUnit-STDOUT noise from `testJsonExportRoundTrip`).
### 3.8 `CliSmokeTest::runSkate`: bound the child (house `timeout -s KILL` idiom per `RunsWallClockBoundedChildTrait` precedent in sugar-crush; per-lib local copy acceptable for standalone libs) so a hung bin fails fast.

---

## Sequencing & Verification

- Order: 1.1+3.7-test-first, 1.2, 1.3, then Phase 2 items as one PR (2.6 touches the same importer
  files as 2.3/2.2 — same branch), then Phase 3 batch.
- Every PR: `cd sugar-skate && composer install && vendor/bin/phpunit` green (baseline 206T/427A;
  delta = added tests only) + PHP 8.4 leg green after 1.3.
- Docs touched (1.2, 2.3, 4.4): regenerate `docs/lib/sugar-skate.html` via `php tools/gen-docs.php`
  from `docs/_data/sugar-skate.body.html` edits — never hand-edit the page.
- If a fix changes any documented behavior (binary export shape!), mirror it in README + `docs/_data`
  body + the relevant example in the same commit.

## Explicit Non-Goals

- No encryption / cloud sync / HTTP API (research doc §10 — out of scope upstream too).
- No symfony/yaml hard dependency (optional accelerator stays optional).
- No re-design of the `key@db` grammar (its `@`-ambiguity is pinned by `testKeyWithMultipleAtSignsThrows`).
