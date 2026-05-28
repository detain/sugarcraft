# c9s/CLIFramework

## Metadata
- **URL:** https://github.com/c9s/CLIFramework
- **Language:** PHP (≥7.2.0)
- **Stars:** 436
- **Forks:** 52
- **License:** MIT (BSD-style, see LICENSE file)
- **Description:** Command-line framework for building flexible, simple command-line applications in PHP. Supports hierarchical commands, automatic help generation, shell completion, interactive prompts, ANSI styling, and more.
- **Author:** Yo-An Lin (cornelius.howl@gmail.com)
- **Package Name:** `corneltek/cliframework` (Packagist)

## Feature List

- **Hierarchical command architecture** — Applications support nested subcommands with a `prepare → execute → finish` lifecycle
- **Option parsing** — Powered by GetOptionKit: long options (`--foo`), short options (`-f`), required/optional/default values, flag combinators
- **Automatic help generation** — `help` command renders usage, synopsis, options, and grouped command list with ANSI styling
- **Zsh/Bash completion generation** — Built-in commands (`zsh`, `bash`) emit shell functions for tab-completion of commands, options, and argument values
- **Argument validation & suggestions** — Arguments can declare `validValues()`, `suggestions()`, `isa('file')`, `glob('*.php')`, `multiple()`; suggestions can be closures for lazy evaluation
- **Interactive prompts** — `ask($message, $validAnswers)` with readline support; `password($prompt)` for hidden input
- **Interactive chooser** — `choose($prompt, $choices)` renders an indexed menu for selecting from options
- **ANSI console styling** — Formatter produces SGR escape codes for foreground/background colors, bold, dim, underline, etc.
- **Structured logging** — 7-level logger (critical → debug2) with styled output to stdout/stderr
- **ASCII table rendering** — Table component with multiple styles (borderless, compact, markdown), column width auto-calc, cell overflow wrapping
- **Progress bars** — ProgressBar component with ETA calculation, customizable decorators and styles
- **Extension system** — Commands and applications can be extended via `CLIFramework\Extension\ExtensionBase` with `prepare()`/`execute()`/`finish()` hooks
- **Event dispatcher** — Triggers `execute.before`, `execute`, `execute.after` hooks; uses `Universal/Event/EventDispatcher`
- **Service container** — DI container (Pimple-based) providing logger, formatter, writer, console, command_loader as singletons
- **Command autoloading** — Auto-discovers command classes by convention (`FooBarCommand` → `foo-bar`) in registered namespaces
- **Command groups** — Logical groupings of related commands displayed in help
- **Topic system** — Arbitrary help topics beyond commands
- **Global config file** — `cliframework.ini` at current directory or `$HOME` for persistent settings
- **Phar compilation** — Built-in `archive` command to bundle app into a single `.phar` file
- **Command correction** — Levenshtein-distance-based typo correction with `guessCommand()` method
- **PHPUnit testing support** — Provides base test case for testing commands

## Key Classes and Methods

### Core Framework

- **`CLIFramework\Application`** extends `CommandBase`
  - `run(array $argv)` — Main entry point; parses options, builds command stack, dispatches prepare/execute/finish
  - `runWithTry(array $argv)` — Wraps run() with centralized exception handling
  - `init()` — Register subcommands and command groups; also registers built-in help/zsh/bash/meta/compile/archive commands
  - `options($opts)` — Define app-level options (`-v|verbose`, `-d|debug`, `-q|quiet`, `--version`, `--help`, `-p|profile`)
  - `execute()` — Default fallback when no subcommand given; shows help or version
  - `prepare()` / `finish()` — Lifecycle hooks for profiling, logging setup
  - `getFormatter()` / `getLogger()` — Access framework services

- **`CLIFramework\CommandBase`** implements `ArrayAccess`, `IteratorAggregate`, `CommandInterface`
  - `command($name, $class = null)` / `addCommand($name, $class = null)` — Register a subcommand
  - `commandGroup($name, $commands)` — Group commands for help display
  - `options($opts)` — Override to define option specs
  - `arguments($args)` — Define positional argument specs
  - `execute($arg1, ...)` — Override with actual command logic
  - `executeWrapper(array $args)` — Validates arguments via reflection, triggers events, calls execute
  - `prepare()` / `finish()` — Lifecycle hooks; iterate extensions
  - `brief()` / `usage()` / `help()` — Meta methods for help text
  - `aliases()` — Return alias array for the command
  - `getArgInfoList()` — Returns ArgInfoList from arguments() or via reflection on execute()
  - `getOptionCollection()` — Returns GetOptionKit\OptionCollection built from options()
  - `guessCommand($name)` — Levenshtein correction for typo'd command names
  - `ask($prompt, $validAnswers)` / `choose($prompt, $choices)` — Interactive IO shortcuts
  - `getApplication()` — Walk parent chain to find Application

