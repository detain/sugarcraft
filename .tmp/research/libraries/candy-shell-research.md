# CLI Shell/Application Libraries Research

## Context

**Library:** candy-shell (PHP 8.3+) — port of charmbracelet/gum
**Location:** `/home/sites/sugarcraft/candy-shell/src/`
**Upstream:** https://github.com/charmbracelet/gum
**Current Framework:** Symfony Console (`symfony/console ^6.4 || ^7.0`)
**Commands:** 13 subcommands (choose, confirm, file, filter, format, input, join, log, pager, spin, style, table, write)

---

## Current Architecture

### Source Structure
```
candy-shell/src/
├── Application.php          # Extends SymfonyApplication, registers all commands
├── Command/                 # 13 Command classes extending Symfony Command
│   ├── ChooseCommand.php    # Interactive selection (Model-based)
│   ├── ConfirmCommand.php   # Yes/no prompt (Model-based)
│   ├── FileCommand.php      # File picker (Model-based)
│   ├── FilterCommand.php    # Interactive filtering (Model-based)
│   ├── FormatCommand.php    # Format transformer (stateless)
│   ├── InputCommand.php     # Text input prompt (Model-based)
│   ├── JoinCommand.php      # Join stdin lines (stateless)
│   ├── LogCommand.php       # Styled log output (stateless)
│   ├── PagerCommand.php     # Paginated output (Model-based)
│   ├── SpinCommand.php      # Spinner + process (Model-based)
│   ├── StyleCommand.php     # Style text (stateless)
│   ├── TableCommand.php     # Format table (stateless)
│   └── WriteCommand.php     # Write to file (stateless)
├── Model/                   # 8 stateful interactive models (implements Model interface)
├── Style/                   # StyleBuilder, SubStyleParser (flag → Style mapping)
├── Process/                 # Process interface (RealProcess/FakeProcess for testing)
└── Lang.php                 # i18n wrapper
```

### Current Patterns

**Command Registration** (Application.php:L30-L44):
```php
$this->addCommands([
    new StyleCommand(),
    new ChooseCommand(),
    // ... all 13 commands manually instantiated
]);
```

**Command Definition** (e.g., StyleCommand.php:L21-L46):
```php
#[AsCommand(name: 'style', description: 'Apply Sprinkles styling...')]
final class StyleCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('text', InputArgument::IS_ARRAY, 'Text to style...')
            ->addOption('foreground', null, InputOption::VALUE_REQUIRED, '...')
            // ... 15+ options defined imperatively
    }
}
```

**Flag-to-Model Mapping** (ChooseCommand.php:L73-L88):
```php
$model = ChooseModel::fromOptions(
    $options,
    $height,
    $limit,
    // ... 8+ parameters passed positionally
);
$program = new Program($model, new ProgramOptions(
    useAltScreen: true,
    hideCursor: true,
    catchInterrupts: true,
));
```

---

## Research: CLI Frameworks Across Languages

### 1. Go: Cobra + Fang

**Library ID:** `/spf13/cobra` | `/charmbracelet/fang`

#### Command Routing
Cobra uses a tree structure where each `Command` can have children (subcommands):
```go
rootCmd.AddCommand(&cobra.Command{...})  // flat registration
cmdEcho.AddCommand(cmdTimes)             // nested subcommands
```

**Pattern:** Hierarchical command tree; `Execute()` handles parsing + routing + error propagation

#### Flag Parsing
- Uses `pflag` (POSIX-compliant fork of stdlib `flag`)
- Persistent flags inherit to all subcommands
- Local flags belong only to the declaring command
```go
rootCmd.PersistentFlags().BoolVarP(&verbose, "verbose", "v", false, "verbose output")
cmd.Flags().StringVarP(&output, "output", "o", "json", "output format")
cmd.MarkFlagRequired("region")
```

#### Help Generation
Auto-generated from command structure, flags, and doc comments:
- `cmd.HelpFunc()` customizable
- `cmd.SetUsageFunc()` for custom format
- Suggestions for typo'd commands ("did you mean --help?")

