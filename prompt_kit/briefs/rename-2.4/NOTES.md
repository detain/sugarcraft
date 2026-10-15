# Step 2.4 — Remote helper scripts (authoring-only)

Date: 2026-10-15. Base master f2901cde2. NOTHING renamed on GitHub, NOTHING
registered on Packagist (authoring-only per step 2.4; real actions start at
2.5 / Phase 3). Never pushed (operator push authority).

## Deliverables

1. `scripts/rename-remote.sh <old> <new>` (new, executable)
   - `set -euo pipefail`, `unset GITHUB_TOKEN` at top (Part A rule 3), ORG=sugarcraft.
   - Rename skeleton exactly per plan: old must exist (exit 1) → if `new`
     already exists and is not the redirect target of `old`, exit 2 (refuse
     clobber) → `gh repo rename` → desc from
     `bash scripts/bootstrap-org-repos.sh --print-description "$new"` →
     `gh repo edit --description --homepage https://sugarcraft.github.io/lib/<new>.html` →
     final `gh repo view --json name,url,description`.
   - Idempotent: if `gh repo view $ORG/$old` already resolves to `$new`, the
     rename is skipped but metadata/description/homepage/topics are still
     re-synced (a partial earlier run converges on replay; no silent skip).
   - Topics: from `<old>/composer.json` keywords (fallback `<new>/`), filtered
     to GitHub-valid (lowercase `[a-z0-9-]`, ≤50 chars); fails loudly if zero
     valid. Stale old-slug topic removed in its OWN `--remove-topic` call,
     only when actually present (gh errors on absent removals).
   - `--archive <slug> <successor>`: clones `sugarcraft/<slug>` into a temp
     dir (`git -c credential.helper='!gh auth git-credential'`), rewrites
     README.md to the plan's verbatim move-notice, commits as
     Joe Huss <detain@interserver.net> (split-var env), pushes current branch,
     `gh repo archive --yes`. Re-run on an archived repo → exit 0 idempotent.
     `SC_ARCHIVE_CLONE_BASE` env overrides the clone base (test seam only).
2. `scripts/packagist.sh create|update|check <slug>` (new, executable)
    - create/update: require `PACKAGIST_TOKEN` + `PACKAGIST_USERNAME` env (Part A rule 7: supplied at
      runtime only — token = the line after `packagist.org:` in
      `grep -A1 packagist /home/sites/mystage/auth_login_credentials.txt`; username = the Packagist
      account owning it, same value the CI workflow passes via its secret);
      exit 2 with a stderr message when unset; token never echoed (no `set -x`,
      auth rides the query string, printed output masked via `mask_tokens()`);
      `update` POSTs `{"repository":{"url":"https://github.com/sugarcraft/<slug>"}}` to
      `/api/update-package?username=..&apiToken=..` (byte-matches sync-sugarcraft.yml);
      `create` POSTs the form `packages[sugarcraft/<slug>]=https://github.com/sugarcraft/<slug>`
      to `/api/packages/_update.json` (same query-param auth);
      prints HTTP code + masked body; non-2xx → loud stderr + exit 1.
      (Review round MAJOR-1 fix — the pre-fix shape used a Bearer header, a flat
      `{"repository":"<url>"}` body and `POST /api/create-package`, none of which
      Packagist accepts.)
   - check (no token): `curl https://repo.packagist.org/p2/sugarcraft/<slug>~dev.json`
     → prints `dev-master: present` + `source.reference: <sha>` (exit 0) or
     `ABSENT` with reason (exit 1). No abandon API — manual web UI, Phase 10.
