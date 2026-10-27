# P-MENU.b — ShellMenu tree derivation — DONE

Commit `4da1fb2c40f1884834e9bb6628272658b8849c63` on `cc/P-MENU.b` (worktree
`/home/sites/cc-cmp-P-MENU.b`, base `75ba82ddb`). Never pushed. Author and
committer `Joe Huss <detain@interserver.net>` (verified `%ae` and `%ce` in
separate greps), zero trailers. Tree clean after commit.

## Files

- **create** `candy-code/src/Tui/Menu/ShellMenu.php` — final class, private
  ctor; `tree(?App $a = null, ?array $rows = null): MenuTree` (rows default
  `CommandRegistry::all()`). `public const HIDDEN = ['generate']`. Nested
  `PLACEMENT` const keyed by the R-MENU-1 design (menu key → ordered entries:
  command names, `SEP`, single-pair submenus `key => list|marker`, markers
  `@panes` = `Pane::tabCycle()` rows, `@more` = runtime leftovers). Derived
  law honoured both ways: a PLACEMENT name absent from the registry never
  becomes a ghost row (test fails on the drift instead); an unplaced registry
  row lands App ▸ More at runtime. More-rows filter uses `declaredNames()` —
  a pre-pass over the whole table — so rows placed *after* the `@more` marker
  (App ▸ exit) do not double up (first build caught exactly that). Panes rows:
  `MenuNode::toggle('pane:<value>', $pane->label(), checked, $pane->icon())`,
  checked = `$a !== null && (!$pane->dockable() || $a->isDocked($pane))` —
  Chat always checked with an App (focus-only centre column; `isDocked(Chat)`
  is structurally false), null App ⇒ structure-identical, every check false.
  generate-form row label = `Lang::t('tui.menu.item.generate_image')`
  ('Generate image…'); every other label is `$spec->label()`. Nothing reads
  ShellMenu yet — P-MENU.d wires it (dormant-until-wired by plan).
- **create** `candy-code/tests/Tui/Menu/ShellMenuTest.php` — 12T/366A. Pins:
  placement↔registry bijection (exactly once, no bluff, via
  `getReflectionConstant('PLACEMENT')`); HIDDEN == ['generate'] alone;
  injected unplaced `CommandSpec('zzz-unplaced')` lands
  `menu:app ▸ sub:more ▸ cmd:zzz-unplaced` (path depth 3); shipped tree has no
  `sub:more`; bar is 4–6 menus with title-sum ≤ 40 cells; generate-image is
  Tools row 0 with `spec->label() === 'Generate image'` proven separately;
  Panes rows are the tabCycle itself in its own order; checks follow
  `isDocked` (plain `App::new(mock,'test-model')->withChat(new Chat())` —
  App's default dock genuinely holds Files; a plain toggle UNdocks it, so
  `withSlotAdded` throws 'already docked' — pinned via precondition instead);
  null-App projection equality (kind/id/label/icon) + all-false checks +
  App-side any-checked; every label ≤ `CommandRegistry::MAX_MENU_LABEL_CELLS + 2`;
  submenus ≤ 2 deep and never empty.
- **modify** `candy-code/lang/en.php` — 19 keys, own prefixes only
  (`tui.menu.title.*` ×5, `tui.menu.sub.*` ×13, `tui.menu.item.generate_image`),
  alphabetical after `tui.menu.empty`. `tui.sessions.*` untouched (P-TABS.c).
  `tests/Commands/RegistryTranslationTest.php` NOT moved (still green).

## Gates (cwd candy-code, plain pipe, `env -u LINES -u COLUMNS`)

- `--filter 'ShellMenu'` → **OK 12T/366A**
- `--filter 'ShellMenu|Menu'` → **OK 221T/3102A**
- `--filter 'RegistryTranslation'` → **OK 8T/791A**
- `--filter 'TuiLang|LangParity'` → **OK 16T/4179A** (census round-trip:
  all quoted tui.menu.* literals resolve both directions)
- five-guard
  `--filter 'ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift'`
  → 133T/15588A, **failures = ONLY the staleness pair** (pinned 25,537 vs live
  25,954 — lane's +12T plus welder-side lag on this base; re-pin is weld/gate
  duty, files forbidden here).
- The `PHPUnit Deprecations: 2` ride the welded base (reproduced on
  `--filter 'MenuTree'` and `MenuBar` alone) — not introduced here.

## Durations duty (gate/welder)

NEW test file `candy-code/tests/Tui/Menu/ShellMenuTest.php` needs a row in
`scripts/parallel-tests-durations.tsv` (never edited in-lane). Suite figure
pair (`suite-figure.json` + README headline) owes re-pin at weld.

## Notes

- GlobDialect law honoured: no glob-shaped literals in test or src prose.
- `declaredNames()` excludes SEP and '@'-prefixed markers structurally; the
  '@' namespace is reserved for markers, so a real command name can never be
  mistaken for one.
- No Chat/Registry-source/Renderer touches; MenuTree/MenuNode consumed as
  welded by P-MENU.a.