#### Subcommand Organization
- `AddCommand()` for explicit registration
- Generator (`cobra-cli`) scaffolds entire CLI structure
- `IsAvailableCommand()` / `Find()` for traversal

#### Error Handling
```go
RunE: func(cmd *cobra.Command, args []string) error {
    // return error; propagated to Execute()
}
cobra.CheckErr(rootCmd.Execute())  // prints error + exits
```

**Fang (charmbracelet):** Adds fancy output, automatic versioning, manpages, completions, theming to Cobra apps.

---

### 2. Rust: Clap (Derive API)

**Library ID:** `/clap-rs/clap` | `/websites/rs_clap`

#### Command Routing
Uses enums for subcommands — type-safe mutual exclusion:
```rust
#[derive(Parser)]
#[command(name = "git")]
struct Cli {
    #[command(subcommand)]
    command: Commands,
}

#[derive(Subcommand)]
enum Commands {
    Clone { remote: String },
    Push { remote: String, force: bool },
    Add { paths: Vec<PathBuf> },
}
```

#### Flag Parsing
Declarative struct fields with attributes:
```rust
#[derive(Parser)]
struct Args {
    #[arg(short, long, default_value_t = 1)]
    count: u8,
    #[arg(short, long)]
    config: Option<String>,
    #[arg(short, long, global = true)]
    verbose: bool,
}
```
- Type-driven: `String` = required, `Option<T>` = optional, `Vec<T>` = multiple
- `value_parser` for custom validation
- `ValueEnum` for enumerated values with auto-validation

#### Help Generation
Auto-derived from struct + doc comments + attributes:
```rust
// Help output:
$ git push --help
Push changes to remote

Usage: git push [OPTIONS] <remote>

Arguments:
  <remote>    The remote to push to [default: origin]

Options:
  -f, --force    Force push
  -h, --help     Print help
```

#### Subcommand Organization
- Enum variants = subcommands (compiler catches missing match arms)
- `#[command(propagate_version = true)]` for version inheritance
- Module-per-command structure recommended

#### Error Handling
- `Result` type enforced by compiler
- Validation at parse time (not runtime)
- Rich error messages with possible values listed

---

### 3. Python: Click

**Library ID:** `/pallets/click`

#### Command Routing
Decorator-based groups:
```python
@click.group()
def cli():
    """A CLI for managing databases."""
    pass

@cli.command()
@click.option('--force', is_flag=True)
def dropdb(force):
    """Drop the database."""
    pass
```

#### Flag Parsing
Decorators with type conversion:
```python
@click.option('--count', default=1, type=int)
@click.option('--name', required=True)
@click.option('--verbose', is_flag=True)
@click.option('--items', multiple=True)  # repeatable
def cmd(count, name, verbose, items):
    pass
```

#### Help Generation
Auto-generated; respects docstrings:
```bash
$ python db.py --help
Usage: db.py [OPTIONS] COMMAND [ARGS]...

  A CLI for managing databases.

Options:
  --debug / --no-debug
  --help                Show this message and exit.

Commands:
  dropdb  Drop the database.
  initdb  Initialize the database.
```

#### Subcommand Organization
- Groups host commands via decorator or `add_command()`
- Context object (`ctx.obj`) shared state across commands
- Command ordering preserved

#### Error Handling
```python
@click.command()
def cmd():
    if error_condition:
        raise click.ClickException('message')
    # or
    click.echo('Error: ...', err=True)
    raise SystemExit(1)
```

---

### 4. JavaScript: Commander.js

**Library ID:** `/tj/commander.js`

#### Command Routing
Chainable API:
```javascript
program
  .name('pdf')
  .command('compress <input>')
  .option('-o, --output <dir>', 'output directory')
  .action((input, options) => { /* ... */ });
```

