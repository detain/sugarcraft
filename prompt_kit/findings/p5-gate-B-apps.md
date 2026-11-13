# P5 gate cluster B (apps) - live-tree verification + BUILD/FIX plan

Audited 2026-11-13, read-only, against `git log` + live files. Standing owner rule applied: build/fix, not disclose/defer.
Targeted phpunit (each app's own vendor), all GREEN: candy-sql 2181T/14249A/2S, candy-files 443T, candy-mail 470T, candy-minesweeper 410T,
candy-starter 85T/1S, candy-kv 618T, candy-vcr 1436T/3S, candy-git 457T, candy-scriptui 950T/8S, candy-discord 192T/1S, candy-git-server 728T, candy-ssh-launcher 518T/1S.
No red tests in this cluster.

## 0. Packet staleness (the packet was compiled without a shell; these claims are wrong against the tree)

- Most "NO-DEFAULT / MUST-ACT" rows already shipped a DISCLOSE/DOC arm under "owner ruling 3a/6a/2b" - the target for this wave is the build arm on top of that.
  SQL-C01, SQL-O03 (doc only), SQL-O04 (keyword leg), SQL-P05 (doc arm), SQL-C25, CF-03, CF-17, CM-11/33 (descope stamps), CM-65, msw-09, msw-26 (cheap arm), msw-35, CS-01/02/03/14/16, sshl-43, KV S25/S36/S37 are all LANDED in some form. Detail per item below.
- DIS-2 premise is wrong: candy-discord is NOT English-only. 47 `Lang::t(` sites in `candy-discord/src` (`use SugarCraft\Code\Lang`) over 51 `channels.discord.*` keys in `candy-code/lang/*.php` (16 locales + zh-tw). The packet REC "English-only disclosure" would be a false statement. Real gap: strings still literal (command descriptions, usage lines, ServeError copy).
- CS-03: sugar-core leg ALREADY LANDED (467a290d4, sc-05). Verified live: `php candy-starter/bin/start </dev/null` rc=0, `printf q | ...` rc=0. Not "MUST-ACT unblock".
- CM-65: dated-exception stamp landed (00a903777) but zh-tw catalogue still absent -> build arm = ship zh-tw (6 other apps already carry one).
- Missing from queue under the packet's ids: DIS-1/DIS-2 live as `discord-14`/`discord-15`; KV-062 is `KV-GATE-CIT` (not a queue row); KV S39/S44/S45 are NOT landed (packet implied "recorded ruling live").
- Environment note: `candy-scriptui` phpunit output redirected to a file truncates mid-progress at ~66% when run concurrently with other suites (stdout of an in-process command test shares the fd); run solo it prints 950/950. Not a failure, but CI log-scrapers should not trust a truncated tail.

## 1. Verdict table

| item | live state | verdict -> BUILD decision | eff |
|---|---|---|---|
| SQL-C01 | LANDED cffc5cc25 (NullDatabase sentinel + fold seams, AdminEntrySurvivalTest) | DONE; add one extension: empty-context pages render a "no server admin on SQLite" notice instead of blank panes (optional S) | S |
| SQL-O03 | doc-only 17df325c8 (limitations row) | BUILD in-grid editing: edit cell / insert / delete row via new DmlBuilder, two-phase confirm; retire limitations row | L |
| SQL-O04 | keyword v1 9becb2543 (Completer, Ctrl+Space) | BUILD schema leg: tables after FROM/JOIN/INTO/UPDATE, columns in context, `t.` qualifier, alias resolution | M |
| SQL-O05 | UNTOUCHED (1 translated key `database.no_file`; ~7 Page files + Renderer + App literals) | BUILD app-wide extraction, 4 legs, all 16 locales, guard test | XL |
| SQL-P05 | doc arm fa982fb3d (pg hole named, counters pair @deprecated REMOVED-IN 2.0.0, island documented) | BUILD: PG key mapping into checkAllMetrics, dispatch via existing notifier sink, adopt-or-delete the Postgres island (adopt through AlertThresholds) | M |
| SQL-C25 | LANDED 198d49e42 (REMOVED-IN markers; `AlertThresholds::default()`->`standard()` hard rename; twin Version cross-notes; AppBuilder clone convention pinned) | Residual: twin `Version` classes still share a short name -> rename server VO `Db\Version`->`Db\ServerVersion` + `class_alias` deprecated; no alias for `default()` (banned-name guard, zero callers) | S |
| CF-03 | LANDED 777edf3b0 (Enter edits via $EDITOR, `l` pages, `x` runs) + faf931c64 legend | DONE. Residual: in-app read-only text viewer (no $PAGER) + "open with default app" (xdg-open) | S-M |
| CF-17 | LANDED 089601fef (Ctrl+R recursive find, budgeted) | DONE | - |
| CF-30 | UNTOUCHED | BUILD bookmarks `a` pin / `B` overlay / `bookmarks.json` | M |
| CF-32 | UNTOUCHED | BUILD `y` stage / `p` paste (additive; c/m untouched) | M |
| CF-33 | UNTOUCHED (no ZipArchive/PharData anywhere) | BUILD (b)+: read-only archive browse behind Enter + extract-to-other-pane; create stays out | L |
| CF-50 | UNTOUCHED; sugar-core already has `Cmd::setClipboard`/`Util\Clipboard` OSC52 | BUILD copy-path/name chords via sugar-core clipboard cmd | S |
| CF-52 | UNTOUCHED (FsLister drops mode/owner) | BUILD mtime column (wide), `I` stat overlay, free space (statvfs) | M |
| CF-55 | UNTOUCHED (no MouseMode, update() drops non-KeyMsg) | BUILD wheel + click + ctrl-click + double-click | M |
| CM-11 | descope stamp b515281d9 | BUILD `MailgunTransport` (+ `--transport mailgun`) | M |
| CM-33 | descope stamp dff17660b | BUILD pop-style compose TUI (`candy-mail tui`) on sugar-core/forms | L |
| CM-65 | exception stamp 00a903777 | BUILD `lang/zh-tw.php`, parity guard 16->17, rewrite README paragraph | S |
| S21 (git-server) | 8b leg landed (least-privilege `user add`, `--admin/--read`, worker-per-request HTTP); first-admin bootstrap ARM absent | BUILD `init --admin <name>` + `--admin-password-stdin` + `GIT_SERVER_BOOTSTRAP_ADMIN` env; refuse-to-serve-with-zero-admins hint | M |
| S36 | NOT landed (value-less `--password`->STDIN, loud password-less/key-less warning) | BUILD with S21 (same bin hunk) | M |
| S25 | LANDED 96e508597 (metadata plane) | DONE | - |
| S44 | NOT landed (README foundation rewrite) | BUILD after S36/S21 (README must describe bootstrap) | L |
| S47 | NOT landed (copy/i18n wave, zh-tw NEW, 12 findings) | BUILD in two waves (locale regen+parity; bin/SSHServer Lang threading) | XL |
| S45 / S37 (git-server) | NOT landed | S45 README tail after S44; S37 independent (Osc52 budget/ring, Stats flush) | M / S |
| CG-43 | UNTOUCHED (candy-git/tools/build-phar.php:154 still only excludes .git/.phpunit.cache/node_modules) | BUILD canonical vendor prune + pin test | S |
| CG-21 | UNTOUCHED (README has no Windows statement; Process.php:84 hardcodes `/dev/null`, `stream_select` on pipes, `proc_terminate(15)`) | BUILD platform ladder behind an injectable seam + commission GitHub-hosted `windows-latest` for candy-git (runner exists, no "pool" needed) | L |
| CG-64 | UNTOUCHED | BUILD README Scope(v1) + OS statement - written AFTER CG-21 so it states shipped truth | S |
| CS-03 | core leg LANDED (467a290d4) | Residual: `ProgramOptions::exitOnStdinEof` opt-out (default true) + CS-08 README sentence | S |
| CS-08 | not done; README :160 still stopgap-style text | BUILD sentence teaching real EOF-stops behaviour | S |
| CS-16 | repo half landed 66a49a32a (bin key dropped, keywords) | Residual = packagist.org legacy-branch hiding (ops, not repo) | ops |
| CS-14 | option b landed 1954ec20e | BUILD option a: route the two frame strings through `Lang::t` + `lang/en.php`(+16) in the template | M |
| CS-01 / CS-02 | channel probe e2fdf5c3a, tag-propagation e2b7cfc48 landed; sync dispatch + first tag mint are release acts | Execute: scoped sync-gate amendment (a), dispatch sync, flip probe `--advisory` off, mint `candy-starter/v0.1.0` | S ops |
| CS-18 | not done (closeout row) | After CS-14 decision/CS-01 ruling: one AGENTS.md sentence | S |
| VCR-S23 | UNTOUCHED (no `lang/`, no `Lang.php`; ~110 literal messages across 11 `src/Cli/*.php`) | BUILD wiring + 16 locales | L |
| msw-09 | LANDED f4f44d766 + 2575bc652 (n opens picker presets 1-4 + `c` custom form, post-game) | Residual: opt-in pre-launch picker `--pick` / `candy-minesweeper pick` (plain launch contract preserved) | S |
| msw-26 | cheap arm landed 6eedd2d92; verb mix still live (`help_moves` "reveal" vs `help_board` "open"; README "Reveal"/"open around flags") | BUILD verb unification -> "reveal" (en + 15 locales + README) | M |
| msw-35 | LANDED 0deb9b7fe | DONE (queue row stale) | - |
| DIS-1 | `/pair approve` live (Commands.php:89-90, README:180-239, DiscordAccount:791) | BUILD rename to `/pair grant`; `approve` stays an accepted deprecated alias for one minor; update PLUGINS.md | M |
| DIS-2 | premise wrong (see section 0) | BUILD: finish the fan-out of residual literals into `channels.discord.*`; record posture = localized via candy-code catalogs | M |
| scriptui-32 | UNTOUCHED (6 `stream_get_contents(STDIN)` slurps before TimeoutGuard arms) | BUILD arm (a) select-loop pre-reads under deadline | M |
| scriptui-35 | UNTOUCHED (ColourGate has no TERM ladder) | BUILD TERM/COLORTERM requantise ladder, spec NO_COLOR, stricter tier | M |
| scriptui-58 | LANDED 2410d744e | DONE | - |
| scriptui-59 | UNTOUCHED (help surface English; README :413 exemption) | BUILD help-surface localization (17 catalogs) | XL |
| scriptui-68 | UNTOUCHED | BUILD allow-listed `GUM_*` -> `CANDY_SCRIPTUI_*` read-only mirror | M |
| scriptui-73 | LANDED e67effc3c (DECSCUSR) | DONE | - |
| scriptui-83 | UNTOUCHED (`'> '` default at ChooseCommand.php:48) | BUILD adopt gum `→ `; goldens move (disclosed) | S |
| scriptui-84 | UNTOUCHED | BUILD `--hard-wrap`/`--fade N` (pager), `--pointer` (confirm), `--code`/`--background`/`--foreground` (format) | M |
| KV-062 | unruled | RULING: both - `Mirrors charmbracelet/skate.<verb>` at the ~6 CLI-verb behaviour sites only, plus a CALIBER law line (S42 forbids parity laundering elsewhere); citation guard test | S |
| KV S39 | NOT landed | BUILD `choosePlural` CLDR chooser local to candy-kv (ru/pl/cs/ar + uk if present) | M |
| KV S44 | NOT landed | BUILD locale copy sweep + `cli.dryrun_none` x16 | M |
| KV S45 | NOT landed (blocked on S44) | BUILD pt vs pt-br regional split | S |
| KV S36/S37/S25 | LANDED 131560f2e / bb63b8233 / 9901cf655 | DONE | - |
| sshl-43 | docs LANDED b5d974773 | Only the tag mint remains (release act); `candy-ssh-launcher/v0.1.0` | ops |

## 2. Per-item detail and implementation plans

Repo rules honoured in all plans: lang edits touch all 16 (17 where zh-tw exists) files in one commit; new tests mutation-proven; per-app drift/figure files (suite-figure.json, parallel-tests-durations.tsv) only apply to candy-code; site-mirror (`docs/_data/<slug>.*`) edits are the closeout lane's, not the step's.

### 2.1 candy-sql

**SQL-C01** (a) live: `NullDatabase` (src/Db/NullDatabase.php), `EmptyServerContext::connection()` returns it, App fold seams, `tests/App/AdminEntrySurvivalTest.php`. (b) nothing broken. (c) done. (d) optional polish: Renderer line "server administration needs MySQL/PostgreSQL" when context is the empty one; 1 test; touches App.php/Renderer.php (hotspot, serialize).

**SQL-O03 in-grid editing** (a) only README bullet landed (17df325c8); no edit mode. (b) whole feature. (c) build. (d) Plan:
- New `src/Db/DmlBuilder.php` (final, immutable): `update(table, pkCols, pkVals, col, newVal)`, `insert(table, values)`, `delete(table, pkCols, pkVals)` returning `{sql, params}`; every identifier through `Identifier` (SQL-C22 landed 0b59a7561), values ALWAYS bound parameters; refuse tables without PK/rowid (SQLite `rowid`, PG `ctid` is unsafe -> require PK; MySQL PK). Need PK info: `SchemaColumn` already carries it via providers (verify `pk` flag in SchemaColumn; else extend providers - src/Schema/*Provider.php).
- App: Rows-pane edit mode (`e` edit cell, `i` insert row form, `D` delete row) as a new state object `src/App/EditState.php`; two-phase confirm reusing the destructive-arm model (App.php ~1004-1015: first press arms, second confirms; edit commits via preview SQL shown in status first). Transaction: PG/SQLite native tx; MySQL single-statement autocommit per commit (document).
- Tests: `tests/Db/DmlBuilderTest.php` (hostile identifiers/values, quoting per flavor), `tests/App/CellEditTest.php` (fixture SQLite round-trip: edit/insert/delete persisted; refusal on PK-less table and on NullDatabase), rollback leg.
- Docs: README remove/replace the Known-limitations "browsing never mutates" bullet (17df325c8), keys table + --help (bin/candy-sql); lang keys for prompts (rides SQL-O05 legs, so write them via Lang::t from birth and add to all 16).
- Deps: SQL-O05 (new strings go through Lang::t immediately); App.php hotspot - serialize with O04b and O05.

**SQL-O04 schema leg** (a) `src/Completer.php` is keyword-only (116 lines), App chord arm Ctrl+Space Query-pane only. (b) no table/column context. (c) build full. (d) Plan: extend `Completer` with a pure context classifier (`contextAt(sql, caret)` -> keyword|table|column|qualified(alias|table)) using a tiny tokenizer that respects quotes/comments; candidates from the already-loaded `SchemaBrowser` tables (`SchemaTable` has columns); alias map from `FROM t a`/`JOIN t AS a`. App passes `SchemaBrowser` snapshot into `Completer::complete()`. Reuse the existing status-list / LCP-advance behaviour. Tests: extend `tests/App/CompletionTest.php` + new `tests/CompleterContextTest.php` (FROM/JOIN/UPDATE/INSERT INTO tables; `a.` alias columns; unqualified columns only from tables in the statement; quoted identifiers; no schema loaded -> keyword fallback). README + `--help` change "v1 is keyword-only" (bin/candy-sql 4 lines from 9becb2543). Deps: none external; App.php hotspot.

**SQL-O05 i18n extraction** (a) untouched: only `database.no_file` is translated (README:884 says so); bootstrap landed (a58ec409b `T::setLocale(T::detect())`), prefix strip landed (7cf729a5c). (c) build. (d) Split per annotation: O05a client chrome/help (Renderer, BorderFrame, App status literals, bin --help) ; O05b admin page copy (Admin/**Page.php ~49 render sites - note admin dir is large); O05c status/error lines (Db/* errors piped into withError; cap hard: re-use PDO message but frame via key); O05d guard test `tests/NoRawLiteralsGuardTest.php` (allow-list ratchet - new render literals outside Lang red) + 16-locale fill + key-parity test (none exists today per G3 note; add `tests/LocaleParityTest.php`). Stable key prefixes `ui.*`, `admin.<page>.*`, `db.*`. Must land after any string-churning step (O03/O04 should already emit keys). Effort XL: schedule as 4 serial agents; lang/*.php + Renderer + App are the collision surface. Keep placeholders ASCII-safe `{name}`; ja/zh width matters (Renderer fit-law tests 'RendererNarrowFitTest' must stay green).

**SQL-P05** (a) fa982fb3d documented the hole; `AlertManager::checkAllMetrics` (AlertManager.php:158) still watches MySQL-named keys; `checkConnectionUsage`/`checkAndDispatch` @deprecated; `PostgresAdminProvider::checkAllMetrics/checkConnectionUsage` (Providers/PostgresAdminProvider.php:339/:438) island with 4 divergences. (c) build the fix the docs promised. (d) Plan: add a flavor-aware metric map in `AlertManager` (`withFlavor(Flavor)`/key resolver: pg `numbackends`->connections, `xact_commit`, `blks_hit/blks_read`, `deadlocks`, `log_min_duration_statement`->slow...), keep MySQL path byte-identical; adopt the Postgres island: delete its two methods (or make them thin adapters returning `Alert` VOs by calling AlertManager with AlertThresholds) and remove fabricated `max_connections=100` fallback; one elapsed<=0 law = Sampler's. Dispatch: counters pair stays deprecated but `checkAllMetrics` already notifies via `withNotifier`; verify DashboardPage::checkAlerts (DashboardPage.php:363) calls notifier for PG. Tests: flip the "screaming PG snapshot must stay silent" pin (fa982fb3d pin in `tests/Admin/Alerts/AlertManagerTest.php`) to "PG snapshot over threshold alerts" + silent-when-calm; delete island tests. README watched-metrics table: Reachable-through column updated. Files: Admin/Alerts/*, Admin/Providers/PostgresAdminProvider.php, Dashboard/DashboardPage.php, README (last).

**SQL-C25 residual** (a) landed 198d49e42 (markers, `standard()`). Note `default()` was hard-renamed with NO deprecated alias; per owner "prefer alias" - a `default()` alias would reintroduce the AGENTS-banned name and trip the repo-wide `function default(` grep guard, zero known callers, pre-1.0: leave hard rename (state this in the commit). (c)/(d) twin `Version`: rename `src/Db/Version.php` -> `ServerVersion` with `class_alias(ServerVersion::class, Version::class)` + `@deprecated REMOVED-IN: 2.0.0` (marker law enforced by AccessorDeprecationTest census); grep callers in Db/Admin; remove the cross-notes' "owner-gated rename" prose. S.

### 2.2 candy-files (Manager.php 2751 lines is the collision hotspot; lang/*.php x16 shared)

Schedule strictly serial in one lane: **CF-52 -> CF-55 -> CF-32 -> CF-30 -> CF-50 -> CF-33**, README last, each with new status keys x16 (LocaleParityTest + lang-coverage guards).
- **CF-03 residual** (a) landed (777edf3b0 + b4700ed3b docs + faf931c64 legend); `ManagerOpenTest` 10 cases. Remaining (owner asked "viewer/pager/editor/run"): editor/pager/run done; build an in-app read-only text viewer overlay for when no $PAGER exists + `o` open-with-default (`xdg-open`/`open`, argv-list spawn like the existing x arm). Optional S-M; same Manager dispatch lane.
- **CF-17** done; no action.
- **CF-52 metadata** files: `src/Entry.php`, `src/FsLister.php` (carry perms/owner/mtime; cache keyed in memo CF-39 landed bc032433d), `src/Renderer.php` (mtime column only when width >= threshold; CF-12 tiers), `I` stat overlay (ConfirmState-style overlay pattern from CF-28 help overlay f7e1b4229), free space via `disk_free_space`. Tests: 120-col shows date col, 80-col not; tmpfs fixture; overlay round-trip. M.
- **CF-55 mouse**: `bin/candy-files` -> `ProgramOptions(mouseMode: MouseMode::...)`; `Manager::update` MouseMsg arms; hit-test in Renderer (row from `termRows` viewport, tab bar hit area CF-23); wheel scrolls windowed pane; click sets cursor + activates pane; ctrl-click toggles selection (CF-31 grammar); double-click = Enter semantics. sugar-core `InputReader` already emits MouseMsg subclasses (comments at InputReader:1026) - sugar-mouse zones optional, not needed. Tests: scripted MouseMsg via ProgramSimulator/direct `update()`. README claim "mouse" (023 phantom claim killed by CF-15) -> now true. M.
- **CF-32 yank/paste**: `y` stages source list+op; `p` arms the EXISTING confirm at paste time at cursor pane/cwd; c/m untouched. Confirm gate fires at paste. M. Depends CF-31 (landed b1bf4f0ff), CF-11.
- **CF-30 bookmarks**: `a` pin cwd, `B` overlay (search-overlay machinery), persistence `bookmarks.json` under `$XDG_DATA_HOME/candy-files/` (interim; CF-51 config home is unbuilt - build a minimal `src/Support/Home.php`-adjacent store; note `Support/Home.php` already exists), jump = CF-29 path-open (0aa3e7ac4). Atomic write (tmp+rename), 0700 dir. M.
- **CF-50 OSC52**: chords copy path / name via sugar-core `Cmd::setClipboard` (confirm API in sugar-core/src/Cmd.php + `Util/Clipboard/Clipboard.php`); TERM capability gate + status "terminal may ignore OSC52"; assert emitted bytes with a capture seam. S.
- **CF-33 archives**: read-only browse behind Enter for .zip (ZipArchive; ext-zip suggest) / .tar(.gz/.bz2) (PharData) as a virtual lister (the `lister` Closure seam in Pane/FsLister is the injection point); actions disabled in-view with visible refusal; `x`-extract selected to other pane via the copy engine; path-traversal/zip-slip guard (refuse `..`, absolute, symlink escapes) + decompression-bomb size cap. composer `suggest` ext-zip. L.
- README/site: CF-38 landed; add rows; mirror rows reported for closeout.

### 2.3 candy-mail

**CM-11 MailgunTransport**: new `src/MailgunTransport.php` implements `Transport` (look at `ResendTransport.php` for the Attachment/cid/`__debugInfo` secret-hiding gates and curl error mapping); form-encoded POST to `https://api.mailgun.net/v3/<domain>/messages` (EU region `api.eu.mailgun.net` flag), basic auth `api:<key>`, multipart for attachments, `o:` options. Config via env/flags (`--transport mailgun`, `CANDY_MAIL_MAILGUN_*`; mirror Resend's env names/validation); `bin/candy-mail` usage + `lang/*.php` keys x16 (`transport.mailgun.*`) - lang is a shared resource with CM-33/CM-65. Tests: stubbed curl/fake server like the Resend tests; redact key in `__debugInfo`/errors. README "Dual transport" -> three; remove the 2026-11-12 descope sentence (README:27-35) and CALIBER stamp. composer `suggest` unchanged (curl already). M.
**CM-33 TUI**: new `src/Tui/ComposeModel.php` (sugar-core `Model`; fields From/To/Cc/Subject/Body/attachments list, send/preview/quit), view via sugar-style, inputs via sugar-forms (`sugar-forms` -> add `require` bump only; or sugar-widgets text inputs); `bin/candy-mail tui` (and no-args-on-tty = tui, non-tty keeps current usage error). Send runs through `Mailer` via `Cmd` (async, never in view()); `--render` preview reuses MIME render. Tests with `sugar-testing` `ProgramSimulator` + golden. Lang keys x16 (+zh-tw). Update README (remove descope), CALIBER. L. Needs sugar-testing/sugar-forms as dev/require entries (no repositories[] in manifest).
**CM-65**: add `lang/zh-tw.php` (translate every en key; reuse candy-code/candy-scriptui zh-tw wording for shared terms), extend `LangLocaleParityTest` roster/enumeration to 17, rewrite README:461-464. Collision: all of candy-mail `lang/*.php` - run CM-65 + CM-11 + CM-33 keys in ONE lane serially (CM-65 last so zh-tw includes the new keys).

### 2.4 candy-git-server (bin 1262 lines; lang 16 + NEW zh-tw; README)

Order: **S21b+S36 -> S47 -> S44 -> S45**, S37 independent.
- **S21 bootstrap arm**: bin `init --admin <name> [--password-stdin|--key-file]` (creates first admin atomically via `UserStore` transaction, refuses when admins already exist unless `--force`), env `CANDY_GIT_SERVER_BOOTSTRAP_ADMIN` honoured only by `init`; `serve` prints a single hint when the store has zero admins (does not refuse - least-privilege `user add` still works locally since the CLI owns the filesystem). Tests: `tests/UserAddLeastPrivilegeTest.php` pattern (child-process, persisted-store assertions), two-process race (UserStoreTwoProcessTest). lang keys x16.
- **S36**: value-less `--password` reads STDIN (bin :729-755 already reads STDIN in one branch - verify), loud stderr warning for password-less+key-less accounts, init next_step_1 corrected form (lang/en.php :37).
- **S47** (two waves): (i) regenerate `cli.help` in the 15 translated locales from current en, add `tests/LocaleParityTest.php` key-parity guard (none exists), NEW `lang/zh-tw.php`, voice standard doc in `src/Lang.php`, strip baked prefixes (064), CJK-safe label alignment (061); (ii) thread Lang through `src/SSH/SSHServer.php` user-facing lines + bin banner/headers/"not wired" wording. `docs/LOCALES.md` zh-tw row check (LOCALES.md:36 already lists the code).
- **S44** README foundation rewrite (verified worked example init->user add->repo create->serve->clone, flag table, exit codes); **S45** gaps list tail; **S37** `src/Clipboard/Osc52.php` bytes budget + ring eviction, `src/Stats.php` optional flush.
- S25 and S39 landed (96e508597, de8ff1623). Note `git-server` doc `DocAccuracySweepTest` (3c18d0593) pins several README/docblock claims: S44/S45 edits must keep those pins green.

### 2.5 candy-git

- **CG-43**: `candy-git/tools/build-phar.php` add `PRUNE_VENDOR_SEGMENTS = ['tests','Tests','test','examples','.vhs','docs','doc']` + `phpunit.xml*`, `*.md` optional, applied at depth >= 2 under `vendor/` ONLY (never `src/`); extend `candy-git/tests/PharBuildScriptTest.php` with a pin on the file list (assert no `vendor/*/tests/` entries, `vendor/autoload.php` and `vendor/sugarcraft/*/src` present). The same 18 lines are copy-pasted in 20 apps' `tools/build-phar.php` (identical EXCLUDE_SEGMENTS at 154-205) - after candy-git lands, one scripted fleet pass (separate lane, `tools/` per-app files, no cross-lib collision but 20 PharBuildScriptTests) cures the family; not blocked on WF-CURE. S (candy-git) + M (fleet).
- **CG-21**: introduce `src/Platform.php` seam (isWindows(), nullDevice() -> `NUL`, signal vs `taskkill` terminate) used by `Process.php` (:84 spec, :136-140 select -> `usleep` polling on Windows because `stream_select` cannot poll proc pipes, :252 `proc_terminate`) and `Git.php:493`. Unit-test both branches via injected seam; commission real verification by adding candy-git to `WINDOWS_LIBS` in `.github/workflows/ci.yml` (GitHub-hosted `windows-latest`; root-file collision - welder/serial lane). Misleading `cli.no_git` symptom: distinct `git.spawn_failed` text.
- **CG-64**: README "Scope (v1)" (absent families, v2 async note) + OS statement written after CG-21 CI is green. Files: README only (candy-git/README.md is shared with CG-21 docs).

### 2.6 candy-starter / sugar-core

- **CS-03 residual**: add `ProgramOptions::$exitOnStdinEof = true` (sugar-core/src/ProgramOptions.php - positional constructor, append the arg LAST to avoid positional breakage), honour it at the EOF arm in `Program::readInput` (467a290d4 sends QuitMsg unconditionally); test in `sugar-core/tests/ProgramEofTest.php` (opt-out leaves loop alive until quit, with a watchdog). sugar-core is a fleet lock: one agent, run sugar-core full suite + census guards. S.
- **CS-08**: candy-starter/README.md line ~160: replace "won't get raw mode" tail with "piped stdin: the program quits cleanly at EOF (exit 0); pass `exitOnStdinEof: false` to keep running" (depends on the S above). `ReadmeSnippetsTest` oracle must stay green. S.
- **CS-14(a)**: `candy-starter/src/Counter.php` view() frame strings -> `Lang::t` with new `candy-starter/lang/en.php` + `src/Lang.php` (copy `sugar-pty/src/Lang.php`), composer autoload for lang dir, README row rewrite, golden proves English bytes unchanged. The template is the fleet's most-copied file: keep one key pair. M. Do NOT drop the 1954ec20e teaching-boundary comment until Lang is wired (then rewrite it).
- **CS-16**: nothing left in repo; packagist.org legacy `dev-legacy/*` branch hiding = ops task (owner/Packagist UI).
- **CS-01/CS-02**: ops - owner decides "(a) scoped gate amendment" (REC accepted) then `workflow_dispatch` sync, drop `--advisory` in `.github/workflows/sync-sugarcraft.yml` (job ~line 86-99), mint `candy-starter/v0.1.0` tag (green-skip landed e2b7cfc48, so safe). Root-workflow collision.
- **CS-18**: after the above, one sentence in root AGENTS.md (root-file collision; coordinate with DIS-2's AGENTS clause).

### 2.7 candy-vcr VCR-S23

Add `src/Lang.php` (BaseLang wrapper, namespace `vcr`, copy `sugar-pty/src/Lang.php`), `lang/en.php`, route every user-facing literal in `src/Cli/*.php` (11 command files; counts: Migrate 20, Inspect 18, Diff 16, Record 14, Replay 13, Stats 12, Application 10, RenderBatch 5), bin locale bootstrap `T::setLocale(T::detect())` before first message, then fan to 15 more locales + `LocaleParityTest` + a `CliLiteralCensusTest`. README Localization row. Prereqs S66-S71/S39 are copy-final steps in the same files - confirm landed (grep shows none merged yet; S22/S19/S16 are the latest) or accept churn. Collision: all `src/Cli/*`, `lang/*`. L.

### 2.8 candy-minesweeper

- **msw-09**: picker landed; add opt-in `--pick`/`-p` (bin/candy-minesweeper, Game start state = picker overlay) keeping bare launch unchanged. S, touches bin + Game.php + lang x16 (1 key).
- **msw-26 unify to "reveal"**: en.php lines 36-38 (`help_rules`, `help_moves`, `help_board`) plus 15 locales' equivalent words, README Keys table :89-93 "open around flags" -> "reveal around flags"; keep FooterWidthGuardTest (78-cell fit in all locales) and TeachToPlayTest green; `ReadmeRosterDrift`-style tests none but LocaleParity applies. M. Mutation: a locale still using the old word must be caught -> add a verb-census test per locale if feasible.
- **msw-35** done (queue row stale; mark landed).

### 2.9 candy-discord (and candy-code lang coupling)

- **DIS-1**: `Commands.php:89-90` choices `approve`->`grant` (keep `approve` as accepted value, hidden from choices, answered with a one-line deprecation notice), `DiscordAccount.php` /pair text fallback (:791), `PairingCodes.php` docblocks, README :180-239, `candy-code/docs/PLUGINS.md` :155-164 (drift-pinned? `candy-code` doc guards - run candy-code's roster drift tests after editing PLUGINS.md), tests `ApprovalInteractionGateTest`/`DiscordAccountTest` re-pin. Lang keys `channels.discord.pairing.approve_hint` text lives in **candy-code/lang x17** - edit all in one commit. Pair with discord-14 copy sweep (same files; annotation says execute together).
- **DIS-2**: inventory residual literals (`grep` for non-`Lang::t` user-facing strings in Commands.php descriptions, Buttons usage lines, ServeError renders, plugin.json is metadata - exempt); add `channels.discord.*` keys across candy-code locales; `LOCALES.md` row + AGENTS clause states "bundles localize through the host catalogs". candy-code/lang is a SHARED resource with candy-code agents - schedule exclusive.

### 2.10 candy-scriptui (17 catalogs incl zh-tw; commands + README)

Serial order: 83 -> 84 -> 35 -> 32 -> 68 -> 59 (last; XL). All README edits batched at the end (scriptui-60/68 README lock).
- **-32**: new `src/Runtime/IntakeReader.php` using `stream_select` with the same deadline as `TimeoutGuard` (rc 124 constant exists: TimeoutGuard.php:28); replace the 6 slurps (FilterCommand:231, PagerCommand:163, FormatCommand:252, JoinCommand:192, TableCommand:331, LogCommand:234); child-process test `sleep 999 | candy-scriptui filter --timeout 1` -> rc 124. M.
- **-35**: ColourGate.php: when no gate var decides, consult TERM family/COLORTERM (sugar-core ColorProfile ladder), `--ansi` -> Ansi tier, `NO_COLOR` any non-empty disables (spec) keeping FORCE_*; optional stricter "no decorations" tier. Tests matrix in `tests/Runtime/ColourGateTest.php`; README colour section last. M.
- **-59**: extract Command descriptions/option texts/Aliases/Examples/Help labels + HelpFormatter plumbing into lang keys (en source), 17 catalogs, `HelpDriftTest`; replaces README:413 exemption. XL: 3 serial agents (extract; translate 8 locales; translate 9).
- **-68**: `Application`/launcher: allow-listed `GUM_*` mirror (read-only fallback when the `CANDY_SCRIPTUI_*` var unset; disclosed list table) + README. M.
- **-83**: ChooseCommand.php:48 default `['→ ']`; goldens/README demo claims move (disclose). S.
- **-84**: PagerCommand `--hard-wrap`, `--fade N`; ConfirmCommand `--pointer`; FormatCommand `--code` alias, `--background/--foreground` via clamped colour builder; frame tests + remove from compat refusal rows. M.
- -58, -73 landed (queue rows stale).

### 2.11 candy-kv

- **KV-062 ruling = both, narrow**: add `Mirrors charmbracelet/skate.<Cmd>` doc-comments only on behaviour-tracking CLI verbs/Database methods (set/get/delete/list/list-dbs/delete-db equivalents - confirm each against `docs/research/libraries/candy-kv-research.md` before citing; S42 a07052b8d forbids parity claims without evidence) + one CALIBER_LEARNINGS law line; `CitationProvenanceGuardTest`-style test (candy-sql has one: tests/CitationProvenanceGuardTest.php - copy). S.
- **S39**: `Support/PluralChooser` keyed `$count % 100` (ru/uk `_one/_few/_many`, pl, cs `_few`, ar zero/one/two/few/many) local to candy-kv; keys added to those locales only (verify `LocaleParityTest` tolerates documented superset - it asserts per-locale key set equals en, so either extend the guard to allow `_few/_many` suffixes or add them to en); bin plural routing. M.
- **S44** then **S45**: strictly serial (same lang files): nl terminology, ja/ko/tr/fr/it fixes, `cli.dryrun_none` x16, punctuation pass; then pt/pt-br split. `lang/*.php` + LocaleParityTest exclusive.

### 2.12 candy-ssh-launcher sshl-43
Docs landed. Remaining is `git tag candy-ssh-launcher/v0.1.0` + push after CS-01-style sync gate decision; verify `release-app-phar.yml` green-skip first (CS-02 landed). Ops, no agent edit.

## 3. File-collision groups / scheduling

Per-app lane = one agent at a time (apps are mutually independent except where marked). Cross-cutting shared resources:
- **R1 root workflows/AGENTS/LOCALES/MATCHUPS/README**: CS-01/02/18, CG-21 (ci.yml WINDOWS_LIBS), DIS-2 (LOCALES.md, AGENTS.md), S47 (LOCALES.md check) -> one welder/closeout lane, serial. Do NOT run these agents concurrently with other lanes that touch docs/MATCHUPS.md or README.md.
- **R2 sugar-core**: CS-03 residual only (ProgramOptions/Program/ProgramEofTest) - single agent; no other lane in this cluster edits sugar-core (CF-50 only consumes `Cmd::setClipboard`).
- **R3 candy-code/lang + docs/PLUGINS.md**: DIS-1 + DIS-2 (+ any candy-code lane): exclusive; run candy-code drift guards afterwards.
- **R4 per-app lang/*.php** (the repo's "one shared resource per app"): sql (O03/O04/O05/C25-none), files (6 items), mail (CM-11/33/65), git-server (S21/36/47), vcr, msw (09/26), scriptui, kv (S39/44/45), starter (CS-14) - each app serializes its own items on lang.
- **R5 hotspots**: `candy-sql/src/App.php` (O03, O04b, O05a), `candy-files/src/Manager.php` (all CF), `candy-git-server/bin/candy-git-server` (S21, S36, S47), `candy-mail/bin/candy-mail` (CM-11, CM-33).
- **R6 fleet build-phar pass** (CG-43 fleet leg): touches 20 `tools/build-phar.php` + tests; run alone, after candy-git's pin test.

## 4. Priority-ordered, schedulable work list

Wave 0 (ops, no agent code; owner-visible): sync-gate amendment + dispatch + tag mints (CS-01/02/sshl-43), packagist legacy branch (CS-16), mark stale queue rows landed (msw-35, scriptui-58/73, SQL-C01, CF-17...).
Wave 1 (parallel across apps, each its own lane):
 1. candy-git: CG-43 -> CG-21 -> CG-64 (+R1 ci.yml line by welder).
 2. sugar-core CS-03 residual -> candy-starter CS-08 -> CS-14(a) (starter after core).
 3. candy-git-server: S21b+S36 -> S47(i) -> S47(ii) -> S44 -> S45; S37 any time.
 4. candy-mail: CM-11 -> CM-33 -> CM-65 (zh-tw last).
 5. candy-minesweeper: msw-26 -> msw-09 residual.
 6. candy-kv: KV-062 -> S39 -> S44 -> S45.
 7. candy-files: CF-52 -> CF-55 -> CF-32 -> CF-30 -> CF-50 -> CF-33 (CF-03 residual viewer with CF-33 as it shares the open-dispatch).
 8. candy-scriptui: -83 -> -84 -> -35 -> -32 -> -68 -> -59.
Wave 2 (heavier / exclusive resources):
 9. candy-sql: P05 || C25 residual (disjoint files) -> O04 schema leg -> O03 editing -> O05 legs a-d (serial, last; README last).
10. candy-vcr S23 (confirm S66-S71 state first).
11. candy-code/lang exclusive: DIS-1 + discord-14 + DIS-2 together, then candy-code drift tests.
12. Fleet build-phar prune pass (R6).

## 5. Other issues noticed

- Suite totals drifted vs commit-message figures (git-server 728T vs 722T claimed in 3c18d0593): harmless, but figure-pinning docs for that app (README suite pair) should be re-derived at S44.
- `candy-kv` LocaleParityTest enumerates `lang/*.php` by glob: S39's plural-suffix keys need a documented-superset allowance or en additions.
- candy-mail README :461-464 and CALIBER descope text must be deleted by CM-11/33/65, or the README contradicts shipped features.
- candy-files README "mouse" claim history: CF-15 killed the phantom claim; CF-55 re-adds it - update Keys/Behavior blocks introduced by CF-38 (b7984d537).
- Queue rows `plan_review.queue.tsv` lines 2579-2646 (candy-sql) and several others still read NO-DEFAULT though landed; update the owner_gate column in the next queue regen.
- Untracked/modified files from other sessions present (candy-top, sugar-colorprofile, sugar-dashboard): not touched.
