---
name: add-locale
description: Adds a translation file at <slug>/lang/<code>.php for an existing SugarCraft library by copying en.php and translating values while preserving keys and {placeholder} names. Codes follow LOCALES.md recommended set (en, fr, de, es, pt, pt-br, zh-cn, zh-tw, ja, ru, it, ko, pl, nl, tr, cs, ar). Use when user says 'add <language> translation', 'translate <lib> to <code>', 'add ar locale', 'add Polish locale to sugar-bits', 'add Japanese locale for all libs'. Do NOT use for first-time wiring of Lang::t() into a lib without a lang/ dir (use scaffold-library); do NOT edit en.php (the source of truth) — translate FROM it instead.
paths:
  - '*/lang/*.php'
  - LOCALES.md
---
# Add locale

Add a translation file for an already-wired SugarCraft library. The English locale file is the canonical source of truth — translate **from** it, never edit it.

## Critical

- **Never edit the English locale.** It is the source of truth. All other locale files mirror its keys.
- **The lib must already have an English locale and a `Lang` facade in `src/`.** If the locale directory does not exist, stop and tell the user to use `scaffold-library` first.
- **Preserve every key verbatim.** `'config.not_found'` stays `'config.not_found'` — only the right-hand value is translated.
- **Preserve every `{placeholder}` name verbatim.** `'failed to exec {bin}'` → French `'échec de l\'exécution de {bin}'` — `{bin}` MUST remain literal `{bin}`. Do NOT translate placeholder identifiers, change their case, or reorder them out of the value.
- **Locale code must be from `LOCALES.md` recommended set** unless the user explicitly asks for an exotic glibc code (then warn them it will fall back to `en` for users not on that exact code). Recommended set: `en`, `fr`, `de`, `es`, `pt`, `pt-br`, `zh-cn`, `zh-tw`, `ja`, `ru`, `it`, `ko`, `pl`, `nl`, `tr`, `cs`, `ar`.
- **Prefer the bare base-language code.** The lookup chain `exact → base → en → raw` makes a single base-language file serve all regional variants. Only create regional variants (`pt-br`, `zh-cn`, `zh-tw`) when wording genuinely diverges from the base.
- **Run the lib's PHPUnit suite after adding the file.** Any `LangTest` or coercion test that loads the registry will fail loudly on a syntax error in the new file.

## Instructions

1. **Resolve the target lib slug and locale code.** Verify the lib's English locale exists. If it does not, stop — this skill is for libs already wired through `Lang::t()`. If the user asked for "all libs", list every lib whose directory contains a locale tree and loop over them in step 2.

2. **Read the English locale.** Capture the full file: the header doc-comment, the `declare(strict_types=1);` line, and the `return [...]` array.

3. **Check whether the target locale already exists.** If it does, ask the user before overwriting — do not blindly clobber an existing translation. If it does not, proceed.

4. **Write the new locale file** using this exact structure:

   ```php
   <?php

   /**
    * <LanguageName> translations for <slug>.
    *
    * @return array<string, string>
    */

   declare(strict_types=1);

   return [
       // every key from the English locale, in the same order, with translated value
   ];
   ```

   - First line of doc-comment uses the English language name (e.g. `Brazilian Portuguese translations for sugar-wishlist.`, `Simplified Chinese translations for candy-core.`).
   - Keep section comments from the English locale (e.g. `// bin/wishlist`) — translate them if natural, leave them in English if the comment names a file path.
   - Preserve key order and column alignment of `=>` arrows where the English file aligned them.

5. **Translate every value.** Rules:

   - Keys: untouched.
   - `{placeholder}` tokens: untouched, identifier and braces literal.
   - Backslash-escapes inside single-quoted strings: keep `\'` for apostrophes, double `\\` for literal backslashes. Example:

     ```php
     'launcher.exec_failed' => 'échec de l\'exécution de {bin}',
     ```

   - For Arabic (`ar`), Hebrew (`he`), Persian (`fa`): write left-to-right in the PHP source (the runtime renders RTL when displayed); do not insert Unicode bidi marks unless the original had them.
   - For CJK locales (`zh-cn`, `zh-tw`, `ja`, `ko`): use fullwidth colons `：` only inside the translated prose if natural; keep ASCII `:` inside placeholder syntax and PHP punctuation.

