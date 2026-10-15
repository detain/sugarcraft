# Step 1.4 — normalize bins: candy-files, candy-tetris

Date: 2026-10-15. Base: master @ 545540f73 (clean). NEVER pushed (brief overrides plan's "Push." line — campaign law).

## Actions
- `git mv candy-files/bin/candyfiles` → `candy-files/bin/candy-files`
- `git mv candy-tetris/bin/tetris` → `candy-tetris/bin/candy-tetris`
- composer.json `bin` blocks updated in both libs.
- Bin-internal self-ID strings updated to full slug (house pattern of already-normalized candy-top / candy-vcr bins):
  - candy-files bin :14 `"candy-files: cannot find composer autoload.php"`, :32 `"candy-files: {$side} directory does not exist: …"`
  - candy-tetris bin :14 `"candy-tetris: cannot find …"`, :29 `"Usage: candy-tetris [options]"` (:28 `"candy-tetris - Tetris clone"` kept).
- candy-files `src/Lang.php`: `NAMESPACE 'candyfiles'` → `'files'` + docblock (plan-prescribed; T registry alias only — lang/en.php keys are relative, no `candyfiles.`-dotted lookups exist anywhere; verified by grep).
- READMEs updated (`./bin/candyfiles` 1 line; `./bin/tetris` 3 lines).
- .vhs tapes updated: candy-files 7 tapes (`./bin/candyfiles …`), candy-tetris 4 tapes (`bin/tetris` / `./bin/tetris …`). Verified candy-tetris DOES have .vhs (4 tapes) despite an early ls miss.
- tests updated: `candy-files/tests/LangCoverageTest.php:92` bin source path; `candy-tetris/tests/BinaryWiringTest.php` 6 refs (path + docblock + assertion messages).
- docs/_data bodies updated (2 files); `php tools/gen-docs.php` regenerated exactly the 2 generated pages; `--check` rc0.
- Root `composer.lock` refreshed via targeted `composer update sugarcraft/candy-files sugarcraft/candy-tetris --no-install` (precedent: prior steps kept lock current — zone/prompt entries removed, gifview added). Diff = 5/5 lines: 2 bin-path lines (this step) + 3 `dist.reference` content-hash re-reads (files/tetris from my edits; candy-gifview hash was stale from step 1.3's post-lock commit — catch-up, disclosed).

## Deliberate NON-changes (record-only)
- `src/Manager.php:519` trash prefix `candyfiles-trash-` + test globs (ManagerDeleteTest :39/:193) and `candyfiles-tab-` tmp prefix (ManagerTest :531): runtime temp-path namespaces, not bin references; out of 1.4 scope; behavior-unchanged.
- composer.json keyword `"candyfiles"` (Packagist search discoverability; residue checker does not police unchanged-name libs).
- findings/candy-files.md + findings/plan_candy-files.md: audit-history quotes of the unchanged trash path.
- No .github workflow references either bin (verified) — vhs.yml/ci.yml cite lib slugs only (unchanged). No shell completions in scope (plan names none for 1.4). sugar-crush has zero refs to old bins (verified) → no crush filter run needed. candy-gifview already ships `bin/candy-gifview`.

## Figures (watchdog + </dev/null, cwd=lib, linked vendors)
| gate | before | after |
|---|---|---|
| candy-files suite | 193T / 716A / 0F/0E/0S rc0 | 193T / 716A rc0 OK |
| candy-tetris suite | 214T / 4322A / 0F/0E/0S rc0 | 214T / 4322A rc0 OK |
| tools/tests | 418T / 1398A (baseline) | 418T / 1398A rc0 (no drift) |
| check-path-repos --no-lib-path-repos | — | rc0 (61 libs scanned) |
| gen-docs --check | — | rc0 (61 pages) |
| check-rename-residue --all-done | — | rc0 (2 entries, 0 findings) |

Functional: `php -l` both bins clean; `bin/candy-tetris --help` prints new usage from lib dir.

## Harness incident
First before-suite command malformed (`mkdir && cd && (watchdog) &` backgrounded the whole chain): logs failed, 6 orphan `sleep` watchdogs spawned — killed PIDs 1005633/1006787/1007645/1007649/1012092/1012147; some predated this session (same-pattern orphans). No tree impact.

## Conflict resolution
- Plan step 1.4 says "Push." → NOT done per orchestrator brief + standing owner law (NEVER push). 
- Plan grep `candyfiles` would flag trash/keyword/findings hits → resolved above as out-of-scope surfaces (recorded).
