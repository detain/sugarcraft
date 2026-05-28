# charmbracelet/scoop-bucket

## Metadata
- **URL**: https://github.com/charmbracelet/scoop-bucket
- **Language**: JSON (bucket manifests) + PowerShell (Scoop internals)
- **Stars**: 29
- **License**: MIT (per-package), no top-level license file
- **Description**: A Scoop package bucket for Charmbracelet tools — enables Windows users to install Charm's CLI suite via the Windows package manager Scoop

## Feature List

- **Multi-architecture distribution**: Package manifests support `32bit`, `64bit`, and `arm64` architectures, downloading architecture-specific zip archives from GitHub Releases
- **SHA-256 hash verification**: Each manifest includes a `hash` field for integrity verification of downloaded binaries
- **Binary bin mapping**: Manifests specify the exact path inside the zip to the executable, enabling Scoop to shim correctly (e.g., `gum_0.17.0_Windows_x86_64/gum.exe`)
- **Dependency declaration**: Optional `depends` array declares external Scoop dependencies (e.g., `soft-serve.json` declares `git`)
- **Homepage and license metadata**: Every manifest carries `homepage`, `license`, `description`, and `version` fields
- **Scoop bucket registration**: Users add the bucket via `scoop bucket add charm https://github.com/charmbracelet/scoop-bucket.git` then install any package with `scoop install charm/<package>`

## Key Classes and Methods

This is not a traditional code library — it is a **package repository** (bucket) containing Scoop manifest files. There are no classes or methods.

**Manifest schema per file** (`<name>.json`):
```
version       string   — upstream release version tag
architecture  object   — keys: "32bit", "64bit", "arm64"
  └─ <arch>   object
       ├─ url  string — direct GitHub Releases zip URL
       ├─ bin  array  — relative path(s) to .exe inside zip
       └─ hash string — SHA-256 of the zip file
homepage      string   — project homepage URL
license       string   — SPDX license identifier
description   string   — one-line human description
depends?      array?   — optional Scoop dependencies
```

## Notable Algorithms / Named Patterns

- **Scoop Bucket pattern**: A Git repository containing JSON manifests that Scoop consumes as a package index. The bucket is added as a remote git remote and Scoop clones/fetches it at runtime.
- **Architecture-specific URL dispatch**: The manifest structure routes requests to architecture-specific assets based on the user's platform, avoiding a single universal binary.
- **Hash-gated trust model**: Binary authenticity is verified via SHA-256 before installation — download → hash-verify → extract → shim.
- **Version-locked releases**: Each manifest pins a specific upstream version; updating requires bumping the manifest and opening a PR to the bucket repo.

## Strengths

- **Zero-maintenance distribution**: Leverages GitHub Releases for hosting — no separate release infrastructure needed
- **Multi-arch native**: Clear path to support ARM64 Windows (e.g., `mods.json`, `crush.json`)
- **Standardized manifest format**: Scoop's JSON schema is well-documented and widely understood by Windows developers
- **Dependency graph**: Optional `depends` field allows expressing external package requirements
- **Fork-friendly**: Scoop bucket can be forked and hosted under any org name; Charm's bucket name `charm` maps cleanly to `sugarcraft` naming conventions
- **Version hygiene**: Each release is explicitly versioned, enabling reproducibility and rollback

## Weaknesses

- **Single point of update**: All 17 packages live in one repo; a broken CI or stale manifest affects every package simultaneously
- **No automated manifest updates**: Unlike `scoop-checkup` or specialized update bots (e.g., `scoop-advisor`), this bucket has no auto-update workflow — manifests drift from upstream releases over time
- **No checksum update automation**: Hash values must be manually computed and updated when upstream releases new versions
- **Limited metadata**: Manifests lack `suggest`, `notes`, `post_install` scripts common in more mature Scoop buckets
- **Windows-only scope**: The bucket serves exclusively Windows users via Scoop; Charm tools are cross-platform Go binaries but this bucket only surfaces them on Windows
- **No CI validation**: No GitHub Actions workflow validates manifest schema correctness or catches broken URLs

## SugarCraft Mapping

