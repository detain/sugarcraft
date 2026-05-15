---
paths:
  - '*/.vhs/*.tape'
  - .github/workflows/vhs.yml
---

# VHS tape recording

- `Set Theme "TokyoNight"` always. Quote ALL values, even numerics: `Env COLUMNS "100"`.
- Standard dims: `FontSize 14` / `Width 800` / `Height 480`. Compact-text variant: `FontSize 16` / `Width 600` / `Height 180`. Canonical: `sugar-bits/.vhs/spinners.tape`.
- Body: `Type "php examples/<demo>.php"` → `Enter` → `Sleep 2s` → interactive keys → `Sleep 1s`.
- Output path: `<slug>/.vhs/<demo>.gif`. Embed via `https://raw.githubusercontent.com/detain/sugarcraft/master/<slug>/.vhs/<demo>.gif`.
- DO NOT commit rendered GIFs — the `commit` job in `.github/workflows/vhs.yml` does that.
- `.github/workflows/vhs.yml` `all=(...)` bash array (~line 51-64) is HAND-MAINTAINED. Adding a lib without listing it = GIF never renders.
- Non-visual primitive libs (`candy-pty`, FFI bindings, codecs) EXEMPT — skip tape AND `vhs.yml` entry; call out exemption in PR body.
- If your lib's `composer.json` declares `ext-ssh2` / `ext-gd` / `ext-ffi` / `ext-pdo_sqlite`, add to the `extensions:` list in `.github/workflows/vhs.yml` (default: `mbstring, intl, pcntl, ssh2`).
- Audit per-lib exts: `for f in */composer.json; do jq -r '.require // {} | to_entries[] | select(.key | startswith("ext-")) | .key' "$f"; done`.