- **`CLIFramework\Command`** extends `CommandBase`
  - `setName($name)` / `getName()` — Command name (auto-derived from class name via `FooBarCommand` → `foo-bar`)
  - `getApplication()` — Explicit application reference
  - `getLogger()` / `getFormatter()` — Convenience accessors
  - `aliases()` — Return array of alias strings (default: empty)

### Services

- **`CLIFramework\ServiceContainer`** extends `Pimple\Container`
  - Singleton via `getInstance()`
  - Provides: `config`, `event`, `writer`, `logger`, `formatter`, `console.stty`, `console`, `command_loader`
  - `isWindows()` — OS detection

- **`CLIFramework\CommandLoader`**
  - `translate($command)` — `foo-bar` → `FooBarCommand`
  - `inverseTranslate($className)` — `FooBarCommand` → `foo-bar`
  - `load($command)` / `loadClass($class)` / `loadSubcommand($subcommand, $parent)` — Class resolution across registered namespaces
  - `addNamespace($ns)` — Register namespace prefix for command class lookup

- **`CLIFramework\Logger`**
  - `error($msg)` / `warn($msg)` / `info($msg)` / `info2($msg)` / `debug($msg)` / `debug2($msg)` / `critical($msg)` — Log levels
  - `setLevel($n)` / `setQuiet()` / `setVerbose()` / `setDebug()` — Level control
  - `write($text)` / `writeln($text)` / `newline()` / `writef($format, ...)` — Raw output
  - `indent($n)` / `unIndent($n)` — Output indentation
  - `logException(\Exception)` — Exception dump

- **`CLIFramework\Formatter`**
  - `format($text, $style)` — Apply ANSI style; returns `$startMark . $text . $clearMark`
  - Built-in styles: `dim`, `red`, `green`, `white`, `yellow`, `bold`, `underline`, `strong_red`, `strong_green`, `ask`, `choose`, `done`, `success`, `fail`, etc.
  - `getStartMark($style)` / `getClearMark()` — Low-level SGR code generation
  - `addStyle($name, $definition)` — Extensible style registry
  - Detects terminal capability via `posix_isatty(STDOUT)`

- **`CLIFramework\Prompter`**
  - `ask($prompt, $validAnswers = null, $default = null)` — Read line with optional validation
  - `password($prompt)` — Hidden-input prompt

- **`CLIFramework\Chooser`**
  - `choose($prompt, $choices)` — Render indexed menu; returns selected value

### Component Library

- **`CLIFramework\Component\Table\Table`**
  - `setHeaders(array)` / `setFooter($string)` — Table headers/footer
  - `addRow($row)` — Add row; supports `RowSeparator`; handles text overflow wrapping
  - `setStyle($name)` — `borderless`, `compact`, `markdown`, or custom `TableStyle`
  - `render()` — Returns complete formatted table string
  - Column width auto-calculation with `getColumnWidth($col)`

- **`CLIFramework\Component\Progress\ProgressBar`** implements `ProgressReporter`
  - `start($title)` / `update($finished, $total)` / `finish($title)` — Progress lifecycle
  - `setUnit($unit)` / `setTitle($title)` — Display configuration
  - Uses `ETACalculator` for ETA time/period computation
  - Decorators: `leftDecorator`, `rightDecorator`, `barCharacter`, `columnDecorator`

- **`CLIFramework\Buffer`** — String buffer with `appendLine()`, `append()`, `indent()`, `unindent()`, `prepend()`

### Completions

- **`CLIFramework\Completion\ZshGenerator`**
  - `output()` — Generates zsh completion function as string
  - Uses `compdef` directive with `_arguments` and `_describe`

- **`CLIFramework\Completion\BashGenerator`**
  - Generates bash completion script using `complete` builtin

### Built-in Commands (in `CLIFramework\Command\`)

- **`HelpCommand`** — `execute()` renders full help for any command path
- **`ZshCompletionCommand`** — `execute()` emits zsh completion script
- **`BashCompletionCommand`** — `execute()` emits bash completion script
- **`MetaCommand`** — `execute()` introspects command specs (options, arguments, valid values)
- **`CompileCommand`** — `execute()` compiles app into a phar archive
- **`ArchiveCommand`** — `execute()` creates distributable archive

### Support Classes

- **`CLIFramework\ArgInfo`** — Single argument definition: name, optional flag, description
- **`CLIFramework\ArgInfoList`** — Collection of ArgInfo, with `append()`, iteration
- **`CLIFramework\CommandGroup`** — Groups commands with name and ordering
- **`CLIFramework\Corrector`** — Levenshtein-based word correction
- **`CLIFramework\LevenshteinCorrector`** — Specific levenshtein corrector
- **`CLIFramework\ValueCollection`** / **`CLIFramework\ValueGroup`** — Value aggregation utilities
- **`CLIFramework\Exception\*`** — Custom exceptions: `CommandNotFoundException`, `CommandArgumentNotEnoughException`, `ExecuteMethodNotDefinedException`, `CommandClassNotFoundException`, `ExtensionException`, `InvalidCommandArgumentException`
- **`CLIFramework\OptionPrinter`** — Renders GetOptionKit option specs as formatted text