3. `scripts/bootstrap-org-repos.sh` — added `--print-description <slug>`
   (offline: map hit → renames.json new→old map hit → `<slug>|<old>/composer.json`
   description fallback → exit 1). Fixed sugar-bits stale description
   ("Fourteen…" → "Seventeen pre-built TUI components — port of
   charmbracelet/bubbles on the SugarCraft stack.", matching its composer.json).
   Note: DESCRIPTIONS has only 29 pre-split-era keys, hence the composer.json
   fallback layer; R3 will rewrite keys eventually.

## Verification (evidence logs in this dir's test run; scripts re-checkable)

- `bash -n`: all three OK. `shellcheck -S warning`: clean on both new
  scripts; only the pre-existing baseline SC2034 (unused TOPICS) in
  bootstrap (verified present at HEAD~ shape via `git show HEAD:… | shellcheck`).
- Done-when (plan :31-32): `--print-description candy-core` prints a line ✔;
  `scripts/packagist.sh check candy-core` → `dev-master: present`,
  `source.reference: 210be8cd25cd472dc29c4fc8addc200fa8eccb8a` ✔ — matches
  `git ls-remote https://github.com/sugarcraft/candy-core refs/heads/master`.
- Mock gh (stub + registry dir, /tmp/opencode/rename-24): fresh rename rc0
  (rename+desc+homepage+topics), replay rc0 "re-syncing metadata", collision
  rc2, missing-old rc1, bad-slug rc1, stale-topic removal fires exactly one
  `--remove-topic <old>` when seeded. Archive flow vs local bare repo:
  README == plan move-notice byte-for-byte, commit author/committer
  Joe Huss <detain@interserver.net>, subject "candy-zone: moved to
  sugarcraft/sugar-mouse", repo marked archived, successor-missing rc1,
  re-run rc0.
- Packagist via curl stubs: request assembly proven (URL, JSON payload,
  masked Authorization header; fake token count in stdout = 0); 401 → exit 1;
  check 404 / 200-without-dev-master / 200-with-dev-master polarities exact.
  Real-network negative: `check candy-gifview` → ABSENT 404 rc1 (not yet
  created — step 2.5 pending).
- `candy-core/vendor/bin/phpunit --no-configuration tools/tests/`:
  OK (422 tests, 1434 assertions) — baseline exact, +0 delta (step 2.4
  prescribes no phpunit tests).

## Deviations / decisions

- Orchestrator brief header said `tools/rename-remote.sh` / `tools/packagist.sh`;
  the PLAN (authoritative) says `scripts/` — landed in `scripts/`.
- Plan step 2.4 line says "Push." — not done: standing campaign law (and this
  brief) says NEVER push; operator holds push authority.
- Idempotent replay still re-syncs metadata rather than exiting early with no
  action ("detect and skip" applied to the rename op itself only).
- HARNESS INCIDENT: the `edit` tool corrupted bootstrap-org-repos.sh (hyphens
  → " - " across the DESCRIPTIONS block) twice; recovered via `git checkout --`
  + python3 exact-string replace. All final diffs verified byte-clean
  (`grep -c ' - '` = 0).

## Review round (2026-10-15)

Review `/tmp/opencode/rename-rv-2.4/REVIEW.md`: APPROVE-WITH-FIXES 0C/1MAJOR/4MINOR.

- MAJOR-1 FIXED: `packagist.sh` create/update rewritten to the wire shape PROVEN
  by `.github/workflows/sync-sugarcraft.yml:248-251` — query-param auth
  (`?username=$PACKAGIST_USERNAME&apiToken=$PACKAGIST_TOKEN`), update body
  `{"repository":{"url":...}}` JSON to `/api/update-package`, create as the
  canonical form `packages[sugarcraft/<slug>]=<url>` to
  `/api/packages/_update.json`. `PACKAGIST_USERNAME` now required at runtime
  alongside the token (both exit 2 when unset). `check` untouched (works live).
- MINOR-2 FIXED: `rename-remote.sh` echoes the resolved chain target before
  renaming when `<old>` already 301s to a third name and `<new>` is free.
- MINOR-3 FIXED: `sync_topics` takes the old slug as a third parameter; the
  `OLD_SLUG` global is gone (pure refactor, behavior identical).
- MINOR-4 RATIFIED (one line, per orchestrator decision): replay re-syncing
  metadata is ACCEPTED behavior — strictly better than the plan's "exits 0
  without touching anything" literal, because it converges partial earlier runs.
  Script header comment updated to state the ratified behavior.
- MINOR-5 FIXED: response body still printed, but every echo now passes through
  `mask_tokens()` (`sed -E 's/(apiToken=)[^&"]+/\1REDACTED/g;
  s/("apiToken":")[^"]+/\1REDACTED/g'`).
- plan_rename.md step 2.4 amended IN PLACE (originals kept struck-through,
  nothing renumbered); step 2.5 line notes the new `PACKAGIST_USERNAME` input;
  tracker row 2.4 carries "(review-fixed 2026-10-15: real Packagist API shape)".

Repro commands used to verify (evidence logs under `/tmp/opencode/rename-24-fix/`):

```bash
bash -n scripts/packagist.sh scripts/rename-remote.sh scripts/bootstrap-org-repos.sh
shellcheck -S warning scripts/packagist.sh scripts/rename-remote.sh   # clean
# mock curl: dump assembled request (masked) — proves CI-shape byte-match
CURL_LOG=/tmp/opencode/rename-24-fix/curl.log PATH=/tmp/opencode/rename-24-fix/mockbin:$PATH \
  PACKAGIST_TOKEN=fake-token PACKAGIST_USERNAME=fake-user bash scripts/packagist.sh update candy-core
sed -E 's/(apiToken=)[^&"]+/\1REDACTED/g' /tmp/opencode/rename-24-fix/curl.log
# creds polarity: env -u PACKAGIST_TOKEN ... rc2; env -u PACKAGIST_USERNAME ... rc2
# 401 polarity: CURL_CODE=401 ... rc1
# live read-only: bash scripts/packagist.sh check candy-core   # rc0, SHA 210be8cd25cd…
# rename-remote polarities under mock gh ($GH_REG registry, incl. MINOR-2 chain
# scenario + stale-topic removal firing) — see run-gh-mocks.sh in the same dir
candy-core/vendor/bin/phpunit --no-configuration tools/tests/ </dev/null   # 422T/1434A
```
