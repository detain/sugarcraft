---
name: add-locale
description: Adds a translation file at <slug>/lang/<code>.php for an existing SugarCraft library by copying en.php and translating values while preserving keys and {placeholder} names. Codes follow LOCALES.md recommended set (en, fr, de, es, pt, pt-br, zh-cn, zh-tw, ja, ru, it, ko, pl, nl, tr, cs, ar). Use when user says 'add <language> translation', 'translate <lib> to <code>', 'add ar locale', 'add Polish locale to sugar-bits', 'add Japanese locale for all libs'. Do NOT use for first-time wiring of Lang::t() into a lib that doesn't have a lang/ dir yet (use scaffold-library); do NOT use to edit en.php (the source of truth) — translate FROM it instead.
paths:
  - */lang/*.php
  - LOCALES.md
---
# Add a translation locale to a SugarCraft lib

## Critical

- **Never edit the English source-of-truth file** — new keys land there first via the lib's own PR; translation files mirror it.
- **Keys are immutable**, including their order and the `{placeholder}` names inside values. Only translate the right-hand-side strings.
- **Filename = locale code from `LOCALES.md`** in lower-case kebab form, never with underscores or upper-case region. The lookup in `candy-core/src/I18n/T.php` normalizes glibc names to this shape.
- **Only add a regional variant** when wording genuinely diverges from the base language. For everything else (`fr-ca`, `de-at`, `en-gb`) the base file falls through automatically.
- **One language per PR, across every lib that already has English translations** — that is the established cadence (see commits `9c7e837`, `7736512`, `47fb236`, `8968e71`, …). Do not split per-lib unless the user asks.
- **Skip libs without translations wired up.** If a lib has no English source file in its `lang` dir, it has not been wired through `Lang::t()` yet — that is `scaffold-library` territory, not this skill.

## Instructions

1. **Confirm the locale code.** Open `LOCALES.md` and pick the bare base-language code from the *Recommended set* table (e.g. `fr`, `de`, `ja`, `ar`). Use a regional variant ONLY if it is in the *Regional variants worth keeping separate* table (Brazilian Portuguese, Simplified/Traditional Chinese, Bokmål/Nynorsk). Verify the chosen code before touching files.

2. **Enumerate target libs.** Run from the repo root to list every lib that already has translations wired:

   ```sh
   ls -d */lang 2>/dev/null
   ```

   Existing locale-wide PRs cover ~25 libs: `candy-core`, `candy-flip`, `candy-freeze`, `candy-lister`, `candy-log`, `candy-metrics`, `candy-mold`, `candy-palette`, `candy-query`, `candy-serve`, `candy-shell`, `candy-shine`, `candy-sprinkles`, `candy-tetris`, `candy-wish`, `candy-zone`, `honey-bounce`, `sugar-bits`, `sugar-charts`, `sugar-glow`, `sugar-post`, `sugar-skate`, `sugar-spark`, `sugar-stash`, `sugar-tick`, `sugar-wishlist`. Verify with the listing — the list grows over time. If the user asked for a single lib, use only that one.

3. **Read each target's English source.** For each `<slug>` in the target list, Read its English language source. Do not assume keys are stable across libs — every lib has its own keyset (e.g. `candy-core` uses `color.*`/`ansi.*`/`program.*`; `sugar-bits` uses `column.*`/`viewport.*`/`spinner.*`/etc.).

4. **Write the new locale file** mirroring the source exactly. Use the Write tool with this template:

   ```php
   <?php

   /**
    * <Language name> translations.
    *
    * @return array<string, string>
    */

   declare(strict_types=1);

   return [
       'first.key'   => '<translated value, {placeholder} preserved>',
       'second.key'  => '<translated value>',
       // ... mirror every key in the same order
   ];
   ```

   Rules for the body:
   - **Same keys, same order** as the source file. Do not alphabetize, group, or drop keys.
   - **Translate values only.** Leave `{name}` placeholders byte-identical to the English file.
   - **Keep section comments** only when the corresponding English source has them — `candy-core/lang/en.php` keeps them, `sugar-bits/lang/en.php` does not. Match the source file's style.
   - **Strip the long doc-block.** Translation files use the short 4-line header (see `candy-core/lang/ar.php`, `candy-core/lang/fr.php`); only the English source carries the verbose contributor-facing doc-block.
   - **RTL languages (`ar`, `he`, `fa`, `ur`)**: write values left-to-right in source order — terminal rendering handles bidi. Keep `{placeholder}` tokens latin (do not transliterate).
   - **CJK (Simplified/Traditional Chinese, Japanese, Korean)**: no spaces around colons unless the English line had them; preserve numeric ranges like `[0,255]` verbatim.

   Verify after writing with this PHP one-liner:

   ```sh
   php -r "var_dump(array_keys(require 'candy-core/lang/en.php') === array_keys(require 'candy-core/lang/it.php'));"
   ```

   Must print `bool(true)`.

5. **Run the per-lib test suite** for at least one touched lib to confirm no parse error:

   ```sh
   cd candy-core && composer install --quiet && vendor/bin/phpunit
   ```

   Translation files are loaded by `T::translate()` lazily, so a syntax error surfaces only when the test or runtime hits the locale. Verify exit code 0 before committing.

6. **Commit + PR per established cadence.** Branch name: `ai/locale-<code>` (or `feat/locale-<code>` for human contributors). Commit author MUST be `Joe Huss <detain@interserver.net>`. Title pattern (mirrors merged PRs #244–#262):
   - All-libs: `Add <Language> locale for all libs`
   - Single lib: `Add <Language> locale for <slug>`

   Push and `gh pr create`. Do NOT update `MATCHUPS.md`, `CONVERSION.md`, `docs/`, or `LOCALES.md` — locale additions do not touch those files (verified across PRs #244–#262).

## Examples

**User:** "Add Italian locale for all libs."

**Actions:**
1. Read `LOCALES.md` → `it` is in the recommended set.
2. List target dirs (~26 libs returned).
3. For each lib, Read its English source; Write the Italian counterpart translating values, keeping keys/order/placeholders intact, using the short 4-line header.
4. `cd candy-core && vendor/bin/phpunit` → green.
5. `git checkout -b ai/locale-it`; commit as Joe Huss; `gh pr create --title 'Add Italian locale for all libs'`.

**Result:** 26 new translation files, one PR mirroring the Polish/Dutch/Czech/Arabic cadence.

**User:** "Add Norwegian Bokmål translation for sugar-bits."

**Actions:**
1. `LOCALES.md` → `nb` is in the *Regional variants worth keeping separate* table (Bokmål vs Nynorsk).
2. Single-lib scope: only `sugar-bits`.
3. Read `sugar-bits/lang/en.php` (15 keys, no section comments).
4. Write `sugar-bits/lang/nb.php` with translated values, same key order, short header, no section comments.
5. `cd sugar-bits && vendor/bin/phpunit` → green.
6. Commit + PR titled `Add Norwegian Bokmål locale for sugar-bits`.

## Common Issues

- **`syntax error, unexpected ';' expecting ']'` when phpunit runs.** Unescaped apostrophe inside a single-quoted value (common in French `l'index`, Italian `dell'`). Fix: escape with `\'` or switch the value's surrounding quotes to double-quotes. Re-run phpunit.