6. **Verify the file parses.** Run:

   ```sh
   cd <slug> && php -l <new locale file>
   ```

   Output must be `No syntax errors detected`. If it fails, fix the escape/quoting issue before continuing.

7. **Verify keys match the English locale exactly.** Run:

   ```sh
   cd <slug> && \
     diff <(php -r 'print_r(array_keys(require "<english file>"));') \
          <(php -r 'print_r(array_keys(require "<new file>"));')
   ```

   Output must be empty. A diff means you added, dropped, renamed, or reordered a key — fix the new file, not the English one.

8. **Run the lib's test suite.**

   ```sh
   cd <slug> && composer test
   ```

   Tests must pass. A failing `T::translate` lookup or registry-load test indicates a syntax issue or a missing key in the new file.

9. **Do NOT edit `composer.json`, CI workflows, `MATCHUPS.md`, root `README.md`, `docs/index.html`, or any other index.** The registry picks up new lang files automatically once dropped in the directory; nothing else needs wiring. Do NOT add a `CHANGELOG.md` entry — out of scope before 1.0.

## Examples

**User says:** `add Polish locale to sugar-wishlist`

**Actions:**

1. Verify the English locale exists under `sugar-wishlist/`. ✓
2. Read it — 13 keys including `launcher.no_pcntl`, `cli.usage`, etc.
3. Confirm the Polish file does not exist.
4. Write the new file with header `* Polish translations for sugar-wishlist.`, identical key order, every value translated, `{path}`/`{bin}`/`{arg}`/`{line}`/`{message}` preserved.
5. `php -l` → `No syntax errors detected`.
6. Key diff → empty.
7. `cd sugar-wishlist && composer test` → green.

**Result:** New locale file. Users with `LANG=pl_PL.UTF-8` now resolve via exact-locale match; users with `pl-pl` fall back to `pl` via base-language match. No other files touched.

---

**User says:** `translate candy-core to Brazilian Portuguese`

**Actions:**

1. Verify the English locale exists under `candy-core/`. ✓
2. Decide on code: `pt-br` (`LOCALES.md` flags `pt` vs `pt-br` as worth splitting).
3. Read the English file.
4. Write with header `* Brazilian Portuguese translations for candy-core.` and Brazilian-Portuguese values (e.g. `arquivo` over Iberian `ficheiro`, `tela` over `ecrã`).
5. `php -l` + key diff + `composer test` in `candy-core/`.

**Result:** Brazilian-Portuguese locale lands. If the user also wants European Portuguese, repeat for `pt` with the Iberian wording.

---

**User says:** `add Japanese locale for all libs`

**Actions:**

1. List every lib whose directory contains a locale tree.
2. For each, in sequence (never in parallel — concurrent writes to shared files collide per project gotcha): perform steps 2–8 of Instructions with `<code>=ja`.
3. After each lib, run that lib's test suite before moving on.

**Result:** A Japanese locale in every lib that ships translations, each translated against that lib's specific English file (the strings are lib-specific, do NOT reuse one translation across libs).

## Common Issues

- **`syntax error, unexpected '...'` from `php -l`**: Usually an unescaped apostrophe inside a single-quoted French/Italian/Spanish value. Fix: escape with `\'`, or switch that one line to double quotes.
- **Diff shows a missing key**: You skipped a line when translating. Open the English locale, find the key, add it in the same position in your new file. Never delete keys from the English locale to make the diff pass.
- **Diff shows an extra key in the new file**: A typo introduced a new key. Fix the typo — do NOT add the typo to the English locale.
- **`{placeholder}` rendered literally in app output**: You translated the placeholder name. The `T::translate` interpolator only substitutes the original token names; revert.
- **`Unknown locale code` user pushback**: They asked for something like `en-gb` or `fr-ca`. Tell them the base-language file already covers regional variants via the fallback chain; only create a regional file if wording genuinely diverges (see `LOCALES.md` "Regional variants worth keeping separate" table).
- **Tests pass but `T::translate` returns the raw key at runtime**: The lib's `Lang::t()` wrapper has not been called yet in that code path, or the namespace passed to `T::register` does not match. This is NOT a translation file bug — it is a wiring issue; out of scope for this skill (use `scaffold-library` to verify wiring).
- **PHP CS Fixer reformats your alignment**: Project follows PSR-12 but the English locale uses extra spaces to align `=>`. Match what the English locale does for that specific lib; if the lib's existing French/German file does not bother aligning, you do not need to either. Consistency within the lib wins.