### IO Layer (`CLIFramework\IO\`)

- **`Console`** — Interface for console I/O
- **`ReadlineConsole`** — Readline-powered input
- **`StandardConsole`** — Simple `readLine()` / `readPassword()` wrappers
- **`Writer`** / **`EchoWriter`** / **`StreamWriter`** — Output destinations
- **`UnixStty`** / **`NullStty`** — TTY detection

### Extension System (`CLIFramework\Extension\`)

- **`ExtensionBase`** — Base class for extensions
  - `isAvailable()` — Check if extension conditions are met
  - `bindCommand($cmd)` / `bindApplication($app)` — Injection
  - `prepare()` / `execute()` / `finish()` — Lifecycle hooks

- **`CommandExtension`** — Binds to individual commands
- **`ApplicationExtension`** — Binds to entire application
- **`DaemonExtension`** — Daemon-specific extension

## Notable Algorithms / Named Patterns

- **Levenshtein distance correction** — `Corrector` class uses `similar_text`-based scoring (not raw levenshtein) to suggest corrections for mistyped command names
- **ContinuousOptionParser** — GetOptionKit's parser that handles interleaved options and arguments across command hierarchy
- **Reflection-based argument extraction** — `execute()` method parameters are introspected via `ReflectionMethod` to auto-build `ArgInfoList` when `arguments()` is not explicitly defined
- **Convention-based command naming** — `FooBarCommand` class → `foo-bar` command name; `list` command → `ListCommand` class — auto-translated by `CommandLoader`
- **Service locator pattern** — `ServiceContainer` provides framework-wide singletons; accessed via `__get()` magic on Application
- **Continuous option parsing** — Option parsing continues across command levels (`$ app -v subcmd -d arg1`) where `-v` belongs to app and `-d` belongs to subcommand
- **Event-driven hooks** — `execute.before`, `execute`, `execute.after` events triggered in `executeWrapper()` via `EventDispatcher`
- **ANSI SGR (Select Graphic Rendition)** — `Formatter` maps style names to numeric SGR codes: foreground 30-37, background 40-47, bold=1, dim=2, underline=4, etc.
- **ETA calculation** — `ETACalculator::calculateEstimatedTime()` / `calculateEstimatedPeriod()` based on elapsed time and completion ratio

## Strengths

- **Comprehensive feature set** — Covers virtually every CLI app need: argument parsing, help generation, shell completion, interactive prompts, styled output, tables, progress bars, and a plugin system
- **Hierarchical command model** — Properly models real-world CLI tools with nested subcommands (`app cmd subcmd`) and a clear prepare/execute/finish lifecycle
- **Shell completion** — Out-of-the-box zsh and bash completion generation is a major UX win; supports lazy-computed valid values via closures
- **Extensibility** — Extension system with prepare/execute/finish hooks, event dispatcher, and service container makes it easy to add cross-cutting concerns
- **Interactive components** — Prompter, Chooser, and ProgressBar provide full terminal interactivity without requiring additional dependencies
- **Convention over configuration** — Auto-translation of `FooBarCommand` to `foo-bar` reduces boilerplate; autoload commands from registered namespaces
- **Argument introspection** — Reflection on `execute()` method parameters means commands can skip explicit `arguments()` definition
- **Error handling** — `runWithTry()` provides centralized exception handling with production vs development exception printers
- **Mature codebase** — 436 stars, active development since 2011, HHVM compatibility noted

## Weaknesses

- **No type hints or return types** — Despite requiring PHP ≥7.2, the codebase uses PHP 5 era style with no return type declarations and minimal type hints on parameters — code is difficult to analyze statically
- **Class-style constants** — Uses `const` on classes rather than `public const` or `final const`, making it awkward to reference as `Application::VERSION` vs `static::VERSION`
- **Singleton anti-pattern** — `ServiceContainer::getInstance()`, `Logger::getInstance()`, `CommandLoader::getInstance()` scatter static state throughout the codebase
- **Global state** — The service container and logger singletons create implicit global state that makes testing harder
- **Minimal PHP 8.x adoption** — No attributes, no named arguments usage, no constructor property promotion, no union types — still writes PHP 5.6 code
- **No async support** — Entirely synchronous; no ReactPHP or Swoole integration despite SugarCraft ecosystem's async focus
- **Limited Windows support** — Console TTY detection explicitly sidesteps Windows (`// TODO support Windows` in `ServiceContainer`)
- **Complex command resolution** — `executeWrapper` reflection on every call; command stack building in `Application::run()` is complex and hard to follow
- **Mixed concerns in base classes** — `CommandBase` is massive (945 lines) with ~60 methods mixing IO, loading, extension management, and argument parsing
- **Outdated dependencies** — Depends on `symfony/finder ~2.8|~3.0|^5.3.4`, `symfony/class-loader ~2.8|~3.0|~3.2`, `corneltek/codegen 4.0.x-dev`, `universal/universal 2.0.x-dev` — some very old versions still in the constraint

