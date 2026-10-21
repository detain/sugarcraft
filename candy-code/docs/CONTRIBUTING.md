# Contributing to candy-code

`candy-code` lives inside the SugarCraft monorepo: a PHP 8.3+ application
under `src/`, a PHPUnit 10 suite of twenty thousand-plus tests under
`tests/`, and documentation pages that a battery of
drift guards re-derives from the code on every run. This page is the working
knowledge that repo-root `CONTRIBUTING.md` does not carry: how to get a
development tree wired, how to run the suite the way CI runs it, and how the
guards expect documentation to be edited.

## Getting a working tree

The monorepo root has `scripts/refresh-deps.php`, which links or unlinks each
lib's sibling dependencies:

```sh
php scripts/refresh-deps.php --mode=linked   # siblings become path-repo symlinks into the monorepo
php scripts/refresh-deps.php --mode=published  # siblings resolve from Packagist dev-master
php scripts/refresh-deps.php --status        # which mode each lib is in right now
```

`--mode=linked` is what you want for work that spans `candy-code` and a
sibling lib: an edit in `sugar-core/src/` becomes visible to this suite
immediately. In linked mode a bare `composer update` is a trap — it replaces
the symlinked siblings with Packagist copies, and suite figures stop being
comparable. Check `--status` before trusting any count. A fresh worktree of
the monorepo has no `vendor/` at all: run `refresh-deps.php --mode=linked`
from the worktree root first, and note the script anchors to its own
directory, so a worktree must run its own copy.

## Running the suite

Targeted runs are the everyday shape, from `candy-code/`:

```sh
env -u LINES -u COLUMNS vendor/bin/phpunit -c phpunit.xml --filter 'Theme|Share'
```

The `env -u` pair keeps the run hermetic: a controlling terminal with a size
makes the renderer probe answer your window instead of the documented
fallbacks, and several pins assert the fallback. Plain pipes (the shape CI
and this guide use) answer the pinned sizes.

Full-suite serial runs are slow by design; the sharded runner exists so you
never reach for them casually:

```sh
scripts/parallel-tests.sh                       # K = min(nproc, 24), unique --out REQUIRED in practice
scripts/parallel-tests.sh --lib candy-code --against-json candy-code/tests/Config/Support/suite-figure.json --out /tmp/pt-$$
```

Always pass a unique `--out` directory: the default `/tmp/parallel-tests`
carries `done-*` resume markers from previous runs, and a second run against
the same directory silently skips shards it believes are finished. The gate
is fail-closed against the pinned suite figure — tests, assertions, failures
and skipped all checked — so a shard that never ran cannot look green.

## The cadence when tests move

Three artifacts pin the suite's shape, and they move together or guards go
red:

- `candy-code/tests/Config/Support/suite-figure.json` — refreshed on a green
  serial run with `php tests/Config/Support/refresh-suite-figure.php
  <junit.xml>` from `candy-code/`. The README headline carries the same
  numbers; update both from the same run.
- `scripts/parallel-tests-durations.tsv` — one row per test file. A new test
  file without a row runs in ZERO shards: the sharded suite simply does not
  see it. Regenerate from a green serial baseline (`--junit <xml> --out
  <dir>` writes a `durations.tsv` into the out directory; copy it over).
- The README headline figure — `ReadmeSuiteFigureDriftTest` compares
  headline, artifact and live enumeration; all three must agree.

Until the TSV carries your new file, the serial run is the truth for
conservation. Expect the staleness pair to be the only red while you are mid
change: it names the pinned figure and the live count, and it heals with the
refresh above.

## The guards

Editing `candy-code` means editing against drift guards that read the docs
and the tests as data:

- **Doc guards** re-derive documentation from code: `ReadmeRosterDriftTest`
  (the README's tool, command and setting rosters), `EnvRosterDriftTest`
  (the `ENVIRONMENT.md` variable table against the live read-site census,
  including the provider ambient variables), `KeyBindingDriftTest` (the
  README key table against `KeyBindingRegistry`),
  `TrustKeyDocumentationDriftTest` and
  `ConfigWriteProducerDocumentationDriftTest` (the settings-tier claims).
  A tool, variable, key or setting added without its doc edit goes red.
- **`DocFigureProseDriftTest`** is the biggest: dozens of arms each take one
  counted claim from a docs page ("thirty-one tools", "fifteen checks", a
  word-number), re-derive the true figure from `src/` or the registry, and
  compare. Prose that cites a number you changed must change in the same
  commit, spelled the way the arm expects (some arms match numerals, some
  match spelled-out words).
- **`GlobDialectDifferentialTest`** harvests glob-shaped string literals
  across the tree and cross-checks them against the library's own matcher.
  A test fixture containing something that LOOKS like a glob (`*.php`,
  `a?b*[c]`) joins the corpus and drifts a documented census figure.
  Respell such literals with `chr(42)`-style concatenation if a fixture
  must contain one.
- **`DuplicatedTestHelperDriftTest`** flags helper methods and constants
  that exist near-identically in two test files (one token of divergence
  is enough to be accused). Name helpers uniquely or fold them into a
  shared trait.
- **`ChildWallClockBudgetTest`** pins the wall-clock budget of tests that
  spawn real children; a new child-spawning test needs its budget listed.

When a guard legitimately must move with your change — a roster count, a
pinned needle for wording you were told to fix — flip it in the same commit
and say so in the commit message.

## Documentation conventions

The prose style the guards are calibrated to: sentence-case headings, no
Oxford comma, table cells that are one sentence, numerals spelled out or
digits exactly as the relevant `DocFigure` arm matches them. Docs describe
current behavior only: audit-era identifiers (`E###`, `ISS-###`, wave
labels) and dated remediation narratives do not belong in prose — code
comments carry them instead (see [GLOSSARY.md](GLOSSARY.md)). Generated
surfaces regenerate through their real generators, never by hand:
`docs/COMMANDS.md` marked regions through `php tools/gen-command-docs.php
--write`, the wire schema through `php tools/gen-protocol-schema.php
--write`, and docs-site pages through the root `tools/gen-docs.php`.

## See also

- [ARCHITECTURE.md](ARCHITECTURE.md) — the code's shape and invariants.
- [GLOSSARY.md](GLOSSARY.md) — vocabulary, including the identifier rules.
- [PROMPT_ENGINEERING.md](PROMPT_ENGINEERING.md) — how the system prompt is
  assembled.
