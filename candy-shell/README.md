<img src=".assets/icon.png" alt="candy-shell" width="160" align="right">

# CandyShell

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-shell)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-shell)
[![Packagist Version](https://img.shields.io/packagist/v/candycore/candy-shell?label=packagist)](https://packagist.org/packages/candycore/candy-shell)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/style.gif)

PHP port of [charmbracelet/gum](https://github.com/charmbracelet/gum) —
a composer-installable CLI of CandyCore TUI primitives, useful for
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

| Command   | Role |
|-----------|------|
| `choose`  | Pick one or many items from a list. |
| `confirm` | Yes/no prompt — exit `0` on confirm, `1` on cancel. |
| `file`    | Interactive file picker. |
| `filter`  | Fuzzy filter over stdin lines (single- or multi-select). |
| `format`  | Render Markdown / code / template / emoji to the terminal. |
| `input`   | Read a single line; supports `--password` masking. |
| `join`    | Concatenate two styled fragments side-by-side or stacked. |
| `log`     | Levelled / structured log output (text · json · logfmt). |
| `pager`   | Scrollable viewer for long input. |
| `spin`    | Run an external command behind a spinner. |
| `style`   | Apply Sprinkles styling to argv (or stdin). |
| `table`   | Render a CSV / TSV table. |
| `write`   | Multi-line text editor. |

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

- `format` still uses `--theme` while gum uses `-t/--type` —
  template-function support and the `emoji` / `template` types are
  pending.
- `--style` flags using the gum dotted form (`--header.foreground`,
  `--cursor.foreground`, …) are not yet wired across every command.
- `--timeout` / `--show-help` / `--padding` are not yet universally
  honoured.
- `confirm --default=yes|no` is the form to use; the older `--default-yes`
  alias is no longer needed.

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

