---
paths:
  - '*/.vhs/*.tape'
  - .github/workflows/vhs.yml
---

# VHS tape recording

- `Set Theme "TokyoNight"` always; quote ALL values, even numerics (`Env COLUMNS "100"`).
- Standard dims `FontSize 14` / `Width 800` / `Height 480`; compact text `FontSize 16` / `Width 600` / `Height 180`. Canonical `sugar-bits/.vhs/spinners.tape`.
- Body: `Type "php examples/<demo>.php"` → `Enter` → `Sleep 2s` → keys → `Sleep 1s`.
- Output `<slug>/.vhs/<demo>.gif`; embed via `https://raw.githubusercontent.com/detain/sugarcraft/master/<slug>/.vhs/<demo>.gif`.
- DO NOT commit rendered GIFs — the `commit` job in `.github/workflows/vhs.yml` does it.
- The `all=(...)` bash array in `vhs.yml` is HAND-MAINTAINED — missing entry = GIF never renders. Non-visual libs (`candy-pty`, FFI bindings, codecs) EXEMPT — note exemption in PR body.
- If `composer.json` needs `ext-ssh2`/`ext-gd`/`ext-ffi`/`ext-pdo_sqlite`, add it to `extensions:` in `vhs.yml` (default: `mbstring, intl, pcntl, ssh2`).
