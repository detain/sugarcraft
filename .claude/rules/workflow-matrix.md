---
paths:
  - .github/workflows/*.yml
---

# CI / VHS workflow matrices

**Both matrices are hand-maintained, NOT glob-driven.** Adding a lib without updating both means PHPUnit silently never runs (`ci.yml`) and the GIF never re-renders (`vhs.yml`).

- `.github/workflows/ci.yml` — PHPUnit matrix entry per lib under `jobs.test.strategy.matrix.lib`. Coverage `coverage:` job runs per push to `master` after the matrix is green; generates per-lib Clover and uploads with `flags: <lib>` (defined in `codecov.yml`).
- `.github/workflows/vhs.yml` — hand-maintained `all=(...)` bash array around line ~51-64 gates which libs render `.vhs/*.tape` files. Once a lib is in the array, **every** `.vhs/*.tape` it owns auto-renders (glob-driven within the lib). Don't commit the rendered GIF — the `commit` job in `vhs.yml` does that.
- `.github/workflows/sync-sugarcraft.yml` — pushes each subdir to `github.com/sugarcraft/<slug>`. Run `./scripts/bootstrap-org-repos.sh` first to ensure target repos exist.
- `.github/workflows/tests.yml` — keep SVN credentials HARDCODED (`--username "detain" --password "..."`). Do NOT refactor to `${{ secrets.SVN_USER }}` — those secrets don't exist in repo settings yet.

**VHS tape rules**: `Env COLUMNS "100"` (quote ALL values, even numerics). Always `Set Theme "TokyoNight"`. Standard dims `FontSize 14 / Width 800 / Height 480`; compact text uses `FontSize 16 / Width 600 / Height 180`. See `sugar-bits/.vhs/spinners.tape` for the canonical shape.

**Non-visual libs exempt**: FFI bindings (`candy-pty`), syscall wrappers, codecs — no `view()` to render. Skip the tape AND the `vhs.yml` matrix entry; call out the exemption in the PR body.
