# Rename 4-B8: sugar-post → candy-mail <!-- @rename-keep -->

Started 2026-10-16. Base master == origin == 3b914bf97. Entry: tools/renames.json batch-B line 15,
kind=rename, type=app, ns Post→Mail, bins {"bin/pop":"bin/candy-mail"}, extra_literals same. <!-- @rename-keep -->

## Recon findings (pre-R1)

- Baseline suite (rename-baseline.md:78): 203T/361A/0F/0E/0S/0W. <!-- @rename-keep -->
- `SugarCraft\Mail` collision check: NONE repo-wide (php grep, word boundary) — clear. No *mail* dir, no candy-mail token anywhere.
- PHP consumers of `SugarCraft\Post\`: ZERO outside the lib. Root composer require + its own files only. <!-- @rename-keep -->
- sugar-crush hits are DOCBLOCK ancestry cites only (src/Cli/ArgvParser.php:12, Help.php:15,
  NonInteractive.php:192,1015 — `sugar-post/bin/pop` pattern provenance). No composer dep, no tests pin <!-- @rename-keep -->
  (`grep sugar-post sugar-crush/tests/` empty). Mechanical R7+R3 rewrite = correct (`candy-mail/bin/candy-mail`). No consumer suite owed; crush unaffected (docblock text only). <!-- @rename-keep -->
- tools/tests/DocsQuickstartTest.php:41 — narrative docblock mention, rewrites mechanically. No behavioural pin.
- ENV PREMISE CORRECTION: plan:950 claims `SUGARPOST*`/`SUGAR_POST_*` — FALSE at tree. Actual user env prefix
  is `POP_*` (bin/pop: POP_FROM, POP_SMTP_HOST/PORT/USERNAME/PASSWORD, POP_SIGNATURE; RESEND_API_KEY is <!-- @rename-keep -->
  third-party — KEEP). SUGARPOST only appears in ansi art descriptions (all-caps, invisible to rules,
  truthful until art pass). Plan INTENT (shim for credential env users WILL have set) applies to POP_*.
  R5 derived prefixes (SUGAR_POST_/SUGARPOST_) are a tool no-op here.
- POP_* → CANDY_MAIL_* rename + LegacyEnv::import(['POP_' => 'CANDY_MAIL_']) shim in bin after autoloader
  (B3/B4 house shape, deprecation notice per var, new name wins). Sites: bin body, help heredoc, README,
  lang/*.php cli.no_transport message ×17 locales, tests/LangTest.php:81 pin, examples/pipeline.php.
  POP_ vocab is residue-invisible (not R5-derived) → manual, gate cannot catch misses → audit table below.
- Generic `pop` hazards (per brief): charmbracelet/pop upstream citations in src docblocks + composer desc = <!-- @rename-keep -->
  KEEP (real upstream repo name; no rule matches the `/pop` shape). `populated` English word KEEP.
  CliTest argv0 fakes `'pop'` — flip to `'candy-mail'` for hygiene (argv0 never read; parseArgs starts at i=1).
  tmp-dir prefixes `/pop-test-` (AttachmentTest) `/pop-demo-` (examples/attachments) — brand-derived → flip to candy-mail-* (hygiene law, precedent B-notes temp-prefix rule).
  Bare `pop` command prose in bin docblock (`Usage: pop --from`, `pop < message.txt`) — flip to candy-mail. <!-- @rename-keep -->
- ansi/: dir ansi/sugar-post + ansi.json keys + ansi/README rows migrated by TOOL (B6 precedent 812d53243 — <!-- @rename-keep -->
  paths renamed, 976 json lines rewritten); art bytes still paint SUGARPOST wordmark → followups line.
  All-caps SUGARPOST in descriptions stays truthful (content-excluded) → leave.
- .vhs/: showcase.tape + showcase.gif exist; paths rewrite, tape content lib-relative OK; GIF shows old wordmark
  → followups (CI re-renders). vhs.yml all=() row + lib matrix line rewrite mechanically.
- Glued blindspot refs needing manual sed (R3 lookbehind `_` blind): `plan_sugar-post.md` cites in <!-- @rename-keep -->
  sugar-post/CALIBER_LEARNINGS.md ×4 + findings/plan.md:182 (file itself renames to plan_candy-mail.md — B7 precedent); <!-- @rename-keep -->
  `docs/plans/leftover/.../step-01-sugar-post-identity.md` + its references (`sugar-post-identity.md`) — VISIBLE <!-- @rename-keep -->
  (hyphen-terminated), tool rewrites file + refs consistently (research-doc precedent: candy-scriptui-research.md).
- Data paths: app grep for .config/.local/.cache derivation — none expected (send-only mailer, no store). Verify R4 #4.
- prompt_kit/CONTEXT.md + rename-baseline.md: whole-file sentinel-excluded (existing tags) — no per-line prep needed;
  still baseline row 78 tagged as-written. NOTES self-protected by header sentinel.
- Pre-existing --all-done residue: 20 findings, ALL in prior entries' NOTES (A3/B2/B3/B5) + followups:36 —
  closeout-owned, NOT B8 vocabulary; untouched, disclosed.
- vhs.yml/codecov.yml/media icon/root README row/docs _data pair/repo_map pair/findings pair/research doc/
  MATCHUPS/index.html/search-index: tool territory (B1–B7 precedent confirmed via candy-kv/candy-ansi-inspect state).

## Pop audit table (every non-vendor `pop` survivor, post-R4, filled at R6) <!-- @rename-keep -->

| site | text | disposition |
|------|------|-------------|
| (fill) | | |

## Steps

- [x] recon
- [ ] tag-prep + R1 dry-run eye-review
- [ ] R2 remote rename
- [ ] R3 tool run + php -l sweep
- [ ] R4 followups (POP_ env rename + shim, README notice, Lang domain check, composer de-frame, glued sweep, followups lines)
- [ ] R5 wiring + regen checks
- [ ] R6 gates (residue 0, suite exact, tools/tests, path-repos, pop audit table)
- [ ] R7 commit (status=renamed)
- [ ] R8 push + remote-done
- [ ] R9 packagist + published + tracker

## Log

(async per step)

## R2/R3 LANDED
- R2 rename-remote.sh rc=0 (twice, idempotent), no 422. Split sugarcraft/candy-mail @ 04d75faaa; sugar-post URL redirects (GitHub rename). <!-- @rename-keep -->
- R3 tool run rc=0: 103 files, 164 paths, R1=99 R2=42 R3=1090 R6=1 R7=26 — EXACT vs dry-run-2 plan after path-display normalization (diff of plan-vs-run content lines: only old->new path spelling; zero unexpected files. findings/candy-async.md R3:1 was in plan — live dependents line, correct). <!-- @rename-keep historical provenance -->
- php -l 48 changed existing PHP files: 0 bad. Root+lib composer.json JSON valid.

## R4 LANDED (manual set)
- bin/candy-mail: usage/Env docblock pop+POP_* → candy-mail/CANDY_MAIL_*; LegacyEnv shim POP_→CANDY_MAIL_ + per-var stderr notice (B4 house shape, tagged); body env reads renamed.
- lang/*.php ×16 + README env table + tests/LangTest.php + examples/pipeline.php: POP_*→CANDY_MAIL_* (survivor grep: only tagged shim/README-note lines).
- CliTest 13× argv0 pop→candy-mail (argv0 unread; parseArgs starts i=1 — safe).
- tmp prefixes: tests/AttachmentTest.php /pop-test-→/candy-mail-test-, examples/attachments.php /pop-demo-→/candy-mail-demo-.
- Lang.php:13 + LangTest.php:13/89 prose post→mail; LangTest:93 assert literal post.this.does.not.exist→mail.this... (R6 NAMESPACE flip BREAKS this pin — suite would have gone red; found by audit, real fix).
- Glued blindspot: plan_sugar-post.md→plan_candy-mail.md ×4 CALIBER_LEARNINGS + findings/plan.md + findings/findings_resume_plan.md (NEW vs plan: resume-plan row was a live pointer); repo_map update:482 sugarcraft_sugar-post.md cite→candy-mail.
- README: formerly+namespace blockquotes (tagged, B7 shape), line84 pop→candy-mail CLI example, legacy POP_* deprecation note (tagged), badge typo FIX sugarcore/→sugarcraft/ (PRE-EXISTING wrong vendor in packagist badge URL — in-scope honesty, disclosed).
- composer description de-framed (modeled on charmbracelet/pop Go tool; \\u2014 escape byte-form preserved — sed with literal em-dash silently no-ops, python assert-count apply).
- MATCHUPS docs/MATCHUPS.md:88/:201 already correct post-R3 (MOOT). PROJECT_NAMES:66 vocab row "Email | Post, Mail" = domain word-palette, not brand ref → KEEP. No user-data paths (src/bin HOME/config grep empty). Charmbracelet/pop docblock Mirrors-citations ×7 KEEP (upstream). ansi art + showcase.gif → followups lines appended. POP3 protocol vocab: none existed in-lib (this is an SMTP/Resend mailer, name was pun-only).

## R5 wiring — LANDED
- root composer require+repositories were auto-rewritten by R3 tool; `composer update sugarcraft/candy-mail --no-install` → lock churn +63/−63 (relocated package block), lock sugar-post refs now 0. <!-- @rename-keep -->
- INCIDENT (disclosed): lib composer.json was CLOBBERED — R3 rewrote it, but my R4 python de-frame edit wrote it back from a stale pre-R3 buffer (name/homepage/support/autoload-ns/bin reverted; description edit landed). Caught at composer update ("could not be found in any version"). Repaired in-file: sugarcraft/sugar-post→sugarcraft/candy-mail ×4, SugarCraft\\Post\\→SugarCraft\\Mail\\ ×2, "bin/pop"→"bin/candy-mail" ×1 (python assert-count, verified vs candy-kv committed shape). LESSON: after a bulk tool run, ALWAYS re-read any file a follow-up script will rewrite — never write from a stale buffer; and R3-log "wrote X" is not proof the worktree kept it. <!-- @rename-keep -->
- refresh-deps --mode=linked --libs=candy-mail: linked=1 5/5 symlinked escaping=0; committed-tree guard rc=0; lib vendor autoload maps SugarCraft\Mail only (dump-autoload re-verified).
- gen-docs.php: 73 files (40 libs + 21 apps); --check OK 61 pages + 12 stubs (13th stub mints after R7 flip). gen-ansi-readme --check OK 3595 pieces.

## R6 gates — LANDED
- Suite `cd candy-mail; phpunit` (watchdog): OK 203 tests / 361 assertions EXACT baseline shape, 0.115s, exit 0.
- tools/tests: OK 426/1484 EXACT (re-run after doc-tag pass — tags don't perturb any census).
- sugar-crush consumer guards --filter 'DocFigureProseDrift|EnvRosterDrift|ReadmeRosterDrift|HelpTest': OK 276/133731 (3 auto-rewritten crush docblock files pin nothing).
- Residue --old=sugar-post: first pass 48 findings → triaged → 0 findings rc=0 (gate re-run immediately before staging per B7 law). <!-- @rename-keep -->

### pop audit table (complete — 48 findings classified; [BIN] rule fires on `pop`/`$ pop`/`php pop` shapes only; POP3/popen vocabulary elsewhere provably untouched — zero such lines in diff outside own NOTES prose) <!-- @rename-keep -->
- FIX→`candy-mail` (5, forward-looking OUR-tool usage lines in update_sugarcraft_candy-mail.md): :83 :174 :429 :434 :572 (composition pipes, completion, --no-tty, docs-content, enable line). <!-- @rename-keep -->
- TAG upstream-Go-tool records (21 — `pop` is literally the upstream binary/func name, truth-preserving): docs/repo_map/sugarcraft_candy-mail.md ×9 (Go `pop`/pop.Send()/pop.SendEmail()/`pop < message.md`/pop man/pop completion comparisons), update_sugarcraft_candy-mail.md :238 (`pop man` gap cite), charmbracelet_pop.md ×2, charmbracelet_scoop-bucket.md :74 (pop.json bucket filename), charmbracelet_winget-pkgs.md :64, pr_charmbracelet_pop.md ×7 (upstream PR quote corpus). <!-- @rename-keep -->
- TAG stack-vocab false positives (14): sugar-crumbs src/NavStack.php:15 (docblock method list — tag-append is pure annotation, verified) + sugarcraft_sugar-crumbs.md ×2 + update_sugarcraft_sugar-crumbs.md ×2 + plan_sugar-crumbs.md ×3 (incl `php pop()` code-comment) + sugar-crumbs.md :47; candy-core README.md:84 + CALIBER_LEARNINGS.md:40 (ScreenStack pop()); docs/plans/candy-git-server-tui.md:149; leftover step-02-core-screen-stack.md:19; repo_map_step_11.md:41 (push/pop/peek). <!-- @rename-keep -->
- TAG git-vocab (1): docs/plans/crush_code_worklog.md:8423 (`git stash push`/`pop` historical incident record). <!-- @rename-keep -->
- TAG history/self (7): findings/candy-mail.md:118 (audit-era heading), docs/plans/candy-mail-identity.md :64 :70 (upstream↔ours mapping rows), own NOTES 4 lines (incl [R3] sugar-post line) — tagged AS WRITTEN. <!-- @rename-keep -->
- Corruption audit: added-lines in-word scan → only legitimate `plan_candy-mail.md`; every deleted `pop` line has a candy-mail twin (CliTest argv0 13/13, bin usage, tmp prefixes, require paths); MATCHUPS already correct post-R3 (MOOT); PROJECT_NAMES:66 "Email | Post, Mail" vocab palette = KEEP (domain palette, not brand); no data paths (this mailer reads env, writes temp only — temp prefixes renamed); ansi SUGARPOST wordmark + showcase.gif → rename-followups.md ×2 lines; charmbracelet/pop Mirrors-citations ×7 KEEP. <!-- @rename-keep -->
