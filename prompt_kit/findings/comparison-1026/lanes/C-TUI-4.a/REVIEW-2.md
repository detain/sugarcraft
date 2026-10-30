VERDICT: FINDINGS — 0C/1M/0m/0n

# C-TUI-4.a review (round 2) — fix round on `3243d8e53`

Lane `cc/C-TUI-4.a` @ `/home/sites/cc-cmp-C-TUI-4.a`; commits `833fff772` + `8bc69dcfa` +
fix `3243d8e53` (base parent verified `aa5ddf638…` — cat-file all three = commits). Fix commit:
8 files +132/−32, `%ae`=`%ce`=`detain@interserver.net` (separate reads), zero trailers.
`git diff 8bc69dcfa..3243d8e53 --numstat` over suite-figure.json / durations.tsv /
candy-code-web/dist = EMPTY; `candy-code/src/Chat.php` numstat EMPTY (m3 weld rider stays owed).

## Findings

### MAJOR — RuntimeNoticeSink census roster never moved; guard RED at lane tip (lane-owned)
`candy-code/tests/Cli/StderrEmitterCensusTest.php` — the brief asked to VERIFY
StderrEmitterCensus. It FAILS 2F (95T/22595A): both arms read `self::RUNTIME_NOTICE_SITES`
(const at :672) and the scan-vs-roster diff shows the ONLY delta is
`src/Theme.php: the roster says nothing, the scan counts 4`.
`Theme.php` carries 4 `RuntimeNoticeSink::warn()` sites (:217 missing, :239 refused — NEW
this round, :251 malformed, :255 notes). Three of the four existed at `8bc69dcfa` (grep of
the landed blob counts 3), so the guard was already red at the r1 tip — the r1 reviewer's
gate list never ran this family, and the fixer's guard triage (ReadmeSuiteFigureDrift,
DTHD, TreeWideGuardRoster) missed it too; the m2 fix then raised the count to 4 without
touching the roster. This is the plan §1.8 in-step guard law: a new seam write needs its
roster row in the same commit, and StderrEmitterCensus is NOT on the foreign standing-reds
roster (the failure diff proves every other file matches master). Picking this range makes
the weld K=24 battery red — blocks weld as-is.
Fix (lane follow-up, one row + decision comment, roster is path-ordered — S < T):
```
candy-code/tests/Cli/StderrEmitterCensusTest.php:810 (before the closing `];` of
RUNTIME_NOTICE_SITES, after `'src/Skills/SkillMatcher.php' => 1,`):
    + 'src/Theme.php' => 4,
```
with a WHY comment per the const's style (the four = the theme-file notice family routed
through the seam by design, each once-per-process latched via `self::$warned`), then
re-run `--filter StderrEmitterCensus` (expect 95T/0F) and check no aggregate-word prose
elsewhere quotes the seam count (array_sum consumers at :3074 derive from the const itself).

## Verified clean (r1 findings cured)

- **m1** — `SettingsSearch::matches` gains `?OptionsProvider $options = null`,
  `$options ??= OptionsProvider::new()`, and `haystack()` unions
  `...$options->for($definition)` (declared enums win; else the live picker list).
  PERF PROVEN SAFE: the only `src/` call-site is `SettingsEditor.php:598`, threaded with
  `$this->sources->options` (built once at `SettingsSources::fromLaunch/bestEffort`, which
  fire at editor-open and save/apply transitions — App.php :1754/:1780/:4419 — never per
  frame); `for()` is an array lookup on the pre-built lists; and even the default is cheap
  because `ThemeFile::names()` memoizes one `scandir()` per directory per process
  (`ThemeFile.php:100-110`). All callers covered (grep: 1 src + 5 test call-sites; the
  2-arg test calls exercise the optional default legitimately).
  Pin `SettingsEditorPolishTest::testTheSearchFindsAKeyByItsLiveOptionsWithoutDeclaredEnums`
  is real: dracula/tokyoNight find `theme`, `zzqxjh` control does not,
  `withList(Themes,['zzcustomtheme'])` both directions (supplied replaces built-in),
  plus an editor `rows()` custom-launch-provider leg. Existing envVar/cliFlag/multi-word
  legs unregressed — `SettingsEditorPolishTest|LangParity|SchemaDocDrift|SettingsSchemaTest`
  re-run here: **84T/6139A OK** (fixer figure exact).
- **m2** — `Theme::fromFileOrFallback` restructured: `resolve()===null` → the untouched
  `file_missing` notice (wording byte-unchanged per diff); listed stem + `load()===null`
  → NEW `chat.theme.file_refused` naming the containment refusal, returns `default()`
  (an unread file's `extends` is unknowable — honest); malformed-file path (`load()`
  returns object with `error`) unchanged. Branch order correct: a refused file can never
  reach the missing notice. Warn-once key `'refused|'.strtolower($name)` — the full
  `$warned` usage is `missing|` / `refused|` / `file|` (grep), no collision.
  `ThemeFileTest` symlink pin extended: drains 1 row, asserts 'refused' + 'real path' +
  NOT 'no file under', second `byName('linked')` drains `[]` (warn-once).
  Trio re-run here: **24T/117A OK** (2 standing PHPUnit deprecations, r1-proven foreign);
  Chat theme filter **7T/16A OK** (superset of claimed 4T/13A pair, green);
  `ReadmeRosterDrift` **10T/78A OK**. en.php key wired (`Theme.php:239`), LangParity green
  inside the 84T run.
- **n1** — `Theme::baseNames()`/`isBaseName()` dropped from `Theme.php` (diff removes both).
  Tree-wide grep: the only remaining `baseNames` hits are `WorkflowRegistry.php`'s own
  pre-existing private `baseNames(string $dir, string $suffix)` — 10 identical hits at
  master's copy of that file (proved via `git show master:…`), zero Theme-namespace
  references, docs clean.
- **n2** — en.php `chat.theme.*` now alphabetical (current, file_malformed, file_missing,
  file_notes, file_refused, set); `Theme.php` imports sorted (`Core\Util\Color` into
  place); `ChatTest.php` `Theme`/`RuntimeNoticeSink` merged into the `Code` block.

## Foreign attributions accepted (not re-run; fixer's base-`8bc69dcfa` proof stands)
staleness pair (weld re-pin duty +14T over `aa5ddf638`), DTHD, TreeWideGuardRoster ×2,
MediaSettings provider-schema, SettingsEditor git-categories, ReadPathCensus
fully-classified. The MAJOR above is NOT foreign — the roster diff isolates src/Theme.php.

## Weld carries (revise, hold until the roster fix lands)
1. Pick range `aa5ddf638..HEAD` EXCLUSIVE start (3 commits + roster fix).
2. DUR `ThemeFileTest.php` row: stays 9T; assertions 35→41 (duration ~10.1s median accepted).
3. §7: +14T. gen-settings-doc `--check` prove post-pick. Serialized regions: Theme.php,
   en.php, SETTINGS.md, README.md, ReadPathCensusTest (+ now StderrEmitterCensusTest) —
   collision-note vs concurrent welds per run-state law.
4. m3 rider: Chat.php ~:7937 comment flip + unreachable-catch → closeout ledger (unchanged).
