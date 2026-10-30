# C-SEC-7.a — FIX-3 (round 4)

Branch `cc/C-SEC-7.a`, parent tip `9d854a5b2cb302bd38c382315c52e3231a3e72d0`.
All three r3 findings land in ONE follow-up commit (lead ruling: same defect
class; any one left behind is a live truth violation). Model wording = the
lane's already-correct `README.md:2632` / `ENVIRONMENT.md:46`:
**`default` in the TUI and `-p`, `bypass-permissions` for a locally spawned daemon.**

## Findings cured

| ID | File:site | Before (reversed fact) | After |
|---|---|---|---|
| MINOR-2 | `candy-code/lang/en.php` `cli.help.screen` heredoc :491-494 | "With none set, the TUI starts in default …; **-p and background sessions start in bypass-permissions.**" | "With none set, **the TUI and -p start in default** (it asks before writes and shell commands); **only a locally spawned daemon starts in bypass-permissions.**" (4-line block, 25-space continuation column, widths 74/74/75/44 vs prior 74/68/71/44 — max 75 still under the file's 78; `php -l` clean) |
| MINOR-3a | `candy-code/docs/PERMISSIONS.md` :75-77 | "**The console paths keep the permissive default on purpose.** … so an asking default would make an unattended `-p` run in CI refuse its first edit." | "**The `-p` console path is on the asking side too**: its approver asks on stderr at a terminal and **refuses** without one, so an unattended `-p` run in CI *refuses* the first tool call that would ask rather than silently allowing it (name `--permission-mode` to change that). **The path that keeps the permissive default is a locally spawned `/bg` daemon** …" (the :78-87 bypass-*decides-less-than-no-gate* floor text is mode-general truth and stays byte-untouched) |
| MINOR-3b | same file, :91-92 (the same paragraph's closing parenthetical — a second in-file teaching of the reversed fact) | "(The doctor roster itself reflects this: **`-p` and background there means the local console paths.**)" | "(The doctor roster itself reflects this: **the asking `default` for the TUI and `-p`, and `bypass-permissions` for a locally spawned daemon only.**)" |
| MINOR-4 | `candy-code/docs/TROUBLESHOOTING.md` :268-269 | "or this is **a `-p` or background run, whose default is `bypass-permissions`.**" | "or this is **a locally spawned background-daemon run**, whose default is `bypass-permissions` **(a `-p` run starts in the asking `default` and refuses its unattended asks)**." |

## No fourth site teaches the reversed fact — how checked

Three sweeps over `candy-code/docs/`, `README.md`, `lang/en.php`, `src/`:

1. `grep -niE "(\-p|headless|console|background)[^.]{0,120}bypass-permissions"` — surviving hits
   adjudicated individually: `SETTINGS.md:1228` (`default` (TUI, `-p`); `bypass-permissions` (daemon))
   and `ENVIRONMENT.md:46` are the *correct* wording; `SERVER.md:856` contrasts the server's admitted
   mode with the standalone-detached-daemon bypass default (true, and consistent with
   `PERMISSIONS.md:32-34`); `PERMISSIONS.md:110` is sub-agent mode inheritance, not a default claim;
   `TROUBLESHOOTING.md:89` is the accepted-*vocabulary* table (listed valid values — brief says leave).
2. Literal-residue `grep` for the exact old phrasings `console paths keep`, `-p and background`,
   `and background sessions start` → **empty** across docs/, README.md, lang/en.php, src/.
3. The two remaining reversed sites are the registered **out-of-scope NITs** the brief names and
   instructs not to touch: `src/Cli/ArgvParser.php:1303` ("`default` in the TUI, the permissive
   `bypass-permissions` for `-p`") and `docs/HOOKS.md:1156` ("it still is for `-p` and background
   sessions"). Left for the closeout ledger. `ENVIRONMENT.md:46`'s tail clause likewise untouched
   (foreign base drift vs the 2026-10-06 no-gate ruling). `TROUBLESHOOTING.md:89` untouched.

## Guards (targeted phpunit only, cwd=candy-code, plain pipe, `-u LINES -u COLUMNS`)

| Filter | Result |
|---|---|
| `SettingsSchemaDocDriftTest\|EnvRosterDriftTest\|HeadlessDefaultPermissionModeTest\|BypassLaunchBannerTest\|BootstrapPermissionGateTest` | **137T / 40,408A OK**, 2 PHPUnit deprecations (global-bootstrap, expected) — matches r3's measurement exactly |
| `CliLangCatalogueTest\|LangParity\|DocFigureProseDriftTest` | 107T / 7,554A — **1 failure**, registered foreign: `CliLangCatalogueTest::testTheCliBlockIsOneSortedRunBetweenItsMarkers` (:196). Re-run with `lang/en.php` stashed at HEAD: **same red** ⇒ pre-existing cli-block key ORDER (lane-committed `cli.headless.permission_hint` unsorted); my edit changed a value only, never key order — not chased |
| `LangParity\|DocFigureProseDriftTest` | **99T / 7,347A OK**, 2 deprecations |
| `PermissionsIntroDriftTest\|LaunchFlagsTest\|ClaimFamiliesDocumentationDriftTest` | 116T / 566A — **1 failure**, registered foreign: `testThePatternToolRosterIsTheWholeCatalog` (:59), whose paragraph window is `PERMISSIONS.md:813`, far below my :75-92 hunks; re-run at HEAD with all three files stashed: **same red** ⇒ not chased |
| `HelpTest` (help-screen + key-help family) | 163T / 100,432A — 6 failures, all **identical at HEAD** with my edits stashed (1× `CANDY_CODE_SERVICE_TIER` roster, 5× `KeyHelpTest` TUI-overlay) ⇒ **zero reds introduced by this commit**; `testTheHelpScreenIsItsOneCatalogueEntry` green (help screen and the lang catalogue entry move together) |

Excluded from every filter as briefed: `tests/Tools/PtyRunToolTest::testExpectMissIsLoudAndTheScriptStillContinues`.

## Commit

`2e7791673` — 3 files, +16/−10. `%ae` grep-count 1 and `%ce` grep-count 1
(both detain@interserver.net, checked on separate format fields), `Co-Authored-By`
grep-count 0. Explicit-path staging only; no caliber refresh; no suite-figure.json /
durations.tsv / dist touched. FIX-3.md rides as this commit's own follow-up (a commit
cannot carry its own SHA).
