# Contributing to SugarCraft

Thanks for your interest in SugarCraft! Bug reports, feature requests,
and PRs are all welcome.

## Development setup

SugarCraft is a monorepo of 50 PHP libraries. Each library has its own
`composer.json` + `vendor/` and is tested independently.

```sh
git clone https://github.com/detain/sugarcraft.git
cd SugarCraft

# Install deps + run tests for one library:
cd candy-core
composer install
vendor/bin/phpunit

# Or, for the whole monorepo:
for d in candy-core candy-ansi candy-buffer candy-layout candy-async candy-testing candy-mouse candy-input candy-fuzzy candy-sprinkles honey-bounce candy-zone candy-forms \
         sugar-bits sugar-charts sugar-dash sugar-prompt candy-shell candy-shine candy-kit \
         candy-freeze sugar-glow sugar-spark \
         candy-wish sugar-wishlist candy-metrics \
         candy-mold candy-tetris candy-files sugar-crush \
         sugar-stash candy-query sugar-tick candy-mines candy-flip honey-flap; do
    (cd "$d" && composer install --quiet && vendor/bin/phpunit) || exit 1
done
```

The libraries are wired together via `composer.json` path repositories,
so changes to (say) `candy-core/src/Util/Width.php` are reflected
immediately in `candy-shine`'s test run with no rebuild step.

If your change introduces a new `sugarcraft/* @dev` dep, run
`php tools/check-path-repos.php --fix` to auto-insert the missing path-repo
entry before committing. The checker walks the FULL transitive `sugarcraft/*`
require graph (not just direct requires), so a gap introduced several hops away
is caught and reported with the dependency path that needs it. The bare script
(`--fix` omitted) is read-only — use it to verify closure without making
changes; add `--strict-closure` to require a local path-repo for every
transitive dep even when it is already published on Packagist.

## Style guide

- **PHP 8.1+**: fibers, readonly properties, enums, `match`, intersection
  types are all in scope.
- **`declare(strict_types=1);`** at the top of every PHP file.
- **PSR-12** via `php-cs-fixer` (config to come; for now, follow the
  surrounding code's conventions).
- **Immutability**: every `Style`, `Model`, `Field`, etc. is immutable;
  `with*()` returns a new instance.
- **Readonly DTOs** for value objects.
- **`fn(...)` short closures** for one-liners; full `static function (…) {}`
  closures otherwise.
- **No silent failures**: throw `\InvalidArgumentException` /
  `\RuntimeException` rather than returning `null` for "wasn't valid input".
- **Don't add comments that re-state the code.** Comments document
  *why* — non-obvious constraints, hidden invariants, links to
  upstream issues. Skip "increment counter" tier prose.

## Tests

- **PHPUnit 10** lives in each library's `tests/` directory under
  `<Lib>\Tests\` namespace.
- Snapshot ANSI-rendering tests (assert against the exact byte string).
- Scripted-input event tests for runtime models — feed a sequence of
  `Msg`s, assert on `view()` output.
- New features need new tests; new bug fixes need a regression test.

## Pull requests

1. Open an issue first for non-trivial changes so we can agree on the
   shape before you spend the time.
2. Branch from `master`. Branch names: `feature/x`, `fix/y`, `docs/z`.
3. One concern per PR. Don't pile a refactor onto a feature.
4. Make sure every test suite the change touches is green before
   pushing.
5. Update the relevant `README.md` and `CONVERSION.md` rows if your
   change visibly affects the public API.
6. Commits should be authored as your real name + email.

## Adding a new library port

SugarCraft is also happy to host PHP ports of additional Charmbracelet
(or Charmbracelet-adjacent) libraries. The flow:

1. Open an issue proposing the port. Include the upstream URL, a
   one-line role summary, and the expected dependencies on existing
   SugarCraft phases.
2. Decide on a name following the `Candy*` / `Sugar*` / `Honey*` +
   technical-suffix pattern documented in
   [`PROJECT_NAMES.md`](./PROJECT_NAMES.md).
3. Add the new library's row to `CONVERSION.md`'s Phase 9+ table with
   the proposed name, subdir, namespace, and dependency list.
4. Scaffold the new directory:

   ```text
   candy-newlib/
   ├── composer.json     # canonical metadata (see existing libs)
   ├── phpunit.xml
   ├── README.md         # composer require + quickstart
   ├── src/              # PSR-4 under SugarCraft\NewLib\
   └── tests/
   ```

5. Wire it into the root `composer.json` `require` + `repositories`.
6. Submit the PR.

## Coverage tracking

Coverage is reported per-library to [Codecov](https://codecov.io/gh/detain/sugarcraft).
The `coverage:` job in `.github/workflows/ci.yml` runs once per push to
master (after the test matrix is green), generates a Clover XML for
each lib via `phpunit --coverage-clover=coverage.xml`, and uploads it
with `flags: <lib>`. Each lib's README has a per-flag badge wired up.

To run coverage locally you need pcov (or xdebug):

```bash
pecl install pcov
echo 'extension=pcov.so' | sudo tee /etc/php/8.3/cli/conf.d/20-pcov.ini
echo 'pcov.enabled=1'    | sudo tee -a /etc/php/8.3/cli/conf.d/20-pcov.ini
cd candy-core && vendor/bin/phpunit --coverage-text
```

## Bootstrapping the sugarcraft org repos

The `sync-sugarcraft.yml` workflow assumes a repo at `sugarcraft/<lib>`
exists for every monorepo subdirectory it pushes. To create the
missing repos (one-shot, idempotent — already-existing repos are
skipped):

```bash
gh auth login                              # as a user with admin on the sugarcraft org
./scripts/bootstrap-org-repos.sh           # 1. creates every repo + topics + settings
gh workflow run sync-sugarcraft.yml -R detain/sugarcraft
                                           # 2. wait for sync to push the master branches
./scripts/set-org-default-master.sh        # 3. flip every default branch from main → master
```

Extend the script's inline `DESCRIPTIONS` map whenever you add a new
lib to the monorepo. To make step 3 unnecessary for *future* repos,
set the org-wide default-branch name to `master` in
<https://github.com/organizations/sugarcraft/settings/repository-defaults>.

## License

By submitting a contribution, you agree to license it under the
project's [MIT license](./LICENSE).
