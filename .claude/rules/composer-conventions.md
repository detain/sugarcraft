---
paths:
  - '*/composer.json'
  - composer.json
---

# Composer conventions

- PHP `^8.3`, PHPUnit `^10.5`. `minimum-stability: dev`, `prefer-stable: true`.
- Sibling deps: `"sugarcraft/<dep>": "@dev"` in `require` AND a path-repo entry in `repositories` (`{type: path, url: "../<dep>", options: {symlink: true}}`).
- Path-repos must cover the FULL transitive closure — copy from a working leaf like `sugar-charts/composer.json` and prune unused.
- Metadata block (after `license`, before `require`): `keywords` (lowercase kebab, always include `"sugarcraft"` + upstream Go name like `"bubbletea"`), `homepage: "https://github.com/sugarcraft/<slug>"`, single author `Joe Huss <detain@interserver.net>` role `Maintainer`, `support.{issues,source,docs}`.
- PSR-4: `"<NS>\\<Sub>\\": "src/"` and `"<NS>\\<Sub>\\Tests\\": "tests/"`. Quirk: `candy-core` → `SugarCraft\\Core\\` (runtime shares umbrella name).
- `composer validate --strict` flags every `"@dev"` — EXPECTED, drop `--strict` when scripting.
- Adding a new lib also requires: root `composer.json` (`require` + `repositories`), `<slug>/phpunit.xml` (marker file — `scripts/affected-libs.php` keys off it), `.github/workflows/vhs.yml` matrix, `README.md` table, `MATCHUPS.md` row, `docs/index.html` tile, `docs/lib/<slug>.html`, `media/icons/<slug>.png`, `codecov.yml` flag + component entries. `ci.yml` auto-picks-up the lib — only edit `scripts/affected-libs.php` if the lib needs the Windows or macOS runner pool.
