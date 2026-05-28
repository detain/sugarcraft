# charmbracelet/confettysh

## Metadata
- URL: https://github.com/charmbracelet/confettysh
- Language: Go
- Stars: ~81
- License: MIT
- Description: Confetti and Fireworks over SSH. An SSH server that renders animated confetti and fireworks in the terminal using the Bubble Tea TUI framework.

## Feature List
- **SSH Server**: Listens on a configurable port (default 2222) for incoming SSH connections
- **Dual Effect Modes**: Two distinct visual effects selectable at connection time:
  - `confetti` — cascading colored confetti particles
  - `fireworks` — exploding firework particles with trails
- **TTY-over-SSH Rendering**: Uses Bubble Tea's `activeterm` middleware to render ANSI graphics through SSH sessions
- **Alternative Screen Mode**: Uses `tea.WithAltScreen()` for clean, non-destructive terminal rendering
- **Prometheus Metrics**: Exposes metrics on a separate port (default 9222) via `promwish`
- **Endpoint Discovery**: Uses `wishlist` for managing multiple SSH endpoint configurations with middleware chains
- **Automatic SSH Key Management**: Auto-generates/loads host keys per endpoint (`.ssh/<name>`)
- **Graceful Degradation**: Bubble Tea model pattern supports fallback to default effect
- **Docker Support**: Minimal scratch-based container image for easy deployment

## Key Classes and Methods
- `main.go`: Single-file application entry point
  - `main()` — initializes SSH server with wishlist configuration, starts Prometheus listener
  - `teaHandler(effect string)` — returns a closure that creates the appropriate Tea model based on effect name
- `confetti.InitialModel()` — creates initial state for confetti particle system (from maaslalani/confetty)
- `fireworks.InitialModel()` — creates initial state for fireworks particle system (from maaslalani/confetty)
- Bubble Tea integration via `bm.Middleware()` wrapping `teaHandler()`
- Middleware chain: `promwish.MiddlewareRegistry()` → `lm.Middleware()` → `activeterm.Middleware()`

## Notable Algorithms / Named Patterns
- **Bubble Tea MVC Pattern**: Uses `tea.Model` interface with `Update()` and `View()` methods; the confetti library provides the model implementation
- **SSH Middleware Chain**: Compose multiple wish middlewares in sequence (promwish → logging → active terminal)
- **Particle System**: The underlying confetti/fireworks effects use a particle system algorithm with:
  - Velocity-based particle movement
  - Color cycling/palette selection
  - Terminal ANSI SGR escape sequences for colored output
  - Frame-by-frame rendering via Bubble Tea's render loop
- **Tea Program Options**: `tea.WithAltScreen()` for alternate screen buffer usage
- **Wishlist Endpoint Pattern**: Factory function pattern for creating per-endpoint SSH server instances with distinct configurations

## Strengths
- **Zero-Dependency Core**: Single `main.go` file with clear, focused responsibility
- **Charm Ecosystem Integration**: Tight integration with wish (SSH), bubbletea (TUI), and related Charm libraries
- **Extensible Architecture**: Easy to add new effects by creating new wishlist endpoints with corresponding Tea handlers
- **Observability Built-In**: Prometheus metrics with per-app labels and command function tracking
- **Secure by Default**: Host key per endpoint, proper middleware ordering
- **Cross-Platform**: Works on any platform with a Go compiler and SSH client
- **MIT Licensed**: Fully open source with no license restrictions
- **Professional CI/CD**: GoReleaser, Dependabot, multiple workflow checks (lint, build, nightly)

## Weaknesses
- **Single Point of Configuration**: Effect selection is hardcoded to two endpoints; cannot select effects dynamically within a session
- **No Authentication**: The demo SSH server has no authentication mechanism configured
- **Limited Effect Customization**: No CLI flags for particle count, color schemes, or animation speed
- **Heavy Dependency Tree**: Pulls in full Charm ecosystem (wish, bubbletea, logging, etc.) for a simple demo effect
- **Requires SSH Client**: Users must have an SSH client available; not a pure TUI application
- **Single-File Limitation**: The entire application logic lives in one file, which may limit readability for complex extensions
- **No Windows Support Note**: Terminal rendering of ANSI codes may behave differently across platforms

## SugarCraft Mapping
This is a **wrapper application** (not a library), so it does not map directly to a single SugarCraft library. The mapping is conceptual:

- **`sugar-bits`**: The Bubble Tea TUI integration pattern — confettysh uses `tea.Model`, `tea.Update()`, `tea.View()` cycle which sugar-bits also uses for its own TUI rendering
- **`candy-core`** (potential): The SSH server infrastructure (`wish`, `ssh`) could inspire a SugarCraft SSH/Telnet wrapper library if needed
- **`honey-bounce`** (conceptual): Particle system physics (velocity, acceleration, gravity) — confetti uses gravity-based particle trajectories; fireworks use explosive radial velocity with decay
- **`sugar-charts`** (loose): Color palette management and ANSI color rendering for terminal output — both use color to convey visual information
- **`candy-shine`** (very loose): Progress indicators/status displays — confetti is a "delightful" output effect similar in spirit to progress spinners

**Note**: confettysh is an _application_, not a reusable library. It wraps `maaslalani/confetty` (which itself is a standalone CLI tool) through an SSH interface. SugarCraft does not currently have a direct port of this functionality — it would require porting the confetti particle system from Go to PHP and integrating it with an SSH server or a terminal rendering library.

## Analysis

**charmbracelet/confettysh** is a delightfully minimal demonstration of the Charm ecosystem's capabilities. At its core, it's an SSH server wrapper that exposes two animated TUI effects (confetti and fireworks) over SSH connections. The architecture is elegant in its simplicity: a single 105-line `main.go` file that composes pre-built Charm libraries (`wish` for SSH, `bubbletea` for TUI rendering, `wishlist` for endpoint management) with the upstream `maaslalani/confetty` library to do the actual particle rendering.

The project's design philosophy embodies the Unix philosophy of composition: instead of building a custom SSH daemon or TUI renderer from scratch, confettysh assembles existing, well-tested components. The `teaHandler` factory function demonstrates this perfectly — it takes an effect name and returns a closure that instantiates the appropriate Bubble Tea model, which is then wired into the SSH middleware chain via `bm.Middleware()`. This pattern allows the application to switch between different visual effects without any conditional logic in the rendering path.

From a software architecture perspective, confettysh is notable for being more of a _demo/showcase_ than a production library. It lacks any configuration system beyond command-line flags for listening address and ports, has no authentication, and offers no customization of the visual effects themselves. However, this simplicity is intentional — its purpose is to entertain SSH users with a fun visual effect, not to provide a configurable service. The 81 stars and inclusion in the Charm portfolio suggest it's valued more as a conversation piece and ecosystem demonstration than as serious infrastructure.

For SugarCraft, the primary lessons from confettysh are in the _architectural patterns_ rather than any specific feature to port. The particle system algorithm (which lives in `maaslalani/confetty`, not in confettysh itself) could theoretically be ported to PHP using terminal ANSI rendering, but SugarCraft currently lacks an SSH server component that would be needed to replicate the full experience. The Bubble Tea model pattern of immutable state + update function + view function is already mirrored in SugarCraft's `Model::init/update/view` contract, making it the most directly applicable mapping.
