# Second-Stage Ecosystem Intelligence Report: charmbracelet/vhs

## Repository Overview
- **URL**: https://github.com/charmbracelet/vhs
- **Language**: Go
- **Stars**: ~19,791
- **License**: MIT
- **Description**: CLI home video recorder — Write terminal GIFs as code for integration testing and demoing CLI tools
- **Architecture**: Lexer/Parser/Evaluator pipeline + ttyd (terminal) + go-rod (browser automation) + FFmpeg (encoding)

## Existing SugarCraft Mapping

| VHS Concept | SugarCraft Lib | Notes |
|------------|----------------|-------|
| `.tape` format / DSL | `sugar-bits` | Both define scripting formats for TUI demos |
| Frame capture / recording | `candy-shell` | Could provide terminal capture primitives |
| Video encoding / FFmpeg | `sugar-charts` | Both produce output files, FFmpeg could be wrapped |
| Theme system (base16) | `sugar-bits` | 16-color theme mapping aligns with Charm color systems |
| Typing simulation | `sugar-prompt` | Input emulation concepts overlap |
| Window chrome (bars, borders) | `candy-shine` | Visual styling of terminal windows |
| SSH server | N/A | PHP has no native SSH server lib in SugarCraft |
| Recording mode | N/A | Would need PTY/terminal capture - platform-specific |

## Previously Identified Gaps

From `repo_map/charmbracelet_vhs.md`:
- **`sugar-vhs`**: PHP port of VHS concept - tape format parser, frame capture, video encoding
  - Heavy dependency on FFmpeg via `symfony/process` or similar
  - No direct equivalent to go-rod browser automation in PHP
  - Would need platform-specific TTY capture code
  - **Not a straightforward port** - architecture would differ significantly

---

## High-Signal Open Issues

### 1. `Wait+Screen` regex fails to match text after scrolling (Issue #659)
**Signal**: High (multiple duplicates, long-running since 2025-08)
**Problem**: `Wait+Screen` searches scrollback buffer instead of visible viewport
**Root Cause**: `VHS.Buffer()` doesn't account for `viewportY` offset
**Impact**: Users cannot wait for text that appears after terminal scrolls
**Affected PRs**: #658, #704 (fix: use viewportY offset in Buffer()), #708 (feat: AwaitPrompt)
**Direct Risk to SugarCraft**: Similar buffer reading issues in any terminal capture implementation

### 2. Text looks really blurry (Issue #625)
**Signal**: 20+ reactions, active discussion
**Problem**: GIF output has poor text quality due to:
- **Scaling filter** in FFmpeg causing downscaling artifacts
- **Dithering limitations** with GIF palette
- **Chromium font rendering** settings
**Proposed Fix**: Change padding from "scale terminal to fit" to "extend canvas with padding" - breaking change
**Discussion**: SVG implementation fork at `agentstation/vhs` for clearer output
**Direct Risk to SugarCraft**: Video encoding quality issues would affect `sugar-charts`

### 3. Branching/conditional flows in cassette files (Issue #675)
**Signal**: Active discussion, 10+ comments, feature request
**Problem**: Non-deterministic flows (commands hitting external services) cannot be captured
**Requested Syntax**:
```
If Wait@10s /regex/
    Type "Pattern found within 10 seconds!"
Else
    Type "Timeout - pattern not found!"
EndIf
```
**Community Workaround**: `pre-vhs` (Node.js preprocessor) implements branching/conditionals
**Direct Risk to SugarCraft**: Conditional execution is a key feature gap

### 4. `Output` command fails to parse absolute file paths (Issue #741)
**Signal**: Recent (2026-04-04)
**Problem**: Lexer treats `/` as regex delimiter, so `/tmp/demo.gif` tokenizes incorrectly
**Workaround**: Quote the path: `Output "/tmp/demo.gif"`
**Fix PR**: #742 (parsePathArgument helper)
**Direct Risk to SugarCraft**: Path parsing edge cases in DSL design