#### Flag Parsing
- `.option()` with shorthand syntax
- Custom processing functions
- `.combineFlagAndOptionalValue()` for `-v45` style

```javascript
program
  .option('-i, --integer <number>', 'integer', myParseInt)
  .option('-v, --verbose', 'verbosity', increaseVerbosity, 0);
```

#### Help Generation
```javascript
program.showHelpAfterError();           // show full help after error
program.showSuggestionAfterError(false); // disable "did you mean"
```

#### Subcommand Organization
- `.command()` returns new command for chaining
- `.addCommand()` for adding pre-built commands
- Positional options with `enablePositionalOptions()`

#### Error Handling
```javascript
program.error('Password must be longer than four characters');
program.error('Custom processing failed', { exitCode: 2, code: 'custom' });
```

---

## Comparison Matrix

| Aspect | Symfony Console (PHP) | Cobra (Go) | Clap (Rust) | Click (Python) | Commander.js (JS) |
|--------|----------------------|------------|-------------|----------------|-------------------|
| **Routing** | Tree via `addCommands()` | Tree via `AddCommand()` | Enum variants | Decorator groups | Chainable `.command()` |
| **Flag API** | Imperative `addOption()` | Imperative + pflag | Derive from struct | Decorator `@option` | Chainable `.option()` |
| **Type Safety** | Runtime only | Runtime only | **Compile-time** | Runtime only | Runtime only |
| **Help Gen** | Auto + `setHelp()` | Auto + suggestions | Auto + rich format | Auto from docstrings | `showHelpAfterError()` |
| **Validation** | Manual in `execute()` | Manual + `RunE` | **Parse-time via types** | ClickException | `InvalidArgumentError` |
| **Enums/Limited Values** | Manual string matching | Manual | **`ValueEnum` derive** | `click.Choice()` | Manual |
| **Shell Completions** | Via `BashCompletion人影` | Built-in | `clap_complete` | Third-party | Via `completions` |
| **Testing** | Manual | `assert_cmd` | `assert_cmd` + `trycmd` | `click.testing.CliRunner` | `assertCommand` |

---

## Key Findings

### 1. Command Routing

**Current (Symfony):** Manual `addCommands([new X(), new Y()...])` — verbose, error-prone for ordering.

**Better Patterns:**
- **Go/Cobra:** Tree with `AddCommand()` / `AddCommand(child)` for nesting
- **Rust/Clap:** Enum-based routing — compiler guarantees exhaustive handling
- **Click:** Decorator-based registration + context sharing

**Recommendation for candy-shell:**
Consider a `CommandRegistry` that auto-discovers `*Command.php` files and registers them programmatically:
```php
// Concept: auto-discovery
foreach (glob(__DIR__ . '/Command/*Command.php') as $file) {
    $class = 'SugarCraft\\Shell\\Command\\' . basename($file, '.php');
    $this->addCommands([new $class()]);
}
```

### 2. Flag Parsing

**Current:** 15+ `addOption()` calls per command — verbose, repetitive.

**Better Patterns:**
- **Rust/Clap derive:** Declarative struct fields = automatic parsing, validation, help
- **Go/Cobra:** Builder pattern with validation helpers
- **Click:** Type-safe decorators with automatic conversion

**Recommendation for candy-shell:**
Create a `FlagSpec` trait/attribute for declarative option definitions:
```php
// Concept: declarative flag definition
#[AsCommand(name: 'style')]
final class StyleCommand extends Command
{
    use FlagSpec;
    
    protected static function flags(): array {
        return [
            'foreground' => ['type' => 'string', 'help' => 'Foreground colour...'],
            'bold' => ['type' => 'bool'],
            // ...
        ];
    }
}
```

### 3. Help Generation

**Current:** Basic Symfony help — works but not rich.

**Better Patterns:**
- **Rust/Clap:** Rich colored help with value enumerations, default values, env var hints
- **Cobra:** Suggestions for typos, command aliases
- **Click:** Excellent terminal edge-case handling

