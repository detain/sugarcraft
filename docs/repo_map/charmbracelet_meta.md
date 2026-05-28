# charmbracelet/meta

## Metadata
- **URL:** https://github.com/charmbracelet/meta
- **Language:** YAML / GoReleaser Config / GitHub Actions Workflows
- **Stars:** Not directly applicable (infrastructure/meta repo)
- **License:** MIT (Copyright 2022-2023 Charmbracelet, Inc)
- **Description:** Centralized meta-configuration repository for the Charmbracelet ecosystem containing shared GoReleaser release configurations and reusable GitHub Actions workflows used across 50+ Go projects.

## Feature List

### GoReleaser Configurations
- **goreleaser.yaml** — Full-featured release config for standard CLI tools with multi-platform builds (Linux, macOS, Windows, FreeBSD, OpenBSD, NetBSD), arm64/amd64/386/arm support, Docker multi-arch image publishing (docker.io + ghcr.io), Homebrew taps, Scoop, AUR, Winget, NIX/NUR, Furies, and SBOM generation with cosign signing
- **goreleaser-lib.yaml** — Simplified config for Go libraries (skips builds, focuses on changelog and source publishing)
- **goreleaser-vhs.yaml** — Specialized config for VHS (includes shell completions, manpages, ffmpeg/ttyd dependencies for video tooling)
- **goreleaser-glow.yaml** — Specialized config for Glow (markdown reader with Snapcraft support)
- **goreleaser-soft-serve.yaml** — Specialized config for Soft Serve (self-hosted Git server)
- **goreleaser-semi.yaml** — Semi-interactive release config variant
- **goreleaser-mods.yaml** — Modifier variants for modular releases
- **goreleaser-enterprise.yaml** — Enterprise variant
- **goreleaser-announce.yaml** — Announcement-only config

### GitHub Actions Workflows (Reusable)
- **build.yml** — Multi-platform CI (Ubuntu/macOS/Windows), Go vulnerability scanning via govulncheck, go mod tidy validation, build + test execution, auto-merge for Dependabot PRs
- **lint.yml** — Cross-platform linting with golangci-lint across Ubuntu/macOS/Windows, supports custom lint configs and pre-run commands
- **goreleaser.yml** — Full release workflow with Nix/Nixfmt, Cosign signing, QEMU/docker buildx, multi-registry Docker login (ghcr.io + docker.io), SBOM generation via Syft, macOS code signing + notarization, GoReleaser Pro distribution
- **snapshot.yml** — Snapshot/nightly builds workflow for pull requests and test releases
- **nightly.yml** — Nightly release workflow with Docker support
- **pr-comment.yml** — Posts downloadable artifact links on PRs via nightly.link integration
- **coverage.yml** — Code coverage collection and Codecov upload with race detection
- **govulncheck.yml** — Vulnerability scanning via golang.org/x/vuln
- **ruleguard.yml** — Ruleguard static analysis
- **semgrep.yml** — Semgrep code analysis
- **dependabot-sync.yml** — Syncs Dependabot configs across all public repos
- **lint-sync.yml** — Syncs golangci.yml config across repos
- **goreleaser-deprecation-check.yml** — Checks for deprecated GoReleaser usage
- **soft-serve.yml** — Specialized build for Soft Serve
- **build-plan9.yml** — Plan 9 OS compatibility builds

### Dependabot Configuration
- **dependabot.yml** — Central Dependabot config managing go.mod, GitHub Actions, and Docker dependencies on weekly schedule with grouped updates
- **dependabot/dependabot-*.yml** — Specialized Dependabot configs per project (bubbletea, lipgloss, huh, vhs, soft-serve-action, colorprofile, wish)

### Other
- **golangci.yml** — Central golangci-lint configuration with strict linter set (bodyclose, exhaustive, goconst, godot, gosec, misspell, nakedret, nilerr, noctx, nolintlint, prealloc, revive, rowserrcheck, sqlclosecheck, tparallel, unconvert, unparam, whitespace, wrapcheck) with gofumpt + goimports formatters
- **templates/README.md** — Standard README template for Charm projects
- **footer.md** — Release footer with artifact verification instructions via cosign
- **scripts/run-dependabot-sync.sh** — Script to dispatch dependabot-sync workflow across all public repos
- **scripts/run-lint-sync.sh** — Script to sync lint config across all repos

## Key Classes and Methods

This is not a Go library — it is a meta-configuration repository. There are no classes or methods in the traditional sense. The "API" consists of:

- **GoReleaser Variables:** `main`, `binary_name`, `description`, `github_url`, `maintainer`, `homepage`, `brew_owner`, `docker_io_registry_owner`, `ghcr_io_registry_owner`, `aur_project_name`
- **GoReleaser Includes:** `from_url` with URL templates for including shared configs
- **Workflow Inputs:** Modular inputs like `go_version`, `working-directory`, `upload_artifact`, `golangci_version`, `timeout`, etc.
- **Secrets:** Docker credentials, GoReleaser Pro keys, macOS signing certificates, AUR keys, social media tokens (Twitter, Mastodon, Discord)

## Notable Algorithms / Named Patterns

### GoReleaser Variable Interpolation
Template variable system used across all release configs:
```yaml
variables:
  main: ""
  binary_name: ""
  description: ""
  maintainer: ""
  homepage: "https://charm.land/"
```

### Semantic Version Tag Sorting
```yaml
git:
  tag_sort: semver
```

### Conventional Changelog Grouping
Regexp-based changelog categorization for automated grouping:
```yaml
changelog:
  groups:
    - title: "Deps"
      regexp: "^.*\\(deps\\)*:+.*$"
      order: 300
    - title: "New!"
      regexp: "^.*feat[(\\w)]*:+.*$"
      order: 100
```

