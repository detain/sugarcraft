---
paths:
  - .github/workflows/*.yml
---

# CI / VHS workflow matrices

**`vhs.yml` is hand-maintained.** Adding a lib without updating its `all=(...)` bash array means the GIF never re-renders. **`ci.yml` is dynamic** — see below.

- `.github/workflows/ci.yml` — every matrix (test / coverage / phpstan / windows-test / macos-test) is computed by `scripts/affected-libs.php`. The script auto-discovers libs from filesystem (any dir with `composer.json` + `phpunit.xml`), walks the reverse-dep graph built from each `composer.json`'s `require[sugarcraft/*]` entries (require-dev intentionally ignored), and emits per-job matrices scoped to changed libs ∪ transitive dependents. Adding a new lib usually requires **no** ci.yml change — just drop in `composer.json` + `phpunit.xml`. Force-all triggers: root `composer.json`/`composer.lock` change, `.github/workflows/ci.yml` change, `scripts/affected-libs.php` change, `workflow_dispatch`, or an unresolvable git range. **Hand-maintained inside the script**: `WINDOWS_LIBS` / `MACOS_LIBS` pools and `PHP_VERSIONS` policy — these are intentional opt-ins, not derived.
- `.github/workflows/vhs.yml` — hand-maintained `all=(...)` bash array around line ~51-64 gates which libs render `.vhs/*.tape` files. Once a lib is in the array, **every** `.vhs/*.tape` it owns auto-renders (glob-driven within the lib). Don't commit the rendered GIF — the `commit` job in `vhs.yml` does that. **No transitive fan-out** — a `candy-core` change doesn't re-render every downstream lib's GIFs, only the libs whose own `src/`, `examples/`, `bin/`, or `.vhs/` actually changed.
- `.github/workflows/sync-sugarcraft.yml` — pushes each subdir to `github.com/sugarcraft/<slug>`. Run `./scripts/bootstrap-org-repos.sh` first to ensure target repos exist.
- `.github/workflows/tests.yml` — keep SVN credentials HARDCODED (`--username "detain" --password "..."`). Do NOT refactor to `${{ secrets.SVN_USER }}` — those secrets don't exist in repo settings yet.

**VHS tape rules**: `Env COLUMNS "100"` (quote ALL values, even numerics). Always `Set Theme "TokyoNight"`. Standard dims `FontSize 14 / Width 800 / Height 480`; compact text uses `FontSize 16 / Width 600 / Height 180`. See `sugar-bits/.vhs/spinners.tape` for the canonical shape.

**Non-visual libs exempt**: FFI bindings (`candy-pty`), syscall wrappers, codecs — no `view()` to render. Skip the tape AND the `vhs.yml` matrix entry; call out the exemption in the PR body.
