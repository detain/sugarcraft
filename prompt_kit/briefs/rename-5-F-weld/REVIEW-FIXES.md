# Batch-F review fixes (r-F-review-fix lane)

Base: 156688b84 (brief cited 156689b84 — transcription slip; disk==origin verified).

## FINDINGS-DISPOSITION

1. **FIXED** docs/_data/candy-code.body.html:37 — `sugarcrush serve|attach|acp` + `sugarcrush.v1` (4×) → `candy-code …` / `candy-code.v1` (verified ServerConfig::SUBPROTOCOL = 'candy-code.v1', bin = candy-code). Regenerated via tools/gen-docs.php; rendered docs/lib/candy-code.html:90 clean; --check rc0. (cdc4b14bf) <!-- @rename-keep quoted evidence -->
2. **FIXED** docs/index.html:810 — targeted one-line tile fix `sugarcrush serve` → `candy-code serve`; index not regenerated. (cdc4b14bf) <!-- @rename-keep quoted evidence -->
3. **FIXED** .opencode/agents/candy-code-*.md ×9 — `# Sugar-Crush …` headings → `# Candy-Code …`. Caliber hook: no-hook → ran `caliber refresh`; root refresh FAILS inside the Claude CLI on user-level permission-rule validation errors in /home/my/.claude/settings.json + .claude/settings.local.json (wildcard-before-rest allow rules, foreign to this repo) — zero managed outputs changed on disk, so only the 9 agent files committed; disclosed in commit message. (cc84c6282)
4. **FIXED** sugar-core/src/Util/LegacyEnv.php:25 — determinism example restored to intended `SUGARCRUSH_MODE`/`SUGAR_CRUSH_MODE` → `CANDY_CODE_MODE` wording (per git history the CANDY_CODE_MODE target predates the mangling), with `// @rename-keep` now on the old-token line (checker is line-scoped). Residue gate re-run rc0.
5. **FIXED** sugar-core/src/Util/LegacyPaths.php:13 — restored `~/.sugar-crush` -> `~/.candy-code`, `// @rename-keep` moved onto the old-token line. (4+5: f68e7f3c6)
6. **FIXED-TAGGED (judgment: historical narrative)** sugar_issues.md:425,436 — ISS-007 was a confirmed audit finding reproduced against the literal old cookie name; rewriting it would falsify the record (the suggested per-port fix was never implemented either — code is `candy_code_session`). Chose `<!-- @rename-keep historical provenance -->` tags, the established pattern in this same file (e.g. :1142, :1792). Verified CookieSessions.php:19 = `candy_code_session` — doc narrative vs code reconciled by tag, not by rewrite. (f68e7f3c6)
7. **FIXED (URL lines dropped)** candy-code/src/MCP/McpRouter.php:28 + src/Agents/PathJail.php:27 — `find` for crush_code_plan* under tracked tree: no file anywhere (docs/plans/ holds crush_code_RESUME/backlog/filemap, not the plan). Dropped the dead `@see` URL lines; surrounding prose kept intact. (f68e7f3c6)
8. **FIXED** scripts/__pycache__/generate_icons.cpython-313.pyc — was tracked; .gitignore had no __pycache__ entry → added. First "rename: untrack pycache" (3ee9a3ecc) used a pathspec commit which re-admitted the on-disk file (git commit -- <path> takes worktree state); cure is follow-up 8aa35ff04 with staged-index `git rm --cached` deletion. git ls-files scripts/__pycache__ now empty.
9. **FIXED (corrected to live name — judgment)** candy-code/.candy-code-build/remediation-progress.json:165-166 — file is TRACKED (git ls-files confirmed). 3× `BinSugarcrushWiringTest` → `BinCandyCodeWiringTest` (live class, tests/Integration/BinCandyCodeWiringTest.php:48 — grepped). Chose correction over @rename-keep: the sentences are present-tense pointers ("tests/… exercises this directly") in a machine-readable build artifact, and a JSON tag string would corrupt the payload. json.load validates. (f68e7f3c6)
10. **BONUS CURE (base-red found at gate)** sugar-core/tests/Util/LegacyEnvTest.php:151 — full-suite gate was RED at base 156688b84 (proven by stashing my edits): batch-F's own sed mangled the untagged expected literal `['SUGARCRUSH_ABE','SUGARCRUSH_ZET','SUGAR_CRUSH_MID']` → CANDY_CODE_* while the implementation (and docblock :36) return OLD names. Restored from cf8a3eb04 pre-mangling text + tag. Suite then exact 1401T/28944A/28S. (f68e7f3c6) <!-- @rename-keep quoted evidence -->

## Gates at final tip 8aa35ff04

- check-rename-residue.php --all-done: 52 entries, 0 findings (rc0)
- gen-docs.php --check: rc0 · gen-ansi-readme.php --check: rc0 · check-path-repos.php --no-lib-path-repos: rc0
- php -l: all 5 touched PHP files rc0
- sugar-core full: 1401T/28944A/28S exit0 EXACT (content unchanged in later pycache-only commits)
- candy-code targeted --filter 'DocFigure|Residue|RenamesJson': OK 91T/4962A (no RenamesJson/Residue class exists there; filter caught DocFigureProseDrift family)
