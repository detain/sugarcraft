VERDICT: CLEAN

All nine axes confirmed statically (shell/php denied here):
1. Scope — reflog shows single commit on base 75ba82ddb (695e75f1d amended→4da1fb2c4, tip matches), committer `Joe Huss <detain@interserver.net>` on every entry; worktree vs main-tree spot-check finds only the claimed 3-file surface; en.php delta = exactly the +19 `tui.menu.*` keys, own prefix, alphabetical after `tui.menu.empty`; no other locale file exists in candy-code.
2. Derived-not-declared — real: test derives names from `PLACEMENT` via `getReflectionConstant`, asserts exactly-once uniqueness and set-equality against `CommandRegistry::all()` minus HIDDEN (catches ghost placement AND unplaced row both ways); unplaced→App▸More landing, More position (after Help, before SEP/Exit), and shipped-tree-no-More all pinned.
3. HIDDEN honest — `['generate']` pinned alone; cmd:generate unreachable in any menu; generate-form reachable; palette label 'Generate image' separately proven untouched.
4. Panes non-vacuity — src mirrors MenuBar.php:267 verbatim (`!dockable || isDocked`); dockSide(Chat)=null and isDocked(Chat) structurally false, so neutering either half reddens the per-pane expectations + Files precondition.
5. Geometry — `CommandRegistry::MAX_MENU_LABEL_CELLS+2` (const=22, exists) over titles + recursive walk; ≤40-cell title sum; ≤2-depth + never-empty submenus pinned.
6. Null-App pin — projection equality (kind/id/label/icon) vs checked all-false + at-least-one-checked App pair.
7. P-MENU.a API only — command/toggle/submenu/separator/new/rows/node/pathOf + readonly props all pre-existing; ShellMenu absent from main tree.
8. §1.9 — NAME_SHAPE literal carries `$` → GlobDialect-exempt; no glob/`**Refused:**`-shaped fixtures; TuiLang|LangParity 16T/4179A green claimed.
9. R-MENU-1 — PLACEMENT hand-counted against the drawn table: 63 placed + 1 hidden = 64 registry rows, positions match; §6.1:4115 wording honored.

NIT (step-local): ShellMenu.php:183,214,245,256 — the `$placed` in/out ledger is dead since the `declaredNames()` pre-pass superseded it (moreRows never reads it); its docblock "names this walk consumed" now lies. Fix: drop the param/local or re-stamp the docblock. Non-blocking; also REPORT.md line 40's "CommandSpec::MAX_MENU_LABEL_CELLS" is a prose typo (code/test correctly use CommandRegistry::).

Weld re-run owed: `git show --stat 4da1fb2c4` file-set, `%ae`/`%ce` + no-trailer greps, ShellMenu filter green, and the durations.tsv row for ShellMenuTest.php (correctly never edited in-lane per §1.5).
