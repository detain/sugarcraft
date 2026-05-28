# alecrabbit/php-console-spinner

## Metadata
- URL: https://github.com/alecrabbit/php-console-spinner
- Language: PHP
- Stars: Unknown (GitHub API did not return data; likely a niche but actively maintained library)
- License: MIT
- Description: An extremely flexible console spinner library for PHP CLI applications with support for both asynchronous (event-loop based) and synchronous modes.

## Feature List
- **Dual-mode operation**: Asynchronous mode (via revolt/react event loops) and synchronous mode
- **ANSI color support**: No color, 16 colors (ANSI4), 256 colors (ANSI8 - default), and true color (ANSI24)
- **Auto cursor hide/show**: Automatically manages terminal cursor visibility
- **Signal handling**: SIGINT/SIGTERM handlers for graceful interruption (requires ext-pcntl)
- **Pipe/stream redirection support**: Works correctly when output is piped or redirected
- **Event loop auto-start**: Automatically starts the event loop at script end
- **Extensible architecture**: Custom character palettes and style palettes
- **PSYCH/pcntl signal handling**: Configurable via options
- **"Zero" dependencies**: Only requires psr/container for core functionality
- **Custom spinners**: Built-in spinners include RainyWeather, Ascii, StromyWeather, Snake, and more

## Key Classes and Methods

### Facade (Main Entry Point)
- `Facade::createSpinner(?ISpinnerSettings $settings = null): ISpinner` — Creates and optionally auto-attaches a spinner to the driver
- `Facade::getDriver(): IDriver` — Retrieves the singleton driver instance
- `Facade::getLoop(): ILoop` — Retrieves the event loop (throws if unavailable in sync mode)
- `Facade::getSettings(): ISettings` — Retrieves user settings

### Core Classes

**ISpinner (Spinner)**
- `getFrame(?float $dt = null): IFrame` — Gets current frame for rendering
- `getInterval(): IInterval` — Gets update interval
- `add(IWidgetContext $element): IWidgetContext` — Adds a widget element (for composite widgets)
- `remove(IWidgetContext $element): void` — Removes a widget element

**IDriver (Driver)**
- `add(ISpinner $spinner): void` — Registers a spinner with the driver
- `remove(ISpinner $spinner): void` — Unregisters a spinner
- `render(?float $dt = null): void` — Renders current frame to output
- `has(ISpinner $spinner): bool` — Checks if spinner is registered

**IDriverLinker (DriverLinker)**
- `link(IDriver $driver): void` — Links driver to an event loop for automatic rendering

**IContainer (Container)**
- PSR-11 compatible DI container with custom service definition registry
- Singleton and transient service support
- Used internally to wire up all factory dependencies

**Widget and WidgetRevolver**
- `AWidget` — Base widget class managing frames and spacers
- `Widget` — Concrete widget implementation
- `WidgetRevolver` — Combines style and character revolvers into final frames

**Frame System**
- `ICharFrame` / `CharFrame` — Represents a character frame with sequence and width
- `IStyleFrame` / `StyleFrame` — Represents a style frame with ANSI formatting
- `IFrameCollection` / `FrameCollection` — Collection of frames

**Palette System**
- `ACharPalette` / `ICharPalette` — Character/spinner character sets
- `AStylePalette` / `IStylePalette` — ANSI color styling
- Built-in palettes: `Snake` (Unicode spinner chars), `Rainbow` (colorful styles), `NoStylePalette`, `NoCharPalette`

**Pattern System**
- `IPattern` / `Pattern` — Represents a spinner pattern (interval + frames)

**Options/Enums**
- `StylingMethodOption` — AUTO, NONE, ANSI4, ANSI8, ANSI24
- `RunMethodOption` — SYNCHRONOUS, ASYNCHRONOUS
- `SignalHandlingOption` — enables/disables signal handling
- `CursorVisibilityOption` — controls cursor auto-hide
- `NormalizerOption` — frame timing normalization
- `AutoStartOption` — event loop autostart control

## Notable Algorithms / Named Patterns

- **Observer Pattern**: `ISubject`/`IObserver` contracts for frame update notifications
- **Strategy Pattern**: Interchangeable loop adapters (`RevoltLoopAdapter`, `ReactLoopAdapter`)
- **Factory Pattern**: Extensive factory hierarchy for creating spinners, widgets, drivers, revolvers, palettes, and configurations
- **Builder Pattern**: Config builders for complex objects (e.g., `DriverConfigBuilder`, `WidgetRevolverConfigBuilder`)
- **Container/DI Pattern**: Custom PSR-11 compatible container for dependency injection
- **Revolver Pattern**: Frame cycling mechanism with tolerance support (`FrameCollectionRevolver`)
- **Delta Timer**: High-resolution timing using `hrtime(true)` for precise interval control
- **Cursor Management**: ANSI escape sequences for cursor positioning and erasure (`\r`, `\e[K`, `\e[?25l`)

