<img src=".assets/icon.png" alt="candy-scriptui" width="160" align="right">

# CandyScriptui

> Formerly published as `sugarcraft/candy-shell` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Shell\` → `SugarCraft\ScriptUi\`. <!-- @rename-keep -->

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-scriptui)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-scriptui)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-scriptui?label=packagist)](https://packagist.org/packages/sugarcraft/candy-scriptui)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/style.gif)
```sh
composer require sugarcraft/candy-scriptui
```

candy-scriptui — a composer-installable CLI of SugarCraft TUI primitives for
shell scripts, for PHP 8.3+. Composer installs the launcher at
`vendor/bin/candy-scriptui`; the examples below assume it is on your `PATH`
(add `vendor/bin` to it, or invoke the full path). Prebuilt single-file
binaries are published per commit as rolling dev phars:

```sh
curl -fsSL -o candy-scriptui \
  https://github.com/detain/sugarcraft/releases/download/candy-scriptui/dev/candy-scriptui.phar
chmod +x candy-scriptui
```

```sh
# Apply styling.
candy-scriptui style --foreground "#ff5f87" --bold "Hello, candy!"

# Pick one item.
choice=$(candy-scriptui choose Pizza Burger Salad)

# Read a single line.
name=$(candy-scriptui input --placeholder "Your name?")

# Confirm a destructive action.
candy-scriptui confirm "Really delete $file?" && rm "$file"
```

## Subcommands

All 13 gum subcommands ship. Run `candy-scriptui <cmd> --help` for the full
flag list per command.

## Auto-discovery

CandyScriptui uses PHP attributes to auto-discover commands at runtime.
Mark any class extending `Symfony\Component\Console\Command\Command` with
`#[Command]` and it will be picked up by `Application::scan()`.

### Help attributes

Two additional class-level attributes enrich the `--help` output of
your commands (attach them to the command class — method placement
registers nothing):

- **`#[Alias('name')]`** — registers an alternative name for the command
  (e.g. `choose` → `cho`) when the command is discovered through
  `Application::scan()`. Multiple aliases are supported via repeated
  attributes.
- **`#[Example('usage', 'description')]`** — adds an example line to the
  command's help block. The `description` parameter is optional. Multiple
  examples are supported via repeated attributes.

The `HelpFormatter` renders these automatically: when help is requested
for a command that carries them — via `--help`, `-h`, or the gum-compat
`--show-help` — their block is printed ahead of Symfony's standard help.
Commands without the attributes get the stock help verbatim.

### Typo suggestion

When a user types an unknown command name, `Application::find()` refuses it
outright — commands are only ever matched by exact name, never by prefix —
after running the name through a `TypoSuggester`: one near alternative is
quoted back (`Did you mean "choose"?`), several are listed (`Did you mean
one of: "completion", "confirm", "log"?`), and beyond distance 2 nothing is
suggested.

### Output channels

Every subcommand keeps two streams apart. **Data** (the styled text, the
chosen value, the rendered table, the completion script) goes to STDOUT
raw — never through the console formatter, so a value that happens to look
like `<info>` survives byte-exact. **Diagnostics** (refusals, malformed
flag values, I/O failures) go to STDERR as plain sentences, so scripts
that `eval` completion output or capture `confirm` answers can never
consume an error message as data.

```php
use SugarCraft\ScriptUi\Attribute\Flag;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

// Fully-qualified: the unaliased `Command` name already belongs to the
// Symfony base class.
#[\SugarCraft\ScriptUi\Attribute\Command(name: 'mycmd', description: 'Does something useful.', descriptionSection: 'Longer help text.')]
#[Flag(name: 'format', short: 'f', description: 'Output format.', enum: FormatType::class)]
final class MyCommand extends Command
{
    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        // The scanner registers the option but does not enforce `enum:`;
        // for runtime checking call ValueEnum::validate() on the value.
        // ...
        return self::SUCCESS;
    }
}

enum FormatType: string
{
    case Json = 'json';
    case Yaml = 'yaml';
}
```

`#[Flag]` sits on the **class** (the scanner reads class attributes only);
repeat it once per option.

Register the namespace in your bootstrap:

```php
$app = new \SugarCraft\ScriptUi\Application();
$app->scan('My\\Namespace\\');  // discovers all #[Command] classes
$app->run();
```

`Application::scan($namespace)` iterates all already-loaded classes
under that namespace, picks those bearing `#[Command]`, and registers
them into the application. Classes must be autoloaded (or `require`d)
before `scan()` can find them — the scanner uses `get_declared_classes()`
and does not trigger autoloading.

