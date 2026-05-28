# charmbracelet/crush

## Metadata
- **URL:** https://github.com/charmbracelet/crush
- **Language:** Go
- **Stars:** 24,731
- **License:** FSL-1.1-MIT (Functional Source License with MIT Future License)
- **Description:** "Glamourous agentic coding for all 💘" - A terminal-based AI coding assistant that connects LLMs to development tools, providing multi-model support, LSP integration, MCP extensibility, and session-based workflow management.

## Feature List

- **Multi-Model LLM Support:** Works with Anthropic, OpenAI, Google Gemini, Amazon Bedrock, Azure OpenAI, OpenRouter, Vercel AI, and custom OpenAI/Anthropic-compatible providers
- **Session-Based Workflow:** Multiple concurrent work sessions with context preservation per project
- **LSP Integration:** Language Server Protocol support (gopls, typescript-language-server, nil, etc.) for code intelligence
- **MCP Extensibility:** Model Context Protocol support via stdio, HTTP, and SSE transports
- **Agent Skills:** Agent Skills standard support for extensible capabilities via SKILL.md files
- **Built-in Tools:** File operations (view, edit, write, glob, ls), search (grep, web search, Sourcegraph), bash execution, LSP restart, diagnostics, and more
- **Cross-Platform:** First-class support for macOS, Linux, Windows (PowerShell/WSL), Android, FreeBSD, OpenBSD, NetBSD
- **Hooks System:** Pre-tool-use shell command hooks for customization
- **Permission System:** Tool execution permission prompts with allow-list configuration
- **Context Files:** AGENTS.md, CLAUDE.md, GEMINI.md, CRUSH.md and variants for project-specific instructions
- **Workspace Sharing:** Multiple TUI clients sharing the same workspace via shared backend
- **Embedded Shell:** POSIX-compatible shell emulation via mvdan.cc/sh/v3
- **Desktop Notifications:** System notifications for tool permissions and agent completion
- **Attribution:** Git commit/PR attribution customization (co-authored-by, assisted-by, none)
- **Provider Auto-Update:** Automatic model/provider updates from Catwalk database

## Key Classes and Methods

### Core Agent (`internal/agent/agent.go`)
- `SessionAgent.Run()` — Executes LLM conversation for a session
- `SessionAgent.SetModels()` — Configures large/small language models
- `SessionAgent.SetTools()` — Registers agent tools
- `SessionAgent.Cancel()` / `CancelAll()` — Cancels running requests
- `SessionAgent.Summarize()` — Auto-summarizes conversation context
- `IsSessionBusy()` / `IsBusy()` — Checks agent activity state

### Coordinator (`internal/agent/coordinator.go`)
- `Coordinator.Run()` — Main entry for agent task execution
- `Coordinator.UpdateModels()` — Refreshes model configuration
- `Coordinator.IsSessionBusy()` — Session busy state check
- Manages named agents ("coder", "task") with separate configurations

### Shell (`internal/shell/shell.go`)
- `Shell.Exec()` — Execute command, return stdout/stderr
- `Shell.ExecStream()` — Execute with streaming output
- `Shell.SetWorkingDir()` / `GetWorkingDir()` — Working directory management
- `SetEnv()` / `GetEnv()` — Environment variable handling
- `CommandsBlocker()` / `ArgumentsBlocker()` — Command blocking predicates

### LSP Manager (`internal/lsp/manager.go` / `client.go`)
- `lsp.Manager` — Manages multiple LSP client instances
- `Client.Initialize()` — Initialize LSP handshake
- `Client.TextDocumentCompletion()` — Request completions
- `Client.Diagnostics()` — Get current diagnostic counts
- Auto-discovery and on-demand LSP server startup

### Config (`internal/config/config.go`)
- `ConfigStore` — Central configuration service
- Provider/Model configuration with credentials support
- Variable resolution for environment expansion
- Context path discovery for AGENTS.md variants

### Skills (`internal/skills/manager.go`)
- `Manager.LoadSkills()` — Discover and load skill packages
- `Manager.GetSkill()` — Retrieve loaded skill by name
- Support for global (`~/.config/crush/skills/`) and project-local (`.crush/skills/`) paths

### Tools (`internal/agent/tools/`)
- `bash.go` — Shell command execution with background job support
- `edit.go` / `multiedit.go` — File editing operations
- `view.go` — File content viewing with syntax highlighting
- `grep.go` / `rg.go` — Pattern search
- `glob.go` — File pattern matching
- `ls.go` — Directory listing
- `fetch.go` / `web_fetch.go` / `web_search.go` — Web operations
- `sourcegraph.go` — Code search integration
- `mcp-tools.go` — MCP server tool bridging

### TUI (`internal/ui/`)
- `model/ui.go` — Main Bubble Tea model with layout management
- `chat/` — Chat message rendering with list-based view
- `diffview/` — Diff visualization
- `dialog/` — Modal dialog system
- `completions/` — Auto-complete popup
- `attachments/` — File attachment handling
- Hybrid rendering: Ultraviolet screen buffer + string-based components

## Notable Algorithms / Named Patterns