## Strengths
- **Extremely flexible architecture**: 419 PHP files with clear separation of concerns
- **Dual-mode support**: Works with or without event loops (ReactPHP, Revolt)
- **Rich customization**: Extensible character palettes, style palettes, and spinner patterns
- **Correct ANSI handling**: Proper terminal escape sequence management for cursor, color, and erasure
- **Zero external runtime dependencies**: Only psr/container required; event loops are optional
- **High-resolution timing**: Uses `hrtime()` for sub-millisecond precision
- **Graceful degradation**: Works in limited environments (Docker, pipes, no colors)
- **Strict typing**: PHP 8.2+ with strict_types, readonly properties, and comprehensive interfaces

## Weaknesses
- **Steep learning curve**: Highly abstracted architecture can be difficult to understand
- **Incomplete documentation**: README notes "documentation is a bit clumsy at the moment and CAN BE MISLEADING"
- **API not stable**: Pre-1.0 version; API subject to change until 1.0.0-BETA.0
- **Complex dependency injection**: Large number of factories and interfaces makes debugging harder
- **Windows limitations**: Requires VT100 terminal (mintty) for full support; no signal handling on Windows
- **Docker considerations**: Requires `-T` flag for docker-compose exec; spinner can pollute docker logs
- **Autostart interference**: Event loop autostart can interfere with custom error handlers

## SugarCraft Mapping

### candy-core
- **Relevant**: Yes — candy-core is the foundational TUI library for SugarCraft
- **Mapping**: The spinner architecture (especially `Driver`, `Renderer`, `SequenceStateWriter`) provides patterns for rendering ANSI terminal output that could inform candy-core's TTY rendering infrastructure
- **Note**: The `ConsoleCursor` and cursor hide/show management (`\e[?25l`, `\e[?25h`) is directly applicable

### sugar-bits
- **Relevant**: Potential — sugar-bits is described as components/data/forms/apps
- **Mapping**: A spinner/progress indicator component could be built as part of sugar-bits using patterns from this library
- **Note**: This library is too low-level to be a direct sugar-bits dependency but could inspire component patterns

### honey-bounce
- **Relevant**: No — honey-bounce is math/physics/motion
- **No direct mapping**

### Other Observations
- This library provides a reference implementation for ANSI terminal spinners that SugarCraft's TUI components could leverage
- The factory/container architecture is more complex than SugarCraft's current patterns but shows how to build extensible terminal UI
- Key relevant files: `src/Spinner/Core/Output/` (output buffering, cursor management) and `src/Spinner/Core/Driver/Renderer.php` (ANSI rendering)

## Analysis

`alecrabbit/php-console-spinner` is a sophisticated console spinner library that provides animated terminal spinners for long-running CLI tasks. At its core, it uses a `Driver` that periodically calls `render()` to output ANSI escape sequences to stderr, cycling through character frames from a configurable palette while applying ANSI color styling from a separate style palette.

The architecture is built around several key abstractions: `ISpinner` wraps a `Widget` that combines a `WidgetRevolver` (which merges style and character `Revolver` objects), each `Revolver` cycles through frames from a `Pattern` or `Palette`. The `DriverLinker` connects the `Driver` to an event loop (Revolt or ReactPHP) using `loop->repeat(interval, fn => driver->render())`.

For output, it uses a two-buffer system: `SequenceStateWriter` tracks what was previously written so it can erase/overwrite with spaces and carriage returns. The `Renderer` outputs ANSI sequences including cursor positioning (`\r` to return to start of line), erasure (`\e[K`), and optionally color codes.

The library is notable for its flexibility but comes with significant complexity. The 419 PHP files implement a full DI/container system, multiple factory hierarchies, and extensive configuration options. For SugarCraft, this represents an aspirational level of terminal rendering sophistication that could be simplified and adapted for thebubbletea model paradigm. The core ANSI rendering patterns (cursor movement, erasure, colors) are directly applicable, but the architecture is over-engineered for what charmbracelet/bubbletea handles more simply through the BubbleTea model/update/view cycle.
