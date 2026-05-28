# charmbracelet/winget-pkgs

## Metadata
- **URL**: https://github.com/charmbracelet/winget-pkgs
- **Actual Repository**: microsoft/winget-pkgs (this is the Windows Package Manager community repository; the URL in your task references charmbracelet tooling manifests within this repo)
- **Language**: YAML manifest files + PowerShell tooling
- **Stars**: Unknown (requires GitHub auth; microsoft/winget-pkgs is one of the largest package repositories)
- **License**: MIT (per LICENSE file)
- **Description**: The Windows Package Manager community repository containing manifest files for publishing packages to WinGet. These manifests are YAML files that describe how to install and upgrade software on Windows via the `winget` command-line package manager.

## Feature List
- **Multi-file Manifest Structure**: Each package version requires three YAML files:
  - `version.yaml` - Package identifier, version, default locale
  - `installer.yaml` - Installer URL, SHA256 checksum, architecture, installation type
  - `locale.en-US.yaml` - Publisher, package name, license, short description
- **Directory Hierarchy**: `manifests/<first_letter>/<publisher>/<package>/<version>/` structure
- **Automated Validation**: Azure Pipelines CI/CD for manifest validation before publication
- **PowerShell Authoring Tools**: `YamlCreate.ps1` for creating manifests, `SandboxTest.ps1` for testing in Windows Sandbox
- **Schema Versioning**: JSON schemas for each manifest type (version, installer, defaultLocale) at multiple versions (1.0.0 through 1.12.0)
- **GoReleaser Integration**: Many packages (including charmbracelet tools) auto-generate manifests via GoReleaser
- **Windows Sandbox Testing**: Ability to test installations in Windows Sandbox before submission
- **Package Versioning Support**: Supports semantic versioning and date-based versions

## Notable Algorithms / Named Patterns
- **YAML Manifest Schema Validation**: JSON Schema-based validation for manifests (e.g., `https://aka.ms/winget-manifest.version.1.12.0.schema.json`)
- **Multi-file Manifest Pattern**: Separate files for version, installer, and locale metadata
- **Portable Command Alias**: For portable executables, specifies the command alias to add to PATH
- **Architecture-specific Installers**: Multiple installer entries per version for x86, x64, arm64 architectures
- **UpgradeBehavior**: `uninstallPrevious` pattern for clean upgrades
- **SHA256 Checksum Validation**: All installers validated via SHA256 hash

## Strengths
- **Community-driven**: Open contribution model for packaging Windows software
- **Automated Validation**: CI/CD pipelines validate manifests before merge
- **Clear Structure**: Well-documented YAML schema with JSON schema validation
- **Tooling Support**: Official PowerShell tools (YamlCreate, SandboxTest) and community tools (Komac, winget-create)
- **Version History**: Full version history maintained per package
- **Multi-architecture Support**: Native support for x86, x64, arm64 installers
- **Well-organized**: Alphabetical directory structure by publisher makes navigation easy

## Weaknesses
- **YAML-only Artifacts**: Not source code - these are package metadata only
- **Windows-centric**: Only relevant for Windows Package Manager users
- **Complex Submission Process**: One package version per PR, specific file naming conventions
- **Limited to Microsoft Formats**: Only MSIX, MSI, APPX, MSIXBundle, APPXBundle, .exe, and font files supported
- **Manual Maintenance**: Each new version requires a new PR submission
- **No Native Automation**: Auto-update requires external tools like GoReleaser
- **Validation Rigidity**: Strict schema validation can cause rejection of valid manifests

## SugarCraft Mapping

SugarCraft is a PHP monorepo porting the Charmbracelet TUI ecosystem to PHP. The winget-pkgs repository contains Windows binaries for charmbracelet tools. Below is the many-to-many mapping:

| Charmbracelet Tool | SugarCraft Library | Winget Package(s) | Description |
|-------------------|---------------------|-------------------|-------------|
| `gum` | `sugar-bits` | `charmbracelet.gum` | Interactive TUI spinner/checkbox/dialog |
| `vhs` | `sugar-charts` (VHS port) | `charmbracelet.vhs` | Terminal session recorder |
| `glow` | `candy-shine` | `charmbracelet.glow` | Markdown renderer |
| `pop` | (email-related) | `charmbracelet.pop` | Terminal email client |
| `wishlist` | `sugar-wishlist` | `charmbracelet.wishlist` | Wishlist manager |
| `mods` | (modifications) | `charmbracelet.mods` | File modification tool |
| `skate` | (skate key-value) | `charmbracelet.skate` | Key-value store |
| `soft-serve` | `candy-soft-serve` | `charmbracelet.soft-serve` | Git server TUI |
| `melt` | (YAML related) | `charmbracelet.melt` | YAML toolkit |
| `crush` | (shell) | `charmbracelet.crush` | Shell with TUI |
| `freeze` | (snapshot) | `charmbracelet.freeze` | Frozen terminal output |
| `markscribe` | (markdown) | `charmbracelet.markscribe` | Markdown writer |
| `sequin` | (sequence) | `charmbracelet.sequin` | String sequencer |

## Analysis

**Repository Nature**: This is not a traditional source code repository but rather a **package manifest repository** for the Windows Package Manager (winget). The `manifests/` directory contains over 13,000 YAML files organized by publisher and package version. The repository's primary function is to provide metadata that WinGet clients use to install and upgrade software on Windows systems. The actual software binaries are hosted on their respective GitHub release pages, not in this repository.

**Charmbracelet Packages**: The repository contains manifests for 13 charmbracelet tools, each with multiple versions. For example, `charmbracelet.gum` has versions from 0.10.0 to 0.17.0, and `charmbracelet.vhs` has versions from 0.7.1 to 0.11.0. Most manifests include a comment `# This file was generated by GoReleaser. DO NOT EDIT.`, indicating these are auto-generated from release metadata. Each package has three manifest files: a version manifest (version.yaml), an installer manifest (.installer.yaml), and a default locale manifest (.locale.en-US.yaml).

**SugarCraft Relationship**: SugarCraft ports Charmbracelet's Go-based TUI libraries to PHP. The winget-pkgs repository provides Windows distribution manifests for those same charmbracelet tools, enabling Windows users to install the original Go binaries via `winget install charmbracelet.<tool>`. This creates a complementary relationship: SugarCraft provides PHP ports for developers who prefer PHP or need PHP integration, while winget-pkgs provides Windows installation manifests for the original Go implementations.
