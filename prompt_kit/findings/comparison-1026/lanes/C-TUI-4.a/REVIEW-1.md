VERDICT: FINDINGS — 0C/0M/3m/2n

# C-TUI-4.a review (round 1) — custom TUI themes from `~/.candy-code/themes/`

Lane `cc/C-TUI-4.a`, base master `aa5ddf638`, two commits:
- `833fff772` src+tests+census+lang — 11 files, +906/−16
- `8bc69dcfa` docs — README.md + docs/SETTINGS.md, +20/−1
Both `%ae`/`%ce` = `detain@interserver.net`, zero trailers (verified `git show -s --format`).

All claims below were re-read from landed blobs (`git show <sha>:<path>`) and gates run in the
lane worktree (`cwd=candy-code -c phpunit.xml`, plain pipe, `env -u LINES -u COLUMNS`).

## Findings

### MINOR m1 (step-local) — settings-search vocabulary silently loses the theme names
`candy-code/src/Tui/Settings/SettingsSearch.php:82-92` builds its haystack from
label, key, help, `$definition->enumValues`, category, envVar, cliFlag — it never consults
`OptionsProvider`/`OptionsSource`. The swap in `InterfaceSettings.php` (theme def:
`withEnumValues(Theme::names())` → `withValidators(ThemeNameValidator::new())`) empties the
theme's `enumValues`, so typing `dracula`, `tokyoNight` or `adaptive` in the TUI settings
search no longer surfaces `theme`. Regression is untested: no test pins enum-value
searchability (`SettingsEditorPolishTest:123-160` covers envVar/cliFlag/multi-word only).
Fix: add the live option list to the haystack (e.g. when `enumValues === []`, union in
`OptionsProvider::for($definition)`), or append `Theme::names()` for the theme key, plus a
pin that searching `dracula` finds `theme`.

### MINOR m2 (step-local) — missing-file notice self-contradicts for a refused file
For a theme name that exists on disk but is refused (symlink escape), `ThemeFile::load()`
collapses to `null` and `Theme::fromFileOrFallback()` emits the `chat.theme.file_missing`
notice — "…has no file under ~/.candy-code/themes…" — while that same notice's
`available:` list (built from `Theme::names()`, which only scandir-lists) still contains the
stem. The two shapes disagree on screen; the lane's own symlink pin (`ThemeFileTest`, name
`linked`) walks exactly this path. Fix: distinguish refused from absent (have `load()`
surface a refusal state, or filter the refused stem from `available`), or use a dedicated
`file_refused` key.

### MINOR m3 (out-of-scope, weld carry) — Chat.php doc-truth now false
`src/Chat.php` is byte-untouched (numstat empty — correct, not in lane ownership), but
`Theme::byName()` no longer throws, making its comment at ~`:7937` ("theme() throws on a
name it does not know…") false, and the two `catch(InvalidArgumentException)` arms at
`:7939-7944` and `:21848-21852` unreachable (the `handleThemeCommand` arm's
`$e->getMessage()` path can never fire; `'Unknown theme'` exists nowhere in src). The
catches may stand as defense-in-depth per the dormant-code law, but the comment must be
truth-flipped. Hand to a follow-up lane or the welder as a rider edit.

### NIT n1 — dead new public API with an unpinned duplicate list
`Theme::baseNames()` and `Theme::isBaseName()` have zero callers tree-wide (grep of both
commits + master src/tests): `ThemeFile::parse` uses `canonicalBaseName()`, the validator
uses `isBuiltInName()`/`suggest()`. `baseNames()` hardcodes `['dark','light','dracula',
'tokyoNight','ansi']` duplicating `NAMES` with no drift pin — a future built-in would be
un-`extends`-able silently. Drop both or derive from `NAMES` and pin.

### NIT n2 — ordering cosmetics
`lang/en.php` inserts `chat.theme.file_malformed/file_missing/file_notes` after
`chat.theme.set`, breaking the file's local alphabetical run (no guard enforces sortedness —
`LangParityTest:64-70` compares sorted key SETS only). Out-of-order import blocks:
`Theme.php` places the new `Code\*` uses after `Core\Util\Color`; `ChatTest.php` inserts
`Theme`/`RuntimeNoticeSink` after the `Providers\…` group before `PHPUnit`. PSR-12
alphabetise-by-length nit; fix opportunistically.

## Clean verification (per design block + brief checklist)

- **Scope.** Touched files = the design list + `ChatTest.php`, `SettingsSchemaTest.php`
  (in-step guard movement, §1.8) + the `ReadPathCensusTest` rows (design-demanded). No
  weld-only files: `suite-figure.json`, `durations.tsv`, `candy-code-web/dist/` numstat
  EMPTY. `src/Chat.php` numstat EMPTY.
- **(a) Chat pin flip honest.** The old pin asserted a throw; `ChatTest` now asserts
  degrade-to-`dark` + transcript line + exactly one drained notice. Anti-bleed checked: the
  throw behavior is fully gone from `byName` (no path throws on miss), and the residual
  unreachable catch is finding m3, not an untested live behavior.
- **(b) projection.** Custom colours enter only through `Theme::pair()`; memo key
  (`Theme.php:364-378`) covers name+background+11 source colours so a custom can't alias a
  built-in entry; low-contrast border pin (`#2a2a2a` vs pinned bg `#1e1e1e` projected to
  ≥ CONTRAST_MIN 4.5) proves projection runs on customs.