| Scoop Manifest | SugarCraft Library | Rationale |
|---|---|---|
| `charm-gum.json` | `sugar-bits` (TUI components) | Gum is a tool for "glamorous shell scripts" — builds interactive TUI prompts with spinners, confirmation, input fields, and selection menus. SugarCraft ports: `sugar-prompt` (input/selection patterns), `sugar-spinner` |
| `vhs.json` | `sugar-bits` + `candy-shine` | VHS records terminal sessions as GIFs — maps to `sugar-bits` view rendering + `candy-shine` (animation/frame composition) |
| `glow.json` | `sugar-bits` (markdown rendering) | Glow renders markdown in the terminal with ANSI styling — SugarCraft has no direct markdown renderer; `sugar-bits` component layer could host a Bubbletea-based markdown view |
| `mods.json` | **none** | AI on the command line — SugarCraft has no AI/LLM integration lib; this would be a new leaf lib if ported |
| `soft-serve.json` | **none** (server infrastructure) | Self-hostable Git server — out of scope for SugarCraft (TUI component library) |
| `wishlist.json` | **none** (SSH directory) | SSH directory management — out of SugarCraft's TUI scope |
| `freeze.json` | `candy-shine` (image generation) | Freeze generates images of code/terminal output — `candy-shine` could port the image composition layer |
| `pop.json` | **none** (email sending) | Email from terminal — out of scope for TUI component library |
| `crush.json` | **none** (AI assistant) | Terminal-based AI assistant — would need new `sugar-ai` leaf lib if ported |
| `melt.json` | **none** (key management) | Ed25519 SSH key backup/restore — out of scope for TUI components |
| `sequin.json` | `sugar-bits` (ANSI sequences) | Human-readable ANSI sequences — parsing/encoding ANSI escape codes; SugarCraft TUI libs handle ANSI but no dedicated library exists |
| `skate.json` | **none** (key-value store) | Personal key-value store — out of TUI scope; if it were a TUI around key-value data, `sugar-bits` could provide the view layer |
| `markscribe.json` | **none** (templating) | Markdown scribe with template engine + GitHub/RSS — templating is out of SugarCraft scope; could be a `sugar-template` leaf lib |
| `confettysh.json` | `honey-bounce` (animation) | Confetti animation over SSH — `honey-bounce` spring/physics animation could port the confetti effect |
| `crush.json` | **none** | AI coding assistant — would need `sugar-ai` |

**Summary**: SugarCraft has strong coverage for TUI-facing tools (`charm-gum`, `vhs`, `freeze`, `confettysh`) but **no mapping** for infrastructure tools (git server, email, SSH key management, AI assistants, key-value store).

## Analysis

`charmbracelet/scoop-bucket` is not a software library in the traditional sense — it is a **package distribution metadata repository** for the Windows Scoop package manager. It contains 17 JSON manifests, each describing how to download, verify, and install a Charmbracelet tool on Windows. The repository's primary value is enabling Windows users to consume Charm's cross-platform Go tooling through a familiar package management workflow, rather than manually downloading releases or compiling from source.

The manifest design reflects Scoop's philosophy: manifests are declarative, version-pinned, and trust-gated via SHA-256 hashes. Architecture-specific URLs allow a single manifest to serve 32-bit, 64-bit, and ARM64 Windows builds — a pattern that could inform SugarCraft's own multi-platform release distribution if it ever expands beyond PHP. Notably, `mods.json` and `crush.json` already support ARM64, reflecting Charm's early adoption of ARM64 Windows.

The bucket's main limitation is **maintenance burden**. With no automated update workflow, manifest versions and hashes drift from upstream releases. A more mature bucket would use a tool like `scoop-checkup`, `scoop-advisor`, or a custom GitHub Action to detect upstream releases and auto-propose PRs. This is a known operational complexity for all package manager buckets in the Charm ecosystem.

For SugarCraft, this bucket provides a clear **distribution use case**: if SugarCraft ever wanted to ship pre-built binaries for non-PHP platforms (e.g., a Go-based CLI wrapper), a Scoop bucket would be a natural Windows distribution channel. The manifest structure also models a clean separation between "package definition" (JSON) and "artifact hosting" (GitHub Releases), which SugarCraft could emulate for its own release workflow.