### 5. ttyd process leaked on early exit from Evaluate (Issue #738)
**Signal**: Recent (2026-03-31), well-documented
**Problem**: Deferred cleanup only calls `browser.Close()`, not `terminate()` - ttyd leaks on early returns
**Affected Paths**: `Page.Wait` fails, `SET` command fails, video dimensions too small, `Hide` block fails
**Direct Risk to SugarCraft**: Process cleanup is always a concern in long-running processes

---

## Important Closed Issues

### 1. `vhs record > cassette.tape` does not redirect to file (Issue #293)
**Status**: Closed (completed) via PR #294
**Problem**: Recording output went to console instead of file
**Fix**: Output to stdout
**Lesson**: File descriptor confusion is common in PTY recording

### 2. `Hide` is not working (Issue #130)
**Status**: Partially addressed (documentation improved)
**Problem**: `Hide` stops frame capture but typed content still appears on screen
**User Confusion**:语义不清晰 — `Hide` operates on capture, not terminal display
**Workarounds**:
- Use `clear` before `Show`
- Use `stty -echo` (imperfect)
**Lesson**: Command naming/semantics must match user expectations

### 3. SSH server security concern (Issue #63)
**Status**: Acknowledged, security hardened
**Problem**: Built-in SSH server with default public access was risky
**Resolution**: Added `VHS_AUTHORIZED_KEYS_PATH` option, binds to localhost by default
**Lesson**: Network listeners require secure defaults