| Command    | Role |
|------------|------|
| `choose`   | Pick one or many items from a list. |
| `completion` | Emit shell completion script (bash · zsh · fish). |
| `confirm`  | Yes/no prompt — exit `0` on confirm, `1` on cancel. |
| `file`     | Interactive file picker. |
| `filter`   | Incremental filter over stdin lines (substring default, `--fuzzy` opt-in). |
| `format`   | Render Markdown / code / template / emoji to the terminal. |
| `input`    | Read a single line; supports `--password` masking. |
| `join`     | Join N strings (or stdin lines) with a separator — raw text, not styled fragments. |
| `log`      | Levelled / structured log output (text · json · logfmt). |
| `pager`    | Scrollable viewer for long input. |
| `spin`     | Run an external command behind a spinner. |
| `style`    | Apply Sprinkles styling to argv (or stdin). |
| `table`    | Render a CSV / TSV table. |
| `write`    | Multi-line text editor. |

## Flag reference (selected highlights)

Some flags from the original `gum` CLI surface are not wired here yet; the
shipped surface covers the 80 % case for shell scripts. Common
flags across commands:

- `--limit N` / `--no-limit` / `--selected="a,b"` — multi-select on
  `choose` and `filter`; `--selected` is repeatable and comma-separated
  lists are split into separate items (previously the whole string named
  ONE item, silently selecting nothing).
- `--ordered` — keep selection order instead of click order on `choose`
  only (`filter` has no such flag).
- `--strict` / `--fuzzy` / `--no-fuzzy` — matching mode on `filter`.
  Substring matching is the default; fuzzy Smith-Waterman is opt-in.
- `--header "Email:"` / `--prompt "> "` / `--value "$LAST"` /
  `--char-limit N` / `--width N` on `input` and `write`; `--max-lines N`
  and `--show-line-numbers` exist on `write` only.
- `--affirmative "Yes"` / `--negative "No"` / `--default=yes|no` /
  `--show-output` on `confirm`. An unknown `--default` word is refused
  (exit `1`) rather than silently treated as "no".
- `--show-output` / `--show-error` plus 12 spinner styles
  (`dot` · `line` · `pulse` · `globe` · `points` · `monkey` · `moon`
  · `meter` · `mini-dot` · `hamburger` · `ellipsis` · `jump`) on `spin`.
- `--min-level info` / `--prefix` / `--time RFC3339` / `--file out.log`
  / `--formatter text|json|logfmt` / `--structured` on `log`. Unknown
  `--formatter` names are refused (exit `1`).
- `--border` / `--border-foreground "#ff0"` / `--height N` / `--trim`
  on `style`.
- Colours on `--foreground` / `--background` / `--border-foreground`
  accept the 16 palette names (`red`, `bright-blue`, …), `#rgb` /
  `#rrggbb` (bare hex too), or a palette index `0-255`.

## Environment

CandyScriptui respects the standard CLI colour conventions. Resolution
lives in `Runtime/ColourGate.php`, which pins sugar-style's ambient
colour profile before each command runs, so every styled byte stream —
flag-built `style` output, `log` badges, and the `format` markdown
theme — renders inside the resolved capability. The tiers are
sugar-core's ColorProfile semantics: `Ascii` drops colour but keeps
decoration, `NoTty` emits no escapes at all, and `Ansi`/`Ansi256`
re-quantise colours to that depth.

- **`--no-ansi`** (global flag) emits plain bytes — every SGR escape
  dropped, decoration included. `--ansi` overrides.
- **`NO_COLOR`** (any truthy value) disables colour; decoration (bold,
  underline, …) and layout survive.
- **`CLICOLOR=0`** disables colour when stdout is not a TTY (otherwise
  defaults to colour).
- **`CLICOLOR_FORCE=1`** keeps full colour even when stdout is piped or
  redirected, winning over `NO_COLOR`.
- **`FORCE_COLOR=1|2|3`** forces a specific tier (16 / 256 /
  TrueColor) and wins over every other env rule.
- **`TERM=dumb`** emits plain bytes, like `--no-ansi`.

Being a pipe does not by itself strip colour — styled bytes flow to
redirected output as always; set `CLICOLOR=0` for pipe-aware muting.

### `CANDY_SCRIPTUI_*` env var fallbacks

Only presentation options may be given via an environment variable, keyed
by the `CANDY_SCRIPTUI_` prefix followed by the option name in uppercase
with non-alphanumeric characters replaced by `_`. The permitted names are
`align`, `background`, `bold`, `border`, `border-foreground`,
`cursor-prefix`, `faint`, `foreground`, `height`, `italic`, `prefix`,
`reverse`, `separator`, `strikethrough`, `timeout`, `underline`,
`unselected-prefix`, `verbose`, `width`. For example:

```sh
# Equivalent to: candy-scriptui style --foreground=#ff0000 --bold "Hello"
CANDY_SCRIPTUI_FOREGROUND=#ff0000 CANDY_SCRIPTUI_BOLD=1 candy-scriptui style "Hello"
```

Everything else is CLI-only **by construction**: options that answer for
the user (`--default`, `--selected`, `--value`), grant capability
(`format --allow-env`), or redirect data (`--file`, `--out`) are never
read from the environment — a stray `CANDY_SCRIPTUI_*` in a shared CI
image cannot silently weaken a fail-closed flag. Names outside the list
are ignored with no notice.

The pre-rename `CANDYSHELL_<NAME>` spellings still work for the same
allow-listed names: the launcher mirrors them onto `CANDY_SCRIPTUI_<NAME>`
with a deprecation notice, and the new name wins if both are set.

The fallback is applied when no explicit CLI option is provided. An
explicit flag on the command line always takes precedence over the env var.
Negatable options (`--no-<name>`, `InputOption::VALUE_NEGATABLE`) are not
sourced from the environment — the fallback skips them (the shipped
command surface currently defines none).

### Shell completion

```sh
candy-scriptui completion --shell=bash
candy-scriptui completion --shell=zsh
candy-scriptui completion --shell=fish
```

Emit a shell completion script for bash, zsh, or fish. Source the output
directly or drop it into the appropriate completion directory.

### Version

`candy-scriptui --version` answers through `Application::versionString()`, in
precedence order:

1. When running from a phar, the version stamped into the archive's phar
   metadata at build time (a `dev-build` working-tree stamp is refused, so a
   dev archive never impersonates a release).
2. Otherwise the nearest reachable `composer.json` that carries a non-empty
   `version` field, found by walking up from the package directory.
3. Otherwise `0.0.0` — the honest answer when no version metadata is
   reachable. A `composer require`-installed copy inside a project whose
   root `composer.json` declares no `version` sees exactly this; use the
   phar (or the versioned release) when the stamp matters.

### Language

`candy-scriptui` reads `LC_ALL`, then `LC_MESSAGES`, then `LANG` at startup
and answers refusal and UI messages in that language. Sixteen catalogs ship
with the package; a territory spelling falls back to its base language
(`de_DE.UTF-8` → `de`) and then to English. `C`/`POSIX` stay English.

## Exit codes

- `0`   — normal completion. For `confirm`, this means the user picked
  the affirmative answer.
- `1`   — generic failure: an unknown command name, `confirm` declined,
  an abort (Esc) from `choose` / `filter` / `input` / `write` / `file`,
  a refused flag value (`--default`, `--formatter`, `--shell`,
  `--cursor-mode`), a `log --level fatal` line, or exit code `1`
  forwarded from the external command run by `spin`.
- `2`   — `confirm` aborted (Esc) — distinct from a "no" answer.
- `124` — a `--timeout` deadline elapsed before the interaction finished
  (gum-compatible, matches GNU `timeout(1)`). A malformed `--timeout` value
  is refused up front (exit `1` on STDERR) — it is never coerced to "no
  deadline". For `spin`, the captured child output is still flushed when
  `--show-output` / `--show-error` are set, so a timed-out build or test run
  shows what it managed to print.
- `130` — cancelled with Ctrl+C, whether typed (raw mode reports it as a
  key, and the models record it separately from Esc) or delivered as SIGINT
  (128 + 2). `spin` likewise exits `130` when its child is interrupted with
  Esc and never reports a completion code.
- `143` — terminated by SIGTERM (128 + 15). Other OS signals follow the
  same `128 + signum` convention.

`spin` forwards the external command's own exit code verbatim (gum parity),
which means a child exiting `2`, `124`, `130`, or `143` is indistinguishable
from candy-scriptui's own codes for those numbers; gate on the child's
success (`0`) rather than specific non-zero values.

## Keyboard map (interactive commands)

| Key | Effect |
|-----|--------|
| ↑ / ↓ | Move the highlighted item / cursor line. |
| Enter | Submit `choose`, `confirm`, `filter`, `input` (in `write`, inserts a newline). |
| y / n | Answer `confirm` directly. |
| Ctrl+D | Submit `write` (end-of-input convention). |
| Space | Toggle selection in multi-select `choose` / `filter`; page down in `pager`. |
| Tab | Toggle the highlighted item in multi-select `filter`. |
| Any printable key | Filter text in `filter`. |
| `j`/`k` `PgUp`/`PgDn` `b`/`f` `g`/`G` `Home`/`End` `Ctrl+U`/`Ctrl+D` `q` | Paging, scrolling, top/bottom jumps, and quit in `pager`. |
| Esc | Abort (exit `1`, or `2` on `confirm`). |
| Ctrl+C | Abort with exit `130`; `spin` also terminates its child. |
| Backspace | Ascend a directory in `file`; delete before the caret elsewhere. |

