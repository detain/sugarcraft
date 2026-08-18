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

Sibling libraries resolve from Packagist, **not** from committed path
repositories. Each `<lib>/` is published standalone as `sugarcraft/<lib>`,
and a `{"type":"path","url":"../candy-buffer"}` entry in a published
manifest is a hard fatal for anyone who clones that split repo — Composer
refuses a path url that does not exist. So per-lib manifests carry no
`repositories[]` block at all; only the root does, because the monorepo
root is the directory those `../` urls resolve against.

To get local wiring back — so an edit to `candy-core/src/Util/Width.php`
shows up in `candy-shine`'s test run — inject the path repos, then throw
them away:

```sh
php tools/check-path-repos.php --fix --strict-closure   # scratch only
cd candy-shine && composer update && vendor/bin/phpunit
cd .. && git checkout -- '*/composer.json'              # never commit these
```

CI does exactly this before every `composer install`, which is how a PR
that breaks a sibling still fails the dependent lib's job.

If your change introduces a new `sugarcraft/*` dep, the only edit is the
`require` line — every lib is published, so it resolves. Before committing,
verify no injected entries leaked in:

```sh
php tools/check-path-repos.php --no-lib-path-repos   # must exit 0
```

Do not commit a per-lib `composer.lock` (`/*/composer.lock` is gitignored;
the root keeps its own). A committed lock makes `composer install` resolve
from the lock and silently ignore the injection — it only warns.

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