### 4. `Wait for command to finish before continuing` (Issue #70)
**Status**: Partially addressed by #708 (AwaitPrompt)
**Signal**: 5+ duplicate issues (#151, #166, #537, etc.)
**Problem**: VHS doesn't know when shell commands complete
**Solution**: AwaitPrompt uses FinalTerm OSC 133 shell integration
**Long-running**: 4+ years to resolution
**Lesson**: Process lifecycle detection requires shell integration

---

## Recurring Pain Points

### 1. Timing/Synchronization Issues
- **PlaybackSpeed not blocking**: `Sleep` types command to shell but VHS doesn't wait for completion (#481)
- **Non-blocking behavior**: Long-running commands with `Sleep` don't actually block
- **Race conditions**: Last typed character sometimes dropped (#497) - timing-dependent, hard to reproduce
- **Frame dropping**: Inconsistent playback speed (#367), cursor blink timing issues

### 2. Platform Compatibility
- **Windows issues**: 
  - PTY support via `creack/pty` doesn't work on Windows (#250, #631)
  - ConPTY failure on Windows 11 (#721)
  - Canvas renderer fails in headless Chrome on Windows (WebGL unavailable)
- **Docker/Alpine**: musl libc vs GNU libc issues, libwebsockets problems (#350)
- **macOS**: Homebrew ttyd installation issues

### 3. Terminal/Shell State Leaking
- **PROMPT_COMMAND leaking**: Bash PROMPT_COMMAND output appears in recordings (#691, #692)
- **Shell configuration pollution**: `BASH_SILENCE_DEPRECATION_WARNING` appearing in output (#39)
- **Initial frames garbage**: Shell startup messages appear before commands

### 4. Parser/Lexer Edge Cases
- **Newline termination**: Invalid identifiers after `Type` accepted as strings (#700)
- **Absolute path parsing**: `/tmp/demo.gif` tokenizes as regex + string
- **Source command ordering**: `Set` after `Source` sometimes ignored (#367)

---

## Frequently Requested Features

### 1. Subtitle/Text Overlay (Discussion #727, PRs #716, #726, #719)
**Signal**: High (3 related PRs, active development)
**Purpose**: Text overlays on recordings for tutorials/demos
**Implementation**: Canvas-based overlay approach - separate `<canvas>` element drawn with subtitle text, composited in FFmpeg
**Syntax Proposed**:
```
Subtitle "Hello world"
# or
Subtitle "Step 1" @0-2s
```
**Zero-overhead**: No impact when not used
**Direct Opportunity for SugarCraft**: Text overlay/annotation system

### 2. Per-Section Playback Speed (Discussion #685, PR #688)
**Signal**: Active development
**Request**: Different speeds for different tape sections
**Challenge**: Speed-to-frame mapping, stitch frames at different speeds
**Related**: #687 (AVIF support - same underlying issue of frame timing)

### 3. WaitScreenSettled (Issue #682)
**Signal**: Developer has implementation ready
**Purpose**: Wait for screen to remain unchanged for duration
**Use Case**: LLM TUIs with random/nondeterministic output
**Syntax**:
```
Type "query"
WaitScreenSettled 1s
```
**Direct Opportunity for SugarCraft**: Stability detection for variable-output commands

### 4. Keystroke Overlay/Key Names (Issue #164)
**Signal**: 20+ reactions, long-running (2022-11)
**Purpose**: Show key names at bottom of screen during playback
**Stalled PR**: #496 (feat: Add keystroke overlay) - abandoned
**Alternative**: External project `steno` by same author
**Recent Activity**: #719 keystroke captions PR
**Direct Opportunity for SugarCraft**: Input visualization

### 5. Custom FFmpeg Options (Issue #656)
**Signal**: Active need, multiple use cases
**Request**: Override hardcoded FFmpeg flags
**Use Cases**:
- `-crf 17` for better quality
- `-tune animation` or `-tune stillimage` for terminal content
- `-movflags +faststart` for streaming
- `-c:v libx264rgb -pix_fmt rgb24` for better color
**GH Actions workaround**: `ffmpeg` getting stuck in encoding loop - workaround is `-frames:v` flag

### 6. WebP/AVIF Support (Issues #50, #687)
**Signal**: Issue #50 open since 2022, #687 recent
**GIF drawbacks**: 256 color limit, poor compression, large file sizes
**AVIF**: Same compression as AV1 video codec, all browsers support
**Signal**: Community fork at `agentstation/vhs` with SVG output

### 7. Library/Testing API (Issue #462)
**Signal**: Active request for Go library usage
**Request**:
```go
import "github.com/charmbracelet/vhs"

func TestIntegrate(t *testing.T) {
    diff, err := vhs.PlayAndCompare("app.tape", "expected.ascii")
}
```
**Workaround**: Execute CLI from test, compare files manually
**Direct Opportunity for SugarCraft**: SugarCraft could provide PHP testing primitives

---

## Important PRs

### 1. PR #708: feat: add AwaitPrompt command for shell prompt detection
**Status**: Merged 2026-02-20
**Signal**: Major feature, years in making (related to #70 from 2022)
**Implementation**:
- Uses FinalTerm OSC 133 shell integration sequences
- Shell emits invisible marker when rendering new prompt
- JavaScript hook on `term.write()` detects marker
- Waits for prompt marker rather than regex match
**Shells Supported**: bash, zsh, fish, PowerShell, pwsh, cmd.exe, nu
**Key Insight**: Works regardless of prompt format or command output

### 2. PR #727: Subtitle command for text overlays
**Status**: Under discussion (2026-03-22)
**Implementation**:
- Canvas-based overlay approach
- Separate `<canvas>` for subtitle text
- Captured as third PNG stream alongside text/cursor
- Composited in FFmpeg after terminal styling
**Zero overhead**: No impact when not used
**Syntax**: `Subtitle "text"` or `Subtitle "text" @0s-5s`

### 3. PR #737: fix: kill ttyd process on early exit
**Status**: Open (2026-03-31)
**Problem**: ttyd leaks on early exit paths
**Solution**: Ensure `terminate()` called on all exit paths

### 4. PR #742: fix: support absolute paths in Output/Screenshot
**Status**: Merged (2026-04-04)
**Problem**: `/tmp/demo.gif` tokenized as REGEX + STRING
**Solution**: `parsePathArgument` helper reconstructs absolute paths

### 5. PR #722: fix: add DOM renderer fallback for Windows
**Status**: Open (2026-03-13)
**Problems**:
- ttyd ConPTY failure on Windows 11
- Canvas renderer unavailable (WebGL not in headless Chrome)
**Solution**: Fall back to DOM renderer, SwiftShader browser flags

### 6. PR #695: fix: empty gif after ffmpeg error
**Status**: Merged (2025-12-30)
**Problem**: "Number of frames to loop is not set" error with large tapes
**Fix**: Loop filter argument handling

---

## Architectural Changes

### 1. Shell Integration Architecture (PR #708)
**Before**: Regex-based `Wait` commands prone to false positives
**After**: OSC 133 prompt detection, counter-based polling
**Pattern**: Embed invisible marker in shell prompt → detect in xterm.js stream → poll until marker count increases

### 2. Video Encoding Refactor
**Before**: Duplicate code in `MakeGIF`, `MakeWebM`, `MakeMP4`
**After**: `makeMedia()` helper with shared logic
**Improvement**: Reduced code duplication, easier maintenance

### 3. Scaling Filter Issue (Issue #625)
**Current**: Scale terminal to fit within padding
**Problem**: Causes quality degradation
**Proposed**: Extend canvas to add padding (breaking change)
**Challenge**: Changes `Width`/`Height` semantics

### 4. SSH Server Architecture
**Pattern**: Middleware chain with:
- PTY rejection
- Tape reading from stdin
- Temporary file creation
- `Evaluate()` execution
- Output streaming back to client

---

## Performance Discussions

### 1. GIF Encoding Performance
- **Large tape files**: Split into multiple files as workaround (#581)
- **FFmpeg encoding loops**: Can generate 100k frames instead of 1k
- **Workaround**: Explicit `-frames:v` flag
- **Frame rate tuning**: Lower framerate (20-25) helps with frame drops

### 2. Recording Performance
- **Cursor blink**: Distracting, causes visual artifacts at high framerates
- **Recording vs playback speed mismatch**: Real-time recording, playback speed post-processing
- **Canvas screenshot overhead**: go-rod `CanvasToImage()` can be slow

### 3. Memory Usage
- **Frame buffer**: 14,514+ frames stored in temp directory
- **GIF palette generation**: Memory-intensive for large frame counts
- **Temporary file cleanup**: Ensured via deferred removal

---

## Extensibility Discussions

### 1. Preprocessor Ecosystem
**pre-vhs** (Node.js): Macro engine for VHS tapes
**Features**:
- Extensible syntactic conventions
- Branching/conditionals (IfProbeMatched, IfProbeNotMatched)
- Advanced typing styles
- Shorthand for repetitive commands
**Lessons**: DSL can be extended via preprocessing layer

### 2. Plugin Architecture Limitations
- No formal plugin system
- Extension via:
  - `Source` command (tape inclusion)
  - External preprocessors
  - Fork and modify
- Workaround via pre-vhs, external scripts

### 3. Library API Gap
- VHS designed as CLI tool
- No Go library API for programmatic use
- Workaround: Execute as subprocess
**Direct Opportunity for SugarCraft**: Provide proper PHP API for library use

---

## API/UX Complaints

### 1. Command Semantics Confusion
- **`Hide`**: Hides frames from capture, not terminal content
- **`Sleep`**: Actual wait, but commands typed to shell execute independently
- **`PlaybackSpeed`**: Post-processing only, doesn't affect real-time recording

### 2. Error Messages
- **Silent failures**: Some errors just show "recording failed"
- **Missing context**: No indication of which tape line failed
- **Timeout errors**: Don't show last state before timeout

### 3. Documentation Gaps
- **Concurrency model**: Not clearly documented (#481)
- **Source command**: Timing issues with Set after Source
- **Hide/Show**: Unclear semantics

### 4. Output Path Semantics
- **Relative to cwd**: Not relative to tape file location
- **stdin input**: No path context for relative paths
- **vhs new**: Creates tape that doesn't work out-of-box (needs examples/ dir)

---

## Migration Problems

### 1. Shell Compatibility
- **Different shells**: Different PROMPT_COMMAND, PS1 formats
- **Shell integration**: OSC 133 support varies
- **Cmd.exe**: Uses ST terminator instead of BEL

### 2. Platform Path Handling
- **Windows paths**: Backslash vs forward slash
- **Absolute paths**: Lexer treats `/` as regex delimiter
- **Path separators**: Inconsistent across platforms

### 3. Version Compatibility
- **ttyd version**: Breaking changes between versions
- **FFmpeg options**: Some flags vary by version
- **Shell behavior**: Bash vs zsh vs fish differences

---

## Clever Fixes & Workarounds

### 1. Shell Integration for Prompt Detection
**Problem**: ttyd has no API for process state
**Solution**: Shell emits OSC 133 marker → flows through ttyd → xterm.js → JavaScript hook
**Pattern**: Use existing stream rather than adding new API

### 2. pre-vhs Preprocessor
**Problem**: VHS lacks branching, macros, advanced typing
**Solution**: Transform extended syntax to standard VHS
**Demonstrates**: DSL can be extended via preprocessing

### 3. Clear Before Show
```diff
Hide
Type 'setup command'
Type 'cd "$vhs_sandbox"'
+ Type 'clear'
Show
```
**Problem**: Hide shows typed content
**Solution**: Clear screen before Show

### 4. stty -echo Workaround
```
Hide
Type "stty -echo"
Type "source /usr/share/bash-completion/bash_completion"
Type "stty echo"
Show
```
**Problem**: Hide shows typed content
**Limitation**: Still shows `stty -echo` input

### 5. DOM Renderer Fallback (Windows)
**Problem**: Canvas (WebGL) not available in headless Chrome
**Solution**: Detect missing canvas layers → fall back to `.xterm-screen` element
**Flags**: SwiftShader/ANGLE browser flags as WebGL fallback

---

## Community Workarounds

### 1. External VHS Tools
- **pre-vhs**: Node.js preprocessor with conditionals
- **steno**: Separate keystroke overlay project
- **cassette_deck**: Alternative with PROMPT_COMMAND approach
- **agentstation/vhs**: SVG output fork

### 2. CI/Testing Patterns
- **vhs-action**: GitHub Action for CI integration
- **GitLab CI**: Execute via docker or direct binary
- **Golden file testing**: Compare .txt/.ascii outputs
- **Auto-commit**: Keep GIFs in sync with tape changes

### 3. Shell Configuration
- **PS1 manipulation**: Set before recording
- **BASH_SILENCE_DEPRECATION_WARNING**: Avoid bash 3.0 warning
- **PROMPT_COMMAND clearing**: Don't inherit user config

---

## Maintainer Guidance Patterns

### 1. Clear Semantics
- OSC 133 shell integration as standard approach
- Clear separation between capture control and terminal control
- Maintain backward compatibility where possible

### 2. Platform-Specific Handling
- Document known platform limitations
- Provide workarounds for common issues
- Accept platform-specific fixes via PRs

### 3. Performance vs Quality
- Default to compatibility over optimization
- Provide options for advanced users
- Test with real-world use cases

### 4. Feature Rejection Reasoning
- WebP rejected due to 4:2:0 chroma limitation
- AVIF preferred for same quality at better compression
- Breaking changes require careful consideration

---

## Rejected Ideas Worth Revisiting

### 1. PTY-based Recording Without ttyd
**Reason rejected**: Complexity too high, xterm.js provides needed features
**Worth revisiting**: For simpler use cases, PTY directly → FFmpeg might work

### 2. Plugin Architecture
**Reason rejected**: Complexity, maintenance burden
**Alternative**: Preprocessor pattern is lighter-weight
**Worth revisiting**: For a limited set of extension points

### 3. Built-in Audio Support
**Reason rejected**: Out of scope, adds significant complexity
**Alternative**: Users can post-process with FFmpeg
**Worth revisiting**: If community demand persists

### 4. Direct FFmpeg Customization
**Concern**: Too many options, complexity creep
**Alternative**: Preset files approach (`--ffpreset`)
**Worth revisiting**: For power users, limited option surface

---

## Problems Likely Relevant To SugarCraft

### 1. Terminal Capture Synchronization
**Problem**: Knowing when a command finishes
**VHS Solution**: OSC 133 shell integration
**SugarCraft Implication**: `sugar-prompt` or `candy-shell` needs command completion detection

### 2. Buffer Viewport Management
**Problem**: Reading visible content vs scrollback
**VHS Issue**: #659 (Wait+Screen reads wrong buffer area)
**SugarCraft Implication**: Terminal buffer abstraction must handle viewport vs history

### 3. Frame Timing Consistency
**Problem**: Playback speed vs real-time recording mismatch
**VHS Issue**: #481, #367
**SugarCraft Implication**: Frame timing abstraction must be clear

### 4. Parser Edge Cases
**Problem**: `/` as regex delimiter breaks absolute paths
**VHS Issue**: #741
**SugarCraft Implication**: `sugar-bits` parser must handle special characters in paths

### 5. Process Lifecycle Management
**Problem**: Cleanup on early exit, process leaks
**VHS Issue**: #738
**SugarCraft Implication**: `candy-shell` must handle subprocess cleanup properly

### 6. Shell State Isolation
**Problem**: User shell config leaking into recordings
**VHS Issues**: #39, #691, #692
**SugarCraft Implication**: Demo/shell capture must isolate from user environment

---

## Features SugarCraft Should Consider

### 1. Text Overlay/Subtitle System
**Rationale**: Active development in VHS, clear community demand
**Implementation**: Canvas-based overlay composited post-capture
**SugarCraft path**: `sugar-charts` or new `sugar-overlay` component

### 2. Shell Integration for Prompt Detection
**Rationale**: Years-long demand, finally resolved in VHS
**Implementation**: OSC 133 prompt markers
**SugarCraft path**: `candy-shell` integration

### 3. Stability Detection
**Rationale**: VHS "WaitScreenSettled" for LLM/nondeterministic output
**Use case**: AI application demos with variable output
**SugarCraft path**: `sugar-prompt` stability check

### 4. Library API for Testing
**Rationale**: VHS only has CLI, no Go library
**VHS Issue**: #462
**SugarCraft path**: `candy-shell` or `sugar-bits` as testable library

### 5. Per-Section Playback Control
**Rationale**: VHS PR #688 in progress
**Use case**: Speed up slow sections, slow down fast sections
**SugarCraft path**: Timing abstraction in frame capture

### 6. Conditional/Branching Execution
**Rationale**: Community demand, pre-vhs demonstrates viability
**Use case**: Demo recording of variable-output commands
**SugarCraft path**: `sugar-bits` branching DSL extension

---

## Architectural Lessons

### 1. Shell Integration Pattern
```
Shell prompt → OSC 133 marker → ttyd → xterm.js → JS hook → counter → AwaitPrompt
```
**Lesson**: Use existing data streams rather than creating new APIs

### 2. Capture vs Control Separation
```
Hide/Show: Controls frame capture
Type/Enter: Controls terminal
Sleep: Controls timing
```
**Lesson**: Clear separation of concerns prevents confusion

### 3. Parser Architecture
```
Lexer: Raw text → Tokens
Parser: Tokens → Commands
Evaluator: Commands → Actions
```
**Lesson**: Simple pipeline, easily extensible

### 4. Platform Abstraction
- ttyd provides cross-platform terminal emulation
- go-rod provides cross-platform browser automation
- FFmpeg provides cross-platform video encoding
**Lesson**: Compose platform-specific components, don't fight them

### 5. Graceful Degradation
- Canvas renderer → DOM renderer for Windows
- SwiftShader fallback for WebGL
**Lesson**: Detect capabilities, fall back gracefully

---

## Defensive Design Lessons

### 1. Process Cleanup
- **Always**: Ensure cleanup on ALL exit paths
- **Defer**: Use defer for cleanup operations
- **Timeout**: Set timeouts for graceful shutdown
- **VHS Lesson**: Early exit leaked ttyd process

### 2. Resource Bounds
- **Memory**: Don't accumulate unbounded frame buffers
- **Disk**: Clean up temp files immediately
- **Fds**: Close file descriptors promptly
- **VHS Lesson**: Temp file cleanup with deferred Remove

### 3. Error Context
- **Include location**: Show which tape line failed
- **Show state**: Display last visible content
- **Give hints**: Suggest common fixes
- **VHS Lesson**: "recording failed" is unactionable

### 4. Platform Assumptions
- **Never assume**: Paths, separators, commands available
- **Check deps**: Validate required tools at startup
- **Document limits**: Be clear about platform support
- **VHS Lesson**: Windows PTY, Alpine libc issues

### 5. Security Defaults
- **Localhost binding**: Don't expose services by default
- **No auth bypass**: Require authentication
- **Known hosts**: Track and verify hosts
- **VHS Lesson**: ttyd security fix (#226, #227)

---

## Ecosystem Trends

### 1. Terminal Recording for Documentation
- **Growing use**: CLI tool documentation
- **CI integration**: Automated GIF regeneration
- **Demo maintenance**: Keep demos in sync with code

### 2. LLM/TUI Compatibility
- **Nondeterministic output**: Need stability detection
- **Random content**: Conditional branching
- **Variable timing**: Prompt-based synchronization

### 3. Format Evolution
- **Beyond GIF**: MP4, WebM, AVIF support
- **Quality matters**: Blurry text is a top complaint
- **Size efficiency**: AVIF at GIF-like sizes

### 4. Preprocessor Ecosystem
- **Extend DSL**: Without core changes
- **Macros**: Reduce repetition
- **Conditionals**: Handle edge cases

### 5. Platform Diversity
- **Windows support**: Still problematic
- **Browser rendering**: WebGL/canvas issues
- **Shell variety**: Different integration requirements

---

## Strategic Opportunities

### 1. PHP-Native Terminal Recording
**Opportunity**: No Go/rod dependency, pure PHP possible
**Approach**: PTY capture → FFmpeg via symfony/process
**Audience**: PHP projects wanting TUI demos

### 2. Superior DSL Design
**Opportunity**: Fix VHS parsing edge cases
**Approach**: Clearer semantics, better error messages
**Audience**: Developers frustrated by VHS quirks

### 3. Testing Integration Library
**Opportunity**: VHS lacks Go library API
**Approach**: SugarCraft provides testable PHP primitives
**Audience**: Projects wanting to test TUI apps

### 4. Text Overlay System
**Opportunity**: VHS still developing this feature
**Approach**: Canvas-based, composited post-capture
**Audience**: Tutorial and documentation creators

### 5. Shell Integration
**Opportunity**: VHS just solved this after 4+ years
**Approach**: OSC 133 pattern, port to PHP
**Audience**: PHP projects needing command completion detection

---

## Cross-Ecosystem Pattern Matches

### 1. asciinema
- **Similar**: Terminal recording
- **Different**: asciinema records, VHS scripts
- **Lesson**: Different abstractions for different use cases

### 2. terminalizer
- **Similar**: YAML-based terminal recording
- **Different**: More rigid, less extensible than VHS
- **Lesson**: DSL flexibility matters

### 3. bubbletea
- **Similar**: Charm ecosystem, TUI framework
- **Different**: Application framework vs recording tool
- **Lesson**: VHS records bubbletea apps, natural fit

### 4. ttyd
- **Similar**: Web-based terminal
- **Different**: Server only, no recording
- **Lesson**: VHS builds on ttyd for terminal emulation

---

## High ROI Recommendations

### For SugarCraft Development

1. **Implement Shell Integration (High Impact)**
   - Add OSC 133 prompt detection to `candy-shell`
   - Enables reliable command completion detection
   - Years of VHS development distilled

2. **Fix Parser Edge Cases (Medium Impact)**
   - Handle special characters in paths
   - Clear error messages with context
   - Document semantics clearly

3. **Add Text Overlay System (High Impact)**
   - Canvas-based subtitle/annotation
   - Enables tutorial and documentation use cases
   - Active development in VHS, can learn from their approach

4. **Provide Library API (High Impact)**
   - VHS lacks library API, only CLI
   - SugarCraft can provide testable PHP primitives
   - Enable golden-file testing patterns

5. **Document Timing Model (Medium Impact)**
   - VHS: Real-time recording, post-processing playback speed
   - SugarCraft should document similar semantics
   - Prevents user confusion about Sleep/PlayackSpeed behavior

6. **Platform Abstraction (Ongoing)**
   - Learn from VHS Windows/Alpine issues
   - Provide clear platform requirements
   - Document known limitations

7. **Consider Preprocessor Pattern (Medium Impact)**
   - pre-vhs shows viability of DSL extension
   - SugarCraft could support extension without core changes
   - Lower maintenance burden than plugins

---

*Report generated via GitHub API analysis, code inspection from HEAD commit, and issue/PR/discussion review.*

*Research conducted: May 2026*
