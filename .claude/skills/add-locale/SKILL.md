---
name: add-locale
description: Adds a translation file at `<slug>/lang/<code>.php` by copying the library's `en.php` and translating only the values while preserving every array key and `{placeholder}` token verbatim; locale codes come from `LOCALES.md`. Use when the user says 'add <language> translation', 'translate <lib> to <code>', 'add Japanese/French/etc. locale', or 'add es.php to sugar-charts'. Do NOT use to edit `en.php` (it is the source of truth), to first-time-wire `Lang::t()` into a lib that has no `lang/` directory yet, or to change the base `SugarCraft\Core\I18n\Lang`/`T` machinery.
paths:
  - */lang/*.php
  - LOCALES.md
---
# Add a locale translation

Create `<slug>/lang/<code>.php` for an existing SugarCraft library by translating its `<slug>/lang/en.php`. This is a pure content task — you copy the exact key structure and placeholder tokens from `en.php` and only translate the string values.

## Critical

- **NEVER edit `<slug>/lang/en.php`.** It is the source of truth — every key starts there. If a key is missing or wrong in English, stop and tell the user; do not patch it inside a locale file.
- **NEVER create a locale file for a lib that has no `lang/en.php`.** That is a first-time i18n wiring task (needs a `src/Lang.php` facade + `Lang::t()` call sites), which is out of scope for this skill. Verify `<slug>/lang/en.php` exists first.
- **Preserve EVERY array key byte-for-byte.** The new file must `return` an array whose keys are an exact match of `en.php`'s keys — same strings, same count. Only the values change.
- **Preserve EVERY `{placeholder}` token verbatim.** A value like `'posix_openpt() failed (rc={rc}, errno={errno})'` must keep `{rc}` and `{errno}` unchanged and untranslated — they are interpolated by `SugarCraft\Core\I18n\T`. Translate the prose around them, never the token names.
- **Pick the code from `LOCALES.md`.** Prefer the bare base-language code (`<slug>/lang/fr.php`, `<slug>/lang/de.php`, `<slug>/lang/ja.php`). Only add a regional file (`<slug>/lang/pt-br.php`, `<slug>/lang/zh-cn.php`, `<slug>/lang/zh-tw.php`) when the table in `LOCALES.md` lists it as distinct. Codes are lowercased with `_`→`-` (e.g. `pt-br`, not `pt_BR`).

## Instructions

1. **Resolve the slug and confirm i18n is wired.** From the user's lib name derive the kebab slug (e.g. "charts" → `sugar-charts`, "pty" → `candy-pty`). Verify the source exists:
   ```sh
   ls <slug>/lang/en.php <slug>/src/Lang.php
   ```
   If `<slug>/lang/en.php` is missing, STOP — this is first-time wiring, not a locale add. Verify both files exist before proceeding.

2. **Resolve the locale code against `LOCALES.md`.** Map the requested language to a code from the "Recommended set" table (Japanese→`ja`, French→`fr`, Brazilian Portuguese→`pt-br`, Simplified Chinese→`zh-cn`). Confirm `<slug>/lang/<code>.php` does not already exist:
   ```sh
   test -f <slug>/lang/<code>.php && echo EXISTS || echo OK
   ```
   If it prints `EXISTS`, ask the user whether to overwrite. Uses the slug from Step 1.

3. **Read the full `<slug>/lang/en.php`.** `Read <slug>/lang/en.php` in its entirety. Capture: the header docblock, every `'key' => 'value'` pair (including comment lines like `// Canvas/Canvas.php` that group keys in multi-section files such as `sugar-charts/lang/fr.php`), and every `{placeholder}` token inside each value. This is the template — the output must have identical structure. Uses the file from Step 1.

4. **Write `<slug>/lang/<code>.php`** matching this exact shape (from `candy-pty/lang/en.php` and `sugar-charts/lang/fr.php`):
   ```php
   <?php

   /**
    * <Language> translations for <slug>.
    *
    * @return array<string, string>
    */

   declare(strict_types=1);

   return [
       // preserve every grouping comment from en.php verbatim
       'canvas.dim_nonneg'        => '<translated prose keeping {tokens}>',
       // ... one entry per en.php key, same keys, same order
   ];
   ```
   Rules for the body: same keys, same order, same grouping comments as `<slug>/lang/en.php`; translate only the right-hand string; keep every `{placeholder}` and any format/upstream identifiers (`barWidth`, `TIOCSWINSZ`, `proc_open()`, paths) as-is. Do NOT add, remove, or reorder keys. Verify your key list matches `en.php` before saving.

5. **Verify key parity and placeholder parity** with a one-off PHP check (adjust slug/code):
   ```sh
   php -r '$s="<slug>"; $c="<code>";
   $en=require "$s/lang/en.php"; $x=require "$s/lang/$c.php";
   $mk=array_diff(array_keys($en),array_keys($x));
   $ek=array_diff(array_keys($x),array_keys($en));
   if($mk||$ek){echo "KEY MISMATCH missing=".implode(",",$mk)." extra=".implode(",",$ek)."\n";exit(1);}
   foreach($en as $k=>$v){preg_match_all("/\{[a-z0-9_]+\}/i",$v,$a);preg_match_all("/\{[a-z0-9_]+\}/i",$x[$k],$b);sort($a[0]);sort($b[0]);
   if($a[0]!=$b[0]){echo "PLACEHOLDER MISMATCH on $k: en=".implode(",",$a[0])." $c=".implode(",",$b[0])."\n";exit(1);}}
   echo "OK: ".count($en)." keys, placeholders match\n";'
   ```
   This must print `OK: <n> keys, placeholders match`. If it reports KEY or PLACEHOLDER MISMATCH, fix the locale file and re-run. Do not proceed until it passes. Uses the file from Step 4.

6. **Run the library's test suite** so `LangCoverageTest` (present in most libs, e.g. `sugar-table/tests/LangCoverageTest.php`) confirms nothing regressed:
   ```sh
   cd <slug> && composer install --quiet && vendor/bin/phpunit
   ```
   If phpunit fails only on stale deps, run `composer update` first (per project gotchas), then re-run. Must be green before shipping.

7. **Ship it** via the ship-as-you-go cadence on branch `ai/<slug>-<code>-locale`, author `Joe Huss <detain@interserver.net>`. Bundle 2-4 locales for the same lib into one PR when adding several. Title: `<slug>: add <code> translation`.

## Examples

**User says:** "add a Japanese translation for sugar-charts"

**Actions taken:**
1. `ls sugar-charts/lang/en.php sugar-charts/src/Lang.php` → both exist.
2. `LOCALES.md` → Japanese = `ja`; `test -f sugar-charts/lang/ja.php` → `OK` (well, `sugar-charts/lang/ja.php` already exists here; for a lib lacking it, proceed).
3. Read `sugar-charts/lang/en.php` — 20+ keys grouped by `// Canvas/Canvas.php` etc., placeholders like none/`{}` where present.
4. Write `sugar-charts/lang/ja.php`: header `Japanese translations for sugar-charts.`, `declare(strict_types=1);`, `return [ 'canvas.dim_nonneg' => 'キャンバスの幅/高さは >= 0 である必要があります', ... ]` — same keys, `barWidth`/`barGap` kept in Latin.
5. PHP parity check → `OK: 22 keys, placeholders match`.
6. `cd sugar-charts && composer install --quiet && vendor/bin/phpunit` → green.

