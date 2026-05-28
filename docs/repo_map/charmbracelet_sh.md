# charmbracelet/sh

## Metadata
- URL: https://github.com/charmbracelet/sh (originally mvdan/sh, migrated to charmbracelet)
- Language: Go
- Stars: ~4,000+ (estimated based on repo age and activity)
- License: BSD-3-Clause
- Description: A shell parser, formatter, and interpreter. Supports POSIX Shell, Bash, and mksh. Requires Go 1.23 or later.

## Feature List
- **Parser (`syntax` package)**: Full AST parser for POSIX Shell, Bash, and mksh with position tracking for error reporting
- **Formatter (`syntax` package)**: Printer that can format/roundtrip shell scripts with configurable indentation, binary-next-line, case-indent, etc.
- **Shell formatter CLI (`shfmt`)**: Production-ready formatter available via `go install mvdan.cc/sh/v3/cmd/shfmt@latest`
- **Interpreter (`interp` package)**: Execution engine for shell programs with handler callbacks for file operations, command execution, directory reading, and stat
- **Interactive shell (`gosh`)**: Proof-of-concept shell built on interp (not meant to replace POSIX shell)
- **Shell expansion (`expand` package)**: Parameter expansion, arithmetic expansion, command substitution, quote removal
- **Pattern matching (`pattern` package)**: Shell glob/wildcard to regex conversion supporting `{a,b}` braces, `**` globstar, character classes
- **High-level API (`shell` package)**: `Expand()` and `Fields()` functions for common shell expansion operations
- **JSON serialization**: Typed JSON support for AST via `syntax/typedjson` package
- **Fuzzing support**: Native Go fuzzing support for the parser

## Key Classes and Methods

### `syntax` package

**Parser**
- `NewParser() *Parser` — Creates a new parser instance
- `Parse(io.Reader, string) (*File, error)` — Parse a shell script into AST
- `ParseWithRecovery(io.Reader, string) (*File, error)` — Parse with error recovery
- `Interactive(io.Reader, func([]*Stmt) bool) error` — Interactive parsing with callback

**File (AST root)**
- `Name string` — File name
- `Stmts []*Stmt` — Statements in the file
- `Last []Comment` — Comments at end of file

**Stmt (Statement)**
- `Cmd Command` — The command being executed
- `Negated bool` — `!` prefix
- `Background bool` — trailing `&`
- `Redirs []*Redirect` — I/O redirections

**Command implementations**
- `*CallExpr` — Simple command execution or function call
- `*IfClause` — if/elif/else/fi
- `*WhileClause` — while/until loops
- `*ForClause` — for loops (word iteration and C-style)
- `*CaseClause` — case statement
- `*Subshell` — `(...)` subshell
- `*Block` — `{ ... }` code block
- `*BinaryCmd` — `|` pipe and `||`, `&&` control operators
- `*FuncDecl` — Function declaration
- `*ArithmCmd` — `((...))` arithmetic
- `*TestClause` — `[[...]]` test
- `*CoprocClause` — mksh `|&` coprocess

**Word structure**
- `*Word` — Shell word with `Parts []WordPart`
- `*Lit` — Literal string
- `*SglQuoted` — Single-quoted string
- `*DblQuoted` — Double-quoted string with nested parts
- `*ParamExp` — `${var}`, `${var[index]}`, `${var:offset:len}`, etc.
- `*CmdSubst` — `$(...)` command substitution
- `*ArithmExp` — `$((...))` arithmetic expansion
- `*ProcSubst` — Process substitution `<(...)`, `>(...)`
- `*ExtGlob` — Bash extended globs `@(*|...)`

**Printer**
- `NewPrinter(...PrinterOption) *Printer` — Create a printer
- `Print(io.Writer, Node) error` — Print an AST node with formatting
- `PrintNode(io.Writer, Node) error` — Print without trailing newline
- Options: `Indent()`, `BinaryNextLine()`, `SwitchCaseIndent()`, `SpaceRedirects()`, `Minify()`, `FunctionNextLine()`

### `interp` package

**Runner**
- `New(...RunnerOption) (*Runner, error)` — Create interpreter instance
- `Run(ctx context.Context, node syntax.Node) error` — Execute AST node
- `Reset()` — Reset runner to initial state for reuse
- `Subshell() *Runner` — Create concurrent-safe copy
- `Exited() bool` — Check if last Run triggered shell exit

**Runner options**
- `Env(expand.Environ)` — Set environment variables
- `Dir(string)` — Set working directory
- `StdIO(io.Reader, io.Writer, io.Writer)` — Configure stdio
- `Interactive(bool)` — Enable interactive mode
- `Params(...string)` — Set shell parameters and options
- `ExecHandlers(...func(ExecHandlerFunc) ExecHandlerFunc)` — Middleware for command execution
- `OpenHandler()`, `ReadDirHandler2()`, `StatHandler()` — Handler injection points

**ExitStatus**
- `ExitStatus uint8` — Exit code type
- `IsExitStatus(err error) (uint8, bool)` — Extract exit status from error

### `shell` package

- `Expand(string, func(string) string) (string, error)` — Expand string with double-quote rules
- `Fields(string, func(string) string) ([]string, error)` — Split string into fields with shell expansion