**Recommendation:**
Extend `AsCommand` attribute to include more metadata (examples, env vars, deprecation):
```php
#[AsCommand(
    name: 'style',
    description: '...',
    examples: [
        ['gum style --foreground 196 "Hello"'],
        ['echo "Hi" | gum style'],
    ],
    seeAlso: ['https://github.com/charmbracelet/gum#style']
)]
```

### 4. Subcommand Organization

**Current:** Flat list in `Application.php`. Commands have no hierarchical relationships.

**Better Patterns:**
- **Cobra:** Groups can nest subcommands (`git commit`, `git push`)
- **Clap:** Enum-based — mutual exclusion enforced at compile time
- **Click:** Groups with `ctx.obj` for shared state

**Recommendation:**
For future extensibility, consider command groups:
```php
// Conceptual: group-based registration
$this->addCommands([
    'input'  => [new InputCommand(), new ConfirmCommand()],  // interactive prompts
    'format' => [new StyleCommand(), new FormatCommand()],   // formatting
    'filter' => [new FilterCommand(), new ChooseCommand()],  // selection/filtering
]);
```

### 5. Error Handling

**Current:** Return `Command::FAILURE` / `Command::SUCCESS`; basic exception propagation.

**Better Patterns:**
- **Go/Cobra:** `RunE` returns error, centralized handling via `Execute()`
- **Rust/Clap:** `Result` type, parse-time validation, rich error messages
- **Click:** `ClickException` with automatic formatting

**Recommendation:**
Adopt `CandyException` with error codes for different failure modes:
```php
enum ErrorCode {
    case InvalidOption;
    case Timeout;
    case Aborted;
    case ProcessFailed;
}

throw new CandyException('User aborted', ErrorCode::Aborted);
```

### 6. Value Enumeration / Limited Options

**Current:** Manual string matching in `StyleBuilder::fromFlags()` (e.g., border presets, align values).

**Better Patterns:**
- **Rust/Clap `ValueEnum`:** Compile-time enum for valid values, auto-help with possible values
- **Click `click.Choice(['foo', 'bar'])`:** Runtime validation with helpful messages

**Recommendation:**
Create PHP enum attributes for constrained options:
```php
enum Alignment: string {
    case Left = 'left';
    case Center = 'center';
    case Right = 'right';
}

#[AsCommand(name: 'style')]
final class StyleCommand extends Command
{
    // In configure():
    $this->addOption('align', null, InputOption::VALUE_REQUIRED, 
        'Alignment: ' . implode('|', array_column(Alignment::cases(), 'value')));
}
```

---

## Specific Improvements for candy-shell

### High Priority

#### 1. **Auto-Discovery of Commands** (Effort: Low, Impact: Medium)
**Problem:** 13 `new XCommand()` calls in `Application.php` — boilerplate, easy to forget a command.

**Solution:** Use `ReflectionClass` to auto-discover and register commands:
```php
// In Application::__construct()
$commandDir = __DIR__ . '/Command';
foreach (glob("{$commandDir}/*Command.php") as $file) {
    $class = 'SugarCraft\\Shell\\Command\\' . basename($file, '.php');
    if (is_a($class, Command::class, true)) {
        $this->addCommands([new $class()]);
    }
}
```

#### 2. **FlagSpec Trait for Declarative Options** (Effort: Medium, Impact: High)
**Problem:** 15+ `addOption()` calls per interactive command — verbose, hard to maintain.

**Solution:** Create a `FlagSpec` attribute/trait:
```php
#[\Attribute(\Attribute::TARGET_CLASS)]
final class FlagSpec
{
    public function __construct(
        public readonly array $arguments = [],
        public readonly array $options = [],
        public readonly array $examples = [],
    ) {}
}

// Usage in command:
#[FlagSpec(
    options: [
        'foreground' => ['type' => 'string', 'help' => 'Foreground colour'],
        'bold' => ['type' => 'bool', 'help' => 'Bold text'],
    ]
)]
final class StyleCommand extends Command { }
```