- **`Undefined array key 'foo.bar'` at runtime / test failure mentions a missing translation key.** The new locale file is missing a key that exists in the English source. Diff with:

  ```sh
  php -r "print_r(array_diff(array_keys(require 'candy-core/lang/en.php'), array_keys(require 'candy-core/lang/it.php')));"
  ```

  Add the missing keys preserving original order.

- **Translation file parses but lookup falls through to English.** Filename mismatch — you used underscores or upper-case region letters. Rename the file to lower-case kebab with a `-` separator.

- **`{placeholder}` rendered literally instead of substituted.** A translator localized the placeholder name (e.g. translated `{value}` → `{valeur}`). `T::translate()` substitutes by exact placeholder name — restore the original English placeholder name and only translate surrounding text.

- **`composer validate` warns about `@dev` requirements.** Expected — the monorepo wires siblings as `@dev` path repositories. Ignore this warning; do not pass `--strict`.

- **Lib has no translations directory.** It has not been wired through `Lang::t()` yet. Stop — this is not an `add-locale` task. Either skip the lib or invoke `scaffold-library` to wire i18n first.

- **User asks for a code not in `LOCALES.md`'s recommended set** (e.g. `vi`, `th`, `he`). Per `LOCALES.md`, open an issue first. If the user insists, proceed using the bare base code from the glibc list at the bottom of `LOCALES.md` and note the deviation in the PR body.
