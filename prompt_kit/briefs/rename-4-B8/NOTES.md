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
- Generic `pop` hazards (per brief): charmbracelet/pop upstream citations in src docblocks + composer desc =
  KEEP (real upstream repo name; no rule matches the `/pop` shape). `populated` English word KEEP.
  CliTest argv0 fakes `'pop'` — flip to `'candy-mail'` for hygiene (argv0 never read; parseArgs starts at i=1).
  tmp-dir prefixes `/pop-test-` (AttachmentTest) `/pop-demo-` (examples/attachments) — brand-derived → flip to candy-mail-* (hygiene law, precedent B-notes temp-prefix rule).
  Bare `pop` command prose in bin docblock (`Usage: pop --from`, `pop < message.txt`) — flip to candy-mail.
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

## Pop audit table (every non-vendor `pop` survivor, post-R4, filled at R6)

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