### Multi-Architecture Docker Builds
Build templates for arm64 + amd64 + armv7 with platform-specific labels.

### Cosign Sigstore Signing
Blob signing for artifact verification using Sigstore's cosign tool:
```yaml
signs:
  - cmd: cosign
    signature: "${artifact}.sigstore.json"
    args:
      - sign-blob
      - "--bundle=${signature}"
      - "${artifact}"
      - "--yes"
    artifacts: checksum
```

### Go Module Proxy
```yaml
gomod:
  proxy: true
```

## Strengths

- **DRY Infrastructure** — Single source of truth for release and CI configs across 50+ repositories; changes propagate via `from_url` includes
- **Comprehensive Multi-Platform Support** — Builds for Linux (multiple distros via APK/DEB/RPM), macOS (Homebrew + code signing + notarization), Windows (Scoop + Winget), FreeBSD, OpenBSD, NetBSD, and ARM variants
- **Security-First** — Cosign signing for all artifacts, govulncheck scanning, gosec linting, SBOM generation via Syft
- **Workflow Reusability** — All GitHub Actions use `workflow_call` trigger enabling drop-in reuse with `uses: charmbracelet/meta/.github/workflows/<workflow>.yml@main`
- **Consistent Release Footers** — All releases include artifact verification instructions via cosign
- **Nix Integration** — Uses nixfmt-rfc-style for consistent formatting and publishes to NUR
- **Nightly/Snapshot Workflows** — Supports both release candidates and ongoing nightly builds for testing
- **Dependabot Automation** — Centralized dependency update management with sync scripts across all public repos
- **macOS Code Signing + Notarization** — Full Apple developer certificate integration for trusted binaries
- **Multiple Distribution Channels** — Docker (docker.io + ghcr.io), Homebrew, AUR, Snapcraft, Winget, Furies, Nix

## Weaknesses

- **No Source Code** — This repo contains only configuration; it cannot be "used" as a library in the traditional sense
- **Complex Configuration** — GoReleaser configs are 200-350+ lines each with specialized variants for different project types
- **GitHub Actions Only** — No GitLab CI, Jenkins, or other CI/CD system support
- **Tightly Coupled to Charm Infrastructure** — GitHub org names (`charmbracelet`, `charmcli`), runner groups (`releasers`), and secrets are hardcoded
- **Secret Management** — Requires numerous secrets to be configured at the repo level (goreleaser_key, docker credentials, macOS certificates, social media tokens)
- **Limited Documentation** — README is minimal; understanding requires reading the actual YAML files and GoReleaser documentation
- **Version Pinning via SHA** — Actions pinned to specific SHAs rather than tags, requiring manual updates

## SugarCraft Mapping

This meta repository is infrastructure/configuration rather than a library, so direct class-to-class mapping is not applicable. However, there are conceptual parallels:

| charmbracelet/meta | SugarCraft Equivalent | Notes |
|---|---|---|
| GoReleaser + GitHub Actions CI/CD | PHPStan/Psalm + GitHub Actions workflows | Infrastructure-as-code for automated releases |
| golangci.yml linter config | .phpstan.neon / .phpcs.xml / phpunit.xml | Quality assurance configuration |
| goreleaser-*.yaml templates | composer.json project templates | Project scaffolding via include mechanism |
| Dependabot config syncing | dependabot/composer-update workflows | Automated dependency management |
| workflow_call reusable workflows | GitHub Actions reusable workflows | DRY CI/CD configuration |
| SBOM generation (Syft) | Security scanning / audit | Vulnerability detection |

**Key Insight:** The SugarCraft monorepo uses a similar pattern with its `scripts/`, `.github/workflows/`, and `phpunit.xml` configurations being shared across libraries. The SugarCraft `scaffold-library` skill effectively replicates the "include" pattern from GoReleaser by creating standardized project skeletons.

## Analysis

**charmbracelet/meta** is not a software library in the traditional sense — it is a **meta-infrastructure repository** serving as the centralized configuration hub for the entire Charmbracelet ecosystem (50+ Go projects including Bubble Tea, Glow, Lip Gloss, Soft Serve, etc.). It provides reusable GitHub Actions workflows and GoReleaser release configurations that enable consistent CI/CD, security, and distribution practices across all projects through a DRY (Don't Repeat Yourself) approach using `workflow_call` triggers and `from_url` includes.

The repository demonstrates enterprise-grade release engineering for open-source Go projects. It handles the complete release lifecycle: multi-platform compilation (6 OSes × 4 architectures), container image building with buildx, code signing and notarization for macOS, Docker/OCI registry publishing to multiple registries (docker.io, ghcr.io), package manager publication (Homebrew, AUR, Snapcraft, Winget, Nix/Furies), SBOM generation with Syft, artifact signing via Cosign/Sigstore, and changelog generation with semantic grouping. The golangci.yml configuration enables 20+ linters with strict settings, and the various lint-sync and dependabot-sync scripts maintain consistency across the entire org.

The primary value of this repo to SugarCraft is as a **reference architecture for monorepo CI/CD infrastructure**. SugarCraft could adopt similar patterns by creating a shared meta repository with reusable GitHub Actions workflows for `build`, `lint`, `release`, `snapshot`, and `coverage` that any consuming library could include via `workflow_call`. The GoReleaser template variable system is conceptually similar to how SugarCraft uses the `add-library-checklist` skill to scaffold new libraries with standardized configurations. The key lesson from charmbracelet/meta is that investing in shared infrastructure configuration pays dividends across an entire ecosystem of related projects.