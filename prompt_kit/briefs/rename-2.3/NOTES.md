# Step 2.3 — gen-docs redirect stubs — NOTES

Verdict: DONE. Commits (master, never pushed): A `6f1ef69a0` (gen-docs.php + GenDocsTest.php),
B `6b3a7c618` (committed regen: docs/lib/candy-zone.html, docs/lib/sugar-prompt.html), <!-- @rename-keep: intentional historical/moved-stub mention of the old slug -->
C = tracker commit updating plan_rename.md row 2.3 → ✅.

## What was implemented (plan Part "Step 2.3", all 4 numbered items)
1. `tools/gen-docs.php` after the per-lib loop: reads `$root/tools/renames.json`, and for every
   entry with `status` ≠ `pending` and non-null `old` writes `docs/lib/<old>.html` — meta-refresh
   stub, byte-shape exactly the plan template (title / canonical / robots noindex / http-equiv
   refresh / body sentence), single trailing newline. Merge/fold entries start at `new` and
   follow its chain.
2. `--check` verifies the stubs: missing → `drift: <old>.html redirect stub missing`,
   hand-edited → `... differs from generated output`; both exit 1.
3. Four new `GenDocsTest` cases: stub written + exact tokens + idempotency + check-green;
   chained rename lands on the FINAL slug; pending entry → no stub; merge-kind stub; both --check
   failure polarities name the stub; live `_data` page wins over a stub (warning, stub skipped);
   `--extract` skips stubs (no phantom `_data` minting).
4. `docs/sitemap.xml`: gen-docs never writes it (grep `sitemap` in the tool → 0 refs) and it
   contains 0 hits for candy-zone/sugar-prompt — requirement satisfied by non-action. <!-- @rename-keep: intentional historical/moved-stub mention of the old slug -->

## Key decision — chain semantics ("same logic as step 2.2")
Step 2.2's `prefixes_for` walks renames.json WITHOUT a status filter (git history: pending
entries are future facts). For URL redirects that reading is refuted by the plan's own
**Done when**: "stubs for candy-zone and sugar-prompt exist now, pointing at candy-mouse and <!-- @rename-keep: intentional historical/moved-stub mention of the old slug -->
candy-forms" — while `candy-mouse→sugar-mouse` and `candy-forms→sugar-forms` sit pending in the
map. So `resolve_final_slug()` follows **executed (status ≠ pending) hops only**: every stub
lands on a page that exists today, and when a later batch executes candy-mouse→sugar-mouse the
candy-zone stub automatically re-targets two hops ahead (test `renamesFixture()` pins exactly <!-- @rename-keep: intentional historical/moved-stub mention of the old slug -->
this with a pending tail). Cycle → `RuntimeException` (fail-loud; authored-data defect, no
silent depth cap).

## Deviations / brief corrections
- **The brief's premise "docs/index.html already contains HTML-comment redirect markers for
  candy-zone and sugar-prompt" is FALSE against the tree** — `grep -c '<!--' docs/index.html` = 0, <!-- @rename-keep: intentional historical/moved-stub mention of the old slug -->
  and steps 1.1/1.2 (plan lines 540, 588) prescribed retargeting the tile links themselves
  (verified in `git show 6eb05734f -- docs/index.html`: the candy-zone icon link became the <!-- @rename-keep: intentional historical/moved-stub mention of the old slug -->
  candy-mouse link; no markers were ever added). Plan step 2.3 says nothing about index markers.
  Therefore NO marker emission was added; verification #3 is satisfied the other allowed way:
  **index.html is byte-identical after regen** (`git show 6b3a7c618 --stat` = only the two stubs;
  `git status` clean after `php tools/gen-docs.php`).
- One extra guard beyond the plan text: `--extract` now skips stub pages (was a latent
  self-corruption: extracting the new stubs would mint `_data/<old>.json` and promote redirects
  to real pages). Documented in USAGE + run_extract docblock.

## Verification log (all on master @ 6b3a7c618)
- `php tools/gen-docs.php` → `generated 63 file(s) (libraries: 40, apps: 21)`;
  `php tools/gen-docs.php --check` → rc 0, `ok: all 61 pages + 2 redirect stubs + index.html counts match generated output`.
- `candy-core/vendor/bin/phpunit --no-configuration tools/tests/` </dev/null → **OK (422 tests, 1434 assertions)**.
  Baseline 418/1398; delta **+4T/+36A = exactly the 4 new GenDocsTest methods**. No docs-derived
  provider drift: DocsPhpVersionTest/DocsQuickstartTest glob `docs/_data/*`, where stubs add nothing.
- `php tools/check-path-repos.php --no-lib-path-repos` → exit 0.
- `php -l` clean on both changed files. `php tools/gen-docs.php --help` usage first line byte-pinned
  by test unchanged.
- Stubs verified against plan template text (canonical URL, refresh target, code spans) — see
  commit B diff.

## Reproduction
```sh
cd /home/sites/sugarcraft
php tools/gen-docs.php && php tools/gen-docs.php --check   # rc 0
candy-core/vendor/bin/phpunit --no-configuration tools/tests/ </dev/null   # 422/1434
php tools/check-path-repos.php --no-lib-path-repos          # rc 0
```