**Result:** `sugar-charts/lang/ja.php` with identical keys to `sugar-charts/lang/en.php`, Japanese values, untouched placeholders — served to `ja`, `ja-jp`, etc. via the exact→base→`en`→raw lookup in `SugarCraft\Core\I18n\Lang`.

## Common Issues

- **`KEY MISMATCH missing=... extra=...` from Step 5:** your locale file dropped or renamed a key. Re-Read `<slug>/lang/en.php`, copy the exact key strings, do not translate keys. If `extra=` shows a key not in `en.php`, you invented one — remove it. Never "fix" it by editing `en.php`.
- **`PLACEHOLDER MISMATCH on <key>` from Step 5:** you translated or dropped a `{token}`. Restore the exact `{...}` names from that key's `en.php` value; only translate the surrounding words. Tokens are case-sensitive: `{fd}` ≠ `{FD}`.
- **`ls: sugar-<x>/lang/en.php: No such file` in Step 1:** the lib is not internationalized yet. Do NOT scaffold `src/Lang.php`/`lang/en.php` here — tell the user this needs first-time i18n wiring (add a `Lang extends SugarCraft\Core\I18n\Lang` facade with `NAMESPACE`/`DIR` like `candy-pty/src/Lang.php`, plus `Lang::t()` call sites) and stop.
- **`LangCoverageTest` fails with "key referenced in src/ missing from en.php":** that is a pre-existing `<slug>/lang/en.php` gap unrelated to your locale file — surface it to the user; it is out of scope for a locale add and must be fixed in `en.php` separately.
- **PHPUnit fails on unrelated classes after only adding a lang file:** stale per-lib `vendor/`/`composer.lock`. Run `cd <slug> && composer update` then re-run phpunit before trusting the failure.
- **File loads but strings never appear at runtime:** the code is `zh_CN`-style but files are `zh-cn`. `T::detect()` lowercases and maps `_`→`-`; name the file `<slug>/lang/zh-cn.php`, not `zh_CN.php`.
