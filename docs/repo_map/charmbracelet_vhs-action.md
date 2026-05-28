# charmbracelet/vhs-action

## Metadata
- **URL**: https://github.com/charmbracelet/vhs-action
- **Language**: TypeScript / GitHub Action (Node.js 20)
- **Stars**: Unknown (API unavailable; upstream `charmbracelet/vhs` ~4.3k+ stars)
- **License**: MIT
- **Description**: A GitHub Action that runs [VHS](https://github.com/charmbracelet/vhs) to render terminal recordings (`.tape` files) into GIF/MP4/WebM output directly in CI workflows, keeping demo GIFs automatically up to date.

## Feature List
- **Tape File Execution**: Parses `.tape` files containing terminal commands and renders them to video output
- **Multi-Format Output**: Generates GIF, MP4, and WebM from the same tape input
- **Cross-Platform Support**: Runs on Linux, macOS, and Windows (ubuntu-latest, macOS-latest, windows-latest)
- **Automatic Dependency Installation**: Installs `ttyd` (terminal server) and `ffmpeg` (video encoding) without manual setup
- **Font Management**: Bundles JetBrains Mono by default; optionally installs 10+ extra monospace fonts (Bitstream Vera, DejaVu, Fira Code, Hack, IBM Plex Mono, Inconsolata, Liberation, Roboto Mono, Source Code Pro, Ubuntu Mono)
- **GitHub Release Integration**: Downloads VHS binary directly from GitHub releases with caching
- **CI Environment Setup**: Unsets `CI` variable to enable ANSI colors; sets `COLORTERM=truecolor` for true color support
- **Artifact Generation**: Produces `vhs.gif`, `vhs.mp4`, `vhs.webm` as artifacts
- **Workflow Examples**: Auto-commit generated GIFs; upload to Imgur and comment on PRs

## Key Classes and Methods

### `src/main.ts` — Entry Point
- `run()` — Orchestrates the full flow: validates input file, installs fonts, installs dependencies, installs VHS binary, sets up PATH and environment variables, executes VHS with the tape file

### `src/installer.ts` — VHS Binary Installation
- `install(version: string): Promise<string>` — Downloads and caches the VHS binary from GitHub releases
  - Resolves `latest` tag via `octo.rest.repos.getLatestRelease()`
  - Maps OS/arch to download URL pattern (`vhs_${version}_${Platform}_${Arch}.tar.gz|.zip`)
  - Uses `@actions/tool-cache` for download, extraction, and caching
  - Returns path to `vhs.exe` on Windows, `vhs` on Unix

### `src/dependencies.ts` — Runtime Dependencies (ttyd + ffmpeg)
- `install(): Promise<void>` — Top-level installer for both ttyd and ffmpeg
- `installTtyd(version?: string): Promise<string>` — Installs `tsl0922/ttyd` terminal server
  - Linux/macOS: Downloads from GitHub releases; MacOS has special brew-based installation
  - Windows: Finds `win10.exe` or `win32.exe` asset
  - Caches via `@actions/tool-cache`
- `installLatestFfmpeg(): Promise<string>` — Installs ffmpeg
  - Linux: Uses `BtbN/FFmpeg-Builds` releases (ffmpeg-n5.1)
  - macOS: Uses `evermeet.cx` builds
  - Windows: Uses `BtbN/FFmpeg-Builds` releases
  - Caches via `@actions/tool-cache`
- `installFfmpeg(): Promise<void>` — Fallback using system package managers (apt-get, choco, brew)
- `installTtydBrewHead(): Promise<void>` — Builds ttyd from source on macOS (rarely used)

### `src/fonts.ts` — Font Installation
- `install(): Promise<void>` — Main font installation orchestrator
  - Creates font directories per OS (`~/.local/share/fonts`, `~/Library/Fonts`, `%LocalAppData%\Microsoft\Windows\Fonts`)
  - Installs Google Fonts (Source Code Pro, Inconsolata, Noto Sans Mono, Roboto Mono, Ubuntu Mono)
  - Installs GitHub-hosted fonts (JetBrains Mono, DejaVu, Fira Code, Hack)
  - Installs Nerd Fonts (JetBrainsMono, BitstreamVeraSansMono, DejaVuSansMono, FiraCode, Hack, IBMPlexMono, Inconsolata, InconsolataGo, LiberationMono, SourceCodePro, UbuntuMono)
  - Runs `fc-cache -f -v` on Linux to refresh font cache
- `installFonts(dir: string): Promise<void[]>` — Copies `.ttf` files to system font directories
- `installGithubFont(font: GithubFont): Promise<void[]>` — Downloads font zip from GitHub releases
- `installNerdFont(font: NerdFont): Promise<void[]>` — Downloads font zip from `ryanoasis/nerd-fonts` release
- `installGoogleFont(font: GoogleFont): Promise<void[]>` — Clones `google/fonts` repo and globs for matching `.ttf` files
- `liberation(): Promise<void[]>` — Installs Liberation Fonts from a direct download link

## Notable Algorithms / Named Patterns

### Platform/Architecture Mapping
```typescript
// Architecture mapping (src/installer.ts:L48-L63)
x64  → x86_64
x32  → i386
arm  → armv{n} or arm

// Platform mapping (src/installer.ts:L64-L78)
darwin → Darwin
win32  → Windows (ext: zip)
linux  → Linux  (ext: tar.gz)
```

### GitHub Release Asset Matching
Asset names follow the pattern `vhs_{version}_{Platform}_{Arch}.{ext}` and are matched by exact string comparison against release assets.

### Tape File Format
The `.tape` format uses line-based DSL:
```
Output <path>              — Set output format (gif/mp4/webm)
Set FontSize <n>           — Terminal font size
Set Width/Height <n>        — Terminal dimensions  
Set Theme <json>           — Color theme
Set Framerate <n>          — Playback framerate
Set PlaybackSpeed <float>  — Recording playback speed
Type "<text>"[@<delay>]    — Type with optional per-char delay
Sleep <duration>           — Sleep (500ms, 2s, etc.)
Enter/Tab/Backspace/Arrow keys — Key presses
Ctrl+<key>                — Control key combos
Hide/Show                  — Toggle command visibility in output
```

### Font Cache Strategy
- Uses `@actions/tool-cache` for all binary and font caching
- Cache keys: `vhs` (VHS binary), `ttyd`, `ffmpeg`, and per-font-repo names
- Fonts cached by repo name + `latest` tag

### Environment Variable Hacks
```typescript
// src/main.ts:L33-L37
core.exportVariable('CI', '')       // Unset CI to enable ANSI sequences
core.exportVariable('COLORTERM', 'truecolor')  // Enable true color
```

## Strengths
- **Zero-Config**: Users only need a `.tape` file and a workflow; all dependencies are handled automatically
- **Cross-Platform**: Works on Linux, macOS, and Windows with appropriate binary downloads
- **Intelligent Caching**: Uses `@actions/tool-cache` for VHS, ttyd, ffmpeg, and fonts — subsequent runs skip downloads
- **Modular Design**: Clean separation between font installation, dependency installation, VHS installation, and execution
- **Rich Font Support**: Bundles 11+ monospace fonts with Nerd Font variants via dedicated font installer
- **True Color Support**: Properly configures `COLORTERM=truecolor` for accurate color rendering in GitHub Actions
- **Artifact Production**: Generates GIF, MP4, and WebM simultaneously from one tape file
- **CI Integration**: Example workflows demonstrate auto-commit and PR comment patterns for keeping demos updated
- **Version Pinning**: Supports specific VHS versions or `latest` via GitHub releases API

## Weaknesses
- **Minimal Tests**: Only a single placeholder test (`__tests__/main.test.ts`);
- **Barely-There Test Suite**: `test('test runs', {})` does nothing; no assertion coverage
- **FIXME Comments**: Several noted issues in code (e.g., `dependencies.ts:L104` "fetch version", `fonts.ts:L329` "liberation-fonts don't upload their fonts to GitHub releases")
- **Hardcoded URLs**: Liberation fonts use a direct file link from a specific release (`liberation-fonts-ttf-2.1.5.tar.gz`)
- **Google Fonts Clone**: `installGoogleFont()` clones the entire `google/fonts` main branch (~1GB+) for a few font files
- **No Type Safety on API Responses**: `nerdFontsRelease` typed as `any`
- **Windows Font Installation**: Relies on a PowerShell COM object which may behave differently across Windows versions
- **Platform-Specific Fallbacks**: Some paths assume specific behaviors (e.g., macOS ttyd via brew --HEAD)
- **CI Variable Override**: Unsetting `CI` environment variable could affect other steps in complex workflows

## SugarCraft Mapping

The vhs-action is the **CI/CD automation layer** for the upstream `charmbracelet/vhs` CLI tool that SugarCraft ports (see `sugar-bits`, `candy-shine`, etc.). While vhs-action is not itself a TUI library, it is the **demo recording and integration-testing harness** that the Charm ecosystem uses.

| SugarCraft Lib | Relationship |
|---|---|
| `sugar-bits` | The `Text`/output rendering components in SugarCraft mirror what VHS renders to GIF — the TUI component tree output |
| `candy-shine` | Theme handling in SugarCraft (`Theme::ansi()`, `Theme::dracula()`) mirrors VHS's `Set Theme` command for color scheme application |
| `candy-core` | The `Model::update()`/`Model::view()` cycle mirrors VHS's command-interpretation loop |
| `sugar-prompt` | Input handling (`Type`, key events) maps to VHS's `Type`/`Enter`/`Tab` commands |
| `honey-bounce` | Animation/timing (`Sleep`, `PlaybackSpeed`) maps to VHS timing commands |
| `candy-sprinkles` | Layout/box model (`Set Width`/`Set Height`/`Set Padding`) maps to VHS layout commands |
| **CI/vhs-action** | Not a port — this is the *infrastructure* that renders the GIF demos of all above libs in CI; no direct SugarCraft equivalent exists yet; candidate for a `sugar-ci` or `sugar-vhs` helper lib |

## Analysis

`vhs-action` is a thin but well-engineered GitHub Action that bridges the gap between the standalone VHS CLI tool and GitHub's CI environment. The core insight is that VHS requires three runtime dependencies (the VHS binary itself, `ttyd` as a terminal server, and `ffmpeg` for video encoding) plus optional fonts, all of which are handled automatically by the action. This makes the developer experience for keeping demo GIFs updated essentially zero-config: add a workflow file, point at a `.tape` file, and get a regenerated GIF on every relevant commit.

The architecture is cleanly split into four concern areas: `fonts.ts` handles font installation from Google Fonts, GitHub releases, and Nerd Fonts with caching; `dependencies.ts` handles `ttyd` and `ffmpeg` installation with platform-specific logic; `installer.ts` handles the VHS binary itself; and `main.ts` orchestrates these plus sets up the environment (`CI=" "`, `COLORTERM=truecolor`) and executes VHS. The use of `@actions/tool-cache` throughout means all downloads are cached between runs, making subsequent executions fast.

The main limitation is the test coverage: the sole test file contains only an empty test case, which provides no regression protection. The `FIXME` comments and `any` type casts suggest the codebase was shipped with some known rough edges. More importantly, the architecture is fundamentally about *running* VHS rather than *being* VHS — it has no equivalent in SugarCraft because SugarCraft ports the TUI libraries themselves, not the CI infrastructure around them. A future SugarCraft `sugar-vhs` or `sugar-ci` helper could potentially wrap the VHS binary and provide PHP-native tape generation for PHP TUI demos, but no such lib currently exists in the monorepo.
