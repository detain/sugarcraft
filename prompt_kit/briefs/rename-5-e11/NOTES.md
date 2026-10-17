# rename-5-e11 NOTES — candy-wish → sugar-ssh-server <!-- @rename-keep -->

Base 6709f44bd, branch rename/e11. Target per renames.json:53 (kind lib, ns Wish→SshServer).
Derived B.4: pascal CandyWish→SugarSshServer; compact candywish 0 sites; env CANDY_WISH_* 0
sites → honest none, NO LegacyEnv (lib, no bin). R6 Lang domain wish→sshserver (tool). <!-- @rename-keep -->

## Flow (D1 template)
- Tag-prep bbf1bd520: 113 historical lines tagged across crush ledgers, PTY_PLAN,
  sugarcraft-is-a-mono-logical-twilight, sugarcraft-pty-status, session_handoff, plans_cleaning,
  repo_map step/update logs (+stage1.txt), monorepo-audit-2026-05-27, resume_prompt,
  plan_candy-pty r779, php74_branch, rename-baseline, crush-report/14, CONTEXT.md dual-mode
  learnings block (12 incl 2 invisible candy_wish underscore wikilines; :316 living-rewrite). <!-- @rename-keep -->
- R1 dry-run: 198 files, 11 paths, R1=489 R2=79 R3=387 R6=1. Tool run identical; all php -l OK.
- Manual: glued `_plan-candy-wish.md`-family prose refs (`_candy-wish` underscore lookbehind
  blocks content rules): findings_resume_plan ×3 + plan.md → plan_sugar-ssh-server.md;
  update_sugarcraft_* ×2 → sugarcraft_sugar-ssh-server.md. <!-- @rename-keep -->
- docs/repo_map/sugarcraft_candy-wish.md kept its NAME by file-level keepsItsName (a B9-era
  line tag :455 pins the path); manual `git mv` → sugarcraft_sugar-ssh-server.md. NEW BLINDSPOT:
  any target doc whose file already carries a tag for ANOTHER lane's token needs the same manual mv. <!-- @rename-keep -->
- README Formerly + namespace-moved notices inserted post-move (tagged). Status flip
  pending→renamed (own line only, python assert n==1); gen-docs minted docs/lib redirect stub
  (self-tagged, survives re-runs); gen-docs --check + gen-ansi --check rc0.
- Vendor: git mv carried stale untracked vendor into the new dir; refresh-deps --mode=linked
  sugar-ssh-server,candy-metrics at tip; PRUNED dangling */vendor/sugarcraft old-name symlink
  (D lesson reproduced, `escaping=1` tell). Root composer.lock untouched (C.3; weld owns lock work).

## Gates (committed tip b1695375c+NOTES)
- residue --old=candy-wish: 0. <!-- @rename-keep -->
- Own suite: 260T/976A/0S exit0 == own base re-measure at 6709f44bd (base worktree, linked)
  == baseline record. ext-ssh2 present; zero skips both sides.
- Consumers: sugarcraft/candy-wish composer-grep 0 remaining; <!-- @rename-keep --> ns-grep 0. candy-metrics (only
  code-side consumer) 174T/464A exit0 == its base re-measure == record. Prose-side only:
  candy-pty, candy-core, sugar-crush, candy-git-server, candy-starter, sugar-widgets example.
- tools/tests 426T/1484A OK. path-repos rc0; child-lifetimes rc0.

## Judgments
- Kept bare-word `wish` sites (upstream charmbracelet/wish name): examples/wish-with-metrics.php
  filename, leftover phase-06-core-shell-wish step paths, composer keywords, upstream repo_map
  docs. Tool and residue ignore bare words; renaming them is a separate product call. <!-- @rename-keep -->
- Rewrite-vs-tag: executed/dated records tagged; forward-looking living docs (leftover steps,
  plans/README, UPSTREAM_OPPORTUNITIES, AUDIT_UPSTREAM_PROMPT, repo_map sugarcraft_*/update_*
  docs, research, findings) rewritten — D1/D7 precedent. <!-- @rename-keep -->

## Weld notes
- Concurrent e2 (candy-metrics→sugar-metrics): I edited candy-wish-token lines in
  findings/plan_candy-metrics.md, findings/candy-metrics.md, docs/repo_map/sugarcraft_candy-metrics.md
  (+update_) — lines disjoint from metrics tokens but shared files; diff those at weld. <!-- @rename-keep -->
- ext-ssh2 needs no matrix change: installed globally in vhs.yml setup-php (plan has no R-step here).
