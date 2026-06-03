---
name: add-locale
description: Adds a translation file at <slug>/lang/<code>.php for an existing SugarCraft library by copying en.php and translating values while preserving keys and {placeholder} names. Codes follow LOCALES.md recommended set (en, fr, de, es, pt, pt-br, zh-cn, zh-tw, ja, ru, it, ko, pl, nl, tr, cs, ar). Use when user says 'add <language> translation', 'translate <lib> to <code>', 'add ar locale', 'add Polish locale to sugar-bits', 'add Japanese locale for all libs'. Do NOT edit en.php (the source of truth) — translate FROM it; do NOT use to first-time-wire Lang::t() into a lib that has no lang/ dir (that is scaffold work).
paths:
  - '**/lang/*.php'
---
# Add a translation locale

Create `<slug>/lang/<code>.php` for a SugarCraft library that already has `lang/en.php`. Copy the English keys, translate the **values**, keep keys and `{placeholder}` names byte-identical. The registry (`SugarCraft\Core\I18n\T`) auto-discovers the new file — no code changes needed.

## Critical

- **NEVER edit `<slug>/lang/en.php`** — it is the source of truth every key starts from. You only ever READ it and write a sibling `<code>.php`.
- **Preserve every key verbatim.** A translation file's keys must be a subset of `en.php`'s keys. A typo'd or extra key silently never resolves.
- **Preserve every `{placeholder}` name verbatim.** `'sort column not found: {column}'` → the `{column}` token stays `{column}` in every language. Renaming it breaks runtime interpolation (`T::interpolate()` does literal `{name}` substitution).
- **Pick the code from `LOCALES.md`.** Always prefer the bare base-language code (`fr.php`, `de.php`) — a single `fr.php` already serves `fr-fr`/`fr-ca`/`fr-be`. Only add a regional file (`pt-br.php`, `zh-cn.php`, `zh-tw.php`) when wording genuinely diverges. Codes are lowercase, `-` separated: `pt-br` not `pt_BR`.
- **Only target libs that already have `lang/en.php` AND a `src/Lang.php`.** If the lib has no `lang/` dir, it is not wired for i18n — STOP and tell the user this needs scaffolding first; do not invent a `Lang.php`.
- **PR convention: one language across every lib, not one lib at a time.** A translation PR adds (e.g.) `de.php` to every lib that has `en.php`. Bundle them per the ship-as-you-go cadence.

## Instructions

1. **Resolve the target lib(s) and locale code.** From the user request, identify the `<slug>` (e.g. `sugar-bits`) and the locale `<code>` from the `LOCALES.md` recommended set. If the user said "add French to all libs", enumerate every lib that has `en.php`:
   ```sh
   ls */lang/en.php
   ```
   Verify the chosen `<code>` appears in `LOCALES.md` before proceeding. If it is a regional variant (`pt-br`, `zh-cn`, `zh-tw`), confirm the wording actually diverges from the base — otherwise create the base file instead.

2. **Confirm the lib is i18n-wired.** Verify both files exist:
   ```sh
   ls <slug>/lang/en.php <slug>/src/Lang.php
   ```
   Read `<slug>/src/Lang.php` and note `protected const NAMESPACE = '<ns>';` — that is the lib's translation namespace (e.g. `sugar-bits` → `'bits'`). You need it for the verification step. If either file is missing, STOP: the lib is not wired for translation. Verify both exist before proceeding to Step 3.

3. **Read the full `en.php`.** Read `<slug>/lang/en.php` end to end so you have every key, its English value, and its `{placeholder}` tokens. This is the input to Step 4 — do not skip or sample it.

4. **Write `<slug>/lang/<code>.php`** mirroring the EXACT file shape of `en.php`. The header docblock comes first, then `declare(strict_types=1);`, then the `return [...]`. Translate the docblock's first line to name the language; translate each value; leave keys and `{placeholder}` names untouched:
   ```php
   <?php
   
   /**
    * French translations for <slug>.
    *
    * @return array<string, string>
    */
   
   declare(strict_types=1);
   
   return [
       'spinner.fps_positive' => 'le fps du spinner doit être > 0',
       'table.sort_unknown_column' => 'colonne de tri introuvable : {column}',
       // … one entry per en.php key …
   ];
   ```
   Translate **all** keys present in `en.php` (any you omit fall back to English at runtime, which is allowed but leaves mixed-language output — translate everything). Use single-quoted strings and escape apostrophes with `\'` (e.g. `'l\'intervalle'`). For RTL languages (`ar`) the file format is identical — do not reorder or add direction markers.

