# C-TUI-6.h — Community locale `candy-code/lang/<code>.php` (W41, LATE, M) — CLOSED

- branch `cc/C-TUI-6.h` @ `/home/sites/cc-cmp-C-TUI-6.h`, base `16d1a94e8`
- **tip: `7e38caae6e5b647892e5cde4ca0085b19b8fa536`** (git cat-file verified; working tree clean; `git show HEAD:candy-code/lang/fr.php` md5 == disk md5, and status-clean proves every blob for every file)

## Shipped (14 files, +53,018 lines)
- `candy-code/lang/{fr,de,es,pt,pt-br,zh-cn,zh-tw,ja,ru,it,ko}.php` — each 4,803 ln / 4,032 keys, full
  machine translation of en.php INCLUDING the 561-key `web.*` slice. Engine: local SGLang
  nvidia/Qwen3.8-Flash-Next-NVFP4 (temp 0), JSONL batch protocol, skeleton-mirroring writer
  (comments/order/heredocs verbatim). Harness: /tmp/opencode/cc-C-TUI-6.h/{parse_en,mt_run,build,review}.py.
- `candy-code/tests/LangCommunityLocaleTest.php` — **NEW *Test.php (durations.tsv row owed by gate)**:
  locale-set census + per-locale non-English `Lang::inLocale` samples (settings.category.permissions
  / skills, chat.agents.no_mailboxes) + `WebStrings::negotiate/for` answers the code (web sample
  web.agents.none_yet) + regional `<code>-xx` falls back to base. GlobDialect-safe (scandir +
  '.php' suffix, no wildcard literals).
- `candy-code/README.md` — `### Languages` note (all 12 codes, example `LANG=fr_FR.UTF-8 candy-code`).
- `candy-code/docs/CONTRIBUTING.md` — `## Locales and translations` (strict-parity rule: a new en key
  must be translated in the same change; placeholder/brand/model-text laws).

## NOT touched (strict ruling)
`lang/en.php`, `tests/LangParityTest.php` (git diff of both vs HEAD: empty), suite-figure.json,
durations.tsv, dist/, any src/, the web app.

## Machine review (validation, all 11)
placeholder-set equality 0 diffs; key set + order identical per file (php array_keys census);
printf/backtick/**/[[NL]] token validation enforced per batch at MT time; php -l all pass.
English-identical values 3–6% per locale (brand/flag/path-only strings — expected).
**154 items (0.3%) fell back to English** after solo retries (fallbacks/<loc>.txt logged) — parity
valid, translation missing; candidates for a follow-up pass, gate unaffected.

