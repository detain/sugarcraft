# Rename B4: candy-shell → candy-scriptui (Procedure R)

<!-- @rename-keep historical ledger; old-name mentions below are intentional -->

## Entry (tools/renames.json line 11)
kind=rename, old `candy-shell`, new `candy-scriptui`, type=app, ns `Shell`→`ScriptUi`,
bins {`bin/candyshell`: `bin/candy-scriptui`} (glued compact bin spelling). Baseline 334T/694A/0F/0E/0S/0W (PTY family → watchdog).

## R0 recon (pre-run inventory)
- **Cross-lib `SugarCraft\Shell\` consumers: ZERO PHP** — only docs/research/libraries/candy-shell-research.md
  code examples (lines 363, 507; tool rewrites via R1). No lib `require`s sugarcraft/candy-shell; root
  composer require+repositories are the only external wiring (R5). candy-code/sugar-crush do NOT import it
  → no consumer serial/K=8 needed.
- Outside-lib `candyshell` brand refs: candy-core/src/ProgramOptions.php:42,308 (docblock prose only),
  tools/tests/DocsQuickstartTest.php:538 (test INPUT string). Both pure R4 rewrites, zero behavior in
  candy-core → no candy-core suite; tools/tests floor run covers DocsQuickstartTest.
- Env: real prefix is `CANDYSHELL_` (44 occ; ENV_PREFIX const Application.php:34, user-facing
  value-option fallback documented in README:165-183). `CANDY_SHELL_` exists only in a research-doc
  line (pr_charmbracelet_gum.md:802 CANDY_SHELL_DEBUG_CONFIG — hypothetical, gets R5-rewritten).
  R5 auto-rewrites full `CANDYSHELL_FOO` tokens; BLIND for bare `'CANDYSHELL_'` const and `CANDYSHELL_*`
  prose (lookahead needs [A-Z0-9]) → manual followups + LegacyEnv shim `['CANDYSHELL_' => 'CANDY_SCRIPTUI_']`
  in bin (B3 pattern, @rename-keep-tagged lines, deprecation notice per imported var).
- Glued `_candyshell_main` bash function (BashCompletion.php:71,83): R4 lookbehind blocks `_`-prefixed.
  Bash function names CANNOT contain hyphens → manual `_candy_scriptui_main`. Tests don't pin it
  (CompletionCommandTest:49/FishCompletionTest:31 pin fish `complete -c candyshell` = appName-derived,
  both sides ride R4 together; zsh uses `_{$appName}` dynamic).
- `_candy-shell` underscore-path refs (blind spot, R3 lookbehind): candy-shell/tests/
  ApplicationEnvTokenInjectionTest.php:46, docs/repo_map/sugarcraft_sugar-post.md:740,
  docs/repo_map/update_sugarcraft_sugar-post.md:60,490, findings/candy-vcr.md:53,
  findings/findings_resume_plan.md:218,1292 → sed to `_candy-scriptui` after tool (findings/
  plan_candy-shell.md + docs/repo_map/sugarcraft_candy-shell.md paths MOVE via pathContext rule).
- Data paths: grep XDG/.config/.local/.cache over src+bin → EMPTY. Honest none; no LegacyPaths.
- ansi/: no ansi.json key, no ansi/candy-shell dir → no wordmark followup needed.
- vhs.yml hand-lists lines 146 + 225 (R3-rewritten, verify in diff). SECURITY.md:10 example (R3).
  docs/MATCHUPS.md:82,177. root README:82. PROJECT_NAMES.md:135 rationale + 232 cheat-sheet example
  (both need HONEST manual rewrite — 'candy + shell = CLI' etymology goes stale).
- composer description: "PHP port of charmbracelet/gum — ..." → de-frame to historical 'modeled on'
  framing per B3 law; keywords keep "gum" (upstream) — "shell" keyword stays accurate (shell scripting).
- Lang.php NAMESPACE='shell' → 'scriptui' via R6 (lower(ns_new)); tool-scoped to the NAMESPACE line.
- Root composer.lock: R5 `composer update sugarcraft/candy-scriptui --no-install` relocates the one
  package block. candy-shell/composer.lock + coverage.xml on disk but UNTRACKED (gitignored) — untouched.
- oc.7z foreign untracked at root: preflight may insist → mv /tmp/oc.7z.b4-hold aside, restore at close.

## R1 dry-run
Landed tag-prep 6ca37bbe8 (7 CONTEXT lines + baseline row tagged; baseline:24 PTY-rule line left UNTAGGED so R3 keeps it live-accurate).
Dry-run rc=0: 261 files / 11 paths, R1=299 R2=42 R3=565 R4=79 R5=30 R6=1. ns_old Shell R1 non-zero ✓. Cross-lib R1 hits are docblock-only (candy-pty PosixProcess/Contract Process @see, SECURITY.md RealProcess line, Process.php) — no runtime coupling, no candy-pty suite needed.
lang/*.php R3:1 = header comments only ✓. Generic-word safety: no rule can match bare shell/$SHELL (all brand-token-bound) — spot-check planned at R6.
Blind spots CONFIRMED in counts: BashCompletion shows only R1:1 (_candyshell_main survives → manual), Application.php has NO R5 (bare CANDYSHELL_ const survives → manual), README R5:2 (full-name lines only; CANDYSHELL_* prose survives → manual), my NOTES file itself gets rewritten (will re-author post-R3 with tags).

## R2 remote rename
rename-remote.sh: rename SUCCEEDED — name/description/homepage verified via gh, both ls-remote resolve to 9500e0aa, old name redirects. DEVIATION: topics sync 422 (repo already at 20-topic cap; keyword-add step refused, cosmetic-only, skipped just the final print).

## R3 tool run
(pending)

## R4 followups
(pending)

## R5 wiring
(pending)

## R6 verification
(pending)

## R7 commit
(pending)

## R8 push + remote-done
(pending)

## R9 packagist
(pending)