### `expand` package

- `Config` — Expansion configuration with environment and options
- `Document(*Config, *syntax.Word) (string, error)` — Expand word as double-quoted document
- `Fields(*Config, ...*syntax.Word) ([]string, error)` — Expand words as command fields
- `FuncEnviron(func(string) string) Environ` — Create environ from function

### `pattern` package

- `Regexp(string, Mode) (string, error)` — Convert shell glob to regex string
- `Mode` flags: `Shortest`, `Filenames`, `Braces`, `EntireString`, `NoGlobCase`, `NoGlobStar`

## Notable Algorithms / Named Patterns

- **Recursive descent parser** with operator precedence for arithmetic expressions (`parser_arithm.go`)
- **Error recovery** using synchronized parsing to continue after errors and report multiple issues
- **Handler/middleware pattern** for command execution in `interp.ExecHandlers()` allowing chained middleware like `next()` calls
- **Overlay environment** pattern for variable scoping with parent/child environ hierarchy
- **Position tracking** using bit-packed uint32 for offset (32-bit) and line/column (18+14 bits) in `syntax.Pos`
- **Lexer-based tokenizer** with token kinds for all shell constructs in `tokens.go`

## Strengths
- **Pure Go** — No external dependencies beyond the standard library and a few utilities; easy to embed
- **Comprehensive AST** — Detailed syntax tree covering all POSIX, Bash, and mksh features
- **Round-trip printing** — Parser → Printer produces semantically equivalent output, enabling format verification
- **Configurable formatting** — Many options for indentation, binary operators, function braces, etc.
- **Handler injection** — Full control over file ops, command execution, directory reading via handler interfaces
- **Error recovery** — `RecoverErrors()` allows collecting multiple parse errors in one pass
- **Well-tested** — Extensive `filetests_test.go` with hundreds of test cases; native Go fuzzing support
- **Active maintenance** — Regular releases, recent Go 1.23 requirement ensures modern Go features
- **WASM/npm availability** — `sh-syntax` npm package bundles the parser as WASM

## Weaknesses
- **Pure Go subshell limitation** — Cannot fork processes, so subshells use goroutines instead; real PIDs and file descriptors cannot be used directly
- **No interactive shell parity** — `gosh` is explicitly a POC; full interactive features like job control, readline editing are out of scope
- **POSIX `$((` ambiguity** — Backtracking would complicate the parser and break streaming support
- **Bash associative arrays require quotes** — Static parser must assume unquoted index is arithmetic expression
- **Partial Bash support** — Not all Bash features are implemented; `extglob`, `complete`, etc. are not supported
- **Deprecated `KeepPadding`** — The formatting option is acknowledged as flawed and will be removed in v4

## SugarCraft Mapping

This repository is a **Go library** (shell parser/formatter/interpreter), while SugarCraft is a **PHP monorepo** of TUI (Text User Interface) library ports from the Charmbracelet Go ecosystem. There is no direct functional equivalent in SugarCraft, as SugarCraft focuses on PHP ports of Go TUI libraries (like bubbletea, glow, etc.) rather than shell parsing.

**Indirect mapping considerations:**
- If SugarCraft were to port this library, it would create `sugar-sh` or similar for shell-related functionality
- The **pattern matching** (`pattern` package) could map to `honey-glob` or similar if globbing were needed
- The **expansion** logic could inform string handling utilities if ever needed
- **Parser combinator approach** could inspire SugarCraft's own parsing utilities

**No direct SugarCraft equivalent exists** — this is a computational linguistics/parsing library, not a TUI component.

## Analysis

The `charmbracelet/sh` library (originally `mvdan/sh`, now under the charmbracelet umbrella) is a mature, production-grade shell parser written in pure Go. Its architecture is clean and layered: the `syntax` package provides the core parser producing an AST, the `interp` package executes that AST with customizable handlers, and the `expand` package handles the myriad expansion forms (variables, arithmetic, command substitution). The `shfmt` tool built on top is widely used in the Go ecosystem for formatting shell scripts consistently.

The parser uses a traditional recursive-descent approach with separate lexing, and its error recovery mechanism allows collecting multiple parse errors rather than failing on the first. The AST design is comprehensive, covering all major shell constructs including functions, control flow, redirections, and expansions. The position tracking in `syntax.Pos` uses clever bit-packing to store offset, line, and column in a single uint32.

The interpreter is designed for embedding, with handler interfaces that allow users to intercept file operations, command execution, and directory reading. The middleware pattern in `ExecHandlers` is particularly elegant, allowing chaining of execution handlers like middleware in web frameworks. The main limitation is that, being pure Go, it cannot truly fork processes, so subshells are implemented as goroutines with goroutine-local state.

The library is notable for its thorough testing, with `filetests_test.go` containing hundreds of test cases covering edge cases. It also uses Go's native fuzzing support, which helps catch parser regressions. The project maintains backward compatibility carefully, with deprecation notices for APIs like `KeepPadding` slated for removal in v4. This is a well-engineered library suitable for both tooling (linting, formatting) and embedding (script execution in Go applications).