- **Coordinator Pattern:** `coordinator.go` manages multiple `SessionAgent` instances for different agent types (coder, task), with provider/model configuration resolution
- **Tool Hooks Decorator:** `hooked_tool.go` wraps tools with pre-execution hook running via `hooks.Runner`, enabling permission checks and custom logic
- **Auto-Summarization:** Context window management using `largeContextWindowThreshold` (200k tokens) and `smallContextWindowRatio` (0.2) to trigger summarization
- **Queue Management:** Per-session prompt queuing with `messageQueue` map and `activeRequests` cancel functions
- **Workspace Sharing:** SSE-based workspace collaboration where multiple TUI clients share session, LSP, and MCP state via `POST /v1/workspaces`
- **Loop Detection:** `loop_detection.go` prevents repetitive tool calls
- **Permission Queue:** Tool permission system with allow-list configuration and desktop notifications
- **VersionedMap:** `csync.VersionedMap` for thread-safe diagnostic cache management
- **Shell Env Markers:** `CRUSH=1`, `AGENT=crush`, `AI_AGENT=crush` markers injected for AI-detection in shell tools

## Strengths

- **Extensive LLM Provider Support:** 15+ providers with OpenAI and Anthropic compatibility for maximum flexibility
- **First-Class LSP Integration:** Real code intelligence via Language Server Protocol, not just pattern matching
- **MCP Ecosystem Bridging:** Native MCP client support bridges LLM agents with external tools and data sources
- **Agent Skills Standard:** Alignment with agentskills.io open standard for skill portability
- **Production-Quality TUI:** Bubble Tea v2 + Ultraviolet hybrid rendering for performant terminal UI
- **Cross-Platform Shell:** POSIX emulation works consistently across all supported platforms including Windows
- **Workspace Collaboration:** Multiple clients can share the same workspace state
- **Sophisticated Tool System:** Self-documenting tools with markdown templates, permission system, and hook decorators
- **Context File Discovery:** Automatic discovery of AGENTS.md, CLAUDE.md, and 15+ context file variants
- **Enterprise-Grade Permissions:** Tool allow-lists, disabled tools/skills configuration, yolo mode

## Weaknesses

- **Single-Agent Focus:** Currently runs primarily single-agent (coder), with multi-agent support noted as "not used yet"
- **Go-Only:** No direct library export - Crush is a CLI application, not a reusable library
- **Complex State Management:** TUI uses imperative mutation pattern rather than standard Elm architecture
- **Provider Dependency:** Heavy reliance on charm.land ecosystem packages (fantasy, catwalk, bubbletea)
- **SQLite Backend:** All state persisted in SQLite, limiting distribution/fan-out architectures
- **No Web UI:** Pure terminal interface, no browser-based alternative
- **CGO Disabled:** Uses pure Go sqlite implementation, may have performance characteristics

## SugarCraft Mapping

SugarCraft maps to the Charmbracelet ecosystem for PHP TUI libraries. Crush is an AI coding agent, not a direct library mapping. However, several Crush components map to SugarCraft architectural concepts:

| Crush Component | SugarCraft Lib | Notes |
|-----------------|---------------|-------|
| `internal/shell` | `candy-shell` | Shell command execution foundation |
| `internal/lsp` | `candy-core` (future) | LSP integration pattern |
| `internal/agent/tools` | `sugar-bits`, `sugar-prompt` | Built-in tool patterns for TUI interaction |
| `internal/ui` | `candy-shine`, `sugar-charts` | TUI rendering with Bubble Tea |
| `internal/skills` | (none yet) | Agent skills - potential `sugar-agent` |
| `internal/hooks` | (none yet) | Hook system for extensibility |
| `internal/config` | `SugarCraft\Core` | Service-based configuration pattern |
| `internal/pubsub` | `SugarCraft\Core` | Internal pub/sub for decoupling |
| `internal/message` | `SugarCraft\Core` | Message model patterns |

**Key Insight:** Crush is a CLI application, not a library. SugarCraft's `candy-shell` maps closest to Crush's `internal/shell` package (POSIX shell execution), but SugarCraft currently lacks:
- AI/LLM agent infrastructure
- MCP client implementation
- LSP integration
- Workspace/session management

## Analysis

Charmbracelet Crush is a sophisticated terminal-based AI coding assistant that represents the intersection of traditional CLI tooling and modern LLM-powered development workflows. Built entirely in Go (1.26.3+), it leverages the Charm ecosystem's mature libraries (Bubble Tea v2 for TUI, Lipgloss v2 for styling, Glamour v2 for markdown rendering) while integrating with the fantasy provider abstraction layer for multi-model LLM support. The architecture follows a clear separation: CLI entry via Cobra commands, core agent logic in `internal/agent`, shell execution in `internal/shell`, and UI rendering in `internal/ui` using a hybrid Ultraviolet screen buffer approach.

The tool system is particularly well-designed with self-documenting tools (each tool has a `.go` implementation paired with a `.md` template), a permission system with allow-lists, and hook decorators that enable pre-execution logic. The MCP integration via `modelcontextprotocol/go-sdk` allows Crush to bridge LLMs with external tools, while the LSP integration provides real code intelligence rather than relying solely on pattern matching. The workspace sharing feature via SSE demonstrates sophisticated real-time collaboration design.

Crush's main limitation from a library perspective is that it's an application, not a reusable library. The patterns and code organization could inspire SugarCraft components (particularly around shell execution, session management, and the tool/hook architecture), but Crush itself cannot be consumed as a dependency. The FSL-1.1-MIT license is business-friendly but has a competing use restriction that converts to pure MIT after two years.
