# Rename B4: candy-shell → candy-scriptui (Procedure R) <!-- @rename-keep -->

Campaign record for Phase-4 Batch-B entry 4. Lines marked `<!-- @rename-keep -->`
carry the pre-rename name on purpose (history).

## Entry (tools/renames.json line 11)
kind=rename, old `candy-shell`, <!-- @rename-keep --> new `candy-scriptui`, type=app, ns `Shell`→`ScriptUi`,
bins {`bin/candyshell`: `bin/candy-scriptui`} (glued compact bin spelling). Baseline 334T/694A/0F/0E/0S/0W. <!-- @rename-keep -->

## R0 recon
- Cross-lib `SugarCraft\Shell\` PHP consumers: ZERO code — only docblock cross-refs <!-- @rename-keep -->
  (candy-pty PosixProcess.php:17/:28 `@see \SugarCraft\Shell\Process\RealProcess`, Contract/Process.php, <!-- @rename-keep -->
  SECURITY.md:43, research docs). All rewritten by R1, comment-only → no candy-pty/candy-core suite re-run.
  No lib requires sugarcraft/candy-shell (root composer only) → no consumer serial/K=8. <!-- @rename-keep -->
- Outside-lib `candyshell` brand refs: candy-core/src/ProgramOptions.php:42,308 + candy-core/src/Renderer.php:35, <!-- @rename-keep -->
  candy-forms/src/ActionSpinner.php:20, candy-core/README.md, sugar-bits/examples/item-list.php,
  candy-sprinkles/examples/table.php (docblock prose), tools/tests/DocsQuickstartTest.php:538 (test INPUT).
  All R2/R4 rewrites; behavior-neutral; tools/tests floor covers the Quickstart test.
- Env: real prefix CANDYSHELL_ <!-- @rename-keep --> (44 occ; ENV_PREFIX const Application.php:34 —
  user-facing value-option fallback documented in README). CANDY_SHELL_ existed only in a research-doc
  hypothetical. R5 rewrote full CANDYSHELL_FOO <!-- @rename-keep --> tokens (30); bare const + `CANDYSHELL_*`
  prose are lookbehind/lookahead blinds → manual (see R4).
- Data paths: grep XDG/.config/.local/.cache over src+bin → EMPTY. No LegacyPaths (honest none).
- ansi/: no wordmark, no ansi.json key → nothing.
- docs/: research doc + _data pair + lib page + findings pair + repo_map pair + icons all moved by tool.

## R1 (tag-prep 6ca37bbe8 + dry-run)
7 CONTEXT.md historical lines + rename-baseline.md:55 tagged; baseline:24 PTY-rule line left live
(tool rewrites it to candy-scriptui correctly). Dry-run rc=0: 261 files / 11 paths, R1=299 R2=42 R3=565
R4=79 R5=30 R6=1. ns R1 non-zero ✓. Lang/*.php R3:1 = header comments only. My NOTES file itself was
listed as rewritable — re-authored post-R3 (this file).

## R2 remote rename
rename-remote.sh: GitHub rename SUCCEEDED — name/description/homepage verified via gh; ls-remote both
names → 9500e0aa (redirect works). DEVIATION: topics sync 422 (repo at 20-topic cap, keyword-add refused);
cosmetic only — nothing else skipped (verified record print was the sole casualty).

## R3 tool run
rc=0, 261 files / 12 paths (bin counted as 12th), replacement counts byte-identical to dry-run.
php -l clean across all touched PHP; bin lints as php (shebang `#!/usr/bin/env php`).

## R4 followups (manual, all verified in place)
- BashCompletion.php `_candyshell_main` → `_candy_scriptui_main` ×2 (R4 `_`-lookbehind blind;
  bash fn names cannot contain hyphens; no test pins the literal — appName-derived tests ride together).
- ENV_PREFIX const `'CANDYSHELL_'` → `'CANDY_SCRIPTUI_'` <!-- @rename-keep --> + all CANDYSHELL_* <!-- @rename-keep -->
  prose (Application.php docblock, README heading+example line, CALIBER_LEARNINGS, 2 test docblocks,
  repo_map docs, docs body/lib pages, leftover updates.md ledger line).
- `_candy-shell` file-path refs sed → `_candy-scriptui` (test docblock, findings_resume_plan ×2,
  candy-vcr.md, sugar-post repo_map ×2, update_sugar-post ×1 CANDYSHELL_* <!-- @rename-keep --> line).
- LegacyEnv shim in bin after autoloader: `['CANDYSHELL_' => 'CANDY_SCRIPTUI_']` <!-- @rename-keep --> with
  per-var deprecation notice (new name wins), B3 pattern, tagged lines. README sentence added (tagged,
  literal + tag same-line).
- composer description de-framed: "PHP port of charmbracelet/gum — ..." → "Interactive terminal widgets
  for shell scripts — composer-installable CLI of SugarCraft TUI primitives, modeled on charmbracelet/gum.
  13 subcommands: ..." (JSON re-validated; keywords untouched — "gum" upstream + "shell" still accurate).
- PROJECT_NAMES.md:135 rationale rewritten honest (candy + script UI, formerly-name clause tagged);
  :232 cheat-sheet "shell (CandyScriptui)" → "terminal scripting (CandyScriptui)".
- `.vhs/filter.tape` demo item list `CandyShell` → `CandyScriptui` (R2 trailing-`n` blind; live demo data). <!-- @rename-keep -->

## R5 wiring
Root composer require+repository rewritten by tool; `composer update sugarcraft/candy-scriptui --no-install` →
lock churn 13/13 lines = one relocated package block (+reference hash shift, +bin path). gen-docs: 69 files
generated, --check rc=0 (61 pages + 8 stubs at that point). gen-ansi-readme --check rc=0 (3595 pieces).
refresh-deps --mode=linked candy-scriptui OK; committed-tree path-repo guard rc=0; tracked per-lib locks 0.

## R6 verification
- residue --old=candy-shell → 0 findings (incl BIN glued rule); tree-wide manual grep for candyshell/ <!-- @rename-keep -->
  CandyShell/CANDYSHELL <!-- @rename-keep --> → only @rename-keep-tagged history + excluded ledgers.
- suite candy-scriptui: **334T/694A OK rc=0 — EXACT baseline** (watchdog, plain pipe, 1.1s).
- tools/tests: **426/1484 EXACT**; path-repos rc=0; child-lifetimes rc=0.
- generic-word safety: `git diff --cached -U0 | grep -i '^-.*shell' | grep scriptui` → ZERO corrupt hits;
  survivors verified: README "shell scripts" ×2, _data description "call from shell scripts",
  sugar-post "Shell wrapper patterns", composer keyword "shell", ProgramOptions stdin prose.
- diff: 283 files changed, 1069+/1057−.

## R7 commit
(pending — see below)

## R8 push + remote-done
(pending)

## R9 packagist
(pending)
