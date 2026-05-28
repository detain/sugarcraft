# charmbracelet/glow

## Metadata
- **URL:** https://github.com/charmbracelet/glow
- **Language:** Go
- **Stars:** ~15k-20k (part of popular charmbracelet ecosystem)
- **License:** MIT
- **Description:** A terminal-based markdown reader designed from the ground up to bring beauty and power to the CLI. Glow renders markdown with pizzazz and discovers local markdown files in subdirectories or Git repositories.

## Feature List
- **Dual-Mode Interface:** TUI (Text User Interface) for interactive browsing, CLI for single-file rendering
- **Markdown Rendering:** Uses glamour for ANSI-aware markdown rendering with syntax highlighting
- **GitHub/GitLab Integration:** Fetches README files directly from GitHub and GitLab repositories via their APIs
- **File Discovery:** Recursively finds markdown files using gitcha, respecting .gitignore rules
- **Fuzzy Filtering:** Real-time filtering of markdown files using fuzzy string matching (sahilm/fuzzy)
- **Live File Watching:** fsnotify-based file watching to auto-reload changed documents
- **Clipboard Support:** OSC 52 terminal clipboard and native system clipboard integration
- **External Editor Integration:** Opens documents in $EDITOR at current cursor position
- **Style System:** Auto-detects terminal background (dark/light) and applies matching glamour styles; supports custom JSON stylesheets
- **Word Wrapping:** Configurable line width with smart word-wrap
- **Pager Support:** CLI output via preferred pager (defaults to `less -r`)
- **Line Numbers:** Optional line number display in TUI mode
- **Config File:** YAML-based configuration via Viper with environment variable overrides

## Key Classes and Methods

### main.go — Entry Point & Command Logic
- `main()` — Application entry point; initializes logging and executes cobra root command
- `execute()` — Routes to TUI or CLI mode based on arguments
- `executeCLI()` — Renders markdown to ANSI output via glamour, optionally through pager
- `executeArg()` — Processes individual source arguments (file, URL, stdin, directory)
- `sourceFromArg()` — Parses argument into a `source` struct (stdin, GitHub/GitLab URL, HTTP URL, file, directory)
- `validateOptions()` — Validates style, width, pager/tui conflicts
- `runTUI()` — Initializes and runs the Bubble Tea TUI program
- `tryLoadConfigFromDefaultPlaces()` — Discovers config in XDG_CONFIG_HOME, GLOW_CONFIG_HOME, or platform default

### ui/ui.go — Main TUI Model (Bubble Tea MVC)
- `NewProgram(cfg, content)` — Creates a Bubble Tea program with alt screen and optional mouse support
- `model` struct — Contains `commonModel`, `state` (stash/document), `stashModel`, `pagerModel`, file finder channel
- `newModel()` — Factory; initializes stash or document view based on path
- `Init()` — Bubble Tea Init; starts file search or loads initial document
- `Update(msg)` — Handles tea.KeyMsg, tea.WindowSizeMsg, fetchedMarkdownMsg, contentRenderedMsg, localFileSearchFinished
- `View()` — Delegates to stash or pager view
- `findLocalFiles()` — Command that invokes gitcha for file discovery
- `localFileToMarkdown()` — Converts gitcha.SearchResult to internal markdown representation

### ui/pager.go — Document Viewer Model
- `pagerModel` struct — Contains viewport (bubbles), currentDocument, statusMessage, watcher (fsnotify)
- `newPagerModel()` — Initializes viewport with high-performance rendering option
- `update()` — Handles navigation keys (g/G/home/end, d/u for half-page), clipboard copy (c), reload (r), editor open (e), help (?)
- `View()` — Renders viewport content + status bar footer + optional help
- `statusBarView()` — Renders logo, scroll percent, filename, help indicator
- `glamourRender()` — Core markdown rendering with glamour; handles code block margin, line numbers
- `watchFile()` / `unwatchFile()` — fsnotify file watching for live reload
- `showStatusMessage()` — Displays temporary success/error messages with timeout