## SugarCraft Mapping

### Primary Mapping: `candy-shell`

**CLIFramework maps directly to `candy-shell`** — both are the umbrella TUI/console application foundation layer. `candy-shell` is SugarCraft's system/framework tier (per `PROJECT_NAMES.md` naming: `Candy-` prefix for foundation/system/framework).

- **Similar to:** `charmbracelet/bubbletea` (Go) — hierarchical command model, option parsing, lifecycle hooks
- **Comparable SugarCraft libs:** `candy-shell` (if it exists), `candy-core` for base abstractions

### Secondary Mapping: `sugar-prompt`

**Interactive prompt/chooser components map to `sugar-prompt`:**

- `CLIFramework\Prompter` → prompt/ask component
- `CLIFramework\Chooser` → interactive selection component

These would be leaf libs under `Sugar\` (components/data/forms/apps) rather than `Candy\`.

### Tertiary: Component-Level Libraries

- **`candy-shine`** (styling/output) — `CLIFramework\Formatter` + `CLIFramework\Logger` = ANSI text styling
- **`sugar-bits`** (small utilities) — `Buffer`, `Corrector`, `ValueCollection`, `ArgInfo`, `ArgInfoList`, `CommandGroup`
- **`sugar-charts`** — not relevant (Table component is text-only, not data visualization)
- **`honey-bounce`** — not relevant (no physics/animation)

### Architecture Comparison

```
CLIFramework                       SugarCraft
────────────────────────────       ──────────────────────────
Application                        (candy-shell root)
CommandBase                        Base classes
Command                            Per-command classes
ServiceContainer                   DI / service provision
GetOptionKit (external)             (candy-core option parsing)
Formatter                          candy-shine (ANSI styling)
Logger                             candy-shine / sugar-bits
Prompter / Chooser                 sugar-prompt
Table                              sugar-bits
ProgressBar                        sugar-bits
ZshGenerator / BashGenerator       sugar-prompt (completion)
Extension system                   (plugin architecture)
```

## Analysis

CLIFramework is a mature, full-featured PHP command-line application framework that predates many modern PHP practices. At its core, it provides a hierarchical command model where commands are PHP classes extending `Command` (which extends `CommandBase`), with a lifecycle of `prepare() → execute() → finish()`. The `Application` class serves as both the root command container and the CLI entry point, using GetOptionKit's `ContinuousOptionParser` to handle interleaved options across command boundaries (e.g., `app -v subcmd --opt arg`).

The framework's most distinctive strength is its comprehensive shell completion support — the built-in `zsh` and `bash` subcommands emit complete completion functions that understand the command hierarchy, option specs, and can use PHP closures for lazy-computed valid values (e.g., running `git rev-list` at completion time). This is significantly more ergonomic than hand-writing shell completion scripts.

The service container (Pimple-based) centralizes framework-wide dependencies (logger, formatter, writer, console, event dispatcher), though the widespread use of singletons and `getInstance()` methods creates global state that complicates testing. The extension system allows cross-cutting behavior to be injected into commands, and the event dispatcher provides hooks at `execute.before`, `execute`, and `execute.after`.

The main weaknesses are architectural. Despite targeting PHP 7.2+, the codebase uses no return type declarations, no typed properties, no named arguments, and almost no attributes — it's PHP 5.6 style code wearing PHP 7.2 compatibility. The `CommandBase` class is a 945-line god object that mixes IO, class loading, extension management, argument parsing, and command resolution. The reliance on reflection for argument introspection on every `execute()` call has performance implications. And while it has an extension/plugin system, the coupling between `Application` and concrete services like `Formatter` and `Logger` makes swapping implementations awkward.

For the SugarCraft ecosystem, CLIFramework maps most naturally to `candy-shell` as the console application foundation. The interactive prompt and chooser components would become `sugar-prompt` leaf libs, while the ANSI formatting and logging infrastructure belongs in `candy-shine`. The table rendering and progress bar components are utility-level leaf libs that could live in `sugar-bits`. The completion generation system is a specialized `sugar-prompt` feature. The overall architecture — hierarchical commands, fluent option building, service container, event hooks — is the same model that `candy-shell` should implement in idiomatic PHP 8.3+ style.
