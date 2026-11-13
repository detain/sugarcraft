---
paths:
  - '*/composer.json'
  - composer.json
---

# Composer conventions

- PHP `^8.3`, PHPUnit `^10.5`. `minimum-stability: dev`, `prefer-stable: true`.
- Sibling deps: `"sugarcraft/<dep>": "@dev"` (or `dev-master`) in `require` ONLY — **no** `repositories[]` path-repo block in a lib manifest. Each lib publishes standalone, and a committed `../<dep>` path repo is a hard fatal for anyone cloning the split repo (the relative path does not exist there). CI re-injects the closure before every install; the ROOT manifest keeps its own `repositories`.
- CI injects the FULL transitive closure itself (`php tools/check-path-repos.php --fix --strict-closure`); guard the committed tree the other way with `php tools/check-path-repos.php --no-lib-path-repos` — any lib-level path repo is a finding.
- Metadata (after `license`, before `require`): `keywords` (lowercase kebab, include `"sugarcraft"` + upstream Go name), `homepage: "https://github.com/sugarcraft/<slug>"`, single author `Joe Huss <detain@interserver.net>` role `Maintainer`, `support.{issues,source,docs}`.
- PSR-4 `"<NS>\\<Sub>\\": "src/"` + matching `Tests\` namespace. Quirk: `sugar-core` → `SugarCraft\\Core\\`.
- `composer validate --strict` flags every `"@dev"` — EXPECTED; drop `--strict`.
- New lib also touches: root `composer.json` (`require`+`repositories`), `<slug>/phpunit.xml` (marker for `scripts/affected-libs.php`), `.github/workflows/vhs.yml`, `README.md`, `MATCHUPS.md`, `docs/index.html`, `docs/lib/<slug>.html`, `media/icons/<slug>.png`, `codecov.yml`.