### ui/stash.go — File Listing Model
- `stashModel` struct — Contains spinner, filterInput (textinput), viewState (ready/loading/error), markdowns slice, paginator, sections
- `newStashModel()` — Initializes spinner (Line style), filter input with "Find:" prompt
- `update()` — Routes to handleDocumentBrowsing or handleFiltering based on filterState
- `handleDocumentBrowsing()` — Navigation (j/k/↑/↓), pagination (h/l/←/→), enter to open, / to filter, e to edit, ? for help
- `handleFiltering()` — Real-time fuzzy filtering as user types; enter/tab to confirm, esc to cancel
- `addMarkdowns()` — Appends new markdown files and re-sorts
- `filterMarkdowns()` — Command that performs fuzzy.Find and returns filteredMarkdownMsg
- `view()` — Renders logo, header with document count, populated list, pagination, help

### ui/markdown.go — Data Model
- `markdown` struct — Fields: `localPath`, `filterValue`, `Body`, `Note`, `Modtime`
- `buildFilterValue()` — Normalizes filename for filtering (removes diacritics)
- `normalize()` — Unicode NFD decomposition, removes nonspacing marks (Mn), NFC recomposition
- `relativeTime()` — Humanizes timestamps (dustin/go-humanize with custom magnitudes)

### ui/stashitem.go — Individual File Row Rendering
- `stashItemView()` — Renders a single file listing row with gutter, icon, title, date; applies selection/filter styling
- `styleFilteredText()` — Highlights matched characters in filtered text using fuzzy.MatchedIndexes

### ui/stashhelp.go — Help System
- `helpView()` — Returns mini or full help based on showFullHelp state
- `miniHelpView()` — Single-line help with truncation
- `fullHelpView()` — Multi-column help with proper alignment
- `mergeColumns()` — Combines help columns side-by-side

### ui/styles.go — Lipgloss Color Palette
- Adaptive colors: `normalDim`, `gray`, `midGray`, `darkGray`, `brightGray`, `dimBrightGray`
- Accent colors: `yellowGreen`, `fuchsia`, `dimFuchsia`, `dullFuchsia`, `green`, `red`
- Styled render functions: `greenFg()`, `fuchsiaFg()`, `grayFg()`, `dimGreenFg()`, etc.

### ui/config.go — TUI Configuration
- `Config` struct — `ShowAllFiles`, `ShowLineNumbers`, `GlamourMaxWidth`, `GlamourStyle`, `EnableMouse`, `PreserveNewLines`, `Path`, `HighPerformancePager`, `GlamourEnabled`

### ui/editor.go — External Editor Integration
- `openEditor(path, lineno)` — Opens file at specific line in $EDITOR using charmbracelet/x/editor