## Gates (cwd=candy-code, env -u LINES -u COLUMNS plain pipe)
- red-before-fix: `--filter 'Lang'` = 602T/7526A/**34F** (all 34 in LangCommunityLocaleTest;
  parity+launch green) before locales landed.
- final `--filter 'Lang'` = **635T/52,010A, 0F/0E, 1S (base canary), 2 pre-existing PHPUnit deprecations, OK**.
- widened `--filter 'Lang|Locale|WebStrings|server.strings|ServerStrings'` = **659T/52,910A OK** (same shape).
- doc guards `ReadmeRosterDrift|DocFigureProseDrift|EnvRosterDrift` = **140T/47,777A OK**.

## Author law
`git log -1 --format='%ae' | grep -c detain@interserver.net` = 1; `--format='%ce'` = 1 (separate greps);
`%b | grep -ci co-authored` = 0. No caliber refresh run. One commit off base.

## Deviations / notes for welder+gate
- Design block said en.php "3,292 lines" — live tree at base is 4,835 ln / 4,032 keys; translated the
  live file (block's own "read from disk" authority).
- WebStrings API is `negotiate`/`for` (no `forLocale` method exists); the test asserts `negotiate(code)
  === code` + `for(code)` content — the design's intent ("answers <code>") verbatim.
- Gate owes: durations.tsv row for tests/LangCommunityLocaleTest.php; suite-figure/README counters
  re-derive on merged tree (this lane adds 33+1 tests to the Lang family; +0 to en.php keys).
- Risk arm from design block (tests asserting English without pinning locale on hosts with LANG=<code>):
  LangParityTest/LangLaunchLocaleTest/TuiLang family all pin via setLocale/overrideNamespace pseudo-catalogues
  — audit found no un-pinned English-literal assertion against the real lang/ dir beyond the shipped guards.

## CLOSURE — 2026-11-01 continuation (post C-WEB-5.f rebase)
Master advanced to 26462c3ae; C-WEB-5.f added 4 keys (web.chrome.about.logs{,_empty,
_no_log,_refresh}) to en.php. This lane rebased cc/C-TUI-6.h --onto 26462c3ae (3 commits,
conflict-free) and added their MT to all 11 community files at the same alphabetical slot
(commit bf349531521fa6d63622c208aaf2fa56c6856e3e, cat-file-verified = TIP). Key-init
census now 3675 == master en.php 3675 in all 12 files. Full 44-row translation table +
gate logs: /home/sites/cc-cmp-C-TUI-6.h/REPORT.md.
Gates: --filter 'Lang' OK 635T/52058A (strict-parity GREEN, LangParityTest untouched,
en.php untouched); php -l 11/11; doc guards OK 140T/47834A. BinCandyCodeDispatchTest
138T/1F = '--worktree offered by no completion' (:1958) PROVEN FOREIGN-INHERITED
(master ArgvParser carries the flag, master test file zero mentions) — the banked §7
gate rider, not owned here. Branch history also carries 11d0d647f (dispatch-harness
LC_ALL=C.UTF-8 en-floor fix, authored on this branch mid-run by another hand — kept:
scoped to BinCandyCodeDispatchTest runBin env + one new passing pin).
Welder note: prior "one commit off base" now three (2f53689bf, 11d0d647f, bf3495315).

## FLEET CLOSED + HOST-LOCALE CURE + MOVING-TRUTH REBASE (2026-11-01, this cut)

- All 11 locales built, strict-valid, php -l clean; committed + rebased below.
- Host-locale audit result: ONLY BinCandyCodeDispatchTest's null-env `runBin`
  path was poisoned by the new catalogues (13 reds measured under
  LC_ALL=de_DE.UTF-8; every other bin spawner uses a LANG-less whitelist env
  or asserts non-Lang raw strings — 'You said:', build-phar errors, all
  absent from lang/en.php). Cure: EN_LAUNCH_FLOOR ['LC_ALL'=>'C.UTF-8'] merged
  over the inherited env (CI spelling, pinned to decide en by
  LangLaunchLocaleTest:87); minimalEnv path already immune. Regression pin
  testTheChildSpeaksEnglishEvenWhenTheRunnerSitsOnAForeignLocale proven
  discriminating: GREEN fixed / RED under floor-neutered mutation (child
  answered German), cp+md5 restore clean.
- TWIN FUSION (disclosed): the original builder session was NOT dead — at
  ~03:19 it noticed C-WEB-5.f (909fd2546) had added 4 web.chrome.about.logs*
  keys to master en.php, MT'd them, committed the carry, and rebased the whole
  branch onto 26462c3ae. Its work is correct and in-scope (numstat: exactly
  the lane's 15 files); this cut adopted it after verification rather than
  racing it. Deviations vs dispatch brief: branch base moved 16d1a94e8 →
  26462c3ae (24 commits, includes the 4-key recount the brief itself
  demanded); early commit 893ac1705 was absorbed by an amend into
  2f53689bf (post-rebase sha).
- Final gates on rebased tree (all with the 2 pre-existing PHPUnit deprecations):
  LangParityTest|LangLaunchLocaleTest|LangCommunityLocaleTest|
  CliLangCatalogueTest = 95T/48875A OK; WebStrings = 21T/650A OK;
  BinCandyCodeDispatch|Wiring|ServeStatusStop|SuiteChildStdinIsolation =
  1503T/9090A/1F NORMAL HOST AND GERMAN HOST IDENTICALLY (the 1F is the
  accepted --worktree completion roster red, reproduced on clean master);
  ReadmeRosterDrift|DocFigureProseDrift|EnvRosterDrift = 140T/47834A OK;
  GlobDialect = 14T/1F = the accepted corpus-figure rider (310,245 vs
  312,477), byte-identical on clean master, no glob-shaped literals added.
- Branch tip (cat-file verified): 3 commits — 2f53689bf (11 locales+docs+test,
  48,819-line adds), 11d0d647f (dispatch harness floor+pin), bf3495315
  (4-key carry). %ae/%ce detain on all three, zero trailers, tree clean.
