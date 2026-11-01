# C-TUI-6.h step review — round 2 (FINAL frozen branch tip)

Reviewer: fresh r2 session (prior r2 incarnation died mid-write; its 759 B stub
recorded facts I independently re-verified — disk is authority).
Branch FROZEN at bf349531521fa6d63622c208aaf2fa56c6856e3e; no commits landed during review.

## Duty 1 — Commit law on all 3 commits: PASS
Anti-phantom first: `git cat-file -t` resolves bf3495315…, 11d0d647f, 2f53689bf all as
`commit`; `git branch --show-current` = `cc/C-TUI-6.h`; `git rev-parse HEAD` =
bf349531521fa6d63622c208aaf2fa56c6856e3e; `git merge-base master HEAD` =
26462c3ae8e465356d7a4475a753dd6db67b98c2 (branch base); local `git rev-parse master` =
8416644c522dae9a80c0bc522e7b68074fbb01fb (matches the brief's pushed master).
- `git log master..HEAD --format='%ae' | grep -c '^detain@interserver.net$'` = 3
- `git log master..HEAD --format='%ce' | grep -c '^detain@interserver.net$'` = 3 (SEPARATE greps)
- `git log master..HEAD --format='%b' | grep -ci co-authored` = 0.
- Subjects sane and lane-prefixed ("C-TUI-6.h: …") ×3; bodies truthful (MT harness provenance,
  LC_ALL=C.UTF-8 rationale, 3675=3675 census claim — each re-verified below).
- 11d0d647f carries detain authorship on-disk despite the builder's note that it was
  "authored mid-run by another hand" — the commit is law-conformant as shipped.

## Duty 2 — File-set: PASS
`git diff 26462c3ae..bf3495315 --stat` = EXACTLY the declared 15 files:
- A candy-code/lang/{de,es,fr,it,ja,ko,pt-br,pt,ru,zh-cn,zh-tw}.php (4,807 lines each)
- M candy-code/README.md (+16), M candy-code/docs/CONTRIBUTING.md (+19)
- A candy-code/tests/LangCommunityLocaleTest.php (+150)
- M candy-code/tests/Integration/BinCandyCodeDispatchTest.php (+74/−2)
Total +53,134/−2. No other file appears.
`git diff 26462c3ae..bf3495315 -- candy-code/lang/en.php candy-code/tests/LangParityTest.php candy-code/src/` →
0 lines: strict-parity non-edit ruling HOLDS byte-for-byte. No suite-figure.json, no
durations.tsv, no dist/, no composer manifest in the diff (weld-only files clean).
README note: `### Languages` lists all 12 codes with the exact design-example form
`LANG=fr_FR.UTF-8 candy-code`; model-read carve-out sentence present; placed directly
above `### Install as a user` (design said "near Install"; base-line drift makes the
block's L112 cite stale — placement matches intent). CONTRIBUTING section states the
strict-parity law, placeholder law, brand/backtick/printf carry law, and
machine-translation provenance honestly. Both match the design block's asks.

## Duty 3 — Gates: PASS (all run personally at tip; cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)
- `--filter 'Lang'` → **OK 635 tests / 52,058 assertions, 0F/0E, 1S, 2 pre-existing
  PHPUnit deprecations**. Builder's closure figure reproduced EXACTLY. This run
  carries the untouched LangParityTest over all 11 new files: its
  `assertSame(array_keys…)` is order-sensitive PHP array identity, and
  `testPlaceholdersSurviveTranslation` diffs per-key placeholder sets — both green.
- `php -l` ×11 community files → 11/11 "No syntax errors detected".
- `--filter 'ReadmeRosterDrift|DocFigureProseDrift|EnvRosterDrift'` → **OK 140T /
  47,834 assertions** (builder's post-+4-keys figure; r1's 47,777 was the old-tip
  value — both self-consistent).
- `--filter 'BinCandyCodeDispatch'` → **138T, 1F**, the single failure being
  `testEveryVerbTheParserAcceptsIsOfferedByAllThreeCompletions`:
  "--worktree is accepted by the parser but offered by no completion" at :1958.
  PROVEN FOREIGN-INHERITED, NOT charged: `git show master:candy-code/src/Cli/ArgvParser.php`
  carries `--worktree` (line 846, C-AGT-6.c) while
  `git show master:candy-code/tests/Integration/BinCandyCodeDispatchTest.php` has
  ZERO mentions — the mismatch exists identically at master; the lane diff touches
  only runBin()'s env plumbing (+EN_LAUNCH_FLOOR docblock, +one new test). This is
  the banked §7 rider (ledger cites :1890; rank drifted to :1958 by this file's adds).
  The lane's new pin `testTheChildSpeaksEnglishEvenWhenTheRunnerSitsOnAForeignLocale`
  PASSES and is genuinely discriminating: it putenv's LC_ALL+LANG=de_DE.UTF-8,
  restores in finally (unset-form when previously absent), and derives the German
  headline POSITIONALLY from the two shipped files (no hardcoded literal).
  runBin null-path now passes `array_merge(getenv() ?: [], ['LC_ALL' => 'C.UTF-8'])`;
  LC_ALL outranks any inherited LANG per the launch ladder (LangLaunchLocaleTest-pinned
  spelling), so the en floor is sound.
- Full suite NOT run (brief law).

## Duty 4 — Parity census: PASS (every number derived by my own tools, not the builder's)
- en.php at base == en.php at master (`git diff 26462c3ae..master -- candy-code/lang/en.php`
  = 0 lines), so the worktree copy IS the master blob for census purposes.
- Key-init census (pattern `^ +'[a-z0-9_.-]+' =>` — the builder's own insert4.py
  definition): **3,675 in ALL 12 files**, matching the brief and the commit-body claim.
- Key-set identity: concatenated per-file key lists, sorted, counted — every key
  appears exactly **12×**, zero deviations (also proves no duplicate key init in any
  file: a collision would have produced a 13-count row).
- Key-ORDER identity: `grep -no` (line-number:token) over the 11 community files —
  every (line, key) pair appears exactly **11×**, zero deviations. All 11 files are
  4,807 lines; en is 4,839 with the delta entirely its longer header docblock;
  uniform sizes + identical key multiset ⇒ en↔locale rank holds too, and
  LangParityTest's order-sensitive arm is green (belt AND braces).
- Placeholder sets: per-file multiset of `{token}` extracted from key-init lines —
  every (count, token) histogram row appears exactly **12×**, zero deviations.
  (Nowdoc bodies carry no braces beyond their verbatim-identical spans — r1
  byte-verified the six heredoc line-spans; LangParityTest's value-based arm covers
  structure regardless.)
- The 4 C-WEB-5.f keys: en.php ranks 4323–4328 verified
  `loading(4323) → logs, logs_empty, logs_no_log, logs_refresh(4324-4327) →
  permissions(4328)` — alphabetical slot as the commit body claims; the 4 appear in
  ALL 11 community files at lines 4292–4295 identically (= same slot minus the
  uniform 32-line header offset).

## Duty 5 — Translation spot-check + guard quality: PASS (ruling-conformant)
- 4 logs keys in fr/de/ja/zh-cn/ru/ko dumped from disk (slot presence proven for
  all 11 by rank census): genuine target-language values; em dash and the
  `server.log` filename literal kept verbatim exactly as the commit body claims;
  no braces in these 4 keys (census confirms trivially satisfied).
- Broader sample (6 keys × all 11 locales = 66 values; incl.
  settings.category.permissions, chat.agents.no_mailboxes,
  host.permissions.mode_refused, tui.dirpicker.confirm, web.agents.none_yet):
  real translations, zero garbage. `{mode}` `{launch}` `{path}` `{server}`
  `{section}` all preserved; backtick spans, `--permission-mode`, `(y/n)`,
  `CandyCode` brand, `*<registration-url>*` markdown all carried byte-true;
  escaped-quote multi-line double-quoted values keep their exact line structure.
  zh-tw traditional vs zh-cn simplified genuinely divergent; pt vs pt-br genuinely
  divergent (e.g. "caixas de correio … em alternativa" vs "caixas de entrada … em
  vez disso") — real regional split, not copies.
- Placeholder fabrication is structurally impossible on value lines: the
  (count,token) histogram census is ×12-identical, and LangParityTest's value-wide
  `testPlaceholdersSurviveTranslation` (covers nowdoc bodies too) is green.
- Fallback ledger reconciliation: disclosed "154 items (0.3%)" vs 244 unique
  per-file rows / 256 total rows / 25 distinct keys in fallbacks/*.txt — the three
  logged fallback keys sampled from every shown locale (`cmd.mcp-auth.empty`,
  `.login-armed`, `.undiscovered`) are TRANSLATED in the shipped files, so the log
  records MT-loop attempts, not final-English residue. Judgment against the owner
  ruling (machine translation + machine review, NO native gate): conformant; the
  number drift is evidence bookkeeping, disclosed either way — NIT-level,
  out-of-scope.
- Guard file LangCommunityLocaleTest.php read in full: real fail-closed design —
  scandir census asserts lang/ == {en}+the 11 codes; per-locale samples must be
  non-English AND non-raw-key through Lang::inLocale; WebStrings::negotiate/for
  arms (design's `forLocale` never existed; deviation disclosed, r1 concur);
  regional ladder arm correctly models `pt-br-xx→pt`, `zh-cn-xx→en` (only the
  regional Chinese files ship); POSIX-spelling arm lands `pt_BR.UTF-8`→pt-br etc.,
  matching the README example. GlobDialect-safe (suffix scan, no wildcard
  literals).
- Design risk clause ("tests asserting English without pinning locale go red on a
  foreign-LANG host") is met: the 11d0d647f runBin floor + its German-host pin is
  the shipped mitigation for the one inherit-env consumer audited; r1's audit
  found no other un-pinned English-literal assertion against the real lang/ dir.

## Duty 6 — Verdict inputs (recorded, NOT charged)
- r1 item 1 (GlobDialect PathGlob 310,245-vs-derived): lead ruling — §7 weld rider,
  lang/ outside HARVEST_DIRS, untouched file; not charged, unchanged at this tip.
- r1 item 2 (header `*/` at column 0, line 13, all 11 files): STILL PRESENT at the
  final tip (verified fr/de/ja line 13); lead ruling says cures at weld — not a fail.
- §7 riders still banked for the welder: durations.tsv row for
  tests/LangCommunityLocaleTest.php; suite-figure/README pair re-derive on the
  merged tree; --worktree dispatch rider :1958 (foreign, cure banked elsewhere).
- Housekeeping observations only: the frozen worktree carries untracked REPORT.md
  (builder's continuation report, 5,037 B at worktree root; the lane evidence dir
  holds a 5,032 B copy whose content is consistent with disk state and whose SHAs
  self-correct to bf3495315).

VERDICT: CLEAN — C:0 M:0 MINOR:0 NIT:0 charged to this lane (2 weld-cure riders +
1 §7 rider + 1 evidence-bookkeeping note recorded, all out-of-scope per lead
rulings). Commit law, file-set, all four gates, the full parity census, and the
translation spot-checks reproduced independently at the frozen tip bf3495315.
