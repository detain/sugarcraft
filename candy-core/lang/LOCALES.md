# Locale codes for SugarCraft translations

Lang files live at `<lib>/lang/<locale>.php` where `<locale>` matches the
output of `T::detect()` after normalization (lowercased, `_` → `-`,
encoding/modifier suffixes stripped). For example, `LANG=fr_FR.UTF-8`
becomes `fr-fr` and `LC_ALL=zh_CN.gb18030` becomes `zh-cn`.

Lookup order is: **exact locale → base language → `en` → raw key**.
That means a single `fr.php` file automatically serves users on
`fr-fr`, `fr-ca`, `fr-be`, `fr-ch`, `fr-lu`, etc. Only add a regional
file when the wording genuinely diverges.

## How to pick a code for a new translation

1. **Always** prefer the bare base-language code (`fr.php`, `de.php`)
   if the wording works for every region.
2. Add a regional variant (`pt-br.php`) **only if** there are real
   lexical or stylistic differences from the base file.
3. Anything else — exotic locales, `LANG=C`, unmapped variants — falls
   through to `en.php`.

## Recommended set for SugarCraft

These are the codes worth investing in first, ranked roughly by reach
in the PHP / CLI tooling community.

| Code      | Language               | Notes                                       |
| --------- | ---------------------- | ------------------------------------------- |
| `en`      | English                | Source of truth — every key starts here     |
| `fr`      | French                 | Covers fr-fr, fr-ca, fr-be, fr-ch, fr-lu    |
| `de`      | German                 | Covers de-de, de-at, de-ch, de-li, de-lu    |
| `es`      | Spanish (Castilian)    | Covers es-es and most Latin-American locales |
| `pt`      | Portuguese (European)  | Covers pt-pt; **see `pt-br` for Brazil**    |
| `pt-br`   | Brazilian Portuguese   | Distinct enough to warrant its own file     |
| `zh-cn`   | Chinese (Simplified)   | Mainland; uses simplified characters        |
| `zh-tw`   | Chinese (Traditional)  | Taiwan / Hong Kong; traditional characters  |
| `ja`      | Japanese               |                                             |
| `ru`      | Russian                |                                             |
| `it`      | Italian                |                                             |
| `ko`      | Korean                 |                                             |
| `pl`      | Polish                 |                                             |
| `nl`      | Dutch                  | Covers nl-nl, nl-be, nl-aw                  |
| `tr`      | Turkish                |                                             |
| `cs`      | Czech                  |                                             |
| `ar`      | Arabic                 | Covers all ar-* locales (rare divergence)   |

## Regional variants worth keeping separate

Distinct enough from the base language that a single file doesn't
cover both well:

| Pair                      | Why split                                      |
| ------------------------- | ---------------------------------------------- |
| `pt` vs `pt-br`           | Different vocabulary, conjugation, formality   |
| `zh-cn` vs `zh-tw`        | Simplified vs Traditional characters; idioms   |
| `nb` vs `nn`              | Bokmål vs Nynorsk — different written Norwegian |
| `sr` vs `sr-rs@latin`     | Cyrillic vs Latin Serbian (rarely needed)      |
| `es` vs `es-mx`/`es-419`  | Optional: Latin-American Spanish vs Castilian  |
| `ca` vs `ca-es@valencia`  | Optional: Valencian dialect of Catalan         |

For everything else (`en-gb` vs `en-us`, `fr-ca` vs `fr-fr`,
`de-at` vs `de-de`) the differences are too small to justify duplicate
files — let the base-language fallback handle them.

## Full list of base language codes recognised by glibc

These are every language code that appears on a typical Linux locale
listing (`locale -a`). Any of them can be used as a SugarCraft lang
file name. The vast majority will never be translated — they are
listed only so contributors who need an obscure locale know the
exact filename to create.

```
aa, af, agr, ak, am, an, anp, ar, as, ast, ayc, az,
be, bem, ber, bg, bhb, bho, bi, bn, bo, br, brx, bs, byn,
ca, ce, chr, ckb, cmn, crh, cs, csb, cv, cy,
da, de, doi, dsb, dv, dz,
el, en, eo, es, et, eu,
fa, ff, fi, fil, fo, fr, fur, fy,
ga, gbm, gd, gez, gl, gu, gv,
hak, ha, he, hif, hi, hne, hr, hsb, ht, hu, hy,
ia, id, ig, ik, is, it, iu,
ja,
ka, kab, kk, kl, km, kn, ko, kok, ks, ku, kv, kw, ky,
lb, lg, li, lij, ln, lo, lt, lv, lzh,
mag, mai, mfe, mg, mhr, mi, miq, mjw, mk, ml, mn, mni, mnw, mr, ms, mt, my,
nan, nb, nds, ne, nhn, niu, nl, nn, no, nr, nso,
oc, om, or, os,
pa, pap, pl, ps, pt,
quz,
raj, rif, ro, ru, rw,
sa, sah, sat, sc, sd, se, sgs, shn, shs, si, sid, sk, sl, sm, so, sq, sr, ss, ssy, st, su, sv, sw, syr, szl,
ta, tcy, te, tg, th, the, ti, tig, tk, tl, tn, to, tok, tpi, tr, ts, tt,
ug, uk, unm, ur, uz,
ve, vi,
wa, wae, wal, wo,
xh,
yi, yo, yue, yuw,
zgh, zh, zu
```

Sentinel locales (`C`, `POSIX`) and the special `en_DK` "computer
English" form fall through to `en` in `T::detect()`.

## How to contribute a translation

1. Pick a code from the **Recommended set** (or open an issue first
   for anything outside it).
2. Copy the relevant lib's `lang/en.php` to `lang/<your-code>.php`.
3. Translate every value, leaving keys and `{placeholder}` names intact.
4. Open a PR — no code changes needed; the registry picks up new files
   automatically as long as the lib is already wired through `Lang::t()`.
