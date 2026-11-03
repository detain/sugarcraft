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
  (the README's tool, command, setting and key-table rosters), `EnvRosterDriftTest`
  (the `ENVIRONMENT.md` variable table against the live read-site census,
  including the provider ambient variables), `KeyBindingDriftTest` (the
  in-app `?` reference — every chord it shows is pressed through the real
  handlers against `KeyBindingRegistry`; the README's own key table is
  credited by `ReadmeRosterDriftTest`),
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

## Locales and translations

`lang/en.php` is the source of truth: every key a person reads lives there,
and only there may a key be added. The community locales (`fr`, `de`, `es`,
`pt`, `pt-br`, `zh-cn`, `zh-tw`, `ja`, `ru`, `it`, `ko`) mirror it key for
key — `LangParityTest` is **strict**: a new English key turns the guard red
in every locale until it is translated, in the same change that adds the
key. Codes follow root `LOCALES.md`: bare base codes, a regional file only
where the wording genuinely diverges.

The rules for a locale file: keep every `{placeholder}` verbatim (the parity
test diffs the placeholder set per key), keep backticked spans, printf
tokens, flags, paths and the brand names `CandyCode`/`candy-code` byte for
byte, and translate nothing the model reads — tool descriptions, prompt
layers and tool results are not in `lang/` and stay English whatever the
locale. The shipped community files began as machine translations with
machine review; a human-reviewed contribution is of course welcome, but the
parity and placeholder arms treat them as law, not drafts.

## See also

- [ARCHITECTURE.md](ARCHITECTURE.md) — the code's shape and invariants.
- [GLOSSARY.md](GLOSSARY.md) — vocabulary, including the identifier rules.
- [PROMPT_ENGINEERING.md](PROMPT_ENGINEERING.md) — how the system prompt is
  assembled.