- **(c) names()/collision.** `names()` = built-ins ∪ discovered, built-ins win; custom
  `dark.json` shadow pinned (`names()` carries exactly one `dark`; `byName('dark')` ignores
  the file). `builtInNames()` feeds docs.
- **(d) never-throws.** Driven through real `byName` in `ThemeFileTest` (9T): malformed
  JSON, unknown `extends`, bad hex, unknown token, `{}`, missing name, symlink, project-only
  file — every shape degrades (malformed→`extends` base + aggregated notes; unknown name →
  `default()` + one notice). Warn-once pinned via repeat `drain() === []` per key.
- **(e) validator swap complete.** Theme definition is the single site
  (`InterfaceSettings.php` L56-65 on this master); `SettingDefinition::effectiveValidators()`
  (`:323`) implies `EnumValidator` only when `enumValues !== []`, so the swap is total;
  project-tier writes with an unknown theme now produce the validator refusal-with-did-you-mean
  (`ThemeNameValidator` message names built-ins + the `~/.candy-code/themes` rule). Web wire
  unaffected: `SettingsMethods.php:181-187` sends `options` (via unchanged
  `OptionsProvider.php:31` `Themes->value => Theme::names()`, `for():59-63` falls through to
  the source when enumValues empty) and `src-web/.../fields.ts:72-81` prefers options —
  the picker gains customs. Protocol schema carries theme only in prose (2 hits) → no regen
  pair owed.
- **(f) OptionsProvider verified-unchanged** — matches builder claim.
- **P1 user-dir-only.** `directoryPath()` = `HomeDirectory::owned()` + `.candy-code/themes`
  (never `configDirPath()`/project); `NAME_PATTERN` forbids `/` and leading-dot so
  traversal is unrepresentable; `load()` realpath-containment refuses symlinks. Both pinned
  (project-tree test; symlink test). `ReadPathCensusTest` rows matched exactly — see gates.
- **P3 SETTINGS.md.** New prose sits outside the generated markers and tells the truth
  about customs/collision/contrast/degrade/extends-fallback and why the definition dropped
  its enum list; it never lists one machine's themes (built-in names only). Generated table
  legitimately byte-unchanged: `tools/gen-settings-doc.php` has zero `enumValues`
  references (grep) — welder still prove `--check` post-pick.
- **P4 lang.** `settings.theme.help` extended + three `chat.theme.file_*` keys, all wired
  through `Lang::t`. candy-code ships `lang/en.php` only (glob) → the 16-locale parity law
  is N/A; LangParityTest green.
- **P5 tests.** `ThemeFileTest` 9T/35A new; `ThemeTest` −1/+3 methods incl
  `testBuiltInNamesAreTheSixAndStable` + `testSuggestFindsNearMissesAndStaysSilentOnNoise`;
  `PaletteThemePickerTest` genuinely lists and seeds a custom (`midnight.json` written into
  the sandbox home, `names()` index > 0, activeThemeName flips).

## Gates (lane tip; foreign reds reproduced at master without lane files)

| Gate | Result |
|---|---|
| `ThemeFileTest\|ThemeTest\|PaletteThemePickerTest` | 24T/112A OK (2 standing PHPUnit deprecations reproduce on untouched main-tree tests) |
| `Tui.Settings\|SettingsSchemaDocDrift\|LangParity\|ReadPathCensus` | 149T/6662A, 2F — BOTH foreign base-reds: (1) ReadPathCensus fully-classified arm (Server/ServerConfig file_get_contents×2 + RemoteSessionPush×1 unrostered) reproduces identically in main tree at master; lane's own two rows green; (2) SettingsEditorTest search-categories (git category added) reproduces in main tree — builder did not disclose running this family, red is not theirs |
| `SettingsSchemaDocDriftTest` alone | 19T/190A OK |
| `theme\|Theme` sweep | 253T/10565A, 1F = MediaSettingsTest provider-schema (disclosed foreign, reproduced at master) |
| `tools/gen-settings-doc.php --check` | not run (reviewer map denies `php <script>`); substituted by SettingsSchemaDocDriftTest green |

## Weld carries

1. `durations.tsv` row: `ThemeFileTest.php` — 9T/35A; measured on this box 9.53s class-run /
   10.14s single-test (bootstrap-dominated). Builder's ~10.1s median accepted with disclosure.
2. §7 figure delta estimate: +13T (ThemeFileTest +9, ThemeTest +3, PaletteThemePicker +1;
   ChatTest/SettingsSchemaTest renames net 0). Re-pin pair at closeout per standing cadence.
3. Serialized-claim files: `Theme.php`, `InterfaceSettings.php`, `lang/en.php`,
   `docs/SETTINGS.md`, `README.md`, `ReadPathCensusTest` — expect clean picks vs master moves
   (C-TUI-4.b was web-only and is welded; C-TUI-4.c, if live, may touch Settings).
4. Post-pick: prove `gen-settings-doc --check` + full `ReadPathCensus` arm still show ONLY
   the two foreign unrostered sites.
5. Carries to fixers: m1, m2 (lane-owned, small); m3 rider into Chat.php comment (any
   Chat-owning lane); n1/n2 optional.
6. Foreign base-red roster at this tip (NOT this lane's): MediaSettingsTest provider-schema,
   SettingsEditorTest search-categories, ReadPathCensus fully-classified arm, standing
   staleness/DTHD pair.
