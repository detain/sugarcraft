---
paths:
  - '*/composer.json'
  - composer.json
---

# Composer conventions

- PHP `^8.3`, PHPUnit `^10.5`. `minimum-stability: dev`, `prefer-stable: true`.
- Sibling deps: `"sugarcraft/<dep>": "@dev"` (or `dev-master`) in `require` AND a path-repo `{type: path, url: "../<dep>", options: {symlink: true}}` in `repositories`.
- Path-repos must cover the FULL transitive closure — copy from `sugar-charts/composer.json`; verify with `php tools/check-path-repos.php`.
- Metadata (after `license`, before `require`): `keywords` (lowercase kebab, include `"sugarcraft"` + upstream Go name), `homepage: "https://github.com/sugarcraft/<slug>"`, single author `Joe Huss <detain@interserver.net>` role `Maintainer`, `support.{issues,source,docs}`.
- PSR-4 `"<NS>\\<Sub>\\": "src/"` + matching `Tests\` namespace. Quirk: `candy-core` → `SugarCraft\\Core\\`.
- `composer validate --strict` flags every `"@dev"` — EXPECTED; drop `--strict`.
- New lib also touches: root `composer.json` (`require`+`repositories`), `<slug>/phpunit.xml` (marker for `scripts/affected-libs.php`), `.github/workflows/vhs.yml`, `README.md`, `MATCHUPS.md`, `docs/index.html`, `docs/lib/<slug>.html`, `media/icons/<slug>.png`, `codecov.yml`.
