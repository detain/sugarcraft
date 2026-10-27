# C-TOOL-1.a — Background-shell two-part cursor — DONE

Commit: `01152d32d4763c645943996ecd2ae3561c878ca4` on `cc/C-TOOL-1.a` (base ff10efc43).
Author+committer detain@interserver.net verified separately; zero Co-Authored trailers; not pushed.

## What landed
- `BackgroundShellRegistry::read()` takes `int|string $cursor`; new two-part token
  `"<stdout offset>.<stderr offset>"`, each offset advancing in its own file.
  `decodeCursor()` accepts the token, a legacy bare integer (decoded with today's
  concatenation arithmetic — exact while stdout is frozen, covering resumed sessions)
  and a digit string; garbage reads fresh from 0. Tail-wins is kept only for the
  default fresh read. The global shrink clamp and the SHL-1 `lastStdoutSize` remap
  collapse into the per-stream clamp; the `lastStdoutSize` row field (spawn init,
  per-read write, parseRow carry) is deleted. Class-docblock OUTPUT paragraph and
  read() docblock teach the token.
- `ShellOutput`: inputSchema `cursor` is now `type: string` (no `minimum`),
  description teaches `[cursor: <out>.<err>]` + legacy integer; paint
  `sprintf('[cursor: %s]')`; `parseCursor()` replaces `nonNegativeInt`;
  class docblock + description() updated.
- `Bash.php` NOT touched: its background-start answer never names the cursor shape
  ("cursor: <the cursor the last answer gave>") — brief's "only if" conditional not met.

## Regression (fails before the fix)
- New `testInterleavedGrowthDeliversEveryByteExactlyOnce`: out/err, gate-loop,
  out/err with threaded reads; each stream's concatenation equals its file bytes
  exactly once. Proven RED pre-fix (old global-4 split skipped `O2` and replayed
  `E1`). Test-side note: burst-polling uses registry reads with `clearstatcache()`;
  a bare `filesize()` poll loop caches the pre-growth size in-process (pre-existing
  hazard, disclosed seam — registry itself uses stream-backed size reads and is
  unchanged in that regard).

## Tests run (all targeted, plain pipe, cwd candy-code)
- `--filter 'BackgroundShellRegistryTest|ShellOutputTest|BashBackgroundTest|ShellStopTest'`
  → `Tests: 49, Assertions: 265, PHPUnit Deprecations: 2.` OK (the 2 deprecations are
  pre-existing AccessorDeprecationTest provider noise — reproduce on a bare
  `--filter ShellStopTest` run; 0 warnings after moving the gate file into a
  subdirectory so the pre-existing tearDown "root entries are directories" shape holds).
- `--filter DocFigureProseDriftTest` → 91T/3889A OK (file-count arm unaffected).
- `--filter ToolSchemaEncodingTest|ToolCatalogTest|GlobDialectDifferentialTest|StderrEmitterCensusTest|ReadPathCensusTest` → 189T/21212A OK (GlobDialect corpus figure unchanged: new preg literals contain `(` → corpus-exempt per §1.9).
- `--filter SymbolCitationDriftTest|DuplicatedDocBlockLineTest|TreeWideGuardRosterTest|DuplicatedTestHelperDriftTest` → 44T/19353A OK.
- `php -l` clean on both src files.

## Guards moved
- None required (§1.8 sweep): brief said docs/guards none (gen-tool-docs renders
  names+gloss only, gloss unchanged); cursor-schema pins live only inside the two
  declared test files, updated in-step; no new test FILES, no new env/doc rosters.

## New *Test.php files
- None — all added tests are inside existing declared files; durations.tsv unaffected.

## Never committed
- suite-figure.json, durations.tsv, dist/, generated protocol files (untouched).
