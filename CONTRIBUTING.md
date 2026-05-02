# Contributing to CandyCore

Thanks for your interest in CandyCore! Bug reports, feature requests,
and PRs are all welcome.

## Development setup

CandyCore is a monorepo of 13 PHP libraries. Each library has its own
`composer.json` + `vendor/` and is tested independently.

```sh
git clone https://github.com/detain/CandyCore.git
cd CandyCore

# Install deps + run tests for one library:
cd candy-core
composer install
vendor/bin/phpunit

# Or, for the whole monorepo:
for d in candy-core candy-sprinkles honey-bounce candy-zone sugar-bits \
         sugar-charts sugar-prompt candy-shell candy-shine candy-kit \
         candy-freeze sugar-glow sugar-spark; do
    (cd "$d" && composer install --quiet && vendor/bin/phpunit) || exit 1
done
```

The libraries are wired together via `composer.json` path repositories,
so changes to (say) `candy-core/src/Util/Width.php` are reflected
immediately in `candy-shine`'s test run with no rebuild step.

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

CandyCore is also happy to host PHP ports of additional Charmbracelet
(or Charmbracelet-adjacent) libraries. The flow:

1. Open an issue proposing the port. Include the upstream URL, a
   one-line role summary, and the expected dependencies on existing
   CandyCore phases.
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
   ├── src/              # PSR-4 under CandyCore\NewLib\
   └── tests/
   ```

5. Wire it into the root `composer.json` `require` + `repositories`.
6. Submit the PR.

## License

By submitting a contribution, you agree to license it under the
project's [MIT license](./LICENSE).