### utils/utils.go — Utility Functions
- `RemoveFrontmatter()` — Strips YAML frontmatter (--- delimiters) from markdown
- `detectFrontmatter()` — Returns [start, end] indices of frontmatter block using regex
- `ExpandPath()` — Expands ~ and environment variables in paths
- `WrapCodeBlock()` — Wraps content in ```lang fence
- `IsMarkdownFile()` — Checks file extension against .md, .mdown, .mkdn, .mkd, .markdown
- `GlamourStyle()` — Returns glamour.TermRendererOption from style name (auto/dark/light/pink/notty/dracula/TokyoNight)

### github.go — GitHub README Fetcher
- `findGitHubREADME()` — Calls GitHub API for repo readme, follows download_url

### gitlab.go — GitLab README Fetcher
- `findGitLabREADME()` — Calls GitLab API v4 for project readme, converts blob URL to raw URL

### url.go — URL Routing
- `readmeURL()` — Parses URL, routes to GitHub or GitLab handler based on hostname
- `githubReadmeURL()` / `gitlabReadmeURL()` — Handles custom protocol prefixes (github://, gitlab://)
- `isURL()` — Checks if string is a parseable URL with scheme

### config_cmd.go — Config File Management
- `configCmd` cobra command — Opens config in $EDITOR via charmbracelet/x/editor
- `ensureConfigFile()` — Creates default config if missing

### log.go — Logging Setup
- `setupLog()` — Sets up file-based logging to cache/glow.log with debug level
- `getLogFilePath()` — Resolves cache directory via muesli/go-app-paths

## Notable Algorithms / Named Patterns

- **Bubble Tea MVC Pattern:** The UI is implemented as a strict Model-Update-View using the Bubble Tea framework (github.com/charmbracelet/bubbletea). The `model` struct holds application state, `Update()` handles all messages and returns new model + commands, and `View()` renders the UI from the model.

- **Concurrent File Discovery via Channels:** File finding is done asynchronously via `gitcha.FindFilesExcept()` which returns a channel of `gitcha.SearchResult`. The TUI receives results incrementally and populates the file list.

- **Fuzzy Filtering:** Uses `sahilm/fuzzy` package to filter markdown filenames in real-time as the user types. `fuzzy.Find(needles, targets)` returns ranked `Match` structs with `MatchedIndexes` for highlighting.

- **Lipgloss Adaptive Colors:** All colors use `lipgloss.AdaptiveColor` with separate Light/Dark values. The TUI auto-detects terminal background via `lipgloss.HasDarkBackground()` and applies dark or light styles accordingly.

- **Unicode Normalization for Filtering:** `normalize()` uses `transform.Chain` with `norm.NFD`, `runes.Remove(runes.In(unicode.Mn))`, and `norm.NFC` to strip diacritics so "café" matches "cafe".

- **fsnotify File Watching:** The pager model watches the directory of the current file for changes. On `fsnotify.Write` or `fsnotify.Create` events matching the file, it sends a `reloadMsg` to re-render.

- **Frontmatter Detection:** Uses `regexp.MustCompile(?m)^---\r?\n(\s*\r?\n)?` to detect YAML frontmatter boundaries and `RemoveFrontmatter()` slices the byte array to remove it before rendering.

- **High-Performance Pager Rendering:** When `config.HighPerformancePager` is true, uses `viewport.Sync()` after scroll operations to enable alternate screen buffer rendering optimizations.

## Strengths

- **Exceptional Code Quality:** Clean separation of concerns (ui/, utils/, main.go), well-documented code with clear intent comments, consistent naming conventions
- **Comprehensive TUI:** Full-featured terminal UI with file browsing, filtering, fuzzy search, inline help, status messages, and keyboard navigation familiar from vim/less
- **Multi-Source Markdown:** Handles local files, stdin, GitHub/GitLab URLs, HTTP URLs, and directory discovery
- **Platform Coverage:** Distributed via Homebrew, APT, RPM, Snap, Chocolatey, Scoop, Winget, and binary releases for macOS, Linux, Windows, FreeBSD, OpenBSD
- **Bubble Tea Ecosystem:** Uses proven charmbracelet libraries (bubbletea, lipgloss, glamour, log) ensuring consistent behavior and look
- **File Watching:** Automatic reload when markdown files change on disk using fsnotify
- **Clipboard Integration:** OSC 52 for terminal-native clipboard plus fallback to native clipboard
- **External Editor Integration:** Opens files in $EDITOR at current reading position for quick edits
- **Configurable Styling:** Glamour styles auto-detect terminal theme, support custom JSON stylesheets
- **MIT Licensed:** Permissive open source license

## Weaknesses

- **Go-Only Implementation:** Not directly portable to other languages without porting the entire Bubble Tea-based TUI architecture
- **No Windows TTY Raw Mode:** console_windows.go is a stub, Windows support may be limited for TUI features
- **Glamour Dependency:** Markdown rendering depends on glamour which has its own rendering quirks and style limitations
- **No Plugin/Extension System:** Extending functionality requires modifying core code
- **Single-User Focus:** No multi-user or server mode; designed purely for local CLI use
- **No Remote Stash Server:** Unlike some markdown tools, doesn't sync or share stashes remotely

## SugarCraft Mapping

| charmbracelet/glow | SugarCraft Library | Relationship |
|--------------------|---------------------|--------------|
| `glamour` markdown rendering | `sugar-bits` | Glamour is the upstream for SugarCraft's markdown rendering components. Glow's `utils.GlamourStyle()` maps directly to how SugarCraft would configure glamour. |
| `lipgloss` styling system | `candy-shine` (styling), `sugar-bits` (output) | Lipgloss is the Charm styling engine; SugarCraft ports lipgloss for ANSI text styling, colors, and layout. |
| `bubbletea` TUI framework | `sugar-bits` (TUI components) | Bubble Tea is the TUI framework; SugarCraft's TUI patterns (Model/Update/View) mirror Bubble Tea's architecture. |
| File browsing & discovery | `sugar-prompt` or `sugar-charts` | The stash/file-browser UI could inspire SugarCraft's file picker or tree-view components. |
| `viewport` for scrolling | `candy-core` (scroll buffer) | The Bubble Tea viewport model for paginated content. |
| Fuzzy filtering | `sugar-bits` (filter/search) | Real-time fuzzy string filtering of file lists. |
| fsnotify file watching | `candy-pty` (file watching) | File system notification for live reload. |
| `gitcha` for file discovery | `sugar-bits` (file discovery) | Git-aware file finding respecting .gitignore. |
| Clipboard (OSC 52) | `candy-core` (clipboard) | Terminal clipboard integration via ANSI escape sequences. |
| Configuration via Viper | `SugarCraft\Core` (config) | YAML config file handling with environment overrides. |

### Specific SugarCraft Equivalents

- **Markdown Rendering:** `sugar-bits` would provide `MarkdownRenderer` using glamour under the hood
- **TUI Components:** `sugar-bits` / `candy-shine` would provide `ListModel`, `PagerModel`, `SpinningModel`, `TextInputModel` following Bubble Tea patterns
- **File Browser:** `sugar-bits` could provide `FileStashModel` for directory browsing with fuzzy filter
- **Styling:** `candy-shine` provides `Lipgloss`-equivalent adaptive colors and styles
- **Viewport:** `candy-core` provides scrollable viewport with status bar

## Analysis

**charmbracelet/glow** is a showcase of the Charmbracelet ecosystem's approach to building beautiful CLI tools. At its core, glow is a markdown reader that operates in two distinct modes: a full-featured TUI for interactive file browsing and a simple CLI for rendering single documents. The TUI is built on Bubble Tea, the same reactive TUI framework used throughout the Charm ecosystem, making the code structure familiar to anyone who has worked with other Charm projects.

The architecture demonstrates several elegant patterns. The `model` struct in ui.go acts as a root coordinator, managing sub-models for the stash (file listing) and pager (document view). Communication between models happens through typed messages (`fetchedMarkdownMsg`, `contentRenderedMsg`, `filteredMarkdownMsg`), and the main `Update()` function dispatches to the appropriate sub-model based on application state. This hierarchical model composition allows complex UI behavior while keeping each piece manageable.

The markdown rendering pipeline is particularly well-designed. It strips YAML frontmatter, wraps non-markdown files in code fences for syntax highlighting, passes through glamour with auto-detected dark/light styling, and finally adds line numbers if enabled. The use of `lipgloss.AdaptiveColor` throughout ensures the UI looks correct on both light and dark terminal backgrounds.

File discovery leverages `gitcha` for concurrent, git-aware file searching, with results streamed via channels and processed incrementally in the UI. The fuzzy filtering system is pure and elegant: as the user types in the filter input, `filterMarkdowns()` runs `fuzzy.Find()` on normalized filenames and returns ranked matches with match positions for highlighting.

For SugarCraft, glow represents the kind of polished TUI application that could be built using PHP ports of the underlying Charm libraries. The markdown rendering, file browsing, filtering, and styling systems all have clear SugarCraft equivalents, though porting would require careful translation of Go's goroutine-based concurrency to ReactPHP's async patterns.