5. **Verify key parity** against `en.php` (no extra keys, optionally report missing). Run from repo root, substituting `<slug>` and `<code>`:
   ```sh
   php -r '$en=require "<slug>/lang/en.php"; $tr=require "<slug>/lang/<code>.php"; $extra=array_keys(array_diff_key($tr,$en)); $missing=array_keys(array_diff_key($en,$tr)); echo "EXTRA: ".implode(",",$extra)."\nMISSING: ".implode(",",$missing)."\n";'
   ```
   `EXTRA` MUST be empty — any extra key is a typo and never resolves. `MISSING` should ideally be empty too (missing keys fall back to English). Fix EXTRA keys before proceeding.

6. **Verify placeholder parity.** Every key whose English value has `{tokens}` must carry the same tokens in the translation:
   ```sh
   php -r '$en=require "<slug>/lang/en.php"; $tr=require "<slug>/lang/<code>.php"; foreach($tr as $k=>$v){ preg_match_all("/\{(\w+)\}/",$en[$k]??"",$a); preg_match_all("/\{(\w+)\}/",$v,$b); sort($a[1]); sort($b[1]); if($a[1]!==$b[1]) echo "MISMATCH $k: en=[".implode(",",$a[1])."] tr=[".implode(",",$b[1])."]\n"; }'
   ```
   Output MUST be empty. Any `MISMATCH` means a placeholder was renamed/dropped/added — fix it before proceeding.

7. **Verify runtime resolution** through the registry using the namespace from Step 2 and a real key from `en.php`:
   ```sh
   cd <slug> && composer install --quiet 2>/dev/null; php -r 'require "vendor/autoload.php"; use SugarCraft\Core\I18n\T; T::register("<ns>",__DIR__."/lang"); echo T::t("<ns>.<some.key>",[],"<code>")."\n";'
   ```
   It must print your translated string (not the raw key, not the English). If it prints the raw key, the namespace or filename is wrong. Verify before reporting done.

8. **Run the lib test suite** to confirm nothing regressed (lang files are pure data, but the suite catches a fatal parse error):
   ```sh
   cd <slug> && vendor/bin/phpunit
   ```

## Examples

**User says:** "Add a German translation to sugar-bits"

**Actions taken:**
1. Code `de` is in `LOCALES.md` recommended set; base language, covers de-de/de-at/de-ch. Target lib `sugar-bits`.
2. `ls sugar-bits/lang/en.php sugar-bits/src/Lang.php` → both exist. `Lang.php` has `NAMESPACE = 'bits'`.
3. Read `sugar-bits/lang/en.php` — 22 keys, two carry `{column}`/no placeholders.
4. Write `sugar-bits/lang/de.php`: docblock `German translations for sugar-bits.`, `declare(strict_types=1);`, return array with German values, keys + `{column}` intact, e.g. `'spinner.fps_positive' => 'spinner fps muss > 0 sein',`.
5. Key-parity check → `EXTRA:` empty. ✔
6. Placeholder check → empty. ✔
7. `T::register('bits', …); T::t('bits.spinner.fps_positive', [], 'de')` prints the German string. ✔
8. `vendor/bin/phpunit` green.

**Result:** `sugar-bits/lang/de.php` added; `en.php` untouched; runtime serves `de`/`de-de`/`de-at` from one file.

## Common Issues

- **`T::t()` returns the raw key (e.g. `bits.spinner.fps_positive`) instead of a translation:** The `(namespace, filename)` pair didn't resolve. 1. Confirm the filename is exactly `<code>.php` lowercase with `-` (not `_`): `pt-br.php`, never `pt_BR.php`. 2. Confirm the namespace passed to `T::register()` matches `const NAMESPACE` in `src/Lang.php`. 3. Confirm the dir passed is `<slug>/lang`.
- **Key-parity check prints `EXTRA: some.key`:** You introduced a key that isn't in `en.php` (usually a typo). Rename it to match `en.php` exactly — extra keys never resolve at any locale.
- **Placeholder check prints `MISMATCH`:** A `{name}` token was translated, dropped, or added. Restore the exact English token name; only the surrounding prose gets translated. `T::interpolate()` does literal `{name}` substitution and leaves unmatched braces in the output.
- **`PHP Parse error: syntax error, unexpected ...` when requiring the file:** Almost always an unescaped apostrophe in a single-quoted string (French/Italian). Escape as `\'` (e.g. `'l\'aide'`) or switch that one value to double quotes.
- **Output shows mixed languages (some strings still English):** Those keys are missing from your file and fell back to `en.php` per the lookup chain (exact locale → base language → `en` → raw key). Add the missing keys (see the `MISSING:` list from Step 5).
- **User asks to translate a lib with no `lang/` dir:** That lib is not i18n-wired. Do NOT create `lang/` + `src/Lang.php` here — that is library scaffolding. Tell the user the lib needs i18n wiring first (canonical wrapper: `sugar-wishlist/src/Lang.php`).