The key bindings are owned by each `Model`; the table matches their
doc-comments, and those are exercised by the model tests.

### Sizing, hints, and terminal modes

- Interactive lists and the pager size themselves from the terminal at
  launch (COLUMNS/LINES, then an ioctl against the controlling stream).
  An explicit `--width`/`--height` wins; when nothing is knowable the
  historical literals (lists 60 cells, pager 80×20) keep piped output
  byte-stable. `choose` gained `--width`; `filter`'s existing `--width`
  is now honoured; `pager`'s `--width`/`--height` default to `0` = auto.
- `pager` re-fits live on resize. The list views do not re-flow live
  (sugar-forms `ItemList` exposes resize, but `--width` is a launch-time
  cap there — following SIGWINCH would silently widen explicitly-sized
  lists; see the sugar-forms seam note below).
- `choose`, `filter`, and `pager` paint a one-line keybinding hint under
  the live view (removed from the resolved/final frame).
- `choose` / `filter` / `pager` / `write` / `file` accept
  `--no-alt-screen` to render inline. Note the flip side: entering the
  alternate screen (the default) ERASES the rendered frames on exit —
  pass `--no-alt-screen` when you want the interaction to remain in
  scrollback.
- On `TERM=dumb` the runtime forces inline mode, keeps the cursor
  visible, and `ColourGate` lowers colour — the DEC-private modes the
  renderer would emit are exactly what such terminals cannot digest.

## Compatibility with `gum` invocations

Most `gum X` invocations work as `candy-scriptui X` verbatim. Known
behavioural differences:

- `format` accepts `-t/--type` (`markdown`, `code`, `template`, `emoji`)
  alongside `--theme`. Template support is the lightweight `{{VAR}}`
  expansion — Go template-function helpers are not implemented.
- `--timeout`, `--show-help`, and `--strip-ansi` work on the commands that
  accept them: `--timeout` arms a real deadline (exit `124`) on every
  interactive command including `file`, `--show-help` is honoured as an
  alias for `--help` on every command that defines it, and `--strip-ansi`
  is read by `input`, `format`, and `style`. The `--cursor-mode` flag is
  honoured by `input` and `write` (`hidden` hides the cursor; `blink`
  and `static` leave the terminal default) and refuses any other value.
  Where a flag is meaningless to a non-interactive command (`format`,
  `join`, `log`, `style`, `table`) it is still accepted but treated
  as a no-op.
- `confirm --default=yes|no` is the form to use; the older `--default-yes`
  alias is preserved.

## Test

```sh
cd candy-scriptui && composer install && vendor/bin/phpunit
```

Runnable examples live in [`examples/`](examples/):

- [`examples/cli.php`](examples/cli.php) — drive the `Application`
  in-process (register commands, run, read exit codes).
- [`examples/setup-script.sh`](examples/setup-script.sh) — a shell script
  gating on `candy-scriptui confirm`'s exit code.

## Demos

### choose

![choose](.vhs/choose.gif)

### confirm

![confirm](.vhs/confirm.gif)

### file

![file](.vhs/file.gif)

### filter

![filter](.vhs/filter.gif)

### format

![format](.vhs/format.gif)

### input

![input](.vhs/input.gif)

### join

![join](.vhs/join.gif)

### log

![log](.vhs/log.gif)

### pager

![pager](.vhs/pager.gif)

### spin

![spin](.vhs/spin.gif)

### style

![style](.vhs/style.gif)

### table

![table](.vhs/table.gif)

### write

![write](.vhs/write.gif)

## Shared foundations

The `filter` command uses [sugar-fuzzy](../sugar-fuzzy/README.md) for
fuzzy-matching via `FilterModel` → `SmithWatermanMatcher`. The
`SubStyleParser` utility parses gum-style `element.prop=value` entries
into per-element `Style` maps for **extension** commands; no built-in
command consumes it today, and `choose`'s `--cursor`/`--header` flags are
plain strings, not sub-style entries.

## Internals

`spin` runs its child through the `RealProcess` wrapper, which is
`@deprecated` in favour of sugar-pty's `PosixProcess`; `SpinCommand` is
currently its only consumer and the wrapper is kept for that path until
the port lands.

## Related

- [SugarCraft monorepo](https://github.com/detain/sugarcraft)
- Originally inspired by [charmbracelet/gum](https://github.com/charmbracelet/gum)

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.
