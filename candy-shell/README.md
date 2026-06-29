<img src=".assets/icon.png" alt="candy-shell" width="160" align="right">

# CandyShell

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-shell)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-shell)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-shell?label=packagist)](https://packagist.org/packages/sugarcraft/candy-shell)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/style.gif)
```sh
composer require sugarcraft/candy-shell
```

PHP port of [charmbracelet/gum](https://github.com/charmbracelet/gum) —
a composer-installable CLI of SugarCraft TUI primitives, useful for
shell scripts.

```sh
# Apply styling.
candyshell style --foreground "#ff5f87" --bold "Hello, candy!"

# Pick one item.
choice=$(candyshell choose Pizza Burger Salad)

# Read a single line.
name=$(candyshell input --placeholder "Your name?")

# Confirm a destructive action.
candyshell confirm "Really delete $file?" && rm "$file"
```

## Subcommands

All 13 gum subcommands ship. Run `candyshell <cmd> --help` for the full
flag list per command.

## Auto-discovery

CandyShell uses PHP attributes to auto-discover commands at runtime.
Mark any class extending `Symfony\Component\Console\Command\Command` with
`#[Command]` and it will be picked up by `Application::scan()`.

### Help attributes

Two additional attributes enrich the `--help` output of your commands:

- **`#[Alias('name')]`** — registers an alternative name for the command
  (e.g. `choose` → `cho`). Multiple aliases are supported via repeated
  attributes.
- **`#[Example('usage', 'description')]`** — adds an example line to the
  command's help block. The `description` parameter is optional. Multiple
  examples are supported via repeated attributes.

The `HelpFormatter` class renders these automatically when `--help` is
invoked. It reads `#[Alias]` and `#[Example]` attributes via
`ReflectionClass::getAttributes()` and formats them alongside the
standard Symfony description and help text.

### Typo suggestion

When a user types an unknown command name, `Application::find()` runs it
through a `TypoSuggester` that computes Levenshtein distance against all
registered command names. If a match is found within distance ≤ 2, the
error message suggests the nearest alternative (e.g. "Did you mean
`choose`?"). Beyond distance 2 the original error propagates silently.

```php
use SugarCraft\Shell\Attribute\Command;
use SugarCraft\Shell\Attribute\Flag;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[Command(name: 'mycmd', description: 'Does something useful.', descriptionSection: 'Longer help text.')]
final class MyCommand extends Command
{
    #[Flag(name: 'format', short: 'f', description: 'Output format.', enum: FormatType::class)]
    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
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

Register the namespace in your bootstrap:

```php
$app = new \SugarCraft\Shell\Application();
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
| `filter`   | Fuzzy filter over stdin lines (single- or multi-select). |
| `format`   | Render Markdown / code / template / emoji to the terminal. |
| `input`    | Read a single line; supports `--password` masking. |
| `join`     | Concatenate two styled fragments side-by-side or stacked. |
| `log`      | Levelled / structured log output (text · json · logfmt). |
| `pager`    | Scrollable viewer for long input. |
| `spin`     | Run an external command behind a spinner. |
| `style`    | Apply Sprinkles styling to argv (or stdin). |
| `table`    | Render a CSV / TSV table. |
| `write`    | Multi-line text editor. |

## Flag reference (selected highlights)

The audit lists upstream-gum flags that are not yet wired in CandyShell.
The shipped surface today covers the 80 % case for shell scripts; see
[AUDIT_2026_05_06.md](../AUDIT_2026_05_06.md) for the full delta. Common
flags across commands:

- `--limit N` / `--no-limit` / `--ordered` / `--selected="a,b"` —
  multi-select on `choose` and `filter`.
- `--header "Email:"` / `--prompt "> "` / `--value "$LAST"` /
  `--char-limit N` / `--width N` / `--max-lines N` / `--show-line-numbers`
  on `input` / `write`.
- `--affirmative "Yes"` / `--negative "No"` / `--default=yes|no` /
  `--show-output` on `confirm`.
- `--show-output` / `--show-error` plus 12 spinner styles
  (`dot` · `line` · `pulse` · `globe` · `points` · `monkey` · `moon`
  · `meter` · `mini-dot` · `hamburger` · `ellipsis` · `jump`) on `spin`.
- `--min-level info` / `--prefix` / `--time RFC3339` / `--file out.log`
  / `--formatter text|json|logfmt` / `--structured` on `log`.
- `--border` / `--border-foreground "#ff0"` / `--height N` / `--trim`
  on `style`.

## Environment

CandyShell respects standard CLI colour conventions:

- **`NO_COLOR=1`** disables every SGR escape — output is plain ASCII.
- **`CLICOLOR=0`** disables colour when stdout is not a TTY (otherwise
  defaults to colour).
- **`CLICOLOR_FORCE=1`** keeps colour even when stdout is piped or
  redirected.
- **`FORCE_COLOR=1|2|3`** forces a specific tier (16 / 256 /
  TrueColor).

### `CANDYSHELL_*` env var fallbacks

Any command option can be given via an environment variable using the
`CANDYSHELL_` prefix followed by the option name in uppercase with
non-alphanumeric characters replaced by `_`. For example:

```sh
# Equivalent to: candyshell style --foreground=#ff0000 --bold "Hello"
CANDYSHELL_FOREGROUND=#ff0000 CANDYSHELL_BOLD=1 candyshell style "Hello"
```

The fallback is applied when no explicit CLI option is provided. An
explicit flag on the command line always takes precedence over the env var.

### Shell completion

```sh
candyshell completion --shell=bash
candyshell completion --shell=zsh
candyshell completion --shell=fish
```

Emit a shell completion script for bash, zsh, or fish. Source the output
directly or drop it into the appropriate completion directory.

### Version

`candyshell --version` reports the version read from the monorepo root
`composer.json` via `Application::versionFromComposer()`. The version is
discovered by walking up from the package directory to find the nearest
`composer.json` with a non-empty `version` field.

## Exit codes

- `0`   — normal completion. For `confirm`, this means the user picked
  the affirmative answer.
- `1`   — `confirm` declined; or non-zero exit forwarded from the
  external command run by `spin`.
- `130` — interrupted (Ctrl-C / SIGINT). Matches POSIX shell convention.

## Porting from gum

Most `gum X` invocations work as `candyshell X` verbatim. Known
behavioural differences (also see
[AUDIT_2026_05_06.md](../AUDIT_2026_05_06.md)):

- `format` accepts `-t/--type` (`markdown`, `code`, `template`, `emoji`)
  alongside `--theme`. Template support is the lightweight `{{VAR}}`
  expansion — Go template-function helpers are not implemented.
- `--timeout`, `--show-help`, `--strip-ansi`, and the `--cursor-mode`
  flags now accept their gum-equivalent values on every command. Where
  a flag is meaningless to a non-interactive command (`format`, `join`,
  `log`, `style`, `table`) it is still accepted for parity but treated
  as a no-op.
- `confirm --default=yes|no` is the form to use; the older `--default-yes`
  alias is preserved.

## Test

```sh
cd candy-shell && composer install && vendor/bin/phpunit
```

## Demos

### choose

![choose](.vhs/choose.gif)

### Confirm

![confirm](.vhs/confirm.gif)

### file

![file](.vhs/file.gif)

### filter

![filter](.vhs/filter.gif)

### format

![format](.vhs/format.gif)

### Input

![input](.vhs/input.gif)

### join

![join](.vhs/join.gif)

### log

![log](.vhs/log.gif)

### pager

![pager](.vhs/pager.gif)

### spin

![spin](.vhs/spin.gif)

### Style

![style](.vhs/style.gif)

### Table

![table](.vhs/table.gif)

### write

![write](.vhs/write.gif)

## Shared foundations

The `filter` command uses [candy-fuzzy](../candy-fuzzy/README.md) for
fuzzy-matching via `FilterModel` → `SmithWatermanMatcher`. The shell-style
flag parser (`SubStyleParser`) operates on style-flag strings in
isolation and has no fuzzy logic.

## Related

- [SugarCraft monorepo](https://github.com/detain/sugarcraft)
- Upstream: [charmbracelet/gum](https://github.com/charmbracelet/gum)

