---
paths:
  - '*/.vhs/*.tape'
  - .github/workflows/vhs.yml
---

# VHS tape recording

- `Set Theme "TokyoNight"` (the fleet default); quote string values (`Env COLUMNS "100"`); bare numeric sizing args (`Set Width 800`) are tolerated fleet-wide.
- Standard dims `FontSize 14` / `Width 800` / `Height 480`; compact text `FontSize 16` / `Width 600` / `Height 180`. Canonical `sugar-widgets/.vhs/spinners.tape`.
- Body: `Type "php examples/<demo>.php"` → `Enter` → `Sleep 2s` → keys → `Sleep 1s`.
- Output `<slug>/.vhs/<demo>.gif`; embed via `https://raw.githubusercontent.com/detain/sugarcraft/master/<slug>/.vhs/<demo>.gif`.
- Never hand-render or hand-commit GIFs — the `commit` job in `.github/workflows/vhs.yml` renders and commits them; those site-referenced GIFs are committed artifacts, and new demos stay render-on-CI.
- The `all=(...)` bash array in `vhs.yml` is HAND-MAINTAINED — missing entry = GIF never renders. Non-visual libs (`sugar-pty`, FFI bindings, codecs) EXEMPT — note exemption in PR body.
- If `composer.json` needs `ext-ssh2`/`ext-gd`/`ext-ffi`/`ext-pdo_sqlite`, add it to `extensions:` in `vhs.yml` (default: `mbstring, intl, pcntl, ssh2`).