#### 3. **ValueEnum Pattern for Constrained Options** (Effort: Low, Impact: Medium)
**Problem:** Manual string validation in `StyleBuilder` for border/alignment values.

**Solution:** PHP native enums (PHP 8.1+):
```php
enum BorderPreset: string {
    case Normal = 'normal';
    case Rounded = 'rounded';
    case Thick = 'thick';
    case Double = 'double';
    case Block = 'block';
    case Hidden = 'hidden';
    
    public static function tryFrom(string $value): ?self {
        foreach (self::cases() as $case) {
            if (strcasecmp($case->value, $value) === 0) {
                return $case;
            }
        }
        return null;
    }
}
```

### Medium Priority

#### 4. **Enhanced Help with Examples** (Effort: Low, Impact: Medium)
**Problem:** No usage examples in `--help` output.

**Solution:** Extend `AsCommand` attribute or use `setExamples()`:
```php
// In Application.php or command configure:
$this->setExamples([
    'gum style --foreground 196 "Hello World"',
    'echo "Hi" | gum style --bold',
]);
```

#### 5. **Command Aliases** (Effort: Low, Impact: Low)
**Problem:** `gum` has aliases (e.g., `filter` → `find`); candy-shell only has primary names.

**Solution:** `AsCommand` attribute accepts aliases:
```php
#[AsCommand(name: 'filter', aliases: ['find'])]
```

#### 6. **Error Suggestions** (Effort: Medium, Impact: Low)
**Problem:** No "did you mean" suggestions for typos.

**Solution:** Implement similar to Commander.js:
```php
// In Application.php - wrap Execute():
try {
    parent::execute($input, $output);
} catch (\Throwable $e) {
    if (str_contains($e->getMessage(), 'unknown option')) {
        $suggestion = $this->suggestOption($input);
        if ($suggestion) {
            $output->writeln("<comment>Did you mean {$suggestion}?</comment>");
        }
    }
    throw $e;
}
```

### Lower Priority (Future Consideration)

#### 7. **Shell Completions** — Symfony has `BashCompletionExtension` but not built-in

#### 8. **Version from composer.json** — Currently hardcoded `'0.4.0'`

#### 9. **Environment Variable Fallbacks** — gum uses `GUM_INPUT_PLACEHOLDER` etc.

---

## Prioritized Recommendations Summary

| Priority | Improvement | Effort | Impact | Complexity |
|----------|-------------|--------|--------|------------|
| **P1** | Auto-discovery of commands | Low | Medium | Low |
| **P1** | ValueEnum for constrained options | Low | Medium | Low |
| **P2** | FlagSpec trait for declarative flags | Medium | High | Medium |
| **P2** | Enhanced help with examples | Low | Medium | Low |
| **P3** | Command aliases | Low | Low | Low |
| **P3** | Error suggestions ("did you mean") | Medium | Low | Medium |
| **P4** | Shell completion generation | High | Medium | High |
| **P4** | Env var fallback configuration | Medium | Medium | Medium |

---

## References

- **Upstream gum:** https://github.com/charmbracelet/gum
- **Cobra docs:** https://context7.com/spf13/cobra/llms.txt
- **Clap derive tutorial:** https://docs.rs/clap/latest/clap/_derive/_tutorial/index.html
- **Click groups:** https://github.com/pallets/click/blob/main/docs/commands-and-groups.md
- **Commander.js error handling:** https://github.com/tj/commander.js/blob/master/Readme.md
- **CLI comparison articles:**
  - https://starlog.is/articles/developer-tools/spf13-cobra
  - https://unixy.io/blog/rust-vs-go-cli-tools/
  - https://techbytes.app/posts/go-vs-rust-cli-tools-performance-dx-guide-2026/
  - https://medium.com/@no-non-sense-guy/building-great-clis-in-2025-node-js-vs-go-vs-rust-e8e4bf7ee10e
