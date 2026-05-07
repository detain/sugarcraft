---
paths:
  - '*/composer.json'
  - composer.json
---

# Composer conventions

- PHP `^8.1`, PHPUnit `^10.5`. `minimum-stability: dev`, `prefer-stable: true`.
- Sibling deps: `"sugarcraft/<dep>": "@dev"` in `require` AND a path-repo entry in `repositories` (`{type: path, url: "../<dep>", options: {symlink: true}}`).
- Path-repos must cover the FULL transitive closure — copy from a working leaf like `sugar-charts/composer.json` and prune unused.
- Metadata block (insert after `license`, before `require`): `keywords` (lowercase kebab, always include `"sugarcraft"` + upstream Go name like `"bubbletea"`), `homepage: "https://github.com/sugarcraft/<slug>"`, single author `Joe Huss <detain@interserver.net>` role `Maintainer`, `support.{issues,source,docs}`.
- PSR-4: `"<NS>\\<Sub>\\": "src/"` and `"<NS>\\<Sub>\\Tests\\": "tests/"`.
- `composer validate --strict` flags every `"@dev"` — EXPECTED, drop `--strict` when scripting.
- Adding a new lib also requires entries in root `composer.json` (`require` + `repositories`), `.github/workflows/ci.yml` matrix `lib:` list, `.github/workflows/vhs.yml` matrix, and `README.md` table.
